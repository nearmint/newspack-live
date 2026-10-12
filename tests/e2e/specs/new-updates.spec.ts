/**
 * The "new updates" button: new entries always wait behind it, whatever the scroll
 * position; the tab title counts them; above 20 the button reloads the page, which catches up
 * without the button even when the page HTML is older. Bounds: 90 s per change for an anonymous
 * reader (60 s `/status` cache + 30 s polling interval).
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';

const REALTIME = 90_000;

const button = ( page: Page ) => page.locator( 'button.newspack-live__new-updates' );
const listIds = ( page: Page ) =>
	page
		.locator( '.newspack-live__entries > .newspack-live-entry' )
		.evaluateAll( nodes => nodes.map( node => ( node as HTMLElement ).dataset.entryId ) );
const existing = ( live: number, count: number ) => {
	for ( let i = 1; i <= count; i++ ) {
		addEntry( live, {
			title: `Existing ${ i }`,
			content: 'A long enough paragraph to take some vertical space in the feed.',
			time: `-${ 120 - i } minutes`,
		} );
	}
};

test.describe( 'new updates button', () => {
	test.describe.configure( { timeout: 240_000 } );
	test.afterAll( () => cleanE2E() );

	test( 'criterion 1: at the bottom of the feed, a published entry shows the button within 90 s; at the top it is never inserted', async ( {
		page,
	} ) => {
		const live = createLive( { title: 'E2E button delay' } );
		existing( live, 8 );
		await page.setViewportSize( { width: 1280, height: 700 } );
		// A reader who has been on the page for a while: two polls are done (the first at 2 s, the
		// next 30 s later) and the browser holds a cached `/status` (60 s), as on a real page.
		const polls = ( async () => {
			for ( let i = 0; i < 2; i++ ) {
				await page.waitForResponse( response => response.url().includes( `/lives/${ live }/status` ), { timeout: REALTIME } );
			}
		} )();
		await page.goto( permalink( live ) );
		await page.locator( '.newspack-live__start' ).scrollIntoViewIfNeeded();
		await polls;

		const published = Date.now();
		addEntry( live, { title: 'Arrived while reading' } );
		await expect( button( page ) ).toBeVisible( { timeout: REALTIME } );
		const delay = Math.round( ( Date.now() - published ) / 1000 );
		test.info().annotations.push( { type: 'button delay (s)', description: String( delay ) } );
		// eslint-disable-next-line no-console -- the measured delay, for reference.
		console.log( `[new updates] button visible ${ delay } s after publishing` );
		expect( delay ).toBeLessThanOrEqual( 90 );
		await expect( button( page ) ).toHaveText( '↑ 1 new update' );
		await expect( button( page ) ).toHaveAccessibleName( '1 new update' );
		await expect( page.locator( '.newspack-live-entry', { hasText: 'Arrived while reading' } ) ).toHaveCount( 0 );

		// At the top of the feed too, the entry waits behind the button.
		await page.evaluate( () => window.scrollTo( 0, 0 ) );
		addEntry( live, { title: 'Arrived at the top' } );
		await expect( button( page ) ).toHaveText( '↑ 2 new updates', { timeout: REALTIME } );
		await expect( page.locator( '.newspack-live-entry', { hasText: 'Arrived at the top' } ) ).toHaveCount( 0 );
	} );

	test( 'criteria 2 and 3: three entries read "3 new updates" and "(3)" in the tab; the click inserts them under the pinned entry, once, focused', async ( {
		page,
	} ) => {
		const live = createLive( { title: 'E2E three updates' } );
		existing( live, 6 );
		const pinned = addEntry( live, { title: 'Pinned note', pinned: true, time: '-200 minutes' } );
		await page.setViewportSize( { width: 1280, height: 700 } );
		await page.goto( permalink( live ) );
		const title = await page.title();
		await page.locator( '.newspack-live__start' ).scrollIntoViewIfNeeded();

		const fresh = [ 'Update A', 'Update B', 'Update C' ].map( ( name, i ) => addEntry( live, { title: name, time: `-${ 3 - i } minutes` } ) );
		await expect( button( page ) ).toHaveText( '↑ 3 new updates', { timeout: 2 * REALTIME } );
		await expect( page ).toHaveTitle( `(3) ${ title }` );
		await expect( page.locator( '.newspack-live__announcer' ) ).toHaveText( '3 new updates available' );
		await expect( page.locator( '.newspack-live__announcer' ) ).toHaveAttribute( 'aria-live', 'polite' );

		await button( page ).click();
		await expect( button( page ) ).toBeHidden();
		await expect( page ).toHaveTitle( title );
		await expect( page.locator( `.newspack-live__pinned #live-entry-${ pinned }` ) ).toBeVisible();
		const ids = await listIds( page );
		expect( ids.slice( 0, 3 ) ).toEqual( fresh.reverse().map( String ) );
		expect( new Set( ids ).size ).toBe( ids.length );
		expect( ids ).not.toContain( String( pinned ) );
		const first = page.locator( `#live-entry-${ fresh[ 0 ] }` );
		await expect( first ).toBeFocused();
		await expect( first ).toBeInViewport();
		expect( await first.evaluate( element => getComputedStyle( element ).outlineStyle ) ).toBe( 'solid' );
	} );

	test( 'criterion 4: an X embed in an inserted entry is initialized on that entry', async ( { page } ) => {
		const live = createLive( { title: 'E2E embed' } );
		existing( live, 2 );
		// Stub X's widget loader (no network in the sandbox) and record what it is asked to process.
		await page.addInitScript( () => {
			const calls: string[] = [];
			( window as unknown as { nlTwttrCalls: string[] } ).nlTwttrCalls = calls;
			( window as unknown as { twttr: unknown } ).twttr = { widgets: { load: ( element?: Element ) => calls.push( element?.id ?? '' ) } };
		} );
		await page.goto( permalink( live ) );
		// Published like the console does (REST, as an editor): the CLI stores content as text.
		const editor = await apiContext( 'editor' );
		const response = await editor.post( `${ API }/lives/${ live }/entries`, {
			data: {
				title: 'A post on X',
				content:
					'<!-- wp:html --><blockquote class="twitter-tweet"><p>Polls are closed.</p><a href="https://twitter.com/RivertonLedger/status/1">Link</a></blockquote><!-- /wp:html -->',
			},
		} );
		expect( response.status() ).toBe( 201 );
		const tweet = ( await response.json() ).entry.id as number;
		await editor.dispose();
		await expect( button( page ) ).toBeVisible( { timeout: REALTIME } );
		await button( page ).click();
		await expect( page.locator( `#live-entry-${ tweet } blockquote.twitter-tweet` ) ).toBeVisible();
		expect( await page.evaluate( () => ( window as unknown as { nlTwttrCalls: string[] } ).nlTwttrCalls ) ).toContain( `live-entry-${ tweet }` );
	} );

	test( 'criterion 5: "Load older updates" after an insertion brings no duplicate', async ( { page } ) => {
		const live = createLive( { title: 'E2E load older after insert' } );
		existing( live, 17 );
		await page.goto( permalink( live ) );
		const fresh = addEntry( live, { title: 'Newest' } );
		await expect( button( page ) ).toBeVisible( { timeout: REALTIME } );
		await button( page ).click();
		await expect( page.locator( `.newspack-live__entries > #live-entry-${ fresh }` ) ).toBeVisible();
		await page.getByRole( 'link', { name: 'Load older updates' } ).click();
		await expect( page.locator( '.newspack-live__start' ) ).toBeVisible();
		const ids = await listIds( page );
		expect( ids ).toHaveLength( 18 );
		expect( new Set( ids ).size ).toBe( 18 );
	} );

	test( 'criterion 7: 21 waiting entries read "20+ new updates" and the click reloads the page, caught up', async ( { page } ) => {
		const live = createLive( { title: 'E2E capped' } );
		existing( live, 2 );
		await page.goto( permalink( live ) );
		const title = await page.title();
		const fresh: number[] = [];
		for ( let i = 1; i <= 21; i++ ) {
			fresh.push( addEntry( live, { title: `Burst ${ i }`, time: `-${ 30 - i } seconds` } ) );
		}
		await expect( button( page ) ).toHaveText( '↑ 20+ new updates', { timeout: 2 * REALTIME } );
		await expect( page ).toHaveTitle( `(20+) ${ title }` );
		await expect( page.locator( '.newspack-live__announcer' ) ).toHaveText( '20+ new updates available' );

		await Promise.all( [ page.waitForEvent( 'load' ), button( page ).click() ] );
		await expect( page.locator( '.newspack-live__entries > .newspack-live-entry' ).first() ).toHaveAttribute(
			'id',
			`live-entry-${ fresh[ fresh.length - 1 ] }`
		);
		await expect( button( page ) ).toBeHidden();
		await expect( page ).toHaveTitle( title );
	} );

	test( 'criterion 8: the click scrolls smoothly, and without animation when the reader prefers reduced motion', async ( { page } ) => {
		const live = createLive( { title: 'E2E reduced motion' } );
		existing( live, 8 );
		await page.addInitScript( () => {
			const calls: string[] = [];
			( window as unknown as { nlScrollCalls: string[] } ).nlScrollCalls = calls;
			const original = Element.prototype.scrollIntoView;
			Element.prototype.scrollIntoView = function ( this: Element, options?: boolean | ScrollIntoViewOptions ) {
				if ( this.classList.contains( 'newspack-live-entry' ) ) {
					calls.push( typeof options === 'object' ? String( options.behavior ) : String( options ) );
				}
				return original.call( this, options );
			};
		} );
		const scrolls = () => page.evaluate( () => ( window as unknown as { nlScrollCalls: string[] } ).nlScrollCalls );

		await page.goto( permalink( live ) );
		addEntry( live, { title: 'Smooth' } );
		await expect( button( page ) ).toBeVisible( { timeout: REALTIME } );
		await button( page ).click();
		expect( await scrolls() ).toEqual( [ 'smooth' ] );

		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.reload();
		addEntry( live, { title: 'Reduced' } );
		await expect( button( page ) ).toBeVisible( { timeout: REALTIME } );
		await button( page ).click();
		expect( await scrolls() ).toEqual( [ 'auto' ] );
	} );
} );
