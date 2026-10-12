/**
 * Console collaboration: who's online and paging.
 */

/**
 * External dependencies
 */
import { test, expect, type Locator, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { cleanE2E, createLive, setMeta } from '../utils/live';
import { openConsole } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { wp } from '../utils/wp-cli';

const userId = ( login: string ) => Number( wp( 'user', 'get', login, '--field=ID' ) );
const displayName = ( login: string ) => wp( 'user', 'get', login, '--field=display_name' );

/**
 * Creates many marked entries, drafts and questions in one WP-CLI call.
 *
 * @param liveId Live ID.
 * @param author Entry author.
 */
function bulk( liveId: number, author: number ): void {
	wp(
		'eval',
		`$live = ${ liveId }; $author = ${ author };
		$mark = static fn( $id ) => is_int( $id ) ? update_post_meta( $id, '_newspack_live_e2e', 1 ) : null;
		for ( $i = 1; $i <= 150; $i++ ) {
			$mark( \\Newspack_Live\\Entries::save( [ 'live_id' => $live, 'title' => "E2E update $i", 'author' => $author, 'time' => time() - 3000 + $i * 10 ] ) );
		}
		for ( $i = 1; $i <= 60; $i++ ) {
			$mark( \\Newspack_Live\\Entries::save( [ 'live_id' => $live, 'title' => "E2E draft $i", 'author' => $author, 'status' => 'draft' ] ) );
		}
		for ( $i = 1; $i <= 60; $i++ ) {
			$mark( \\Newspack_Live\\Questions::create( $live, 'Ask ' . md5( "a$i" ) . ' ' . md5( "b$i" ), "Reader $i" ) );
		}`
	);
}

/**
 * Clicks "Load more" under a list and checks that focus moved to the first new item.
 *
 * @param page  Page.
 * @param list  The list.
 * @param count Items expected after loading.
 */
async function loadMore( page: Page, list: Locator, count: number ): Promise< void > {
	const before = await list.locator( ':scope > li' ).count();
	await page.getByRole( 'button', { name: 'Load more' } ).click();
	await expect( list.locator( ':scope > li' ) ).toHaveCount( count );
	await expect( list.locator( ':scope > li' ).nth( before ) ).toBeFocused();
}

test.describe( 'console collaboration', () => {
	test.afterAll( () => cleanE2E() );

	test( 'team members see each other; a member who leaves drops off; others never appear', async ( { browser } ) => {
		test.setTimeout( 180_000 );
		const live = createLive( { title: 'E2E presence' } );
		setMeta( live, '_newspack_live_team', `[${ userId( 'nl-e2e-author' ) }]` );
		const editorName = displayName( 'nl-e2e-editor' );
		const authorName = displayName( 'nl-e2e-author' );

		const editor = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const author = await browser.newContext( { storageState: storageStatePath( 'author' ) } );
		const editorPage = await editor.newPage();
		const authorPage = await author.newPage();
		await openConsole( editorPage, live );
		await openConsole( authorPage, live );

		const list = ( page: Page ) => page.getByRole( 'list', { name: 'On this live:' } );
		await expect( list( editorPage ).getByText( authorName, { exact: true } ) ).toBeVisible( { timeout: 30_000 } );
		await expect( list( authorPage ).getByText( editorName, { exact: true } ) ).toBeVisible( { timeout: 30_000 } );
		await expect( list( editorPage ).getByText( `${ editorName } (you)` ) ).toBeVisible();

		const results = await new AxeBuilder( { page: editorPage } ).include( '.newspack-live-console__status' ).analyze();
		expect( results.violations.filter( violation => [ 'serious', 'critical' ].includes( violation.impact ?? '' ) ) ).toEqual( [] );

		// Leaving the console (navigating away or closing it) leaves at once: gone at the editor's
		// next heartbeat (15 s). Playwright's close() doesn't fire `pagehide`, so navigate away.
		await authorPage.goto( 'about:blank' );
		await expect( list( editorPage ).getByText( authorName, { exact: true } ) ).toHaveCount( 0, { timeout: 30_000 } );

		// A contributor off the team can't open the console, so never appears.
		const contributor = await browser.newContext( { storageState: storageStatePath( 'contributor' ) } );
		const contributorPage = await contributor.newPage();
		const response = await contributorPage.goto( `/wp-admin/admin.php?page=newspack-live-console&live=${ live }` );
		expect( response?.status() ).toBe( 403 );
		await editorPage.reload();
		await expect( list( editorPage ).getByText( displayName( 'nl-e2e-contributor' ), { exact: true } ) ).toHaveCount( 0 );
		await Promise.all( [ editor.close(), author.close(), contributor.close() ] );
	} );

	test( 'Feed, Drafts and Questions show 50 items, then "Load more" with focus on the first new one', async ( { browser } ) => {
		test.setTimeout( 180_000 );
		const live = createLive( { title: 'E2E console paging' } );
		bulk( live, userId( 'nl-e2e-editor' ) );
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		await openConsole( page, live );

		const feed = page.getByRole( 'list', { name: 'Published entries' } );
		await expect( feed.locator( ':scope > li' ) ).toHaveCount( 50 );
		await expect( feed.locator( ':scope > li' ).first() ).toContainText( 'E2E update 150' );
		await loadMore( page, feed, 100 );
		await loadMore( page, feed, 150 );
		await expect( feed.locator( ':scope > li' ).last() ).toContainText( 'E2E update 1' );
		await expect( page.getByRole( 'button', { name: 'Load more' } ) ).toHaveCount( 0 );

		await page.getByRole( 'tab', { name: 'Drafts (60)' } ).click();
		const drafts = page.getByRole( 'list', { name: 'Drafts' } );
		await expect( drafts.locator( ':scope > li' ) ).toHaveCount( 50 );
		await loadMore( page, drafts, 60 );
		// The next refresh (15 s) keeps the older drafts.
		await page.waitForResponse( response => response.url().includes( '/console' ) && response.url().includes( 'drafts_until' ), {
			timeout: 30_000,
		} );
		await expect( drafts.locator( ':scope > li' ) ).toHaveCount( 60 );

		await page.getByRole( 'tab', { name: /Questions/ } ).click();
		const questions = page.getByRole( 'list', { name: 'Questions' } );
		await expect( questions.locator( ':scope > li' ) ).toHaveCount( 50 );
		await loadMore( page, questions, 60 );

		const results = await new AxeBuilder( { page } ).include( '.newspack-live-console' ).analyze();
		const serious = results.violations.filter( violation => [ 'serious', 'critical' ].includes( violation.impact ?? '' ) );
		// Core's RichText (`role="document"` with aria attributes) is excepted, as in accessibility.spec.ts.
		const ours = serious.flatMap( violation =>
			violation.nodes
				.filter( node => ! ( violation.id === 'aria-allowed-attr' && node.html.includes( 'role="document"' ) ) )
				.map( node => `${ violation.id }: ${ node.target.join( ' ' ) }` )
		);
		expect( ours ).toEqual( [] );
		await context.close();
	} );

	test( 'Drafts shows "Load more" only when another draft exists', async ( { browser } ) => {
		const live = createLive( { title: 'E2E drafts paging' } );
		const author = userId( 'nl-e2e-editor' );
		// 50 drafts: exactly one page.
		wp(
			'eval',
			`$live = ${ live }; $author = ${ author };
			for ( $i = 1; $i <= 50; $i++ ) {
				$id = \\Newspack_Live\\Entries::save( [ 'live_id' => $live, 'title' => "E2E draft $i", 'author' => $author, 'status' => 'draft' ] );
				update_post_meta( $id, '_newspack_live_e2e', 1 );
			}`
		);
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		await openConsole( page, live );
		await page.getByRole( 'tab', { name: 'Drafts (50)' } ).click();
		const drafts = page.getByRole( 'list', { name: 'Drafts' } );
		await expect( drafts.locator( ':scope > li' ) ).toHaveCount( 50 );
		await expect( page.getByRole( 'button', { name: 'Load more' } ) ).toHaveCount( 0 );
		await context.close();
	} );
} );
