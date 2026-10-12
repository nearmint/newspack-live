/**
 * Inline asset data for inserted entries and share popovers on
 * narrow screens. Plural forms are covered by PHPUnit and Jest.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';

const PROBE = '<!-- wp:newspack-live-e2e/inline-probe /-->';

/**
 * Asserts that the open share popover is inside the viewport.
 *
 * @param page  Page.
 * @param width Viewport width.
 */
async function expectPopoverOnScreen( page: Page, width: number ): Promise< void > {
	const box = await page.locator( 'details.newspack-live-share[open] .newspack-live-share__popover' ).boundingBox();
	expect( box && box.x >= 0 && box.x + box.width <= width ).toBe( true );
}

test.describe( 'inline assets and share popovers', () => {
	test.describe.configure( { timeout: 180_000 } );
	test.afterAll( () => cleanE2E() );

	test( 'an entry needing inline script data works on page load and when inserted live', async ( { page } ) => {
		const loaded = createLive( { title: 'E2E inline data on load' } );
		addEntry( loaded, { title: 'Probe on load', content: PROBE } );
		await page.goto( permalink( loaded ) );
		await expect( page.locator( '.nl-inline-probe' ) ).toHaveAttribute( 'data-ready', 'ok' );

		const live = createLive( { title: 'E2E inline data live' } );
		addEntry( live, { title: 'Before the probe', time: '-5 minutes' } );
		await page.goto( permalink( live ) );
		await expect( page.locator( '#nl-e2e-probe-js-after' ) ).toHaveCount( 0 );
		addEntry( live, { title: 'Probe inserted live', content: PROBE } );
		// Inserted with the "new updates" button.
		await page.getByRole( 'button', { name: '1 new update' } ).click( { timeout: 90_000 } );
		await expect( page.locator( '.nl-inline-probe' ) ).toHaveAttribute( 'data-ready', 'ok' );
		await expect( page.locator( '#nl-e2e-probe-js-extra' ) ).toHaveCount( 1 );
	} );

	test( 'share popovers stay on screen at 390 px without JavaScript', async ( { browser } ) => {
		const live = createLive( { title: 'E2E popover no JS' } );
		addEntry( live, { title: 'Narrow' } );
		const context = await browser.newContext( { javaScriptEnabled: false, viewport: { width: 390, height: 844 } } );
		const page = await context.newPage();
		await page.goto( permalink( live ) );
		for ( const summary of [ page.locator( '.newspack-live__header summary' ), page.locator( '.newspack-live-entry summary' ).first() ] ) {
			await summary.click();
			await expectPopoverOnScreen( page, 390 );
			await summary.click();
		}
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBeLessThanOrEqual( 390 );
		await context.close();
	} );

	test( 'an open share popover stays on screen after a resize', async ( { page } ) => {
		const live = createLive( { title: 'E2E popover resize' } );
		addEntry( live, { title: 'Resize' } );
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( permalink( live ) );
		await page.locator( '.newspack-live__header summary' ).click();
		await expectPopoverOnScreen( page, 1280 );
		for ( const width of [ 700, 390 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await expect
				.poll( async () => {
					const box = await page.locator( 'details.newspack-live-share[open] .newspack-live-share__popover' ).boundingBox();
					return !! box && box.x >= 0 && box.x + box.width <= width;
				} )
				.toBe( true );
		}
	} );
} );
