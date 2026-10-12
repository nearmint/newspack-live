/**
 * Permalinks, pagination and sharing.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';

const shown = async ( page: Page ) =>
	page
		.locator( '.newspack-live__entries > .newspack-live-entry' )
		.evaluateAll( els => els.map( el => Number( ( el as HTMLElement ).dataset.entryId ) ) );

test.describe( 'permalinks and pagination', () => {
	let live: number;
	let newestFirst: number[];

	test.beforeAll( () => {
		live = createLive( { title: 'E2E pagination 45' } );
		const ids: number[] = [];
		for ( let i = 0; i < 45; i++ ) {
			ids.push( addEntry( live, { title: `Update ${ i + 1 }`, time: `-${ 200 - i } minutes` } ) );
		}
		newestFirst = ids.reverse();
	} );

	test.afterAll( () => cleanE2E() );

	test( '"Load older updates" appends the next 15; the no-JS link renders the same entries', async ( { page, browser } ) => {
		await page.goto( permalink( live ) );
		expect( await shown( page ) ).toEqual( newestFirst.slice( 0, 15 ) );
		const older = page.getByRole( 'link', { name: 'Load older updates' } );
		const href = await older.getAttribute( 'href' );
		await older.click();
		await expect( page.locator( '.newspack-live__entries > .newspack-live-entry' ) ).toHaveCount( 30 );
		expect( await shown( page ) ).toEqual( newestFirst.slice( 0, 30 ) );
		await expect( page ).toHaveURL( permalink( live ) );

		const noJs = await browser.newContext( { javaScriptEnabled: false } );
		const plain = await noJs.newPage();
		await plain.goto( href ?? '' );
		expect( await shown( plain ) ).toEqual( newestFirst.slice( 15, 30 ) );
		await noJs.close();

		await page.getByRole( 'link', { name: 'Load older updates' } ).click();
		await expect( page.locator( '.newspack-live__entries > .newspack-live-entry' ) ).toHaveCount( 45 );
		await expect( page.getByText( "You've reached the start of this live." ) ).toBeVisible();
	} );

	test( 'a ?live-before= URL returns the same entries after new ones are published', async ( { page } ) => {
		const url = `${ permalink( live ) }?live-before=${ newestFirst[ 14 ] }`;
		await page.goto( url );
		const before = await shown( page );
		addEntry( live, { title: 'Brand new' } );
		await page.goto( url );
		expect( await shown( page ) ).toEqual( before );
		await expect( page.getByRole( 'link', { name: 'Back to latest' } ) ).toBeVisible();
	} );

	test( 'an entry permalink beyond the first page renders its window and scrolls to it', async ( { page } ) => {
		const target = newestFirst[ 30 ];
		await page.goto( `${ permalink( live ) }?live-entry=${ target }#live-entry-${ target }` );
		const ids = await shown( page );
		expect( ids ).toContain( target );
		expect( ids.indexOf( target ) ).toBeGreaterThan( 0 );
		await expect( page.locator( `#live-entry-${ target }` ) ).toBeInViewport();
		await expect( page.getByRole( 'link', { name: 'Back to latest' } ) ).toBeVisible();
	} );

	test( 'over http the share popover copies the permalink; with navigator.share stubbed, Web Share is used', async ( { page, browser } ) => {
		await page.goto( permalink( live ) );
		const first = page.locator( '.newspack-live__entries > .newspack-live-entry' ).first();
		const id = await first.getAttribute( 'data-entry-id' );
		await first.locator( '.newspack-live-entry__share' ).click();
		const popover = first.locator( '.newspack-live-share__popover' );
		await expect( popover ).toBeVisible();
		await expect( popover.getByRole( 'link', { name: 'X' } ) ).toHaveAttribute( 'href', new RegExp( `live-entry%3D${ id }` ) );
		await page.evaluate( () => {
			const original = document.execCommand.bind( document );
			( window as unknown as { __copied: string } ).__copied = '';
			document.execCommand = ( command: string, ...rest: unknown[] ) => {
				if ( command === 'copy' ) {
					// eslint-disable-next-line @wordpress/no-global-active-element -- page context: the fallback focuses its textarea.
					( window as unknown as { __copied: string } ).__copied = ( document.activeElement as HTMLTextAreaElement )?.value ?? '';
				}
				return original( command, ...( rest as [ boolean, string ] ) );
			};
		} );
		await popover.getByRole( 'button', { name: 'Copy link' } ).click();
		await expect( popover.getByRole( 'status' ) ).toHaveText( 'Link copied' );
		expect( await page.evaluate( () => ( window as unknown as { __copied: string } ).__copied ) ).toContain(
			`live-entry=${ id }#live-entry-${ id }`
		);

		const context = await browser.newContext();
		await context.addInitScript( () => {
			( window as unknown as { __shared: unknown[] } ).__shared = [];
			Object.defineProperty( window.navigator, 'share', {
				value: ( data: unknown ) => {
					( window as unknown as { __shared: unknown[] } ).__shared.push( data );
					return Promise.resolve();
				},
			} );
		} );
		const shared = await context.newPage();
		await shared.goto( permalink( live ) );
		await shared.locator( '.newspack-live__entries > .newspack-live-entry' ).first().locator( '.newspack-live-entry__share' ).click();
		const calls = await shared.evaluate( () => ( window as unknown as { __shared: Array< { url: string } > } ).__shared );
		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ].url ).toContain( `live-entry=${ id }` );
		await expect( shared.locator( 'details.newspack-live-share[open]' ) ).toHaveCount( 0 );
		await context.close();
	} );
} );
