/**
 * Accessibility: axe scans and announcements without focus moves.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { openConsole } from '../utils/console';
import { storageStatePath } from '../utils/env';
import { API, apiContext } from '../utils/api';
import { addEntry, addQuestion, cleanE2E, createLive, permalink, setState } from '../utils/live';

const WCAG_TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * Known issue of core's block editor, outside the plugin's code: RichText paragraphs carry
 * `role="document"` with `aria-multiline`/`aria-readonly`. Only failures made of exactly these
 * attributes on block nodes are ignored.
 *
 * @param rule    Axe rule.
 * @param html    Node HTML.
 * @param summary Failure summary.
 * @return Whether the node is ignored.
 */
function isCoreEditorIssue( rule: string, html: string, summary = '' ): boolean {
	if ( rule !== 'aria-allowed-attr' || ! html.includes( 'role="document"' ) || ! html.includes( 'data-block=' ) ) {
		return false;
	}
	const attributes = [ ...summary.matchAll( /(aria-[a-z]+)=/g ) ].map( match => match[ 1 ] );
	return attributes.length > 0 && attributes.every( attribute => attribute === 'aria-multiline' || attribute === 'aria-readonly' );
}

/**
 * Runs axe on a scope and fails on serious or critical violations, listing them.
 *
 * @param page     Page.
 * @param selector Scope.
 */
async function expectNoSeriousViolations( page: Page, selector: string ): Promise< void > {
	await expect( page.locator( selector ).first() ).toBeVisible();
	const results = await new AxeBuilder( { page } ).include( selector ).withTags( WCAG_TAGS ).analyze();
	const serious = results.violations
		.filter( violation => violation.impact === 'serious' || violation.impact === 'critical' )
		.map( violation => ( {
			...violation,
			nodes: violation.nodes.filter( node => ! isCoreEditorIssue( violation.id, node.html, node.failureSummary ) ),
		} ) )
		.filter( violation => violation.nodes.length )
		.map(
			violation =>
				`${ violation.id } (${ violation.impact }): ${ violation.nodes.map( node => `${ node.target.join( ' ' ) } ${ node.failureSummary }` ).join( ', ' ) }`
		);
	expect( serious ).toEqual( [] );
}

/**
 * Creates a live using every reader module.
 *
 * @return Live ID.
 */
function richLive(): number {
	const live = createLive( {
		title: 'E2E accessibility',
		excerpt: 'Results as they come in.',
		context: '<ul><li>Background of the race.</li></ul>',
	} );
	addEntry( live, { title: 'Polls open', time: '-30 minutes', label: 'breaking' } );
	addEntry( live, { title: 'Pinned note', pinned: true, time: '-20 minutes' } );
	addEntry( live, {
		title: 'Analysis',
		content: '<p>What the numbers say.</p><blockquote><p>A quote.</p></blockquote>',
		time: '-10 minutes',
		label: 'key-point',
	} );
	const question = addQuestion( live, 'When do polls close?', 'Ana', 'nl_answered' );
	addEntry( live, { content: '<p>At 8 p.m.</p>', questionIds: [ question ] } );
	return live;
}

test.describe( 'accessibility', () => {
	test.describe.configure( { timeout: 180_000 } );
	test.afterAll( () => cleanE2E() );

	test( 'no serious or critical axe violations on a live page, live and ended', async ( { page } ) => {
		const live = richLive();
		await page.goto( permalink( live ) );
		await expectNoSeriousViolations( page, '.newspack-live' );

		setState( live, 'end' );
		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live' ) ).toContainText( 'Ended' );
		await expectNoSeriousViolations( page, '.newspack-live' );
	} );

	test( 'no serious or critical axe violations on a live page at 390 px', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		const live = richLive();
		await page.goto( permalink( live ) );
		await expectNoSeriousViolations( page, '.newspack-live' );

		// Opened panels (closed by default on mobile) and an open share popover are scanned too
		// (axe skips hidden content).
		for ( const name of [ 'Context', 'Ask the newsroom a question' ] ) {
			await page.getByRole( 'button', { name } ).click();
		}
		await page.locator( '.newspack-live__header .newspack-live-share__button' ).click();
		await expect( page.locator( 'details.newspack-live-share[open] .newspack-live-share__popover' ) ).toBeVisible();
		await expectNoSeriousViolations( page, '.newspack-live' );
	} );

	test( 'no serious or critical axe violations on the console', async ( { browser } ) => {
		const live = richLive();
		addQuestion( live, 'Where do I vote?', 'Ben' );
		const context = await browser.newContext( { storageState: storageStatePath( 'editor' ) } );
		const page = await context.newPage();
		await openConsole( page, live );
		await expectNoSeriousViolations( page, '#newspack-live-console' );
		await page.getByRole( 'tab', { name: /Questions/ } ).click();
		await expectNoSeriousViolations( page, '#newspack-live-console' );
		await context.close();
	} );

	test( 'a new update is announced politely and focus stays where the reader left it', async ( { page } ) => {
		const live = createLive( { title: 'E2E announcements' } );
		addEntry( live, { title: 'Earlier', time: '-5 minutes' } );
		await page.goto( permalink( live ) );
		const share = page.locator( '.newspack-live__header .newspack-live-share__button' );
		await share.focus();
		await expect( share ).toBeFocused();

		addEntry( live, { title: 'Breaking now' } );
		// It waits behind the button; the announcement says so.
		await expect( page.getByRole( 'button', { name: '1 new update' } ) ).toBeVisible( { timeout: 90_000 } );
		const announcer = page.locator( '.newspack-live__announcer' );
		await expect( announcer ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( announcer ).toHaveText( '1 new update available' );
		await expect( share ).toBeFocused();
	} );

	test( 'focus stays on the question form title and on an entry being edited when updates arrive', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		const live = createLive( { title: 'E2E focus kept' } );
		const edited = addEntry( live, { title: 'Original wording', time: '-10 minutes' } );
		await page.goto( permalink( live ) );
		const focusMoves: string[] = [];
		await page.exposeFunction( 'nlFocusIn', ( target: string ) => focusMoves.push( target ) );
		await page.evaluate( () =>
			document.addEventListener( 'focusin', event =>
				( window as unknown as { nlFocusIn: ( target: string ) => void } ).nlFocusIn( ( event.target as Element ).outerHTML.slice( 0, 80 ) )
			)
		);

		const toggle = page.getByRole( 'button', { name: 'Ask the newsroom a question' } );
		await toggle.focus();
		addEntry( live, { title: 'Second key moment' } );
		await expect( page.getByRole( 'button', { name: '1 new update' } ) ).toBeVisible( { timeout: 90_000 } );
		await expect( toggle ).toBeFocused();

		const share = page.locator( `#live-entry-${ edited } .newspack-live-entry__share` );
		await share.focus();
		const context = await apiContext( 'editor' );
		expect( ( await context.put( `${ API }/entries/${ edited }`, { data: { title: 'Corrected wording' } } ) ).status() ).toBe( 200 );
		await context.dispose();
		await expect( page.locator( `#live-entry-${ edited }` ) ).toContainText( 'Corrected wording', { timeout: 90_000 } );
		await expect( share ).toBeFocused();
		// Focus only ever landed on the controls the test focused (and their replacements).
		expect( focusMoves.every( target => /newspack-live__expander|newspack-live-entry__share/.test( target ) ) ).toBe( true );
	} );

	test( 'keyboard: the skip link and toggles work without a mouse, with a visible focus and 24 px targets', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		const live = createLive( { title: 'E2E keyboard' } );
		addEntry( live, { title: 'A key moment' } );
		await page.goto( permalink( live ) );

		const skip = page.getByRole( 'link', { name: 'Skip to latest updates' } );
		await skip.focus();
		const skipBox = await skip.boundingBox();
		expect( skipBox && skipBox.y >= 0 && skipBox.x >= 0 && skipBox.x + skipBox.width <= 390 ).toBe( true );
		await page.keyboard.press( 'Enter' );
		await expect( page ).toHaveURL( /#newspack-live-feed$/ );

		const toggle = page.getByRole( 'button', { name: 'Ask the newsroom a question' } );
		await toggle.focus();
		const outline = await toggle.evaluate( element => ( {
			style: getComputedStyle( element ).outlineStyle,
			width: parseFloat( getComputedStyle( element ).outlineWidth ),
		} ) );
		expect( outline.style ).toBe( 'solid' );
		expect( outline.width ).toBeGreaterThanOrEqual( 2 );
		// Closed by default on mobile.
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await page.keyboard.press( 'Enter' );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		await page.keyboard.press( 'Space' );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await page.keyboard.press( 'Enter' );

		// Every control, with both panels open and a share popover open (WCAG 2.5.8: 24 × 24 px,
		// links inside running text excepted).
		await page.locator( '.newspack-live-entry__share' ).first().press( 'Enter' );
		await expect( page.locator( 'details.newspack-live-share[open] .newspack-live-share__popover' ) ).toBeVisible();
		const small: string[] = [];
		for ( const control of await page.locator( '.newspack-live :is(button, a, input, textarea):visible' ).all() ) {
			const box = await control.boundingBox();
			const inText = await control.evaluate( element => {
				const parent = element.parentElement;
				return (
					element.tagName === 'A' && !! parent && ( parent.textContent ?? '' ).trim().length > ( element.textContent ?? '' ).trim().length
				);
			} );
			if ( box && ! inText && ( box.height < 23.5 || box.width < 23.5 ) ) {
				small.push( await control.evaluate( element => element.outerHTML.slice( 0, 100 ) ) );
			}
		}
		expect( small ).toEqual( [] );
	} );
} );
