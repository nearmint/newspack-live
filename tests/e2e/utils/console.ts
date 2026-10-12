/**
 * Live console helpers.
 */

/**
 * External dependencies
 */
import path from 'path';
import { expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { PLUGIN_DIR } from './env';
import { wp } from './wp-cli';

/**
 * Opens the console of a live and waits for it to load.
 *
 * @param page   Page.
 * @param liveId Live ID.
 */
export async function openConsole( page: Page, liveId: number ): Promise< void > {
	await page.goto( `/wp-admin/admin.php?page=newspack-live-console&live=${ liveId }` );
	await expect( page.getByTestId( 'newspack-live-state' ) ).toBeVisible( { timeout: 30_000 } );
}

/**
 * The composer region.
 *
 * @param page Page.
 * @return Locator.
 */
export const composer = ( page: Page ) => page.getByRole( 'region', { name: /New entry|Edit entry/ } );

/**
 * Types a paragraph in the composer body.
 *
 * @param page Page.
 * @param text Text.
 */
export async function typeBody( page: Page, text: string ): Promise< void > {
	const body = composer( page ).getByRole( 'group', { name: 'Entry body' } );
	const appender = body.locator( '.block-editor-default-block-appender__content' );
	if ( await appender.count() ) {
		await appender.click();
	} else {
		await body.locator( '[contenteditable="true"]' ).last().click();
	}
	await page.keyboard.type( text );
}

/**
 * Adds an image block to the composer body and uploads the fixture photo.
 *
 * @param page Page.
 */
export async function addImage( page: Page ): Promise< void > {
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( '/image' );
	await page.getByRole( 'option', { name: 'Image', exact: true } ).click();
	const block = composer( page ).locator( '[data-type="core/image"]' );
	await block.locator( 'input[type="file"]' ).setInputFiles( path.join( PLUGIN_DIR, 'tests/e2e/fixtures/photo.jpg' ) );
	await expect( block.locator( 'img[src*="uploads"]' ) ).toBeVisible( { timeout: 30_000 } );
}

/**
 * The console feed list items.
 *
 * @param page Page.
 * @return Locator.
 */
export const feedItems = ( page: Page ) => page.locator( '.newspack-live-console-entries > li' );

/**
 * A datetime-local value in the site timezone, `offset` seconds from now.
 *
 * @param offset Seconds (negative for the past).
 * @return Value.
 */
export function siteTimeInput( offset: number ): string {
	return wp( 'eval', `echo wp_date( 'Y-m-d\\TH:i', time() + ${ Number( offset ) } );` );
}
