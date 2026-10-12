/**
 * Smoke tests (@smoke): home page and plugin state.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { wp } from '../utils/wp-cli';
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';
import { openConsole } from '../utils/console';
import { storageStatePath } from '../utils/env';

test.describe( 'smoke @smoke', () => {
	test( 'home page responds with 200', async ( { page } ) => {
		const response = await page.goto( '/' );
		expect( response?.status() ).toBe( 200 );
	} );

	test( 'plugin is active', async () => {
		expect( wp( 'plugin', 'is-active', 'newspack-live' ) ).toBe( '' );
		expect( wp( 'plugin', 'get', 'newspack-live', '--field=status' ) ).toBe( 'active' );
	} );

	test( 'a live page renders', async ( { page } ) => {
		const live = createLive( { title: 'E2E smoke live' } );
		addEntry( live, { title: 'Smoke entry' } );
		const response = await page.goto( permalink( live ) );
		expect( response?.status() ).toBe( 200 );
		await expect( page.locator( '.newspack-live' ) ).toBeVisible();
		await expect( page.locator( '.newspack-live-entry' ) ).toContainText( 'Smoke entry' );
		cleanE2E();
	} );

	test( 'the console loads', async ( { browser } ) => {
		const live = createLive( { title: 'E2E smoke console' } );
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		const errors: string[] = [];
		page.on( 'pageerror', error => errors.push( error.message ) );
		await openConsole( page, live );
		await expect( page.getByRole( 'region', { name: 'New entry' } ) ).toBeVisible();
		expect( errors ).toEqual( [] );
		await context.close();
		cleanE2E();
	} );

	test( 'the e2e constant is enabled during the run', async () => {
		expect( wp( 'config', 'get', 'NEWSPACK_LIVE_E2E' ) ).toBe( '1' );
	} );
} );
