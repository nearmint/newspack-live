/**
 * Delta application on the live feed. Plain DOM, no directives.
 */

export interface DeltaEntry {
	id: number;
	rev: number;
	time: number;
	html: string;
	pinned: boolean;
}

export interface FeedElements {
	list: HTMLElement;
	pinned: HTMLElement;
}

export interface ApplyOptions {
	/**
	 * Whether new entries go straight to the top of the feed: only to catch up with entries the
	 * reader already asked for. Otherwise they always wait in the queue, behind the button.
	 */
	autoInsert: boolean;
	/** Whether every entry past the end of the list is loaded (no "Load older updates" link). */
	complete?: boolean;
	/** New entries waiting behind the "new updates" button, by ID. Updated in place. */
	queue: Map< number, HTMLElement >;
}

export interface ApplyResult {
	/** Entries inserted at the top of the feed, newest first. */
	inserted: HTMLElement[];
	/** Number of entries newly added to the queue. */
	queued: number;
}

/**
 * Parses server HTML into elements with DOMParser (scripts parsed this way never run).
 *
 * @param html Same-origin HTML from the REST API.
 * @return Elements.
 */
export function parseHtml( html: string ): HTMLElement[] {
	const doc = new DOMParser().parseFromString( `<!doctype html><body>${ html }</body>`, 'text/html' );
	return Array.from( doc.body.children ).map( node => document.importNode( node, true ) as HTMLElement );
}

/**
 * Re-creates `<script>` elements of inserted HTML once, so they run (oEmbed widgets).
 *
 * @param root Element about to be inserted.
 */
export function activateScripts( root: HTMLElement ): void {
	root.querySelectorAll( 'script' ).forEach( original => {
		const script = document.createElement( 'script' );
		Array.from( original.attributes ).forEach( attribute => script.setAttribute( attribute.name, attribute.value ) );
		script.text = original.text;
		original.replaceWith( script );
	} );
}

/**
 * Selectors finding the counterpart of a focused element in replacement HTML, most specific first.
 *
 * @param element Focused element.
 * @return Selectors.
 */
function focusSelectors( element: Element ): string[] {
	const tag = element.tagName.toLowerCase();
	const selectors = element.id ? [ `#${ CSS.escape( element.id ) }` ] : [];
	for ( const attribute of [ 'aria-controls', 'href', 'name', 'data-action' ] ) {
		const value = element.getAttribute( attribute );
		if ( value ) {
			selectors.push( `${ tag }[${ attribute }="${ CSS.escape( value ) }"]` );
		}
	}
	if ( element.classList[ 0 ] ) {
		selectors.push( `${ tag }.${ CSS.escape( element.classList[ 0 ] ) }` );
	}
	return selectors;
}

/**
 * Replaces an element, keeping keyboard focus on the matching control of the new element when
 * the old one contained it (focus never moves on its own). When nothing matches, the
 * new element itself takes focus so it isn't lost to the page.
 *
 * @param current Element being replaced.
 * @param next    Replacement (not yet in the document).
 * @param swap    Performs the DOM change (and makes the new controls focusable, e.g. un-hides them).
 */
export function withFocusKept( current: Element, next: HTMLElement, swap: () => void ): void {
	const active = current.ownerDocument.activeElement;
	if ( ! active || active === current.ownerDocument.body || ! current.contains( active ) ) {
		swap();
		return;
	}
	const selectors = active === current ? [] : focusSelectors( active );
	// Inside a disclosure (closed again in the new version): its summary, found by position.
	const disclosure = active.tagName === 'SUMMARY' ? null : active.closest( 'details' );
	const disclosureIndex =
		disclosure && current.contains( disclosure ) ? Array.from( current.querySelectorAll( 'details' ) ).indexOf( disclosure ) : -1;
	const parent = current.parentElement;
	swap();
	// Content of a closed disclosure can't take focus (its own summary can).
	const usable = ( element: HTMLElement | null | undefined ): boolean => {
		if ( ! element || ! element.isConnected || element.closest( '[hidden]' ) ) {
			return false;
		}
		const closed = element.closest( 'details:not([open])' );
		return ! closed || ( element.parentElement === closed && element.tagName === 'SUMMARY' );
	};
	const candidates = selectors.map( selector => next.querySelector< HTMLElement >( selector ) );
	if ( disclosureIndex >= 0 ) {
		candidates.push( next.querySelectorAll( 'details' )[ disclosureIndex ]?.querySelector< HTMLElement >( ':scope > summary' ) ?? null );
	}
	const target = candidates.find( element => usable( element ) );
	if ( target ) {
		target.focus( { preventScroll: true } );
		return;
	}
	// The new element itself, or (when it is hidden or wasn't inserted) the closest visible container.
	let fallback: HTMLElement | null = usable( next ) ? next : parent;
	while ( fallback && ! usable( fallback ) ) {
		fallback = fallback.parentElement;
	}
	if ( ! fallback ) {
		return;
	}
	if ( ! fallback.hasAttribute( 'tabindex' ) ) {
		fallback.setAttribute( 'tabindex', '-1' );
	}
	fallback.focus( { preventScroll: true } );
}

/**
 * Removes an entry; when it holds keyboard focus, focus moves to the nearest remaining entry (the
 * next one, else the previous one), so it isn't lost to the page.
 *
 * @param element Entry.
 */
export function removeKeepingFocus( element: HTMLElement ): void {
	const active = element.ownerDocument.activeElement;
	if ( ! active || ! element.contains( active ) ) {
		element.remove();
		return;
	}
	const sibling = ( from: Element | null, next: boolean ): HTMLElement | null => {
		let node = from;
		while ( node && ! node.matches( '.newspack-live-entry' ) ) {
			node = next ? node.nextElementSibling : node.previousElementSibling;
		}
		return node as HTMLElement | null;
	};
	const nearest = sibling( element.nextElementSibling, true ) ?? sibling( element.previousElementSibling, false );
	const parent = element.parentElement;
	element.remove();
	const target = nearest ?? parent?.closest< HTMLElement >( '.newspack-live__feed, .newspack-live' ) ?? null;
	if ( target ) {
		if ( ! target.hasAttribute( 'tabindex' ) ) {
			target.setAttribute( 'tabindex', '-1' );
		}
		target.focus( { preventScroll: true } );
	}
}

/**
 * Moves an element, keeping focus on it (moving a focused node makes the browser drop focus).
 *
 * @param element Element.
 * @param move    Performs the move.
 */
function moveKeepingFocus( element: HTMLElement, move: () => void ): void {
	const active = element.ownerDocument.activeElement as HTMLElement | null;
	const hadFocus = !! active && element.contains( active );
	move();
	if ( hadFocus && active.isConnected ) {
		active.focus( { preventScroll: true } );
	}
}

const timeOf = ( element: Element ): number => Number( ( element as HTMLElement ).dataset.time ?? 0 );
const idOf = ( element: Element ): number => Number( ( element as HTMLElement ).dataset.entryId ?? 0 );
const revOf = ( element: Element ): number => Number( ( element as HTMLElement ).dataset.rev ?? 0 );

/**
 * Whether `a` sorts before `b` in the newest-first feed (time desc, ID desc).
 *
 * @param a Entry.
 * @param b Entry.
 * @return True when `a` comes first.
 */
export const newerThan = ( a: Element, b: Element ): boolean => timeOf( a ) > timeOf( b ) || ( timeOf( a ) === timeOf( b ) && idOf( a ) > idOf( b ) );

/**
 * Runs DOM changes without moving what the reader is looking at: the first entry (at least
 * partially) visible keeps its position on screen.
 *
 * @param container Element containing the entries.
 * @param skip      IDs that may be moved or removed by the change (not usable as anchors).
 * @param change    DOM change.
 */
export function keepReadingPosition( container: HTMLElement, skip: Set< number >, change: () => void ): void {
	const anchor = Array.from( container.querySelectorAll< HTMLElement >( '.newspack-live-entry' ) ).find(
		element => ! skip.has( idOf( element ) ) && element.getBoundingClientRect().bottom > 0
	);
	const before = anchor?.getBoundingClientRect().top ?? 0;
	change();
	if ( anchor && anchor.isConnected ) {
		const delta = anchor.getBoundingClientRect().top - before;
		if ( delta !== 0 ) {
			window.scrollBy( 0, delta );
		}
	}
}

/**
 * Inserts an entry in the feed list at its chronological position.
 *
 * @param list     Feed list.
 * @param element  Entry.
 * @param complete Whether every entry past the end of the list is loaded.
 * @return Whether it was inserted (entries past the loaded page are skipped unless complete).
 */
function insertChronologically( list: HTMLElement, element: HTMLElement, complete: boolean ): boolean {
	const next = Array.from( list.querySelectorAll( ':scope > .newspack-live-entry' ) ).find( other => newerThan( element, other ) );
	if ( next ) {
		list.insertBefore( element, next );
		return true;
	}
	if ( complete ) {
		list.append( element );
		return true;
	}
	return false;
}

/**
 * Places the pinned entry (or none) in the pinned container.
 *
 * @param elements Feed elements.
 * @param pinnedId Pinned entry ID (0 for none).
 * @param complete Whether every older entry is loaded.
 */
function syncPinned( elements: FeedElements, pinnedId: number, complete: boolean ): void {
	const current = elements.pinned.querySelector< HTMLElement >( '.newspack-live-entry' );
	if ( current && idOf( current ) !== pinnedId ) {
		current.classList.remove( 'newspack-live-entry--pinned' );
		moveKeepingFocus( current, () => {
			current.remove();
			insertChronologically( elements.list, current, complete );
		} );
	}
	if ( pinnedId && ! elements.pinned.querySelector( `#live-entry-${ pinnedId }` ) ) {
		const inFeed = elements.list.querySelector< HTMLElement >( `#live-entry-${ pinnedId }` );
		if ( inFeed ) {
			moveKeepingFocus( inFeed, () => elements.pinned.appendChild( inFeed ) );
		}
	}
	// The "Pinned" pill is part of the entry's own markup.
	elements.pinned.hidden = ! elements.pinned.querySelector( '.newspack-live-entry' );
}

/**
 * Applies a delta: removals, replacements (only by newer revisions, so deltas are
 * idempotent), backdated insertions, pin moves, then new entries at the top or in the queue.
 *
 * @param elements        Feed elements.
 * @param delta           Delta.
 * @param delta.entries   Changed entries.
 * @param delta.removed   Removed IDs.
 * @param delta.pinned_id Pinned ID.
 * @param options         Options.
 * @return Inserted entries and number of newly queued ones.
 */
export function applyDelta(
	elements: FeedElements,
	delta: { entries: DeltaEntry[]; removed: number[]; pinned_id: number },
	options: ApplyOptions
): ApplyResult {
	const complete = !! options.complete;
	const queue = options.queue;
	const container = elements.list.parentElement ?? document.body;
	const changedIds = new Set( [ ...delta.removed, ...delta.entries.map( entry => entry.id ), delta.pinned_id ] );
	const fresh: HTMLElement[] = [];
	let queued = 0;

	// Newest displayed entry before this delta: anything newer is new; anything older is backdated.
	const newest = elements.list.querySelector< HTMLElement >( ':scope > .newspack-live-entry' );

	keepReadingPosition( container, changedIds, () => {
		delta.removed.forEach( id => {
			const element = container.querySelector< HTMLElement >( `#live-entry-${ id }` );
			if ( element ) {
				removeKeepingFocus( element );
			}
			queue.delete( id );
		} );

		delta.entries.forEach( entry => {
			const [ element ] = parseHtml( entry.html );
			if ( ! element ) {
				return;
			}
			// Waiting in the queue: keep the latest version there.
			const waiting = queue.get( entry.id );
			if ( waiting ) {
				if ( revOf( waiting ) >= entry.rev ) {
					return;
				}
				activateScripts( element );
				if ( entry.pinned && entry.id === delta.pinned_id ) {
					// Pinned while waiting: it goes to the pinned container now.
					queue.delete( entry.id );
					elements.pinned.appendChild( element );
				} else if ( newest && ! newerThan( element, newest ) ) {
					// Backdated while waiting: no longer new, it takes its place in the feed.
					queue.delete( entry.id );
					insertChronologically( elements.list, element, complete );
				} else {
					queue.set( entry.id, element );
				}
				return;
			}
			const existing = container.querySelector< HTMLElement >( `#live-entry-${ entry.id }` );
			if ( existing ) {
				if ( revOf( existing ) >= entry.rev ) {
					return;
				}
				activateScripts( element );
				if ( timeOf( existing ) === entry.time ) {
					withFocusKept( existing, element, () => existing.replaceWith( element ) );
					return;
				}
				// The time changed: move it to its new position.
				const inPinned = elements.pinned.contains( existing );
				withFocusKept( existing, element, () => {
					existing.remove();
					if ( inPinned ) {
						elements.pinned.appendChild( element );
					} else {
						insertChronologically( elements.list, element, complete );
					}
				} );
				return;
			}
			activateScripts( element );
			if ( entry.pinned && entry.id === delta.pinned_id ) {
				elements.pinned.appendChild( element );
				return;
			}
			if ( ! newest || newerThan( element, newest ) ) {
				fresh.push( element );
				return;
			}
			insertChronologically( elements.list, element, complete );
		} );

		syncPinned( elements, delta.pinned_id, complete );
	} );

	if ( options.autoInsert ) {
		// Catching up: insert everything new, including what was waiting in the queue.
		const all = [ ...queue.values(), ...fresh ].sort( ( a, b ) => ( newerThan( a, b ) ? -1 : 1 ) );
		queue.clear();
		insertTop( elements.list, all );
		return { inserted: all, queued: 0 };
	}
	fresh.forEach( element => {
		if ( ! queue.has( idOf( element ) ) ) {
			queue.set( idOf( element ), element );
			queued++;
		}
	} );
	return { inserted: [], queued };
}

/**
 * Inserts entries at the top of the feed, newest first, with the highlight.
 *
 * @param list     Feed list.
 * @param elements Entries, newest first.
 */
function insertTop( list: HTMLElement, elements: HTMLElement[] ): void {
	elements
		.slice()
		.reverse()
		.forEach( element => {
			if ( ! list.querySelector( `#${ element.id }` ) ) {
				element.classList.add( 'newspack-live-entry--new' );
				list.prepend( element );
			}
		} );
}

/**
 * Inserts the queued entries at the top of the feed (the "new updates" button).
 *
 * @param list  Feed list.
 * @param queue Queue (emptied).
 * @return Inserted entries, newest first.
 */
export function flushQueue( list: HTMLElement, queue: Map< number, HTMLElement > ): HTMLElement[] {
	const all = [ ...queue.values() ].sort( ( a, b ) => ( newerThan( a, b ) ? -1 : 1 ) );
	queue.clear();
	insertTop( list, all );
	return all;
}

/**
 * Replaces a server-rendered section (the Context card) by its new HTML.
 *
 * @param current Current element.
 * @param html    New HTML (may be a hidden empty section).
 * @param prepare Runs after the swap, before focus is restored (e.g. initializes toggles).
 * @return The new element, or the current one when the HTML is empty.
 */
export function replaceSection( current: HTMLElement | null, html: string, prepare?: ( next: HTMLElement ) => void ): HTMLElement | null {
	if ( ! current ) {
		return null;
	}
	const [ next ] = parseHtml( html );
	if ( ! next ) {
		current.hidden = true;
		return current;
	}
	const container = current.closest< HTMLElement >( '.newspack-live' ) ?? document.body;
	keepReadingPosition( container, new Set(), () =>
		withFocusKept( current, next, () => {
			current.replaceWith( next );
			prepare?.( next );
		} )
	);
	return next;
}
