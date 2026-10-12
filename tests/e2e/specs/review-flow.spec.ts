/**
 * Review flow.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { composer, feedItems, openConsole, typeBody } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

test.describe( 'review flow', () => {
	test.afterAll( () => cleanE2E() );

	test( 'a contributor submits for review, an editor publishes; off-team contributors get 403', async ( { browser } ) => {
		const live = createLive( { title: 'E2E review' } );
		const other = createLive( { title: 'E2E review, other team' } );
		setMeta( live, '_newspack_live_team', `[${ wp( 'user', 'get', 'nl-e2e-contributor', '--field=ID' ) }]` );

		const contributorContext = await browser.newContext( { storageState: storageStatePath( 'contributor' ) } );
		const contributor = await contributorContext.newPage();
		await openConsole( contributor, live );
		await expect( composer( contributor ).getByRole( 'button', { name: 'Publish' } ) ).toHaveCount( 0 );
		await composer( contributor ).getByLabel( 'Title (optional)' ).fill( 'From the field' );
		await typeBody( contributor, 'Long lines at the school gym.' );
		await composer( contributor ).getByRole( 'button', { name: 'Submit for review' } ).click();
		await contributor.getByRole( 'tab', { name: /Drafts \(1\)/ } ).click();
		await expect( feedItems( contributor ).first() ).toContainText( 'Pending review' );

		// Not on the team: the console refuses and the API returns 403.
		const response = await contributor.goto( `/wp-admin/admin.php?page=newspack-live-console&live=${ other }` );
		expect( response?.status() ).toBe( 403 );
		const api = await apiContext( 'contributor' );
		expect( ( await api.post( `${ API }/lives/${ other }/entries`, { data: { title: 'Nope', status: 'pending' } } ) ).status() ).toBe( 403 );
		await api.dispose();
		await contributorContext.close();

		const editorContext = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const editor = await editorContext.newPage();
		await openConsole( editor, live );
		await editor.getByRole( 'tab', { name: /Drafts \(1\)/ } ).click();
		const pending = feedItems( editor ).first();
		await expect( pending ).toContainText( 'Pending review' );
		await pending.getByRole( 'button', { name: 'Edit' } ).click();
		await expect( composer( editor ).getByLabel( 'Title (optional)' ) ).toHaveValue( 'From the field' );
		await composer( editor ).getByRole( 'button', { name: 'Publish' } ).click();
		await editor.getByRole( 'tab', { name: 'Feed' } ).click();
		await expect( feedItems( editor ).first() ).toContainText( 'From the field' );

		await editor.goto( permalink( live ) );
		await expect( editor.locator( '.newspack-live-entry' ).first() ).toContainText( 'Long lines at the school gym.' );
		await editorContext.close();
	} );
} );
