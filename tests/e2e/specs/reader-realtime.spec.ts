/**
 * Reader real-time updates. Bounds: 90 s per change.
 */

/**
 * External dependencies
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';

const REALTIME = 90_000;

let editor: APIRequestContext;

test.describe( 'reader real-time', () => {
	test.describe.configure( { timeout: 180_000 } );

	test.beforeAll( async () => {
		editor = await apiContext( 'editor' );
	} );

	test.afterAll( async () => {
		await editor.dispose();
		cleanE2E();
	} );

	const publish = async ( liveId: number, title: string ) => {
		const response = await editor.post( `${ API }/lives/${ liveId }/entries`, {
			data: { title, content: `<!-- wp:paragraph --><p>${ title }.</p><!-- /wp:paragraph -->` },
		} );
		expect( response.status() ).toBe( 201 );
		return ( await response.json() ).entry.id as number;
	};

	const entry = ( page: Page, text: string ) => page.locator( '.newspack-live-entry', { hasText: text } );

	test( 'a reader gets a new entry within 90 s without reloading, through the button', async ( { page } ) => {
		const live = createLive( { title: 'E2E realtime' } );
		addEntry( live, { title: 'Before' } );
		await page.goto( permalink( live ) );
		await publish( live, 'Arrived without reload' );
		const button = page.getByRole( 'button', { name: '1 new update' } );
		await expect( button ).toBeVisible( { timeout: REALTIME } );
		await button.click();
		await expect( entry( page, 'Arrived without reload' ) ).toBeVisible();
	} );

	test( 'new entries never move the page; the button inserts them and focuses the first', async ( { page } ) => {
		const live = createLive( { title: 'E2E new updates button' } );
		for ( let i = 1; i <= 12; i++ ) {
			addEntry( live, {
				title: `Existing ${ i }`,
				content: 'A long enough paragraph to take some vertical space in the feed.',
				time: `-${ 60 - i } minutes`,
			} );
		}
		await page.setViewportSize( { width: 1280, height: 700 } );
		await page.goto( permalink( live ) );

		const anchor = entry( page, 'Existing 4' );
		await anchor.scrollIntoViewIfNeeded();
		await page.evaluate( () => window.scrollBy( 0, 150 ) );
		const before = ( await anchor.boundingBox() )?.y ?? 0;
		await publish( live, 'Queued one' );
		const button = page.getByRole( 'button', { name: /new update/ } );
		await expect( button ).toBeVisible( { timeout: REALTIME } );
		await expect( button ).toHaveText( '↑ 1 new update' );
		expect( Math.abs( ( ( await anchor.boundingBox() )?.y ?? 0 ) - before ) ).toBeLessThanOrEqual( 2 );
		await expect( entry( page, 'Queued one' ) ).toHaveCount( 0 );

		await button.click();
		await expect( entry( page, 'Queued one' ) ).toBeVisible();
		await expect( button ).toBeHidden();
		// eslint-disable-next-line @wordpress/no-global-active-element -- page context.
		const focused = await page.evaluate( () => document.activeElement?.id );
		expect( focused ).toBe( await entry( page, 'Queued one' ).getAttribute( 'id' ) );
		await expect( entry( page, 'Queued one' ) ).toBeInViewport();
	} );

	test( 'edits update in place and unpublished entries disappear', async ( { page } ) => {
		const live = createLive( { title: 'E2E edits' } );
		const edited = addEntry( live, { title: 'Original wording', time: '-10 minutes' } );
		const removed = addEntry( live, { title: 'To be removed', time: '-5 minutes' } );
		await page.goto( permalink( live ) );
		expect( ( await editor.put( `${ API }/entries/${ edited }`, { data: { title: 'Corrected wording' } } ) ).status() ).toBe( 200 );
		expect( ( await editor.post( `${ API }/entries/${ removed }/status`, { data: { status: 'draft' } } ) ).status() ).toBe( 200 );
		await expect( page.locator( `#live-entry-${ edited }` ) ).toContainText( 'Corrected wording', { timeout: REALTIME } );
		await expect( page.locator( `#live-entry-${ removed }` ) ).toHaveCount( 0, { timeout: REALTIME } );
	} );

	test( 'pin changes reach the page', async ( { page } ) => {
		const live = createLive( { title: 'E2E flags' } );
		const first = addEntry( live, { title: 'Flag me', time: '-10 minutes' } );
		addEntry( live, { title: 'Newer', time: '-5 minutes' } );
		await page.goto( permalink( live ) );
		await editor.post( `${ API }/entries/${ first }/flags`, { data: { pinned: true } } );
		await expect( page.locator( `.newspack-live__pinned #live-entry-${ first }` ) ).toBeVisible( { timeout: REALTIME } );
		await expect( page.locator( `#live-entry-${ first } .newspack-live-entry__pinned` ) ).toHaveText( 'Pinned' );
	} );

	test( 'ending the live switches the badge, hides the form and stops polling', async ( { page } ) => {
		const live = createLive( { title: 'E2E ending' } );
		await page.goto( permalink( live ) );
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
		expect( ( await editor.post( `${ API }/lives/${ live }/state`, { data: { action: 'end' } } ) ).status() ).toBe( 200 );
		await expect( page.locator( '.newspack-live-badge' ) ).toHaveText( 'Ended', { timeout: REALTIME } );
		await expect( page.getByLabel( 'Your question' ) ).toBeHidden();
		await expect( page.getByText( 'Questions are closed.' ) ).toBeVisible();

		const requests: string[] = [];
		page.on( 'request', request => {
			if ( request.url().includes( `/lives/${ live }/status` ) ) {
				requests.push( request.url() );
			}
		} );
		// Observing that nothing happens needs real time: one minute without any /status request.
		await page.waitForTimeout( 60_000 );
		expect( requests ).toEqual( [] );
	} );
} );
