/**
 * The Context box below the block editor (with axe) and the description.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { storageStatePath } from '../utils/env';
import { cleanE2E, createLive, permalink } from '../utils/live';
import { wp } from '../utils/wp-cli';

/**
 * Opens the block editor of a post and waits for it; closes the welcome guide if it shows.
 *
 * @param page   Page.
 * @param postId Post ID.
 */
async function openEditor( page: Page, postId: number ): Promise< void > {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForFunction( () =>
		( window as unknown as { wp?: { data?: { select: ( s: string ) => { getCurrentPostId?: () => number } | undefined } } } ).wp?.data
			?.select( 'core/editor' )
			?.getCurrentPostId?.()
	);
	const guide = page.getByRole( 'dialog', { name: /Welcome/ } );
	if ( await guide.isVisible().catch( () => false ) ) {
		await page.keyboard.press( 'Escape' );
	}
}

test.describe( 'Context box', () => {
	test.use( { storageState: storageStatePath( 'editor' ) } );
	test.afterAll( () => cleanE2E() );

	test( 'the Context box below the editor saves its meta; axe finds nothing serious', async ( { page } ) => {
		const live = createLive( { title: 'E2E context box', content: '<p>The description.</p>' } );
		await openEditor( page, live );
		const box = page.locator( '#newspack-live-context' );
		// The editor's meta box pane remembers per user whether it is collapsed.
		await page.getByRole( 'region', { name: 'Meta Boxes' } ).waitFor();
		if ( ! ( await box.isVisible() ) ) {
			// By keyboard: the resize handle covers the toggle's center.
			await page.getByRole( 'button', { name: 'Meta Boxes', exact: true } ).focus();
			await page.keyboard.press( 'Enter' );
		}
		await expect( box ).toBeVisible();
		await expect( box.getByRole( 'heading', { name: 'Context' } ) ).toBeVisible();
		await expect( box ).toContainText( 'one per bullet: press Enter to add a bullet' );
		const field = box.getByRole( 'group', { name: 'Context: key facts' } );
		// An empty Context shows one bullet with a placeholder and a formatting hint.
		await expect( field ).toContainText( 'Click a bullet to format it: bold, italic, link.' );
		const bullet = field.locator( '[contenteditable="true"]' ).first();
		// The placeholder is shown by core's RichText (its accessible name stays core's own).
		await expect( field.locator( '[data-rich-text-placeholder="Add a key fact…"]' ) ).toHaveCount( 1 );
		expect( await bullet.evaluate( element => getComputedStyle( element ).display ) ).toBe( 'list-item' );
		await bullet.click();
		// The toolbar sits at the top of the field: bold, italic and link only.
		await expect( field.getByRole( 'button', { name: 'Bold' } ) ).toBeVisible();
		await expect( field.getByRole( 'button', { name: 'Italic' } ) ).toBeVisible();
		await expect( field.getByRole( 'button', { name: 'Link' } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Inline code' } ) ).toHaveCount( 0 );
		await page.keyboard.type( 'Polls close at ' );
		await field.getByRole( 'button', { name: 'Bold' } ).click();
		await page.keyboard.type( '8 pm' );
		await page.keyboard.press( 'ControlOrMeta+b' );
		await page.keyboard.type( '.' );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( 'Measure R is on the ballot.' );
		await expect( field.locator( '[contenteditable="true"]' ) ).toHaveCount( 2 );

		const results = await new AxeBuilder( { page } ).include( '#newspack-live-context' ).analyze();
		const serious = results.violations.filter( violation => [ 'serious', 'critical' ].includes( violation.impact ?? '' ) );
		// Core's RichText (`role="document"` with aria attributes) is excepted, as in accessibility.spec.ts.
		expect(
			serious.flatMap( violation =>
				violation.nodes
					.filter( node => ! ( violation.id === 'aria-allowed-attr' && node.html.includes( 'role="document"' ) ) )
					.map( node => `${ violation.id }: ${ node.target.join( ' ' ) }` )
			)
		).toEqual( [] );

		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect
			.poll( () => wp( 'post', 'meta', 'get', String( live ), '_newspack_live_context' ), { timeout: 30_000 } )
			.toBe( '<ul><li>Polls close at <strong>8 pm</strong>.</li><li>Measure R is on the ballot.</li></ul>' );
		expect( wp( 'post', 'get', String( live ), '--field=post_content' ) ).toContain( 'The description.' );

		// The page: the description under the header, then the Context card.
		await page.goto( permalink( live ) );
		await expect( page.locator( '.newspack-live__description' ) ).toHaveText( 'The description.' );
		await expect( page.locator( '.newspack-live__context-content li' ) ).toHaveText( [ 'Polls close at 8 pm.', 'Measure R is on the ballot.' ] );
	} );

	test( 'the Context box stays hidden on a post that is not a live', async ( { page } ) => {
		const post = Number(
			wp( 'post', 'create', '--post_title=E2E regular post', '--post_status=draft', '--meta_input={"_newspack_live_e2e":1}', '--porcelain' )
		);
		await openEditor( page, post );
		await expect( page.locator( '#newspack-live-context' ) ).toBeHidden();
	} );
} );
