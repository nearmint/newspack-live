/**
 * Console composer.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { addImage, composer, feedItems, openConsole, siteTimeInput, typeBody } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { addEntry, cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

const entryIds = async ( page: import( '@playwright/test' ).Page ) =>
	page
		.locator( '.newspack-live__entries > .newspack-live-entry' )
		.evaluateAll( els => els.map( el => Number( ( el as HTMLElement ).dataset.entryId ) ) );

test.describe( 'console compose', () => {
	test.use( { storageState: storageStatePath( 'editor' ) } );
	test.afterAll( () => cleanE2E() );

	test( 'publishing title + paragraph + image shows it in the console and on the page', async ( { page } ) => {
		const live = createLive( { title: 'E2E compose' } );
		await openConsole( page, live );
		await composer( page ).getByLabel( 'Title (optional)' ).fill( 'Polls are open' );
		await typeBody( page, 'Voters are lining up downtown.' );
		await addImage( page );
		await composer( page ).getByRole( 'button', { name: 'Publish' } ).click();
		await expect( feedItems( page ).first() ).toContainText( 'Polls are open' );

		await page.goto( permalink( live ) );
		const entry = page.locator( '.newspack-live-entry' ).first();
		await expect( entry.getByRole( 'heading', { name: 'Polls are open' } ) ).toBeVisible();
		await expect( entry ).toContainText( 'Voters are lining up downtown.' );
		await expect( entry.locator( 'img' ) ).toBeVisible();
	} );

	test( 'an entry backdated to 30 minutes ago takes its chronological place', async ( { page } ) => {
		const live = createLive( { title: 'E2E backdate' } );
		const older = addEntry( live, { title: 'Forty minutes ago', time: '-40 minutes' } );
		const newer = addEntry( live, { title: 'Twenty minutes ago', time: '-20 minutes' } );
		await openConsole( page, live );
		await composer( page ).getByLabel( 'Title (optional)' ).fill( 'Thirty minutes ago' );
		await composer( page ).getByRole( 'button', { name: 'Change time' } ).click();
		await composer( page )
			.getByLabel( 'Time', { exact: true } )
			.fill( siteTimeInput( -30 * 60 ) );
		await composer( page ).getByRole( 'button', { name: 'Publish' } ).click();
		await expect( feedItems( page ) ).toHaveCount( 3 );

		await page.goto( permalink( live ) );
		const ids = await entryIds( page );
		expect( ids[ 0 ] ).toBe( newer );
		expect( ids[ 2 ] ).toBe( older );
		await expect( page.locator( `#live-entry-${ ids[ 1 ] }` ) ).toContainText( 'Thirty minutes ago' );
	} );

	test( 'editing more than 5 minutes after first publish shows "Updated"', async ( { page } ) => {
		const live = createLive( { title: 'E2E edit' } );
		const entry = addEntry( live, { title: 'Original title', content: 'Body.' } );
		setMeta( entry, '_newspack_live_published_at', '-10 minutes', true );
		const timeBefore = wp( 'post', 'get', String( entry ), '--field=post_date_gmt' );
		await openConsole( page, live );
		await feedItems( page ).first().getByRole( 'button', { name: 'Edit' } ).click();
		await expect( composer( page ).getByLabel( 'Title (optional)' ) ).toHaveValue( 'Original title' );
		await composer( page ).getByLabel( 'Title (optional)' ).fill( 'Corrected title' );
		await composer( page ).getByRole( 'button', { name: 'Update' } ).click();
		await expect( feedItems( page ).first() ).toContainText( 'Corrected title' );

		await page.goto( permalink( live ) );
		await expect( page.locator( `#live-entry-${ entry } .newspack-live-entry__updated` ) ).toHaveText( /^\s*Updated \S/ );
		// Editing doesn't move the entry: its time is kept to the second.
		expect( wp( 'post', 'get', String( entry ), '--field=post_date_gmt' ) ).toBe( timeBefore );
	} );

	test( 'unpublishing removes the entry from the page', async ( { page } ) => {
		const live = createLive( { title: 'E2E unpublish' } );
		const entry = addEntry( live, { title: 'Mistake' } );
		await openConsole( page, live );
		await feedItems( page ).first().getByRole( 'button', { name: 'Unpublish' } ).click();
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toContainText( 'Unpublish this entry? It will disappear from the live.' );
		await dialog.getByRole( 'button', { name: 'Unpublish' } ).click();
		await expect( feedItems( page ) ).toHaveCount( 0 );

		await page.goto( permalink( live ) );
		await expect( page.locator( `#live-entry-${ entry }` ) ).toHaveCount( 0 );
		expect( wp( 'post', 'get', String( entry ), '--field=post_status' ) ).toBe( 'draft' );
	} );

	test( 'Cmd/Ctrl+Enter publishes and compose_ms is recorded', async ( { page } ) => {
		const live = createLive( { title: 'E2E shortcut' } );
		await openConsole( page, live );
		await composer( page ).getByLabel( 'Title (optional)' ).fill( 'Published from the keyboard' );
		await typeBody( page, 'Fast.' );
		await page.keyboard.press( 'ControlOrMeta+Enter' );
		await expect( feedItems( page ).first() ).toContainText( 'Published from the keyboard' );
		const id = wp( 'post', 'list', '--post_type=newspack_live_entry', `--post_parent=${ live }`, '--field=ID', '--posts_per_page=1' );
		expect( Number( wp( 'post', 'meta', 'get', id, '_newspack_live_compose_ms' ) ) ).toBeGreaterThan( 0 );
	} );

	test( 'pinning an entry unpins the previous one; the page shows the pinned entry first', async ( { page } ) => {
		const live = createLive( { title: 'E2E pin' } );
		const first = addEntry( live, { title: 'First pin', time: '-30 minutes', pinned: true } );
		const second = addEntry( live, { title: 'Second pin', time: '-20 minutes' } );
		addEntry( live, { title: 'Latest', time: '-1 minutes' } );
		await openConsole( page, live );
		await feedItems( page ).filter( { hasText: 'Second pin' } ).getByRole( 'button', { name: 'Pin', exact: true } ).click();
		await expect( feedItems( page ).filter( { hasText: 'Second pin' } ).getByRole( 'button', { name: 'Unpin' } ) ).toBeVisible();
		await expect( feedItems( page ).filter( { hasText: 'First pin' } ).getByRole( 'button', { name: 'Pin', exact: true } ) ).toBeVisible();
		expect( wp( 'post', 'meta', 'get', String( first ), '_newspack_live_pinned' ) ).toBe( '0' );

		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live__pinned .newspack-live-entry' ) ).toHaveAttribute( 'id', `live-entry-${ second }` );
		expect( ( await entryIds( page ) ).includes( first ) ).toBe( true );
	} );
} );
