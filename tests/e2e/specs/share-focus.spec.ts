/**
 * Share without JavaScript, icon-only share buttons on narrow screens, focus
 * after a removed entry, and registered-only questions after a Reader Activation sign-in.
 */

/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { credentials, storageStatePath } from '../utils/env';
import { addEntry, cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

/**
 * Backdates the form's signed timestamp past the anti-spam minimum delay.
 *
 * @param page   Page.
 * @param liveId Live ID.
 */
async function ageForm( page: Page, liveId: number ): Promise< void > {
	const signed = wp( 'eval', `echo \\Newspack_Live\\Questions::sign_timestamp( ${ liveId }, time() - 10 );` );
	await page
		.locator( 'form.newspack-live-ask input[name="ts"]' )
		.evaluate( ( input, value ) => ( ( input as HTMLInputElement ).value = value ), signed );
}

test.describe( 'share, focus and sign-in', () => {
	test.describe.configure( { timeout: 180_000 } );
	test.afterAll( () => cleanE2E() );

	test( 'share links work without JavaScript', async ( { browser } ) => {
		const live = createLive( { title: 'E2E share no JS' } );
		const entry = addEntry( live, { title: 'Shared update' } );
		const context = await browser.newContext( { javaScriptEnabled: false } );
		const page = await context.newPage();
		await page.goto( permalink( live ) );
		const share = page.locator( `#live-entry-${ entry } details.newspack-live-share` );
		await share.locator( 'summary' ).click();
		await expect( share ).toHaveAttribute( 'open', '' );
		await expect( share.getByRole( 'link', { name: 'X' } ) ).toHaveAttribute(
			'href',
			new RegExp( `^https://x\\.com/intent/post\\?url=.*live-entry%3D${ entry }` )
		);
		await expect( share.getByRole( 'link', { name: 'Facebook' } ) ).toBeVisible();
		await expect( share.getByRole( 'button', { name: 'Copy link' } ) ).toBeHidden();
		await context.close();
	} );

	test( 'entry share buttons are icons at every width, named "Share: {title}", with a 24 px target at least', async ( { page } ) => {
		const live = createLive( { title: 'E2E share icon' } );
		const entry = addEntry( live, { title: 'Icon update' } );
		for ( const width of [ 375, 1280 ] ) {
			await page.setViewportSize( { width, height: 800 } );
			await page.goto( permalink( live ) );
			const summary = page.locator( `#live-entry-${ entry } .newspack-live-entry__share` );
			await expect( summary ).toHaveAccessibleName( 'Share: Icon update' );
			await expect( summary.locator( 'svg' ) ).toHaveAttribute( 'aria-hidden', 'true' );
			await expect( summary.locator( '.newspack-live-share__label' ) ).toHaveCount( 0 );
			const box = await summary.boundingBox();
			expect( Math.min( box?.width ?? 0, box?.height ?? 0 ) ).toBeGreaterThanOrEqual( 24 );
			// A visible focus indicator.
			await summary.focus();
			expect( await summary.evaluate( element => window.getComputedStyle( element ).outlineStyle ) ).not.toBe( 'none' );
		}
		// The header keeps its "Share" text button.
		await expect( page.locator( '.newspack-live__header .newspack-live-share__button' ) ).toHaveText( 'Share' );
	} );

	test( 'forced colors keep the share icon visible', async ( { page } ) => {
		await page.emulateMedia( { forcedColors: 'active' } );
		const live = createLive( { title: 'E2E share forced colors' } );
		const entry = addEntry( live, { title: 'Contrast update' } );
		await page.goto( permalink( live ) );
		const circle = page.locator( `#live-entry-${ entry } .newspack-live-entry__share svg circle` ).first();
		const [ fill, color ] = await circle.evaluate( element => [
			window.getComputedStyle( element ).fill,
			window.getComputedStyle( element.closest( 'summary' ) as Element ).color,
		] );
		expect( fill ).toBe( color );
		expect( fill ).not.toMatch( /rgba\(0, 0, 0, 0\)|transparent|none/ );
	} );

	test( 'focus moves to the nearest entry when the focused entry is unpublished', async ( { page } ) => {
		const live = createLive( { title: 'E2E removed focus' } );
		const older = addEntry( live, { title: 'Older', time: '-20 minutes' } );
		const removed = addEntry( live, { title: 'To remove', time: '-10 minutes' } );
		await page.goto( permalink( live ) );
		await page.locator( `#live-entry-${ removed } .newspack-live-entry__share` ).focus();
		const editor = await apiContext( 'editor' );
		expect( ( await editor.post( `${ API }/entries/${ removed }/status`, { data: { status: 'draft' } } ) ).status() ).toBe( 200 );
		await editor.dispose();
		await expect( page.locator( `#live-entry-${ removed }` ) ).toHaveCount( 0, { timeout: 90_000 } );
		await expect( page.locator( `#live-entry-${ older }` ) ).toBeFocused();
	} );

	test( 'registered-only questions: a Reader Activation sign-in without reload enables the form', async ( { page, context } ) => {
		wp( 'newspack-live', 'reset-rate-limits' );
		const live = createLive( { title: 'E2E ask after modal sign-in' } );
		setMeta( live, '_newspack_live_questions_mode', 'registered' );
		await page.goto( permalink( live ) );
		await page.getByLabel( 'Your question' ).fill( 'Asked around the modal?' );
		await page.getByLabel( 'Name to display' ).fill( 'Subscriber' );
		await expect( page.getByRole( 'button', { name: 'Send question' } ) ).toHaveCount( 0 );

		// What Reader Activation's modal does on success: the session cookie, then the reader event.
		await context.addCookies( JSON.parse( readFileSync( storageStatePath( 'subscriber' ), 'utf8' ) ).cookies );
		const { user } = credentials( 'subscriber' );
		const email = wp( 'user', 'get', user, '--field=user_email' );
		await page.evaluate( address => {
			const readerActivation = (
				window as unknown as { newspackReaderActivation: { setReaderEmail: ( e: string ) => void; setAuthenticated: ( a: boolean ) => void } }
			 ).newspackReaderActivation;
			readerActivation.setReaderEmail( address );
			readerActivation.setAuthenticated( true );
		}, email );

		const send = page.getByRole( 'button', { name: 'Send question' } );
		await expect( send ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Sign in to ask a question' } ) ).toHaveCount( 0 );
		await expect( page.getByLabel( 'Your question' ) ).toHaveValue( 'Asked around the modal?' );
		await ageForm( page, live );
		await send.click();
		await expect( page.getByText( /^Thanks! Your question was sent/ ) ).toBeVisible();
		const id = wp( 'post', 'list', '--post_type=newspack_live_q', `--post_parent=${ live }`, '--post_status=nl_new', '--field=ID' );
		expect( wp( 'post', 'get', id, '--field=post_author' ) ).toBe( wp( 'user', 'get', user, '--field=ID' ) );
	} );

	test( 'open share popovers stay on screen at 390 px', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		const live = createLive( { title: 'E2E share popover on screen' } );
		const entry = addEntry( live, { title: 'Popover update' } );
		await page.goto( permalink( live ) );
		for ( const summary of [ page.locator( '.newspack-live__header summary' ), page.locator( `#live-entry-${ entry } summary` ) ] ) {
			await summary.click();
			const box = await page.locator( 'details.newspack-live-share[open] .newspack-live-share__popover' ).boundingBox();
			expect( box && box.x >= 0 && box.x + box.width <= 390 ).toBe( true );
			await page.keyboard.press( 'Escape' );
		}
	} );
} );
