/**
 * The "new updates" button above its cap reloads the page: when a page cache serves
 * HTML older than what the reader was told about, the page catches up without the button.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API } from '../utils/api';
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';

// A page served by `route.fulfill()` counts as public for Chrome's Private/Local Network Access
// checks, which then block every same-host script: turn them off for this simulation (this file only).
test.use( {
	launchOptions: {
		args: [
			'--disable-features=BlockInsecurePrivateNetworkRequests,PrivateNetworkAccessSendPreflights,PrivateNetworkAccessRespectPreflightResults,LocalNetworkAccessChecks',
		],
	},
} );

const button = ( page: Page ) => page.locator( 'button.newspack-live__new-updates' );

test.describe( 'new updates button: page cache', () => {
	test.describe.configure( { timeout: 120_000 } );
	test.afterAll( () => cleanE2E() );

	test( 'a reload served from an older page cache catches up without the button', async ( { page } ) => {
		const live = createLive( { title: 'E2E stale page cache' } );
		addEntry( live, { title: 'Existing 1', time: '-10 minutes' } );
		addEntry( live, { title: 'Existing 2', time: '-9 minutes' } );
		const url = permalink( live );
		// The page as a page cache would keep it, before the next entries.
		const stale = await ( await page.request.get( url ) ).text();
		const fresh = [ addEntry( live, { title: 'Missed one' } ), addEntry( live, { title: 'Missed two' } ) ];
		const status = await ( await page.request.get( `${ API }/lives/${ live }/status?nocache=${ Date.now() }` ) ).json();
		await page.route( url, route => route.fulfill( { body: stale, contentType: 'text/html; charset=UTF-8' } ) );
		// What the button stores before reloading.
		await page.addInitScript(
			( [ id, rev ] ) => window.sessionStorage.setItem( `newspack-live-catch-up-${ id }`, String( rev ) ),
			[ live, status.rev ]
		);
		await page.goto( url );
		for ( const id of fresh ) {
			await expect( page.locator( `.newspack-live__entries > #live-entry-${ id }` ) ).toBeVisible( { timeout: 15_000 } );
		}
		await expect( button( page ) ).toBeHidden();
	} );
} );
