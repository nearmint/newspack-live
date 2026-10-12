/**
 * Entry labels: rendering and colors, the Key point border, "Your questions" on
 * answers, a label change reaching an open page, the console control by keyboard, axe.
 */

/**
 * External dependencies
 */
import { test, expect, type Locator, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { API, apiContext } from '../utils/api';
import { composer, feedItems, openConsole, typeBody } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { addEntry, addQuestion, cleanE2E, createLive, permalink } from '../utils/live';

const REALTIME = 90_000;
const WCAG_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

const entry = ( page: Page, title: string ) => page.locator( '.newspack-live-entry', { has: page.getByRole( 'heading', { name: title } ) } );
const label = ( page: Page, title: string ) => entry( page, title ).locator( '.newspack-live-entry__label' );

/**
 * Computed styles of an element.
 *
 * @param locator    Element.
 * @param properties CSS properties.
 * @return Values.
 */
const styles = ( locator: Locator, properties: string[] ) =>
	locator.evaluate(
		( element, names ) => Object.fromEntries( names.map( name => [ name, getComputedStyle( element ).getPropertyValue( name ) ] ) ),
		properties
	);

/**
 * WCAG contrast ratio between two `rgb()` colors.
 *
 * @param foreground Text color.
 * @param background Background color.
 * @return Ratio.
 */
function contrast( foreground: string, background: string ): number {
	const luminance = ( color: string ) => {
		const [ r, g, b ] = ( color.match( /\d+(\.\d+)?/g ) ?? [] ).slice( 0, 3 ).map( Number );
		const channel = ( value: number ) => {
			const c = value / 255;
			return c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * channel( r ) + 0.7152 * channel( g ) + 0.0722 * channel( b );
	};
	const [ high, low ] = [ luminance( foreground ), luminance( background ) ].sort( ( a, b ) => b - a );
	return ( high + 0.05 ) / ( low + 0.05 );
}

/**
 * Axe on a scope: no serious or critical violation.
 *
 * @param page     Page.
 * @param selector Scope.
 */
async function expectNoSeriousViolations( page: Page, selector: string ): Promise< void > {
	const results = await new AxeBuilder( { page } ).include( selector ).withTags( WCAG_TAGS ).analyze();
	const serious = results.violations
		.filter( violation => violation.impact === 'serious' || violation.impact === 'critical' )
		// Core's RichText `role="document"` attributes, outside the plugin's code.
		.map( violation => ( {
			...violation,
			nodes: violation.nodes.filter( node => ! ( violation.id === 'aria-allowed-attr' && node.html.includes( 'role="document"' ) ) ),
		} ) )
		.filter( violation => violation.nodes.length )
		.map( violation => `${ violation.id }: ${ violation.nodes.map( node => node.target.join( ' ' ) ).join( ', ' ) }` );
	expect( serious ).toEqual( [] );
}

test.describe( 'entry labels', () => {
	test.describe.configure( { timeout: 180_000 } );
	test.afterAll( () => cleanE2E() );

	test( 'each label after the time, red or black, Key point with the Context border, Your questions on answers', async ( { page } ) => {
		const live = createLive( { title: 'E2E labels', context: '<ul><li>Background.</li></ul>' } );
		const editor = await apiContext( 'editor' );
		const created = await editor.post( `${ API }/lives/${ live }/labels`, { data: { text: 'Turnout' } } );
		expect( created.status() ).toBe( 201 );
		const custom = ( await created.json() ).label.value as string;
		await editor.dispose();

		addEntry( live, { title: 'Bridge closed', label: 'breaking', time: '-50 minutes' } );
		addEntry( live, { title: 'Gallery', label: 'photos', time: '-45 minutes' } );
		addEntry( live, { title: 'Clip', label: 'video', time: '-40 minutes' } );
		addEntry( live, { title: 'What matters', label: 'key-point', time: '-35 minutes' } );
		addEntry( live, { title: 'Pinned key', label: 'key-point', pinned: true, time: '-30 minutes' } );
		addEntry( live, { title: 'Numbers', label: custom, time: '-25 minutes' } );
		addEntry( live, { title: 'No label', time: '-20 minutes' } );
		const question = addQuestion( live, 'When do polls close?', 'Ana', 'nl_answered' );
		addEntry( live, { title: 'Answer', content: 'At 8 p.m.', questionIds: [ question ] } );

		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( permalink( live ) );

		const expected: Array< [ string, string, boolean ] > = [
			[ 'Bridge closed', 'Breaking', true ],
			[ 'Gallery', 'Photos', false ],
			[ 'Clip', 'Video', false ],
			[ 'What matters', 'Key point', true ],
			[ 'Pinned key', 'Key point', true ],
			[ 'Numbers', 'Turnout', false ],
			[ 'Answer', 'Your questions', false ],
		];
		const text = await styles( page.locator( '.newspack-live' ), [ 'color' ] );
		for ( const [ title, name, red ] of expected ) {
			const element = label( page, title );
			// Real text, read after the time: "…, Breaking".
			await expect( element ).toHaveText( `, ${ name }` );
			await expect( element ).toBeVisible();
			// It follows the time on the same line.
			const [ timeBox, labelBox ] = await Promise.all( [
				entry( page, title ).locator( '.newspack-live-entry__time' ).boundingBox(),
				element.boundingBox(),
			] );
			expect( labelBox!.x ).toBeGreaterThan( timeBox!.x + timeBox!.width - 1 );
			expect( Math.abs( labelBox!.y + labelBox!.height / 2 - ( timeBox!.y + timeBox!.height / 2 ) ) ).toBeLessThan( 4 );
			// Uppercase through CSS only, bold, no background.
			const style = await styles( element, [ 'color', 'text-transform', 'font-weight', 'background-color' ] );
			expect( style[ 'text-transform' ] ).toBe( 'uppercase' );
			expect( Number( style[ 'font-weight' ] ) ).toBeGreaterThanOrEqual( 700 );
			expect( style[ 'background-color' ] ).toBe( 'rgba(0, 0, 0, 0)' );
			if ( red ) {
				expect( style.color, title ).toBe( 'rgb(200, 16, 46)' );
				const card = await styles( entry( page, title ).locator( '.newspack-live-entry__card' ), [ 'background-color' ] );
				expect( contrast( style.color, card[ 'background-color' ] ) ).toBeGreaterThanOrEqual( 4.5 );
			} else {
				expect( style.color, title ).toBe( text.color );
			}
		}
		await expect( label( page, 'No label' ) ).toHaveCount( 0 );

		// The Key point border is the Context card's, pinned or not; other cards keep the thin border.
		const properties = [ 'border-left-width', 'border-left-style', 'border-left-color' ];
		const context = await styles( page.locator( '.newspack-live__context' ), properties );
		expect( context[ 'border-left-width' ] ).toBe( '4px' );
		for ( const title of [ 'What matters', 'Pinned key' ] ) {
			expect( await styles( entry( page, title ).locator( '.newspack-live-entry__card' ), properties ), title ).toEqual( context );
		}
		expect( ( await styles( entry( page, 'Bridge closed' ).locator( '.newspack-live-entry__card' ), properties ) )[ 'border-left-width' ] ).toBe(
			'1px'
		);
		await expect( entry( page, 'Pinned key' ).locator( '.newspack-live-entry__pinned' ) ).toHaveText( 'Pinned' );

		// Themes can set the red.
		await page.evaluate( () => document.documentElement.style.setProperty( '--newspack-live-label-accent', 'rgb(0, 100, 0)' ) );
		expect( ( await styles( label( page, 'Bridge closed' ), [ 'color' ] ) ).color ).toBe( 'rgb(0, 100, 0)' );

		await page.evaluate( () => document.documentElement.style.removeProperty( '--newspack-live-label-accent' ) );
		await expectNoSeriousViolations( page, '.newspack-live' );
	} );

	test( 'a label change reaches an open page without a reload', async ( { page } ) => {
		const live = createLive( { title: 'E2E label change' } );
		const edited = addEntry( live, { title: 'Developing', label: 'breaking', time: '-10 minutes' } );
		await page.goto( permalink( live ) );
		await expect( label( page, 'Developing' ) ).toHaveText( ', Breaking' );
		await page.evaluate( () => ( ( window as unknown as { notReloaded: boolean } ).notReloaded = true ) );

		const editor = await apiContext( 'editor' );
		expect( ( await editor.put( `${ API }/entries/${ edited }`, { data: { label: 'key-point' } } ) ).status() ).toBe( 200 );
		await expect( label( page, 'Developing' ) ).toHaveText( ', Key point', { timeout: REALTIME } );
		await expect( entry( page, 'Developing' ) ).toHaveClass( /newspack-live-entry--key-point/ );

		// Removing it reaches the page too.
		expect( ( await editor.put( `${ API }/entries/${ edited }`, { data: { label: '' } } ) ).status() ).toBe( 200 );
		await expect( label( page, 'Developing' ) ).toHaveCount( 0, { timeout: REALTIME } );
		await editor.dispose();
		expect( await page.evaluate( () => ( window as unknown as { notReloaded?: boolean } ).notReloaded ) ).toBe( true );
	} );

	test( 'the console control works by keyboard: create with errors announced, publish, delete only unused, locked on answers', async ( {
		browser,
		page: reader,
	} ) => {
		const live = createLive( { title: 'E2E label console' } );
		const question = addQuestion( live, 'Where do I vote?', 'Ben', 'nl_answered' );
		addEntry( live, { content: 'At the town hall.', questionIds: [ question ], time: '-5 minutes' } );
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		await openConsole( page, live );

		const select = composer( page ).getByRole( 'combobox', { name: 'Label' } );
		await expect( select.locator( 'option' ) ).toHaveText( [ 'None', 'Breaking', 'Photos', 'Video', 'Key point', '+ Create custom label…' ] );

		// Keyboard only (type-ahead picks an option of a closed select on every platform): "+ Create
		// custom label…" opens the inline field, which comes next in the tab order.
		await select.focus();
		await page.keyboard.type( '+' );
		const field = composer( page ).getByRole( 'textbox', { name: 'New label' } );
		await expect( field ).toBeVisible();
		await expect( select ).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect( field ).toBeFocused();

		// A preset name, any case: refused inline, tied to the field and announced.
		await page.keyboard.type( 'BREAKING' );
		await page.keyboard.press( 'Enter' );
		await expect( field ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( field ).toHaveAccessibleDescription( 'This live already has this label.' );
		await expect( page.locator( '#a11y-speak-assertive' ) ).toContainText( 'This live already has this label.' );
		await expect( field ).toBeFocused();
		await expectNoSeriousViolations( page, '#newspack-live-console' );

		// Too long.
		await field.fill( 'A label far longer than allowed' );
		await page.keyboard.press( 'Enter' );
		await expect( field ).toHaveAccessibleDescription( 'Use 24 characters or fewer.' );

		// Created and selected; the focus returns to the select.
		await field.fill( '  Recount  ' );
		await page.keyboard.press( 'Enter' );
		await expect( field ).toHaveCount( 0 );
		await expect( select ).toBeFocused();
		await expect( select.locator( 'option:checked' ) ).toHaveText( 'Recount' );
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText( 'Label created and selected.' );
		// Not used yet: it can be deleted.
		await expect( composer( page ).getByRole( 'button', { name: 'Delete label' } ) ).toBeVisible();

		await composer( page ).getByRole( 'textbox', { name: 'Title (optional)' } ).fill( 'Recount ordered' );
		await typeBody( page, 'The county will recount the ballots.' );
		await page.keyboard.press( 'ControlOrMeta+Enter' );
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText( 'Entry published.' );

		// Used now: no delete. The option stays for the next entries of this live.
		await expect( select.locator( 'option' ) ).toContainText( [ 'Recount' ] );
		await select.selectOption( { label: 'Recount' } );
		await expect( select.locator( 'option:checked' ) ).toHaveText( 'Recount' );
		await expect( composer( page ).getByRole( 'button', { name: 'Delete label' } ) ).toHaveCount( 0 );

		// An unused one is deleted by keyboard and leaves the list.
		await select.selectOption( { label: '+ Create custom label…' } );
		await field.fill( 'Spare' );
		await page.keyboard.press( 'Enter' );
		await expect( select.locator( 'option:checked' ) ).toHaveText( 'Spare' );
		await page.keyboard.press( 'Tab' );
		await expect( composer( page ).getByRole( 'button', { name: 'Delete label' } ) ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( select.locator( 'option' ) ).not.toContainText( [ 'Spare' ] );
		await expect( select.locator( 'option:checked' ) ).toHaveText( 'None' );
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText( 'Label deleted.' );

		// The server refuses to delete a used label.
		const editor = await apiContext( 'editor' );
		const labels = ( await ( await editor.get( `${ API }/lives/${ live }/console` ) ).json() ).live.labels as Array< {
			id: string;
			text: string;
			used: boolean;
		} >;
		const recount = labels.find( item => item.text === 'Recount' )!;
		expect( recount.used ).toBe( true );
		const refused = await editor.delete( `${ API }/lives/${ live }/labels/${ recount.id }` );
		expect( refused.status() ).toBe( 409 );
		await editor.dispose();

		// Answers: the label is locked to "Your questions".
		await feedItems( page ).filter( { hasText: 'At the town hall.' } ).getByRole( 'button', { name: 'Edit' } ).click();
		await expect( select ).toBeDisabled();
		await expect( select.locator( 'option:checked' ) ).toHaveText( 'Your questions' );
		await expect( composer( page ).getByText( 'Set automatically on answers to reader questions.' ) ).toBeVisible();
		await context.close();

		// The reader page shows the custom label.
		await reader.goto( permalink( live ) );
		await expect( label( reader, 'Recount ordered' ) ).toHaveText( ', Recount' );
	} );
} );
