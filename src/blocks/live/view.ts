/**
 * Live page view module: polling, deltas, new-updates button, load older, share,
 * collapsibles, connection notice and analytics events.
 *
 * Directives live only on small, stable regions; the feed and the Context card are plain DOM
 * updated here.
 */

/**
 * WordPress dependencies
 */
import { getElement, store } from '@wordpress/interactivity';

/**
 * Internal dependencies
 */
import { copyText } from '../../shared/copy';
import { loadAssets, type Asset } from './assets';
import { type Plural } from './plural';
import { refreshTimestamp, setupAskForm } from './questions';
import { refreshTimes } from './times';
import { showCopyFeedback } from './copy-feedback';
import { applyDelta, flushQueue, parseHtml, activateScripts, replaceSection, type DeltaEntry } from './feed';
import { PENDING_CAP, isCapped, pendingText, titleWithCount } from './pending';

interface Strings {
	connection: string;
	newUpdatesCapped: string;
	announceCapped: string;
	startReached: string;
	loadOlder: string;
	yesterday: string;
	yesterdayMid: string;
	dateTime: string;
	updated: string;
	updatedYesterday: string;
	updatedDate: string;
	copied: string;
	copyFailed: string;
	live: string;
	ended: string;
	questionsClosed: string;
	askCounter: string;
	askSending: string;
	askSend: string;
	askSuccess: string;
	askNetwork: string;
	askInvalidLength: string;
}

interface Status {
	state: 'draft' | 'upcoming' | 'live' | 'ended';
	rev: number;
	updated_label: string;
	/** Parts of the header line. */
	updated?: { iso: string; day: string; clock: string; date: string; date_year: string; full: string } | null;
	badge_label: string;
	poll_interval: number;
	questions_open: boolean;
	questions_mode: string;
	gated: boolean;
	question_ts?: string;
}

interface Delta {
	until: number;
	reset: boolean;
	entries: DeltaEntry[];
	removed: number[];
	pinned_id: number;
	state: Status[ 'state' ];
	context?: { html: string };
	assets: Asset[];
}

interface State {
	liveId: number;
	rev: number;
	liveState: Status[ 'state' ];
	pollInterval: number;
	restUrl: string;
	nonce: string;
	nonceUrl: string;
	gated: boolean;
	loggedIn: boolean;
	view: string;
	perPage: number;
	pendingCount: number;
	connectionLost: boolean;
	timezone: string;
	plurals: { newUpdates: Plural; announce: Plural };
	strings: Strings;
	readonly hasPending: boolean;
	readonly newUpdatesLabel: string;
	readonly connectionText: string;
}

// Values come from the server (wp_interactivity_state); client-side defaults would override them,
// so the store only declares the derived state.
const { state } = store( 'newspack-live', {
	state: {
		get hasPending(): boolean {
			return ( state as State ).pendingCount > 0;
		},
		// The status region stays in the page and its text changes, so screen readers announce it.
		get connectionText(): string {
			const current = state as State;
			return current.connectionLost ? ( current.strings.connection ?? '' ) : '';
		},
		get newUpdatesLabel(): string {
			const current = state as State;
			return pendingText( current.pendingCount, current.plurals?.newUpdates, current.strings.newUpdatesCapped );
		},
	} as State,
	actions: {
		showPending(): void {
			controller?.showPending();
		},
	},
	callbacks: {
		init(): void {
			const { ref } = getElement();
			if ( ref && ! controller ) {
				controller = new LiveController( ref.closest( '.newspack-live' ) as HTMLElement );
			}
		},
	},
} );

let controller: LiveController | null = null;

/**
 * Dispatches an analytics event and forwards it to gtag when present.
 *
 * @param name    Event name (kebab case).
 * @param entryId Entry ID, if any.
 */
function track( name: string, entryId?: number ): void {
	const detail = { liveId: state.liveId, state: state.liveState, ...( entryId ? { entryId } : {} ) };
	document.dispatchEvent( new CustomEvent( `newspack-live:${ name }`, { detail } ) );
	const gtag = ( window as unknown as { gtag?: ( ...args: unknown[] ) => void } ).gtag;
	if ( typeof gtag === 'function' ) {
		gtag( 'event', `np_live_${ name.replace( /-/g, '_' ) }`, { live_id: state.liveId, state: state.liveState, entry_id: entryId } );
	}
}

/**
 * Drives one live page.
 */
class LiveController {
	private root: HTMLElement;
	private list: HTMLElement;
	private pinned: HTMLElement;
	private announcer: HTMLElement | null;

	private resizeFrame = 0;
	private queue = new Map< number, HTMLElement >();
	private removed = new Set< number >();
	private collapsed = new Map< string, boolean >();
	private timer = 0;
	private failures = 0;
	private delay = 0;
	private stopped = false;
	private busy = false;
	// The page title without the waiting count, and the count last announced.
	private title = document.title;
	private announced = 0;

	/**
	 * @param root `.newspack-live` element.
	 */
	constructor( root: HTMLElement ) {
		this.root = root;
		this.list = root.querySelector( '.newspack-live__entries' ) as HTMLElement;
		this.pinned = root.querySelector( '.newspack-live__pinned' ) as HTMLElement;
		this.announcer = root.querySelector( '.newspack-live__announcer' );
		this.delay = this.baseInterval();

		root.addEventListener( 'click', event => this.onClick( event ) );
		root.addEventListener( 'keydown', event => this.onKeydown( event ) );
		// `toggle` doesn't bubble: listen in the capture phase for every share disclosure.
		root.addEventListener( 'toggle', event => this.onShareToggle( event ), true );
		document.addEventListener( 'click', event => this.closeShares( event.target as Node ) );
		window.addEventListener( 'resize', () => this.onResize() );
		root.querySelectorAll< HTMLElement >( '[data-action="copy-link"]' ).forEach( button => ( button.hidden = false ) );
		document.addEventListener( 'visibilitychange', () => this.onVisibility() );
		// Collapsible sections: closed by default on mobile, open on desktop.
		this.applyCollapsed( root );
		window.matchMedia?.( '(max-width: 781px)' ).addEventListener?.( 'change', () => this.applyCollapsed( this.root ) );
		this.setupStickyAside();
		this.setupQuestions();
		this.refreshTimes();
		// Pages stay open past midnight: "today" / "yesterday" follow the site day.
		window.setInterval( () => this.refreshTimes(), 60000 );
		track( 'view' );

		if ( state.liveState === 'ended' || ! this.list ) {
			this.stopped = true;
			return;
		}
		// Entries the reader asked for before a reload come in first, without the button;
		// then the first poll, 2 s after load.
		this.catchUp().finally( () => this.schedule( 2000 ) );
	}

	/**
	 * Storage key of the revision a reload must catch up with.
	 *
	 * @return Key.
	 */
	private catchUpKey(): string {
		return `newspack-live-catch-up-${ state.liveId }`;
	}

	/**
	 * Reloads the page, remembering the revision the reader has seen announced: the reloaded page may
	 * come from a page cache older than that, and then catches up without the button.
	 *
	 * @param rev Revision to catch up with.
	 */
	private reloadCaughtUp( rev: number ): void {
		try {
			window.sessionStorage.setItem( this.catchUpKey(), String( rev ) );
		} catch {
			// Storage unavailable: the reloaded page offers the button again if it is stale.
		}
		window.location.reload();
	}

	/**
	 * After a reload from the button (or a reset): when the page HTML is older than the revision
	 * the reader asked for, applies the missing entries directly, without the button.
	 */
	private async catchUp(): Promise< void > {
		let target = 0;
		try {
			target = Number( window.sessionStorage.getItem( this.catchUpKey() ) ) || 0;
			window.sessionStorage.removeItem( this.catchUpKey() );
		} catch {
			return;
		}
		if ( target <= state.rev || state.view !== 'latest' ) {
			return;
		}
		this.busy = true;
		try {
			const delta = await this.get< Delta >( `/lives/${ state.liveId }/entries?since=${ state.rev }&until=${ target }` );
			if ( ! delta.reset ) {
				await this.applyDelta( delta, true );
				state.rev = delta.until;
			}
		} catch {
			// The next polls bring the same entries, behind the button.
		} finally {
			this.busy = false;
		}
	}

	/**
	 * Sticky aside: the theme's sticky header height goes to `--newspack-live-sticky-offset`
	 * (the CSS adds the admin bar), and the aside only sticks while it fits below them. Sizes come
	 * from a ResizeObserver and window resizes; nothing listens to scrolling.
	 */
	private setupStickyAside(): void {
		// The offset also places the "new updates" button and inserted entries: set it on
		// every live page, with or without an aside.
		if ( typeof window.ResizeObserver !== 'function' ) {
			return;
		}
		const aside = this.root.querySelector< HTMLElement >( '.newspack-live__aside' );
		const header = Array.from(
			document.querySelectorAll< HTMLElement >( '.site-header, .wp-site-blocks > header, header.wp-block-template-part, .is-position-sticky' )
		).find( element => ! this.root.contains( element ) && [ 'sticky', 'fixed' ].includes( window.getComputedStyle( element ).position ) );
		const update = () => {
			const offset = header ? header.getBoundingClientRect().height : 0;
			this.root.style.setProperty( '--newspack-live-sticky-offset', `${ Math.round( offset ) }px` );
			const adminBar =
				parseFloat( window.getComputedStyle( document.documentElement ).getPropertyValue( '--wp-admin--admin-bar--height' ) ) || 0;
			// The same 16 px margin as the CSS `top`, above and below.
			aside?.classList.toggle( 'newspack-live__aside--sticky', aside.offsetHeight <= window.innerHeight - adminBar - offset - 32 );
		};
		const observer = new window.ResizeObserver( () => update() );
		if ( aside ) {
			observer.observe( aside );
		}
		if ( header ) {
			observer.observe( header );
		}
		window.addEventListener( 'resize', update );
		update();
	}

	/**
	 * Corrects the entry time labels for the current site day.
	 */
	private refreshTimes(): void {
		refreshTimes( this.root, Date.now(), state.timezone, state.strings );
	}

	/**
	 * Interval for the current state, in milliseconds.
	 *
	 * @return Milliseconds.
	 */
	private baseInterval(): number {
		return ( state.liveState === 'upcoming' ? 60 : state.pollInterval ) * 1000;
	}

	/**
	 * Schedules the next poll.
	 *
	 * @param ms Delay.
	 */
	private schedule( ms: number ): void {
		window.clearTimeout( this.timer );
		if ( this.stopped || document.hidden ) {
			return;
		}
		this.timer = window.setTimeout( () => this.poll(), ms );
	}

	/**
	 * Pauses while hidden; polls at once when visible again.
	 */
	private onVisibility(): void {
		if ( document.hidden ) {
			window.clearTimeout( this.timer );
		} else if ( ! this.stopped ) {
			this.schedule( 0 );
		}
	}

	/**
	 * Fetches a public route: anonymous on public lives, credentials + nonce on gated ones.
	 *
	 * @param path Route path.
	 * @return JSON.
	 */
	private async get< T >( path: string ): Promise< T > {
		const controllerAbort = new AbortController();
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- the timeout must start before the request.
		const timeout = window.setTimeout( () => controllerAbort.abort(), 15000 );
		try {
			const init: RequestInit = { credentials: 'omit', signal: controllerAbort.signal };
			if ( state.gated ) {
				init.credentials = 'same-origin';
				init.headers = state.nonce ? { 'X-WP-Nonce': state.nonce } : {};
			}
			const url = new URL( state.restUrl + path, window.location.href );
			const response = await window.fetch( url.toString(), init );
			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}
			return ( await response.json() ) as T;
		} finally {
			window.clearTimeout( timeout );
		}
	}

	/**
	 * One poll: /status, then the delta when the revision moved.
	 */
	private async poll(): Promise< void > {
		if ( this.busy ) {
			// A page of older entries is loading: try again shortly, never drop the loop.
			this.schedule( 1000 );
			return;
		}
		this.busy = true;
		try {
			const status = await this.get< Status >( `/lives/${ state.liveId }/status` );
			state.pollInterval = Math.max( 30, Math.min( 120, status.poll_interval ) );
			if ( status.rev > state.rev ) {
				const delta = await this.get< Delta >( `/lives/${ state.liveId }/entries?since=${ state.rev }&until=${ status.rev }` );
				if ( delta.reset ) {
					this.reloadCaughtUp( status.rev );
					return;
				}
				await this.applyDelta( delta );
				state.rev = delta.until;
			}
			this.applyStatus( status );
			this.refreshTimes();
			this.failures = 0;
			state.connectionLost = false;
			this.delay = this.baseInterval();
		} catch {
			this.failures++;
			// Exponential backoff, ×2 up to 120 s; the notice appears after 3 consecutive failures.
			this.delay = Math.min( 120000, Math.max( this.baseInterval(), this.delay * 2 ) );
			state.connectionLost = this.failures >= 3;
		} finally {
			this.busy = false;
			this.schedule( this.delay );
		}
	}

	/**
	 * Applies a delta: assets first, then entries, removals, pin, and the Context card. New entries
	 * wait behind the button, unless the page is catching up after a reload.
	 *
	 * @param delta   Delta.
	 * @param catchUp Whether new entries go straight into the feed.
	 */
	private async applyDelta( delta: Delta, catchUp = false ): Promise< void > {
		await loadAssets( delta.assets );
		delta.removed.forEach( id => this.removed.add( id ) );
		delta.entries.forEach( entry => this.removed.delete( entry.id ) );
		const result = applyDelta( { list: this.list, pinned: this.pinned }, delta, {
			autoInsert: catchUp,
			complete: ! this.root.querySelector( '.newspack-live__load-older' ),
			queue: this.queue,
		} );
		if ( delta.context ) {
			// The new card keeps the reader's choice (open or closed).
			replaceSection( this.root.querySelector( '#newspack-live-context' ), delta.context.html, next => this.applyCollapsed( next ) );
		}
		this.root.querySelector( '.newspack-live__empty' )?.remove();

		if ( state.view === 'latest' ) {
			this.updatePending();
		} else if ( result.queued ) {
			// Cursor and entry views never insert: offer to go back to the latest updates.
			this.root.querySelector< HTMLElement >( '.newspack-live__new-updates--cursor' )?.removeAttribute( 'hidden' );
		}
		this.inserted( [
			...result.inserted,
			...delta.entries.map( entry => this.root.querySelector< HTMLElement >( `#live-entry-${ entry.id }` ) ),
		] );
	}

	/**
	 * After entries are inserted: the `entries-inserted` event and embed re-initialization.
	 *
	 * @param elements Inserted elements (nulls ignored).
	 */
	private inserted( elements: Array< HTMLElement | null > ): void {
		const connected = Array.from( new Set( elements ) ).filter( ( element ): element is HTMLElement => !! element?.isConnected );
		if ( ! connected.length ) {
			return;
		}
		const ids = connected.map( element => Number( element.dataset.entryId ) );
		document.dispatchEvent( new CustomEvent( 'newspack-live:entries-inserted', { detail: { ids: Array.from( new Set( ids ) ) } } ) );
		this.reinitEmbeds( connected );
	}

	/**
	 * Updates the header and form from /status; stops when the live has ended.
	 *
	 * @param status Status.
	 */
	private applyStatus( status: Status ): void {
		const changed = status.state !== state.liveState;
		state.liveState = status.state;
		const badge = this.root.querySelector< HTMLElement >( '.newspack-live-badge' );
		if ( badge && changed ) {
			badge.className = `newspack-live-badge newspack-live-badge--${ status.state }`;
			badge.textContent = '';
			if ( status.state === 'live' ) {
				const dot = document.createElement( 'span' );
				dot.className = 'newspack-live-badge__dot';
				dot.setAttribute( 'aria-hidden', 'true' );
				badge.append( dot, state.strings.live );
			} else {
				badge.textContent = status.state === 'upcoming' ? status.badge_label : state.strings.ended;
			}
		}
		const updated = this.root.querySelector< HTMLElement >( '.newspack-live__updated' );
		const label = updated?.querySelector< HTMLElement >( '.newspack-live-time' );
		if ( updated && label && status.updated ) {
			// refreshTimes() then labels it for the reader's current site day.
			updated.setAttribute( 'datetime', status.updated.iso );
			updated.dataset.day = status.updated.day;
			updated.dataset.clock = status.updated.clock;
			updated.dataset.date = status.updated.date;
			updated.dataset.dateYear = status.updated.date_year;
			label.textContent = status.updated_label;
			const full = updated.querySelector< HTMLElement >( '.newspack-live__sr-only' );
			if ( full ) {
				full.textContent = status.updated.full;
			}
		}
		this.syncQuestions( status );
		refreshTimestamp( this.root.querySelector( 'form.newspack-live-ask' ), status.question_ts );
		if ( status.state === 'ended' ) {
			this.stopped = true;
			window.clearTimeout( this.timer );
		}
	}

	/**
	 * Wires the ask form.
	 */
	private setupQuestions(): void {
		const form = this.root.querySelector< HTMLFormElement >( 'form.newspack-live-ask' );
		if ( ! form ) {
			return;
		}
		setupAskForm( form, {
			liveId: state.liveId,
			restUrl: state.restUrl,
			loggedIn: state.loggedIn,
			nonce: state.nonce,
			nonceUrl: state.nonceUrl,
			strings: {
				counter: state.strings.askCounter,
				sending: state.strings.askSending,
				send: state.strings.askSend,
				success: state.strings.askSuccess,
				network: state.strings.askNetwork,
				invalidLength: state.strings.askInvalidLength,
			},
			onSubmitted: () => track( 'question-submitted' ),
		} );
	}

	/**
	 * Shows or hides the question form.
	 *
	 * @param status Status.
	 */
	private syncQuestions( status: Status ): void {
		const ask = this.root.querySelector< HTMLElement >( '#newspack-live-ask' );
		if ( ! ask || status.questions_mode === 'off' ) {
			return;
		}
		const panel = ask.querySelector< HTMLElement >( '#newspack-live-ask-panel' );
		const title = ask.querySelector< HTMLElement >( '#newspack-live-ask-title' );
		let closed = ask.querySelector< HTMLElement >( '.newspack-live__closed' );
		if ( status.questions_open ) {
			if ( panel ) {
				panel.hidden = !! title?.querySelector( '[aria-expanded="false"]' );
			}
			if ( title ) {
				title.hidden = false;
			}
			closed?.remove();
			return;
		}
		if ( panel ) {
			panel.hidden = true;
		}
		if ( title ) {
			title.hidden = true;
		}
		if ( ! closed ) {
			closed = document.createElement( 'p' );
			closed.className = 'newspack-live__closed';
			closed.textContent = state.strings.questionsClosed;
			ask.append( closed );
		}
	}

	/**
	 * Syncs the button, the tab title and the announcement with the waiting entries: the
	 * count, "20+" above the cap; one announcement per change of what is shown (21 → 22 stays
	 * "20+"), none when it drops to 0. The title is only written when that changes too, so other
	 * scripts' titles survive the polls.
	 */
	private updatePending(): void {
		const count = this.queue.size;
		const shown = Math.min( count, PENDING_CAP + 1 );
		state.pendingCount = count;
		if ( shown === this.announced ) {
			return;
		}
		document.title = titleWithCount( this.title, count );
		if ( shown > 0 ) {
			this.announce( count );
		}
		this.announced = shown;
	}

	/**
	 * Announces waiting updates in the polite live region (never the content).
	 *
	 * @param count Number of waiting updates.
	 */
	private announce( count: number ): void {
		if ( ! this.announcer ) {
			return;
		}
		const message = pendingText( count, state.plurals?.announce, state.strings.announceCapped );
		const announcer = this.announcer;
		// Clear first, so an identical message is announced again.
		announcer.textContent = '';
		window.setTimeout( () => {
			announcer.textContent = message;
		}, 100 );
	}

	/**
	 * The button: inserts the waiting entries under the pinned one, scrolls to the
	 * first and focuses it, then restores the tab title. Above the cap, reloads the page instead.
	 */
	showPending(): void {
		if ( ! this.queue.size ) {
			return;
		}
		track( 'new-updates-click' );
		if ( isCapped( this.queue.size ) ) {
			this.reloadCaughtUp( state.rev );
			return;
		}
		const inserted = flushQueue( this.list, this.queue );
		const first = inserted[ 0 ];
		this.updatePending();
		this.inserted( inserted );
		first.setAttribute( 'tabindex', '-1' );
		first.scrollIntoView( { block: 'start', behavior: this.reducedMotion() ? 'auto' : 'smooth' } );
		first.focus( { preventScroll: true } );
	}

	/**
	 * Whether the reader prefers reduced motion.
	 *
	 * @return Boolean.
	 */
	private reducedMotion(): boolean {
		return window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ?? false;
	}

	/**
	 * Whether the layout is the mobile one: collapsible sections start closed there.
	 *
	 * @return Boolean.
	 */
	private isMobile(): boolean {
		return window.matchMedia?.( '(max-width: 781px)' ).matches ?? false;
	}

	/**
	 * Opens or closes the collapsible sections of an element: the reader's choice when they
	 * made one, else closed on mobile and open on desktop. The server renders them open, so they
	 * stay readable without JavaScript and pages stay cacheable.
	 *
	 * @param scope Element holding collapsible sections.
	 */
	private applyCollapsed( scope: HTMLElement ): void {
		const mobile = this.isMobile();
		scope.querySelectorAll< HTMLButtonElement >( '.newspack-live__expander' ).forEach( button => {
			const panel = document.getElementById( button.getAttribute( 'aria-controls' ) ?? '' );
			// A hidden title: questions are closed, the form stays hidden (syncQuestions()).
			if ( ! panel || button.closest< HTMLElement >( 'h2' )?.hidden ) {
				return;
			}
			const collapsed = this.collapsed.get( panel.id ) ?? mobile;
			button.setAttribute( 'aria-expanded', String( ! collapsed ) );
			panel.hidden = collapsed;
		} );
	}

	/**
	 * Delegated clicks: share, copy link, toggles, load older.
	 *
	 * @param event Click.
	 */
	private onClick( event: MouseEvent ): void {
		const target = ( event.target as HTMLElement ).closest< HTMLElement >( '[data-action]' );
		if ( ! target || ! this.root.contains( target ) ) {
			return;
		}
		switch ( target.dataset.action ) {
			case 'share':
				event.preventDefault();
				if ( typeof window.navigator.share === 'function' ) {
					// The Web Share sheet when the browser has one.
					this.share( target );
				} else {
					// Opened here rather than natively, so the popover is placed before it's painted.
					const details = target.closest< HTMLDetailsElement >( 'details' );
					if ( details ) {
						details.open = ! details.open;
						if ( details.open ) {
							this.placePopover( details );
						}
					}
				}
				break;
			case 'copy-link':
				event.preventDefault();
				this.copy( target );
				break;
			case 'toggle': {
				const panel = document.getElementById( target.getAttribute( 'aria-controls' ) ?? '' );
				const expanded = target.getAttribute( 'aria-expanded' ) === 'true';
				target.setAttribute( 'aria-expanded', String( ! expanded ) );
				if ( panel ) {
					panel.hidden = expanded;
					this.collapsed.set( panel.id, expanded );
				}
				break;
			}
			case 'load-older':
				event.preventDefault();
				this.loadOlder( target );
				break;
		}
	}

	/**
	 * Share through the Web Share API (secure contexts only).
	 *
	 * @param summary Share disclosure summary.
	 */
	private share( summary: HTMLElement ): void {
		const url = summary.dataset.url ?? window.location.href;
		const title = summary.dataset.title ?? document.title;
		const entryId = Number( summary.closest< HTMLElement >( '.newspack-live-entry' )?.dataset.entryId ?? 0 ) || undefined;
		track( 'entry-share', entryId );
		window.navigator.share?.( { url, title } ).catch( () => undefined );
	}

	/**
	 * A share disclosure opened: close the others, reveal "Copy link" (inserted entries), clear the
	 * status, and count the share.
	 *
	 * @param event Toggle event.
	 */
	private onShareToggle( event: Event ): void {
		const details = event.target as HTMLDetailsElement;
		if ( ! details.matches?.( 'details.newspack-live-share' ) || ! details.open ) {
			return;
		}
		this.closeShares( details );
		details.querySelector< HTMLElement >( '[data-action="copy-link"]' )?.removeAttribute( 'hidden' );
		this.placePopover( details );
		const status = details.querySelector< HTMLElement >( '.newspack-live-share__status' );
		if ( status ) {
			status.textContent = '';
		}
		const entryId = Number( details.closest< HTMLElement >( '.newspack-live-entry' )?.dataset.entryId ?? 0 ) || undefined;
		track( 'entry-share', entryId );
	}

	/**
	 * Re-places the open share popover after a resize (once per frame).
	 */
	private onResize(): void {
		if ( this.resizeFrame ) {
			return;
		}
		this.resizeFrame = window.requestAnimationFrame( () => {
			this.resizeFrame = 0;
			const details = this.root.querySelector< HTMLDetailsElement >( 'details.newspack-live-share[open]' );
			if ( details ) {
				this.placePopover( details );
			}
		} );
	}

	/**
	 * Keeps an open share popover on screen: aligns it to the other edge of its button when it
	 * would overflow the viewport.
	 *
	 * @param details Open share disclosure.
	 */
	private placePopover( details: HTMLDetailsElement ): void {
		const popover = details.querySelector< HTMLElement >( '.newspack-live-share__popover' );
		if ( ! popover ) {
			return;
		}
		details.classList.remove( 'newspack-live-share--flip' );
		const box = popover.getBoundingClientRect();
		if ( box.right > document.documentElement.clientWidth || box.left < 0 ) {
			details.classList.add( 'newspack-live-share--flip' );
		}
	}

	/**
	 * Closes the open share disclosures that don't contain a node.
	 *
	 * @param keep Node whose disclosure stays open (a click target, or the disclosure just opened).
	 */
	private closeShares( keep: Node | null ): void {
		this.root.querySelectorAll< HTMLDetailsElement >( 'details.newspack-live-share[open]' ).forEach( details => {
			if ( ! keep || ! details.contains( keep ) ) {
				details.open = false;
			}
		} );
	}

	/**
	 * Escape closes the open share disclosure and returns focus to its summary.
	 *
	 * @param event Key event.
	 */
	private onKeydown( event: KeyboardEvent ): void {
		if ( event.key !== 'Escape' ) {
			return;
		}
		const details = this.root.querySelector< HTMLDetailsElement >( 'details.newspack-live-share[open]' );
		if ( details ) {
			details.open = false;
			details.querySelector< HTMLElement >( 'summary' )?.focus();
		}
	}

	/**
	 * Copies a link with the clipboard API or the fallback.
	 *
	 * @param button Copy button.
	 */
	private async copy( button: HTMLElement ): Promise< void > {
		const done = await copyText( button.dataset.url ?? window.location.href, button );
		const status = button.closest( '.newspack-live-share__popover' )?.querySelector< HTMLElement >( '.newspack-live-share__status' );
		showCopyFeedback( button, status ?? null, done, state.strings );
	}

	/**
	 * Appends the next page of older entries.
	 *
	 * @param link "Load older updates" link (its href is the no-JS fallback).
	 */
	private async loadOlder( link: HTMLElement ): Promise< void > {
		const entries = this.list.querySelectorAll< HTMLElement >( ':scope > .newspack-live-entry' );
		const last = entries[ entries.length - 1 ];
		if ( ! last ) {
			return;
		}
		if ( this.busy ) {
			// A poll is running: try again once it's done.
			window.setTimeout( () => this.loadOlder( link ), 300 );
			return;
		}
		// Exclusive with polling, so a delta can't interleave with the page being appended.
		this.busy = true;
		link.setAttribute( 'aria-busy', 'true' );
		const added: HTMLElement[] = [];
		const cursor = Number( last.dataset.entryId );
		try {
			const page = await this.get< {
				entries: DeltaEntry[];
				has_more: boolean;
				assets: Asset[];
			} >( `/lives/${ state.liveId }/entries?before=${ cursor }&per_page=${ state.perPage }&rev=${ state.rev }` );
			await loadAssets( page.assets );
			page.entries.forEach( entry => {
				// Queued entries are newer than any older page.
				if ( this.removed.has( entry.id ) || this.queue.has( entry.id ) || this.root.querySelector( `#live-entry-${ entry.id }` ) ) {
					return;
				}
				parseHtml( entry.html ).forEach( element => {
					activateScripts( element );
					this.list.append( element );
					added.push( element );
				} );
			} );
			track( 'load-older' );
			this.inserted( added );
			if ( ! page.has_more ) {
				const done = document.createElement( 'p' );
				done.className = 'newspack-live__start';
				done.textContent = state.strings.startReached;
				link.replaceWith( done );
			}
		} catch {
			// Keep the link: its href still works as the no-JS fallback.
			window.location.href = ( link as HTMLAnchorElement ).href;
		} finally {
			link.removeAttribute( 'aria-busy' );
			this.busy = false;
		}
	}

	/**
	 * Re-initializes known embed widgets after insertion.
	 *
	 * @param elements Inserted or replaced entries.
	 */
	private reinitEmbeds( elements: HTMLElement[] ): void {
		const w = window as unknown as { twttr?: { widgets?: { load?: ( el?: Element ) => void } }; instgrm?: { Embeds?: { process?: () => void } } };
		// Third-party widget code must never break the polling loop.
		try {
			// X widgets: only in the inserted entries, each once.
			elements.forEach( element => w.twttr?.widgets?.load?.( element ) );
			w.instgrm?.Embeds?.process?.();
		} catch {
			// Ignore.
		}
	}
}
