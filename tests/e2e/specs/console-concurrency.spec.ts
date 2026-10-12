/**
 * Concurrent publishing (browser part; the 10 × 10 load run is bin/load-concurrent-publish.mjs).
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { composer, feedItems, openConsole, typeBody } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { cleanE2E, createLive } from '../utils/live';
import { wp } from '../utils/wp-cli';

test.describe( 'console concurrency', () => {
	test.afterAll( () => cleanE2E() );

	test( 'two editors publishing at the same moment both succeed', async ( { browser } ) => {
		const live = createLive( { title: 'E2E concurrency' } );
		const contexts = await Promise.all( [
			browser.newContext( { storageState: storageStatePath( 'editor' ) } ),
			browser.newContext( { storageState: storageStatePath( 'admin' ) } ),
		] );
		const pages = await Promise.all( contexts.map( context => context.newPage() ) );
		await Promise.all( pages.map( page => openConsole( page, live ) ) );
		for ( const [ index, page ] of pages.entries() ) {
			await composer( page )
				.getByLabel( 'Title (optional)' )
				.fill( `Simultaneous ${ index + 1 }` );
			await typeBody( page, `Written by editor ${ index + 1 }.` );
		}
		await Promise.all( pages.map( page => composer( page ).getByRole( 'button', { name: 'Publish' } ).click() ) );
		for ( const page of pages ) {
			await expect( composer( page ).getByLabel( 'Title (optional)' ) ).toHaveValue( '' );
			await expect( page.locator( '.components-notice.is-error' ) ).toHaveCount( 0 );
		}
		// Each console sees both entries after its next refresh (15 s).
		await expect( feedItems( pages[ 0 ] ) ).toHaveCount( 2, { timeout: 30_000 } );

		const revs = wp( 'post', 'list', '--post_type=newspack_live_entry', `--post_parent=${ live }`, '--field=ID' )
			.split( /\s+/ )
			.map( id => wp( 'post', 'meta', 'get', id, '_newspack_live_rev' ) );
		expect( revs ).toHaveLength( 2 );
		expect( new Set( revs ).size ).toBe( 2 );
		await Promise.all( contexts.map( context => context.close() ) );
	} );
} );
