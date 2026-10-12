/**
 * Live menu, New live, Context card, entry time above the
 * card, time labels and "Link copied" feedback.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { storageStatePath, type Role } from '../utils/env';
import { addEntry, cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

const para = ( text: string ) => `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`;

/**
 * Names of the "Live" menu's submenu items for the current user.
 *
 * @param page Page on an admin screen.
 * @return Item names.
 */
async function liveSubmenu( page: Page ): Promise< string[] > {
	return page.locator( '#adminmenu li#toplevel_page_edit-newspack_live-1 .wp-submenu a' ).allInnerTexts();
}

/**
 * ID of the post open in the block editor.
 *
 * @param page Editor page.
 * @return Post ID.
 */
async function currentPostId( page: Page ): Promise< number > {
	const handle = await page.waitForFunction(
		() =>
			( window as unknown as { wp?: { data?: { select: ( s: string ) => { getCurrentPostId?: () => number } | undefined } } } ).wp?.data
				?.select( 'core/editor' )
				?.getCurrentPostId?.(),
		undefined,
		{ timeout: 30_000 }
	);
	return Number( await handle.jsonValue() );
}

test.describe( 'Live menu', () => {
	test.afterAll( () => cleanE2E() );

	const expected: Record< Exclude< Role, 'subscriber' >, string[] > = {
		admin: [ 'Lives', 'New live' ],
		editor: [ 'Lives', 'New live' ],
		author: [ 'Lives', 'New live' ],
		contributor: [ 'Lives' ],
	};

	for ( const [ role, items ] of Object.entries( expected ) as [ Role, string[] ][] ) {
		test( `${ role } sees the Live menu after Posts with ${ items.join( ', ' ) }`, async ( { browser } ) => {
			const context = await browser.newContext( { storageState: storageStatePath( role ) } );
			const page = await context.newPage();
			await page.goto( '/wp-admin/edit.php' );
			const top = page.locator( '#adminmenu > li#toplevel_page_edit-newspack_live-1' );
			await expect( top ).toHaveCount( 1 );
			await expect( top.locator( '.wp-menu-name' ) ).toHaveText( 'Live' );
			await expect( top.locator( '.wp-menu-image' ) ).toHaveClass( /dashicons-format-status/ );
			// Right after Posts.
			const previous = await top.evaluate( element => element.previousElementSibling?.id ?? '' );
			expect( previous ).toBe( 'menu-posts' );
			await expect( top.locator( 'a.menu-top' ) ).toHaveAttribute( 'href', 'edit.php?newspack_live=1' );
			// Core drops the submenu when "Lives" is its only item.
			expect( await liveSubmenu( page ) ).toEqual( items.length > 1 ? items : [] );
			// Not under Posts any more.
			await expect( page.locator( '#menu-posts .wp-submenu a', { hasText: /^(Lives|New live)$/ } ) ).toHaveCount( 0 );

			// Lives list: highlighted, "Add New" goes to New live (or is absent).
			await page.goto( '/wp-admin/edit.php?newspack_live=1' );
			await expect( top ).toHaveClass( items.length > 1 ? /wp-has-current-submenu/ : /\bcurrent\b/ );
			if ( items.length > 1 ) {
				await expect( page.locator( '#adminmenu li#toplevel_page_edit-newspack_live-1 .wp-submenu li.current a' ) ).toHaveText( 'Lives' );
			}
			await expect( page.locator( '#menu-posts.wp-has-current-submenu' ) ).toHaveCount( 0 );
			if ( items.includes( 'New live' ) ) {
				await expect( page.locator( '.page-title-action' ).first() ).toHaveAttribute( 'href', /post-new\.php\?newspack_live=1&_wpnonce=/ );
			}
			await context.close();
		} );
	}

	test( 'the subscriber has no Live menu', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: storageStatePath( 'subscriber' ) } );
		const page = await context.newPage();
		await page.goto( '/wp-admin/profile.php' );
		await expect( page.locator( '#adminmenu > li#toplevel_page_edit-newspack_live-1' ) ).toHaveCount( 0 );
		await context.close();
	} );

	test( 'New live opens the editor on a live, with the Live menu highlighted', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		await page.goto( '/wp-admin/edit.php?newspack_live=1' );
		const href = await page
			.locator( '#adminmenu li#toplevel_page_edit-newspack_live-1 .wp-submenu a', { hasText: 'New live' } )
			.getAttribute( 'href' );
		await page.goto( `/wp-admin/${ href }` );
		await expect( page ).toHaveURL( /post-new\.php\?newspack_live=1/ );
		await expect( page.locator( '#adminmenu li#toplevel_page_edit-newspack_live-1 .wp-submenu li.current a' ) ).toHaveText( 'New live' );
		const id = await currentPostId( page );
		expect( id ).toBeGreaterThan( 0 );
		expect( wp( 'post', 'meta', 'get', String( id ), '_newspack_live_enabled' ) ).toBe( '1' );
		expect( wp( 'post', 'get', String( id ), '--field=post_content' ) ).toBe( '' );

		// The editor of a live keeps the Live menu highlighted.
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		await expect( page.locator( '#adminmenu > li#toplevel_page_edit-newspack_live-1.wp-has-current-submenu' ) ).toHaveCount( 1 );
		await expect( page.locator( '#menu-posts.wp-has-current-submenu' ) ).toHaveCount( 0 );
		await context.close();
	} );

	test( 'New live without a valid nonce creates a regular post', async ( { browser } ) => {
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		await page.goto( '/wp-admin/post-new.php?newspack_live=1&_wpnonce=invalid' );
		const id = await currentPostId( page );
		expect( wp( 'post', 'meta', 'list', String( id ), '--keys=_newspack_live_enabled', '--format=count' ) ).toBe( '0' );
		await context.close();
	} );
} );

test.describe( 'reader layout', () => {
	test.afterAll( () => cleanE2E() );
	let live = 0;

	test.beforeAll( () => {
		live = createLive( { title: 'E2E live UI', context: '<p>Background on the race.</p>' } );
		addEntry( live, { title: 'Key result', time: '-30 minutes' } );
		addEntry( live, { title: 'Pinned note', pinned: true, time: '-20 minutes' } );
		addEntry( live, { title: 'Latest update', content: para( 'Counting goes on.' ), time: '-5 minutes' } );
	} );

	for ( const width of [ 1280, 375 ] ) {
		test( `Context card, no aside Context, time in the card's top row at ${ width } px`, async ( { page } ) => {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( permalink( live ) );
			const root = page.locator( '.newspack-live' );
			const context = root.locator( '.newspack-live__context' );
			await expect( context ).toBeVisible();
			await expect( context.getByRole( 'heading', { level: 2, name: 'Context' } ) ).toBeVisible();
			await expect( context ).toContainText( 'Background on the race.' );
			await expect( context ).not.toContainText( 'Updated' );
			await expect( root.locator( '.newspack-live__main > .newspack-live__context:first-child + .newspack-live__ask' ) ).toHaveCount( 1 );
			// The aside only has related coverage (and the e2e ad) here: no Context.
			await expect( root.locator( '.newspack-live__aside .newspack-live__context' ) ).toHaveCount( 0 );

			// Context comes before the ask form and the feed.
			const y = async ( selector: string ) => ( await root.locator( selector ).first().boundingBox() )?.y ?? 0;
			expect( await y( '.newspack-live__context' ) ).toBeLessThan( await y( '.newspack-live__ask' ) );
			expect( await y( '.newspack-live__ask' ) ).toBeLessThan( await y( '.newspack-live__feed' ) );

			// Each entry: one top row in the card: the time at the start edge, "Pinned" after it,
			// the share icon at the end edge, all on one line.
			for ( const entry of await root.locator( '.newspack-live-entry' ).all() ) {
				const card = entry.locator( ':scope > .newspack-live-entry__card' );
				const time = card.locator( ':scope > .newspack-live-entry__meta > time.newspack-live-entry__time' );
				const share = card.locator( ':scope > .newspack-live-entry__meta .newspack-live-entry__share' );
				const [ timeBox, cardBox, shareBox ] = [ await time.boundingBox(), await card.boundingBox(), await share.boundingBox() ];
				expect( timeBox && cardBox && shareBox ).toBeTruthy();
				expect( ( timeBox?.x ?? 0 ) - ( cardBox?.x ?? 0 ) ).toBeLessThan( 40 );
				expect( ( cardBox?.x ?? 0 ) + ( cardBox?.width ?? 0 ) - ( ( shareBox?.x ?? 0 ) + ( shareBox?.width ?? 0 ) ) ).toBeLessThan( 40 );
				// Same row: the vertical centers are within a few pixels.
				expect(
					Math.abs( ( timeBox?.y ?? 0 ) + ( timeBox?.height ?? 0 ) / 2 - ( ( shareBox?.y ?? 0 ) + ( shareBox?.height ?? 0 ) / 2 ) )
				).toBeLessThan( 8 );
				await expect( time.locator( '.newspack-live-time' ) ).toHaveText( /^\d{1,2}:\d{2}\s?[ap]m$/i ); // Today: the clock only, in the site's time format.
				await expect( time.locator( '.newspack-live__sr-only' ) ).toHaveText( / at / );
			}
			await expect( root.locator( '.newspack-live__pinned .newspack-live-entry__card .newspack-live-entry__pinned' ) ).toHaveText( 'Pinned' );
			await expect( root.locator( '.newspack-live__pinned-label' ) ).toHaveCount( 0 );
		} );
	}

	test( 'editing the Context replaces the card on the next poll, which stays closed if the reader closed it', async ( { page } ) => {
		test.setTimeout( 180_000 );
		const other = createLive( { title: 'E2E context update', context: '<p>First background.</p>' } );
		await page.goto( permalink( other ) );
		await expect( page.locator( '.newspack-live__context li' ) ).toHaveText( 'First background.' );
		const title = page.getByRole( 'button', { name: 'Context' } );
		await title.click();
		await expect( title ).toHaveAttribute( 'aria-expanded', 'false' );
		setMeta( other, '_newspack_live_context', '<p>Second background.</p>' );
		await expect( page.locator( '.newspack-live__context' ) ).toContainText( 'Second background.', { timeout: 90_000 } );
		await expect( page.locator( '.newspack-live__context' ) ).toHaveCount( 1 );
		await expect( title ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( page.locator( '#newspack-live-context-panel' ) ).toBeHidden();
		await title.click();
		await expect( page.locator( '#newspack-live-context-panel' ) ).toBeVisible();
	} );

	test( 'an empty Context is hidden; without an aside the main column keeps the full width', async ( { page } ) => {
		await page.setViewportSize( { width: 1280, height: 900 } );
		const empty = createLive( { title: 'E2E no context' } );
		await page.goto( permalink( empty ) );
		await expect( page.locator( '.newspack-live__context' ) ).toBeHidden();
		await page.evaluate( () => document.querySelector( '.newspack-live__aside' )?.remove() );
		await page.evaluate( () => document.querySelector( '.newspack-live__layout' )?.classList.remove( 'newspack-live__layout--aside' ) );
		const [ main, layout ] = await Promise.all( [
			page.locator( '.newspack-live__main' ).boundingBox(),
			page.locator( '.newspack-live__layout' ).boundingBox(),
		] );
		expect( Math.abs( ( main?.width ?? 0 ) - ( layout?.width ?? 0 ) ) ).toBeLessThan( 2 );
	} );

	test( '"Copy link" turns into "Link copied" for 2 s, in place, keeping focus', async ( { page } ) => {
		await page.addInitScript( () => {
			( window as unknown as { nlCopied: string[] } ).nlCopied = [];
			// The sandbox runs over http: pretend it's secure so the Clipboard API path is used.
			Object.defineProperty( window, 'isSecureContext', { configurable: true, value: true } );
			Object.defineProperty( navigator, 'clipboard', {
				configurable: true,
				value: {
					writeText: ( text: string ) => {
						( window as unknown as { nlCopied: string[] } ).nlCopied.push( text );
						return Promise.resolve();
					},
				},
			} );
		} );
		await page.goto( permalink( live ) );
		const share = page.locator( '.newspack-live__header details.newspack-live-share' );
		await share.locator( '.newspack-live-share__button' ).click();
		const copy = share.locator( '.newspack-live-share__copy' );
		await expect( copy ).toBeVisible();
		const before = await copy.boundingBox();
		await copy.focus();
		await copy.press( 'Enter' );

		await expect( copy ).toHaveClass( /is-copied/ );
		await expect( copy.locator( '.newspack-live-share__copy-done' ) ).toBeVisible();
		await expect( copy.locator( '.newspack-live-share__copy-done-text' ) ).toHaveText( 'Link copied' );
		await expect( copy.locator( '.newspack-live-share__check' ) ).toBeVisible();
		await expect( copy ).toBeFocused();
		await expect( share.locator( '.newspack-live-share__status' ) ).toHaveText( 'Link copied' );
		expect( await copy.boundingBox() ).toEqual( before );
		expect( await page.evaluate( () => ( window as unknown as { nlCopied: string[] } ).nlCopied ) ).toHaveLength( 1 );

		await expect( copy ).not.toHaveClass( /is-copied/, { timeout: 3_000 } );
		await expect( copy.locator( '.newspack-live-share__copy-label' ) ).toBeVisible();
		await expect( share.locator( '.newspack-live-share__status' ) ).toHaveText( '' );

		// Entry menu, failure path: the error shows in the same place, without the check.
		await page.evaluate( () => {
			Object.defineProperty( navigator, 'clipboard', {
				configurable: true,
				value: { writeText: () => Promise.reject( new Error( 'denied' ) ) },
			} );
			document.execCommand = () => false;
		} );
		const entryShare = page.locator( '.newspack-live-entry details.newspack-live-share' ).first();
		await entryShare.locator( '.newspack-live-share__button' ).click();
		const entryCopy = entryShare.locator( '.newspack-live-share__copy' );
		await entryCopy.click();
		await expect( entryCopy ).toHaveClass( /is-failed/ );
		await expect( entryCopy.locator( '.newspack-live-share__check' ) ).toBeHidden();
		await expect( entryCopy.locator( '.newspack-live-share__copy-done-text' ) ).not.toHaveText( 'Link copied' );
	} );

	test( 'the execCommand fallback shows the same feedback', async ( { page } ) => {
		await page.addInitScript( () => {
			Object.defineProperty( navigator, 'clipboard', { configurable: true, value: undefined } );
			document.execCommand = () => true;
		} );
		await page.goto( permalink( live ) );
		const entryShare = page.locator( '.newspack-live-entry details.newspack-live-share' ).first();
		await entryShare.locator( '.newspack-live-share__button' ).click();
		const copy = entryShare.locator( '.newspack-live-share__copy' );
		await copy.focus();
		await copy.press( 'Enter' );
		await expect( copy ).toHaveClass( /is-copied/ );
		await expect( copy.locator( '.newspack-live-share__copy-done-text' ) ).toHaveText( 'Link copied' );
		await expect( copy ).toBeFocused();
	} );

	test( 'no-JS: share menus still open with their network links', async ( { browser } ) => {
		const context = await browser.newContext( { javaScriptEnabled: false } );
		const page = await context.newPage();
		await page.goto( permalink( live ) );
		const share = page.locator( '.newspack-live-entry details.newspack-live-share' ).first();
		await share.locator( 'summary' ).click();
		await expect( share ).toHaveAttribute( 'open', '' );
		await expect( share.locator( '.newspack-live-share__link' ).first() ).toBeVisible();
		await expect( share.locator( '.newspack-live-share__copy' ) ).toBeHidden();
		await expect( page.locator( '.newspack-live-entry__time' ).first() ).toBeVisible();
		await context.close();
	} );

	test( 'axe: no serious or critical violations with the Context card and a copied link', async ( { page } ) => {
		await page.goto( permalink( live ) );
		await page.locator( '.newspack-live__header .newspack-live-share__button' ).click();
		const results = await new AxeBuilder( { page } ).include( '.newspack-live' ).analyze();
		const serious = results.violations.filter( violation => [ 'serious', 'critical' ].includes( violation.impact ?? '' ) );
		expect( serious.flatMap( violation => violation.nodes.map( node => `${ violation.id }: ${ node.target.join( ' ' ) }` ) ) ).toEqual( [] );
	} );
} );
