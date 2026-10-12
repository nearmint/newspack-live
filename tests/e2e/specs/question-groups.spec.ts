/**
 * Similar-question groups.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { composer, openConsole } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { addQuestion, cleanE2E, createLive, permalink } from '../utils/live';
import { wp } from '../utils/wp-cli';

test.describe( 'question groups', () => {
	test.describe.configure( { timeout: 240_000 } );
	test.beforeEach( () => {
		wp( 'newspack-live', 'reset-rate-limits' );
	} );
	test.afterAll( () => cleanE2E() );

	test( 'similar questions are grouped and answered together', async ( { browser, page } ) => {
		const live = createLive( { title: 'E2E groups' } );
		addQuestion( live, 'When will the final results be certified?', 'Ana' );
		addQuestion( live, 'When are the results certified?', 'Ben' );
		addQuestion( live, 'When will the final results be certified by the county?', 'Cleo' );
		addQuestion( live, 'Who is running for mayor?', 'Dan' );

		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const editor = await context.newPage();
		await openConsole( editor, live );
		await editor.getByRole( 'tab', { name: /Questions \(4 new\)/ } ).click();
		const group = editor.locator( '.newspack-live-question', { has: editor.locator( '.newspack-live-question__similar' ) } );
		await expect( group ).toHaveCount( 1 );
		await expect( group.locator( '.newspack-live-question__similar-title' ) ).toHaveText( '2 similar questions' );
		await group.getByRole( 'checkbox' ).first().check();
		await editor.getByRole( 'button', { name: 'Answer selected' } ).click();
		await expect( composer( editor ).locator( '.newspack-live-composer__question' ) ).toHaveCount( 3 );
		await composer( editor ).getByRole( 'button', { name: 'Publish' } ).click();
		await expect( editor.getByRole( 'tab', { name: /Questions \(1 new\)/ } ) ).toBeVisible();
		await context.close();

		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live-entry--qa .newspack-live-qa__item' ) ).toHaveCount( 3 );
	} );
} );
