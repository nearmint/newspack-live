/**
 * Theme smoke tests: the 7 Newspack themes and a generic block theme, at 1280 and 390 px.
 * Run alone with `npm run test:e2e -- --grep @themes`.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { addEntry, cleanE2E, createLive, permalink } from '../utils/live';
import { wp } from '../utils/wp-cli';

const THEMES = [
	'newspack-theme',
	'newspack-joseph',
	'newspack-katharine',
	'newspack-nelson',
	'newspack-sacha',
	'newspack-scott',
	'newspack-block-theme',
	'twentytwentyfive',
];

const DEFAULT_THEME = 'newspack-theme';

/**
 * Opens the live and collects console errors and failed requests from plugin assets.
 *
 * @param page   Page.
 * @param liveId Live ID.
 * @return Errors seen so far (the array keeps filling while the page is open).
 */
async function openLive( page: Page, liveId: number ): Promise< string[] > {
	const errors: string[] = [];
	page.on( 'console', message => {
		if ( message.type() === 'error' && /newspack-live/.test( `${ message.text() } ${ message.location().url }` ) ) {
			errors.push( message.text() );
		}
	} );
	page.on( 'pageerror', error => {
		if ( /newspack-live/.test( error.stack ?? '' ) ) {
			errors.push( error.message );
		}
	} );
	page.on( 'requestfailed', request => {
		if ( request.url().includes( '/plugins/newspack-live/' ) ) {
			errors.push( `${ request.url() } ${ request.failure()?.errorText }` );
		}
	} );
	await page.goto( permalink( liveId ) );
	await expect( page.locator( '.newspack-live' ) ).toBeVisible();
	return errors;
}

/**
 * Asserts the page doesn't scroll horizontally.
 *
 * @param page Page.
 */
async function expectNoHorizontalScroll( page: Page ): Promise< void > {
	const { scroll, client, right } = await page.evaluate( () => ( {
		scroll: document.documentElement.scrollWidth,
		client: document.documentElement.clientWidth,
		// Overflow clipped by a theme container would not scroll the page but would still cut the live.
		right: Math.max(
			0,
			...Array.from( document.querySelectorAll( '.newspack-live, .newspack-live *' ) )
				.map( element => element.getBoundingClientRect() )
				.filter( box => box.width > 1 && box.height > 1 )
				.map( box => box.right )
		),
	} ) );
	expect( scroll ).toBeLessThanOrEqual( client );
	expect( right ).toBeLessThanOrEqual( client + 1 );
}

test.describe( 'themes @themes', () => {
	test.describe.configure( { timeout: 120_000 } );
	let live = 0;

	test.beforeAll( () => {
		live = createLive( { title: 'E2E themes', content: '<p>A short description.</p>', context: '<p>Background on the race.</p>' } );
		addEntry( live, { title: 'Polls open', time: '-30 minutes' } );
		addEntry( live, { title: 'Turnout update', content: '<p>Turnout is at 40% by noon.</p>', time: '-10 minutes' } );
	} );

	test.afterAll( () => {
		wp( 'theme', 'activate', DEFAULT_THEME );
		cleanE2E();
	} );

	for ( const theme of THEMES ) {
		test( `${ theme } renders the live at 1280 px and 390 px`, async ( { page } ) => {
			try {
				wp( 'theme', 'activate', theme );

				await page.setViewportSize( { width: 1280, height: 900 } );
				let errors = await openLive( page, live );
				expect( await page.content(), `${ theme } is active` ).toContain( `/themes/${ theme }/` );
				for ( const selector of [ '.newspack-live__header', '.newspack-live__context', '.newspack-live__ask', '.newspack-live__feed' ] ) {
					await expect( page.locator( selector ).first(), `${ selector } on ${ theme }` ).toBeVisible();
				}
				await expect( page.locator( '.newspack-live-entry', { hasText: 'Turnout update' } ) ).toBeVisible();
				await expect( page.getByLabel( 'Your question' ) ).toBeVisible();
				await expectNoHorizontalScroll( page );
				expect( errors ).toEqual( [] );
				// Contrast holds on every tested theme.
				const contrast = await new AxeBuilder( { page } )
					.include( '.newspack-live' )
					.withRules( [ 'color-contrast', 'link-in-text-block' ] )
					.analyze();
				expect(
					contrast.violations.flatMap( violation => violation.nodes.map( node => `${ violation.id }: ${ node.target.join( ' ' ) }` ) )
				).toEqual( [] );

				await page.setViewportSize( { width: 390, height: 844 } );
				errors = await openLive( page, live );
				for ( const selector of [ '.newspack-live__header', '.newspack-live__context', '.newspack-live__feed' ] ) {
					await expect( page.locator( selector ).first(), `${ selector } on ${ theme }` ).toBeVisible();
				}
				// Collapsible sections, closed by default on mobile.
				for ( const name of [ 'Context', 'Ask the newsroom a question' ] ) {
					const toggle = page.getByRole( 'button', { name } );
					await expect( toggle ).toBeVisible();
					await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
				}
				await expectNoHorizontalScroll( page );
				expect( errors ).toEqual( [] );
			} finally {
				wp( 'theme', 'activate', DEFAULT_THEME );
			}
		} );
	}

	// The aside sticks at ≥ 1024 px, below the admin bar and the theme's sticky header,
	// and only while it fits in the viewport.
	for ( const theme of [ 'newspack-theme', 'newspack-joseph', 'newspack-block-theme' ] ) {
		test( `the aside stays in view after a scroll at 1280 px on ${ theme }; a taller one scrolls`, async ( { page } ) => {
			const sticky = createLive( { title: 'E2E sticky aside', category: 'politics' } );
			for ( let i = 0; i < 15; i++ ) {
				addEntry( sticky, {
					title: `Long update ${ i + 1 }`,
					content: `<p>${ 'Counting continues across the county as precincts report their results. '.repeat( 8 ) }</p>`,
					time: `-${ 60 - i } minutes`,
				} );
			}
			try {
				wp( 'theme', 'activate', theme );
				await page.setViewportSize( { width: 1280, height: 900 } );
				await page.goto( permalink( sticky ) );
				const aside = page.locator( '.newspack-live__aside' );
				await expect( aside ).toBeVisible();
				await expect( aside ).toHaveClass( /newspack-live__aside--sticky/ );
				await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight * 0.6 ) );
				await expect( aside ).toBeInViewport();
				// Not under the theme's sticky header.
				const headerBottom = await page.evaluate( () => {
					const header = Array.from(
						document.querySelectorAll( '.site-header, .wp-site-blocks > header, header.wp-block-template-part' )
					).find( element => [ 'sticky', 'fixed' ].includes( window.getComputedStyle( element ).position ) );
					return header ? header.getBoundingClientRect().bottom : 0;
				} );
				expect( ( await aside.boundingBox() )?.y ?? 0 ).toBeGreaterThanOrEqual( headerBottom - 1 );

				// Taller than the viewport: not sticky, it scrolls with the page.
				await page.setViewportSize( { width: 1280, height: 320 } );
				await expect( aside ).not.toHaveClass( /newspack-live__aside--sticky/ );
				expect( await aside.evaluate( element => window.getComputedStyle( element ).position ) ).not.toBe( 'sticky' );
			} finally {
				wp( 'theme', 'activate', DEFAULT_THEME );
			}
		} );
	}
} );
