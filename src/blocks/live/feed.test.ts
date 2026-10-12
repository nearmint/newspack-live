/**
 * Internal dependencies
 */
import { activateScripts, applyDelta, flushQueue, parseHtml, replaceSection, type DeltaEntry, type FeedElements } from './feed';

const article = ( id: number, time: number, rev = 1, extra = '' ) =>
	`<article class="newspack-live-entry" id="live-entry-${ id }" data-entry-id="${ id }" data-rev="${ rev }" data-time="${ time }">${ extra }Entry ${ id } r${ rev }</article>`;

const entry = ( id: number, time: number, rev = 1, pinned = false, extra = '' ): DeltaEntry => ( {
	id,
	time,
	rev,
	pinned,
	html: article( id, time, rev, extra ),
} );

/**
 * Builds a feed fixture with entries (newest first).
 *
 * @param ids   [ id, time ] pairs, newest first.
 * @param pinId Pinned entry ID in the pinned container.
 * @return Elements.
 */
function setup( ids: Array< [ number, number ] >, pinId = 0 ): FeedElements {
	document.body.innerHTML = `<section><div class="newspack-live__pinned"${ pinId ? '' : ' hidden' }>${ pinId ? article( pinId, 1 ) : '' }</div><div class="newspack-live__entries">${ ids
		.map( ( [ id, time ] ) => article( id, time ) )
		.join( '' ) }</div></section>`;
	return {
		list: document.querySelector( '.newspack-live__entries' ) as HTMLElement,
		pinned: document.querySelector( '.newspack-live__pinned' ) as HTMLElement,
	};
}

const order = ( elements: FeedElements ) => Array.from( elements.list.children ).map( el => Number( ( el as HTMLElement ).dataset.entryId ) );

describe( 'applyDelta', () => {
	let queue: Map< number, HTMLElement >;
	beforeEach( () => {
		queue = new Map();
	} );

	it( 'inserts new entries at the top when catching up', () => {
		const elements = setup( [
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		const result = applyDelta(
			elements,
			{ entries: [ entry( 3, 300 ), entry( 4, 400 ) ], removed: [], pinned_id: 0 },
			{ autoInsert: true, queue }
		);
		expect( order( elements ) ).toEqual( [ 4, 3, 2, 1 ] );
		expect( result.inserted ).toHaveLength( 2 );
		expect( elements.list.firstElementChild?.classList.contains( 'newspack-live-entry--new' ) ).toBe( true );
	} );

	it( 'queues new entries when auto-insert is off', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		const result = applyDelta( elements, { entries: [ entry( 2, 200 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( order( elements ) ).toEqual( [ 1 ] );
		expect( result.queued ).toBe( 1 );
		expect( [ ...queue.keys() ] ).toEqual( [ 2 ] );
		flushQueue( elements.list, queue );
		expect( order( elements ) ).toEqual( [ 2, 1 ] );
		expect( queue.size ).toBe( 0 );
	} );

	it( 'updates and removes queued entries without duplicating them', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		applyDelta( elements, { entries: [ entry( 2, 200 ), entry( 3, 300 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		const again = applyDelta( elements, { entries: [ entry( 2, 200, 4 ) ], removed: [ 3 ], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( again.queued ).toBe( 0 );
		expect( [ ...queue.keys() ] ).toEqual( [ 2 ] );
		expect( queue.get( 2 )?.textContent ).toContain( 'r4' );
		flushQueue( elements.list, queue );
		expect( order( elements ) ).toEqual( [ 2, 1 ] );
		expect( elements.list.textContent ).toContain( 'r4' );
	} );

	it( 'merges the queue when the reader is back at the top', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		applyDelta( elements, { entries: [ entry( 2, 200 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		const result = applyDelta( elements, { entries: [ entry( 3, 300 ) ], removed: [], pinned_id: 0 }, { autoInsert: true, queue } );
		expect( result.inserted.map( el => el.id ) ).toEqual( [ 'live-entry-3', 'live-entry-2' ] );
		expect( order( elements ) ).toEqual( [ 3, 2, 1 ] );
		expect( queue.size ).toBe( 0 );
	} );

	it( 'inserts backdated entries at their chronological position and skips older-than-loaded ones', () => {
		const elements = setup( [
			[ 3, 300 ],
			[ 1, 100 ],
		] );
		const result = applyDelta(
			elements,
			{ entries: [ entry( 2, 200 ), entry( 9, 50 ) ], removed: [], pinned_id: 0 },
			{ autoInsert: true, queue }
		);
		expect( order( elements ) ).toEqual( [ 3, 2, 1 ] );
		expect( result.inserted ).toEqual( [] );
		expect( result.queued ).toBe( 0 );
	} );

	it( 'replaces an entry only when the incoming revision is newer', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		applyDelta( elements, { entries: [ entry( 1, 100, 5 ) ], removed: [], pinned_id: 0 }, { autoInsert: true, queue } );
		expect( elements.list.textContent ).toContain( 'r5' );
		applyDelta( elements, { entries: [ entry( 1, 100, 3 ) ], removed: [], pinned_id: 0 }, { autoInsert: true, queue } );
		expect( elements.list.textContent ).toContain( 'r5' );
		expect( order( elements ) ).toEqual( [ 1 ] );
	} );

	it( 'moves an entry whose time changed', () => {
		const elements = setup( [
			[ 3, 300 ],
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		applyDelta( elements, { entries: [ entry( 3, 150, 2 ) ], removed: [], pinned_id: 0 }, { autoInsert: true, queue } );
		expect( order( elements ) ).toEqual( [ 2, 3, 1 ] );
	} );

	it( 'removes entries', () => {
		const elements = setup( [
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		applyDelta( elements, { entries: [], removed: [ 2 ], pinned_id: 0 }, { autoInsert: true, queue } );
		expect( order( elements ) ).toEqual( [ 1 ] );
	} );

	it( 'moves entries into and out of the pinned container', () => {
		const elements = setup(
			[
				[ 3, 300 ],
				[ 2, 200 ],
			],
			1
		);
		applyDelta(
			elements,
			{ entries: [ entry( 2, 200, 2, true ), entry( 1, 100, 2, false ) ], removed: [], pinned_id: 2 },
			{ autoInsert: true, complete: true, queue }
		);
		expect( elements.pinned.querySelector( '#live-entry-2' ) ).not.toBeNull();
		expect( elements.pinned.hidden ).toBe( false );
		expect( elements.pinned.hidden ).toBe( false );
		expect( order( elements ) ).toEqual( [ 3, 1 ] );

		applyDelta( elements, { entries: [ entry( 2, 200, 3, false ) ], removed: [], pinned_id: 0 }, { autoInsert: true, complete: true, queue } );
		expect( elements.pinned.querySelector( '.newspack-live-entry' ) ).toBeNull();
		expect( elements.pinned.hidden ).toBe( true );
		expect( order( elements ) ).toEqual( [ 3, 2, 1 ] );
	} );
} );

describe( 'scripts in inserted HTML', () => {
	it( 'parses without running scripts, then re-creates them once', () => {
		const [ element ] = parseHtml( '<div><script>window.__ran = ( window.__ran || 0 ) + 1;</script></div>' );
		document.body.append( element );
		expect( ( window as unknown as { __ran?: number } ).__ran ).toBeUndefined();
		activateScripts( element );
		expect( element.querySelectorAll( 'script' ) ).toHaveLength( 1 );
		expect( element.querySelector( 'script' )?.text ).toContain( '__ran' );
	} );
} );

/**
 * Focused element of the test document.
 *
 * @return Element.
 */
const focused = (): Element | null => document.body.ownerDocument.activeElement;

describe( 'focus', () => {
	it( 'stays on the same control when an entry is replaced by a newer revision', () => {
		const share = '<button type="button" class="newspack-live-entry__share">Share</button>';
		const elements = setup( [
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		elements.list.querySelector( '#live-entry-2' )?.insertAdjacentHTML( 'afterbegin', share );
		( elements.list.querySelector( '#live-entry-2 button' ) as HTMLElement ).focus();
		applyDelta(
			elements,
			{ entries: [ entry( 2, 200, 2, false, share ) ], removed: [], pinned_id: 0 },
			{ autoInsert: true, complete: true, queue: new Map() }
		);
		expect( focused()?.closest( 'article' )?.getAttribute( 'data-rev' ) ).toBe( '2' );
		expect( focused()?.className ).toBe( 'newspack-live-entry__share' );
	} );

	it( 'stays on a toggle when its section is replaced', () => {
		const section = ( count: number ) =>
			`<section class="newspack-live newspack-live__context" id="km"><button type="button" aria-controls="km-panel" aria-expanded="false">Context (${ count })</button><div id="km-panel" hidden></div></section>`;
		document.body.innerHTML = section( 1 );
		( document.querySelector( 'button' ) as HTMLElement ).focus();
		const next = replaceSection( document.getElementById( 'km' ), section( 2 ) );
		expect( focused()?.textContent ).toBe( 'Context (2)' );
		expect( next?.contains( focused() ) ).toBe( true );
	} );

	it( 'falls back to the new element when the focused control is gone', () => {
		document.body.innerHTML = '<section class="newspack-live" id="s"><a href="#a">A</a></section>';
		( document.querySelector( 'a' ) as HTMLElement ).focus();
		const next = replaceSection( document.getElementById( 's' ), '<section id="s"><p>No links</p></section>' );
		expect( focused() ).toBe( next );
	} );

	it( 'leaves focus alone when it is elsewhere', () => {
		document.body.innerHTML = '<button id="out">Out</button><section class="newspack-live" id="s"><a href="#a">A</a></section>';
		( document.getElementById( 'out' ) as HTMLElement ).focus();
		replaceSection( document.getElementById( 's' ), '<section id="s"><a href="#a">A</a></section>' );
		expect( focused()?.id ).toBe( 'out' );
	} );

	it( 'stays on a pinned entry when it moves into the pinned container', () => {
		const share = '<button type="button" class="newspack-live-entry__share">Share</button>';
		const elements = setup( [
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		const element = elements.list.querySelector( '#live-entry-1' ) as HTMLElement;
		element.insertAdjacentHTML( 'afterbegin', share );
		( element.querySelector( 'button' ) as HTMLElement ).focus();
		applyDelta(
			elements,
			{ entries: [ entry( 1, 100, 2, true, share ) ], removed: [], pinned_id: 1 },
			{ autoInsert: true, complete: true, queue: new Map() }
		);
		expect( focused()?.closest( '.newspack-live__pinned' ) ).not.toBeNull();
		expect( focused()?.className ).toBe( 'newspack-live-entry__share' );
	} );

	it( 'falls back to the closest visible container when the replacement is hidden', () => {
		document.body.innerHTML = '<div class="newspack-live" id="root"><section id="s"><a href="#a">A</a></section></div>';
		( document.querySelector( 'a' ) as HTMLElement ).focus();
		replaceSection( document.getElementById( 's' ), '<section id="s" hidden></section>' );
		expect( focused()?.id ).toBe( 'root' );
	} );

	it( 'moves to the nearest remaining entry when the focused entry is removed', () => {
		const share = '<button type="button" class="newspack-live-entry__share">Share</button>';
		const elements = setup( [
			[ 3, 300 ],
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		elements.list.querySelector( '#live-entry-2' )?.insertAdjacentHTML( 'afterbegin', share );
		( elements.list.querySelector( '#live-entry-2 button' ) as HTMLElement ).focus();
		applyDelta( elements, { entries: [], removed: [ 2 ], pinned_id: 0 }, { autoInsert: true, complete: true, queue: new Map() } );
		expect( focused()?.id ).toBe( 'live-entry-1' );

		( elements.list.querySelector( '#live-entry-1' ) as HTMLElement ).focus();
		applyDelta( elements, { entries: [], removed: [ 1 ], pinned_id: 0 }, { autoInsert: true, complete: true, queue: new Map() } );
		expect( focused()?.id ).toBe( 'live-entry-3' );
	} );

	it( 'goes back to the share summary when focus was inside a disclosure the new version closes', () => {
		const share =
			'<details class="newspack-live-share"><summary data-action="share">Share</summary><div><a href="https://x.com/intent/post?url=a">X</a></div></details>';
		const elements = setup( [
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		const entryElement = elements.list.querySelector( '#live-entry-2' ) as HTMLElement;
		entryElement.insertAdjacentHTML( 'afterbegin', share.replace( '<details ', '<details open ' ) );
		( entryElement.querySelector( 'a' ) as HTMLElement ).focus();
		expect( focused()?.tagName ).toBe( 'A' );
		applyDelta(
			elements,
			{ entries: [ entry( 2, 200, 2, false, share ) ], removed: [], pinned_id: 0 },
			{ autoInsert: true, complete: true, queue: new Map() }
		);
		expect( focused()?.tagName ).toBe( 'SUMMARY' );
		expect( focused()?.closest( 'article' )?.getAttribute( 'data-rev' ) ).toBe( '2' );
	} );

	it( 'returns to the summary of the disclosure that held focus, not the first one', () => {
		const html = ( open: boolean ) =>
			`<details class="newspack-live-share"><summary data-action="share">Share</summary></details><details class="wp-block-details"${ open ? ' open' : '' }><summary>More</summary><a href="#more">Inside</a></details>`;
		const elements = setup( [
			[ 2, 200 ],
			[ 1, 100 ],
		] );
		const entryElement = elements.list.querySelector( '#live-entry-2' ) as HTMLElement;
		entryElement.insertAdjacentHTML( 'afterbegin', html( true ) );
		( entryElement.querySelector( 'a' ) as HTMLElement ).focus();
		applyDelta(
			elements,
			{ entries: [ entry( 2, 200, 2, false, html( false ) ) ], removed: [], pinned_id: 0 },
			{ autoInsert: true, complete: true, queue: new Map() }
		);
		expect( focused()?.textContent ).toBe( 'More' );
	} );
} );

describe( 'new entries wait behind the button', () => {
	let queue: Map< number, HTMLElement >;
	beforeEach( () => {
		queue = new Map();
	} );

	it( 'queues new entries even when the reader is at the top of the feed', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		// jsdom lays nothing out: the list top is at 0, in view.
		expect( elements.list.getBoundingClientRect().top ).toBe( 0 );
		const result = applyDelta( elements, { entries: [ entry( 2, 200 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( order( elements ) ).toEqual( [ 1 ] );
		expect( result ).toEqual( { inserted: [], queued: 1 } );
		expect( [ ...queue.keys() ] ).toEqual( [ 2 ] );
	} );

	it( 'drops a waiting entry deleted before the click', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		applyDelta( elements, { entries: [ entry( 2, 200 ), entry( 3, 300 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( queue.size ).toBe( 2 );
		applyDelta( elements, { entries: [], removed: [ 3 ], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( [ ...queue.keys() ] ).toEqual( [ 2 ] );
		expect( order( elements ) ).toEqual( [ 1 ] );
		flushQueue( elements.list, queue );
		expect( order( elements ) ).toEqual( [ 2, 1 ] );
	} );

	it( 'keeps the latest version of a waiting entry edited before the click', () => {
		const elements = setup( [ [ 1, 100 ] ] );
		applyDelta( elements, { entries: [ entry( 2, 200, 1 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		const result = applyDelta( elements, { entries: [ entry( 2, 200, 4 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( result.queued ).toBe( 0 );
		expect( queue.size ).toBe( 1 );
		expect( queue.get( 2 )?.textContent ).toBe( 'Entry 2 r4' );
		// An older revision arriving late never overwrites it.
		applyDelta( elements, { entries: [ entry( 2, 200, 3 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		expect( queue.get( 2 )?.textContent ).toBe( 'Entry 2 r4' );
		expect( order( elements ) ).toEqual( [ 1 ] );
	} );

	it( 'moves a waiting entry pinned before the click to the pinned container, out of the queue', () => {
		const elements = setup( [ [ 1, 100 ] ], 9 );
		applyDelta( elements, { entries: [ entry( 2, 200 ) ], removed: [], pinned_id: 9 }, { autoInsert: false, queue } );
		applyDelta( elements, { entries: [ entry( 2, 200, 2, true ) ], removed: [], pinned_id: 2 }, { autoInsert: false, queue } );
		expect( queue.size ).toBe( 0 );
		expect( elements.pinned.querySelector( '#live-entry-2' )?.textContent ).toBe( 'Entry 2 r2' );
		expect( elements.pinned.querySelector( '#live-entry-9' ) ).toBeNull();
		expect( order( elements ) ).toEqual( [ 1 ] );
	} );

	it( 'puts a waiting entry backdated before the click in its place in the feed, out of the queue', () => {
		const elements = setup( [
			[ 3, 300 ],
			[ 1, 100 ],
		] );
		applyDelta( elements, { entries: [ entry( 4, 400 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue } );
		applyDelta( elements, { entries: [ entry( 4, 200, 2 ) ], removed: [], pinned_id: 0 }, { autoInsert: false, queue, complete: true } );
		expect( queue.size ).toBe( 0 );
		expect( order( elements ) ).toEqual( [ 3, 4, 1 ] );
	} );
} );
