/**
 * Mobile console and the reader's collapsible sections.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { addImage, composer, feedItems, openConsole, typeBody } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { addEntry, addQuestion, cleanE2E, createLive, permalink } from '../utils/live';
import { wp } from '../utils/wp-cli';

test.describe( 'mobile console', () => {
	test.use( { storageState: storageStatePath( 'editor' ), viewport: { width: 375, height: 812 }, hasTouch: true } );
	test.afterAll( () => cleanE2E() );

	test( 'compose, upload a photo, publish, flag and bulk-moderate at 375 px', async ( { page } ) => {
		const live = createLive( { title: 'E2E mobile' } );
		const questions = [ addQuestion( live, 'First?', 'Ana' ), addQuestion( live, 'Second?', 'Ben' ) ];
		await openConsole( page, live );
		expect( await page.evaluate( () => document.documentElement.scrollWidth ) ).toBeLessThanOrEqual( 375 );

		await composer( page ).getByLabel( 'Title (optional)' ).fill( 'From my phone' );
		await typeBody( page, 'Photo from the polling station.' );
		await addImage( page );
		const publish = composer( page ).getByRole( 'button', { name: 'Publish' } );
		const box = await publish.boundingBox();
		expect( box?.height ).toBeGreaterThanOrEqual( 44 );
		await publish.tap();
		await expect( feedItems( page ).first() ).toContainText( 'From my phone' );

		await feedItems( page ).first().getByRole( 'button', { name: 'Pin', exact: true } ).tap();
		await expect( feedItems( page ).first().getByRole( 'button', { name: 'Unpin' } ) ).toBeVisible();

		await page.getByRole( 'tab', { name: /Questions \(2 new\)/ } ).tap();
		const target = await page.locator( '.newspack-live-question__check .components-checkbox-control__label' ).first().boundingBox();
		expect( Math.min( target?.width ?? 0, target?.height ?? 0 ) ).toBeGreaterThanOrEqual( 44 );
		// The transparent label covers the checkbox: tap it like a reader's thumb would.
		for ( const name of [ 'Ana', 'Ben' ] ) {
			await page.locator( 'label', { hasText: `Select the question from ${ name }` } ).tap();
			await expect( page.getByRole( 'checkbox', { name: `Select the question from ${ name }` } ) ).toBeChecked();
		}
		await page.getByRole( 'button', { name: 'Shortlist' } ).tap();
		await expect( page.getByRole( 'tab', { name: /Questions \(0 new\)/ } ) ).toBeVisible();
		for ( const id of questions ) {
			expect( wp( 'post', 'get', String( id ), '--field=post_status' ) ).toBe( 'nl_shortlisted' );
		}
	} );
} );

test.describe( 'mobile reader', () => {
	test.use( { viewport: { width: 390, height: 844 } } );
	test.afterAll( () => cleanE2E() );

	test( 'Context and the question form are closed by default on mobile; their titles open them', async ( { page } ) => {
		const live = createLive( { title: 'E2E mobile reader', context: '<ul><li>Background.</li></ul>' } );
		addEntry( live, { title: 'An update' } );
		await page.goto( permalink( live ) );
		await expect( page.getByLabel( 'Your question' ) ).toBeHidden();
		for ( const name of [ 'Context', 'Ask the newsroom a question' ] ) {
			const toggle = page.getByRole( 'heading', { name } ).getByRole( 'button', { name } );
			await expect( toggle ).toBeVisible();
			await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
			const panel = page.locator( `#${ await toggle.getAttribute( 'aria-controls' ) }` );
			await expect( panel ).toBeHidden();
			// The chevron sits at the end edge of the title (measured once its rotation has settled).
			await expect
				.poll( async () => {
					const [ button, chevron ] = await Promise.all( [ toggle.boundingBox(), toggle.locator( 'svg' ).boundingBox() ] );
					return Math.abs( ( button?.x ?? 0 ) + ( button?.width ?? 0 ) - ( ( chevron?.x ?? 0 ) + ( chevron?.width ?? 0 ) ) );
				} )
				.toBeLessThanOrEqual( 1 );
			await toggle.click();
			await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
			await expect( panel ).toBeVisible();
		}
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();

		// Desktop: open by default; a section the reader closed stays closed across a resize.
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.reload();
		const context = page.getByRole( 'button', { name: 'Context' } );
		await expect( context ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
		await context.click();
		await page.setViewportSize( { width: 390, height: 844 } );
		await expect( context ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( page.getByRole( 'button', { name: 'Ask the newsroom a question' } ) ).toHaveAttribute( 'aria-expanded', 'false' );
		await page.setViewportSize( { width: 1280, height: 900 } );
		await expect( context ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( page.getByRole( 'button', { name: 'Ask the newsroom a question' } ) ).toHaveAttribute( 'aria-expanded', 'true' );
	} );
} );
