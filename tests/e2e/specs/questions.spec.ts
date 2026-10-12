/**
 * Reader questions.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { composer, feedItems, openConsole } from '../utils/console';
import { credentials, storageStatePath } from '../utils/env';
import { addQuestion, cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

const questionStatuses = ( liveId: number ): string[] =>
	wp(
		'post',
		'list',
		'--post_type=newspack_live_q',
		`--post_parent=${ liveId }`,
		'--post_status=nl_new,nl_shortlisted,nl_answered,nl_rejected,nl_spam',
		'--field=post_status'
	)
		.split( /\s+/ )
		.filter( Boolean );

/**
 * Fills and sends the form, after the 3-second minimum since page load.
 *
 * @param page Page.
 * @param text Question.
 * @param name Display name.
 */
async function ask( page: Page, text: string, name = 'Ana' ): Promise< void > {
	await page.getByLabel( 'Your question' ).fill( text );
	await page.getByLabel( 'Name to display' ).fill( name );
	await page.getByRole( 'button', { name: 'Send question' } ).click();
}

/**
 * Sets the form's signed timestamp `age` seconds in the past, instead of sleeping.
 *
 * @param page   Page.
 * @param liveId Live ID.
 * @param age    Seconds since the simulated page load.
 */
async function ageForm( page: Page, liveId: number, age = 10 ): Promise< void > {
	const signed = wp( 'eval', `echo \\Newspack_Live\\Questions::sign_timestamp( ${ liveId }, time() - ${ age } );` );
	await page
		.locator( 'form.newspack-live-ask input[name="ts"]' )
		.evaluate( ( input, value ) => ( ( input as HTMLInputElement ).value = value ), signed );
}

test.describe( 'questions', () => {
	test.describe.configure( { timeout: 180_000 } );
	test.beforeEach( () => {
		wp( 'newspack-live', 'reset-rate-limits' );
	} );
	test.afterAll( () => cleanE2E() );

	test( 'an anonymous question reaches the inbox as New, escaped everywhere', async ( { page, browser } ) => {
		const live = createLive( { title: 'E2E ask' } );
		await page.goto( permalink( live ) );
		await ageForm( page, live );
		const hostile = 'Is <b>bold</b> & <img src=x onerror=alert(1)> escaped?';
		await ask( page, hostile, 'Ana <i>' );
		await expect( page.getByRole( 'status' ).filter( { hasText: 'Thanks! Your question was sent to the newsroom.' } ) ).toBeVisible();
		await expect( page.getByLabel( 'Your question' ) ).toHaveValue( '' );
		expect( questionStatuses( live ) ).toEqual( [ 'nl_new' ] );

		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const editor = await context.newPage();
		let dialogs = 0;
		editor.on( 'dialog', dialog => {
			dialogs++;
			dialog.dismiss();
		} );
		await openConsole( editor, live );
		await editor.getByRole( 'tab', { name: /Questions \(1 new\)/ } ).click();
		const row = editor.locator( '.newspack-live-question' ).first();
		await expect( row.locator( '.newspack-live-question__text' ) ).toContainText( 'escaped?' );
		await expect( row.locator( 'img, b' ) ).toHaveCount( 0 );

		// Answer it and check the public page escapes it too.
		await row.getByRole( 'checkbox' ).check();
		await editor.getByRole( 'button', { name: 'Answer selected' } ).click();
		await composer( editor ).getByRole( 'button', { name: 'Publish' } ).click();
		await expect( editor.getByRole( 'tab', { name: /Questions \(0 new\)/ } ) ).toBeVisible();
		expect( dialogs ).toBe( 0 );
		await context.close();

		page.on( 'dialog', dialog => {
			dialogs++;
			dialog.dismiss();
		} );
		await page.goto( permalink( live ) );
		const bubble = page.locator( '.newspack-live-qa__question' );
		// Stored as plain text (tags stripped) and printed escaped: no markup ever renders.
		await expect( bubble ).toContainText( 'escaped?' );
		await expect( bubble.locator( '*:not(span)' ) ).toHaveCount( 0 );
		await expect( page.locator( '.newspack-live-qa__name i' ) ).toHaveCount( 0 );
		expect( dialogs ).toBe( 0 );
	} );

	test( 'too long, too soon after load, and a second question within 60 s are refused', async ( { page } ) => {
		const live = createLive( { title: 'E2E ask limits' } );
		await page.goto( permalink( live ) );
		// Less than 3 s after load.
		await ageForm( page, live, 0 );
		await ask( page, 'Too fast?' );
		await expect( page.locator( 'form.newspack-live-ask' ).getByRole( 'alert' ) ).toHaveText(
			'Please take a moment before sending your question.'
		);

		await ageForm( page, live );
		await page.getByLabel( 'Your question' ).evaluate( textarea => textarea.removeAttribute( 'maxlength' ) );
		await ask( page, 'x'.repeat( 501 ) );
		await expect( page.locator( 'form.newspack-live-ask' ).getByRole( 'alert' ) ).toHaveText(
			'Your question must be between 1 and 500 characters, and your name between 1 and 50.'
		);

		await ask( page, 'First question' );
		await expect( page.getByText( /^Thanks! Your question was sent/ ) ).toBeVisible();
		await ask( page, 'Second question' );
		await expect( page.locator( 'form.newspack-live-ask' ).getByRole( 'alert' ) ).toHaveText(
			'You just sent a question. Please wait a minute before sending another one.'
		);
		expect( questionStatuses( live ) ).toHaveLength( 1 );
	} );

	test( 'registered-only mode keeps the question across sign-in', async ( { page } ) => {
		const live = createLive( { title: 'E2E ask registered' } );
		setMeta( live, '_newspack_live_questions_mode', 'registered' );
		await page.goto( permalink( live ) );
		await page.getByLabel( 'Your question' ).fill( 'Asked before signing in?' );
		await page.getByLabel( 'Name to display' ).fill( 'Subscriber' );
		await expect( page.getByRole( 'button', { name: 'Send question' } ) ).toHaveCount( 0 );
		await page.getByRole( 'link', { name: 'Sign in to ask a question' } ).click();

		const { user, pass } = credentials( 'subscriber' );
		await page.getByLabel( 'Username or Email Address' ).fill( user );
		await page.getByLabel( 'Password', { exact: true } ).fill( pass );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( new RegExp( `${ new URL( permalink( live ) ).pathname }` ) );

		await expect( page.getByLabel( 'Your question' ) ).toHaveValue( 'Asked before signing in?' );
		await ageForm( page, live );
		await page.getByRole( 'button', { name: 'Send question' } ).click();
		await expect( page.getByText( /^Thanks! Your question was sent/ ) ).toBeVisible();
		const id = wp( 'post', 'list', '--post_type=newspack_live_q', `--post_parent=${ live }`, '--post_status=nl_new', '--field=ID' );
		expect( wp( 'post', 'get', id, '--field=post_author' ) ).toBe( wp( 'user', 'get', user, '--field=ID' ) );
	} );

	test( 'the honeypot returns success and stores nothing', async ( { page } ) => {
		const live = createLive( { title: 'E2E honeypot' } );
		await page.goto( permalink( live ) );
		await ageForm( page, live );
		await page.locator( 'input[name="website"]' ).evaluate( input => ( ( input as HTMLInputElement ).value = 'http://spam.example' ) );
		await ask( page, 'Buy now' );
		await expect( page.getByText( /^Thanks! Your question was sent/ ) ).toBeVisible();
		expect( questionStatuses( live ) ).toEqual( [] );
	} );

	test( 'answering 3 questions creates one Q&A entry and marks them Answered', async ( { page, browser } ) => {
		const live = createLive( { title: 'E2E answers' } );
		const ids = [
			addQuestion( live, 'When do polls close?', 'Ana' ),
			addQuestion( live, 'Where do I vote?', 'Ben' ),
			addQuestion( live, 'Who is ahead?', 'Cy' ),
		];
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const editor = await context.newPage();
		await openConsole( editor, live );
		await editor.getByRole( 'tab', { name: /Questions \(3 new\)/ } ).click();
		// Ticked in a different order than the inbox lists them: bubbles follow the selection.
		for ( const name of [ 'Ana', 'Ben', 'Cy' ] ) {
			await editor.getByRole( 'checkbox', { name: `Select the question from ${ name }` } ).check();
		}
		await editor.getByRole( 'button', { name: 'Answer selected' } ).click();
		await expect( composer( editor ).locator( '.newspack-live-composer__question' ) ).toHaveCount( 3 );
		await expect( composer( editor ).getByRole( 'group', { name: 'Entry body' } ) ).toContainText( 'Hi Ana, Ben and Cy,' );
		await composer( editor ).getByRole( 'button', { name: 'Publish' } ).click();
		await editor.getByRole( 'tab', { name: 'Feed' } ).click();
		await expect( feedItems( editor ) ).toHaveCount( 1 );
		for ( const id of ids ) {
			expect( wp( 'post', 'get', String( id ), '--field=post_status' ) ).toBe( 'nl_answered' );
		}
		await context.close();

		await page.goto( permalink( live ) );
		const qa = page.locator( '.newspack-live-entry--qa' );
		await expect( qa.locator( '.newspack-live-qa__question' ) ).toHaveCount( 3 );
		await expect( qa.locator( '.newspack-live-qa__name' ) ).toHaveText( [ 'Ana', 'Ben', 'Cy' ] );
	} );

	test( 'closing questions in the console hides the form within 90 s', async ( { page } ) => {
		const live = createLive( { title: 'E2E close questions' } );
		await page.goto( permalink( live ) );
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
		const editor = await apiContext( 'editor' );
		expect( ( await editor.post( `${ API }/lives/${ live }/settings`, { data: { questions_open: false } } ) ).status() ).toBe( 200 );
		await editor.dispose();
		await expect( page.getByText( 'Questions are closed.' ) ).toBeVisible( { timeout: 90_000 } );
		await expect( page.getByLabel( 'Your question' ) ).toBeHidden();
	} );
} );
