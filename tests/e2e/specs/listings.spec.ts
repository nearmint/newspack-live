/**
 * Lives listed like articles, with a state badge before their title: classic theme
 * archives and search results, Homepage Posts, block theme Query Loops; `the_title` unchanged.
 */

/**
 * External dependencies
 */
import { test, expect, type Locator, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { BASE_URL } from '../utils/env';
import { addEntry, cleanE2E, createLive } from '../utils/live';
import { wp } from '../utils/wp-cli';

const DEFAULT_THEME = 'newspack-theme';

const BADGES = {
	live: { title: 'E2E listing live', className: 'newspack-live-badge--live', text: 'Live' },
	upcoming: { title: 'E2E listing upcoming', className: 'newspack-live-badge--upcoming', text: 'Upcoming live' },
	ended: { title: 'E2E listing ended', className: 'newspack-live-badge--ended', text: 'Live ended' },
};

/**
 * The listing item (article) linking to a title.
 *
 * @param page  Page.
 * @param title Linked title.
 * @return Locator.
 */
const item = ( page: Page, title: string ): Locator =>
	page
		.locator( 'article:not(:has(article)), li.wp-block-post' )
		.filter( { has: page.getByRole( 'link', { name: title, exact: true } ) } )
		.first();

/**
 * Checks the three badges on a listing page, and their contrast.
 *
 * @param page Page.
 */
async function expectBadges( page: Page ): Promise< void > {
	for ( const { title, className, text } of Object.values( BADGES ) ) {
		const badge = item( page, title ).locator( `.${ className }` );
		await expect( badge, title ).toHaveText( text );
		await expect( badge ).toBeVisible();
	}
	const contrast = await new AxeBuilder( { page } ).include( '.newspack-live-badge' ).withRules( [ 'color-contrast' ] ).analyze();
	expect( contrast.violations.flatMap( violation => violation.nodes.map( node => node.target.join( ' ' ) ) ) ).toEqual( [] );
}

test.describe( 'listings @listings', () => {
	test.describe.configure( { timeout: 120_000 } );
	const ids: Record< string, number > = {};
	let archive = '';

	test.beforeAll( () => {
		ids.live = createLive( { title: BADGES.live.title, category: 'politics' } );
		addEntry( ids.live, { title: 'First update' } );
		ids.upcoming = createLive( { title: BADGES.upcoming.title, category: 'politics', state: 'upcoming' } );
		ids.ended = createLive( { title: BADGES.ended.title, category: 'politics', state: 'ended' } );
		archive = wp( 'term', 'get', 'category', 'politics', '--by=slug', '--field=url' );
	} );

	test.afterAll( () => {
		wp( 'theme', 'activate', DEFAULT_THEME );
		cleanE2E();
	} );

	test( 'classic theme: category archive and search results; titles stay plain elsewhere', async ( { page, request } ) => {
		await page.goto( archive );
		const card = item( page, BADGES.live.title );
		// The badge opens the category line, so it follows the site's choice to show categories on
		// archives (Jetpack Content Options; off on the sandbox): in the markup either way.
		if ( await card.locator( '.cat-links' ).isVisible() ) {
			await expectBadges( page );
			// Before the title: in the category line right above it.
			const [ badge, title ] = [
				await card.locator( '.newspack-live-badge' ).boundingBox(),
				await card.locator( '.entry-title' ).boundingBox(),
			];
			expect( badge?.y ?? 0 ).toBeLessThan( title?.y ?? 0 );
		} else {
			for ( const { title, className, text } of Object.values( BADGES ) ) {
				await expect( item( page, title ).locator( `.cat-links .${ className }` ), title ).toHaveText( text );
			}
		}
		// No plugin stylesheet or script on a listing (self-styled badges).
		expect( await page.locator( 'link[href*="/plugins/newspack-live/"], script[src*="/plugins/newspack-live/"]' ).count() ).toBe( 0 );

		await page.goto( `${ BASE_URL }/?s=${ encodeURIComponent( 'E2E listing' ) }` );
		await expectBadges( page );

		// `the_title` is never filtered: feeds and REST keep the plain title.
		const feed = await ( await request.get( `${ archive.replace( /\/$/, '' ) }/feed/` ) ).text();
		expect( feed ).toContain( `<title>${ BADGES.ended.title }</title>` );
		expect( feed ).not.toContain( 'newspack-live-badge' );
		const rest = await ( await request.get( `${ BASE_URL }/wp-json/wp/v2/posts/${ ids.live }` ) ).json();
		expect( rest.title.rendered ).toBe( BADGES.live.title );
	} );

	test( 'Homepage Posts block', async ( { page } ) => {
		const teasers = wp(
			'post',
			'create',
			'--post_type=page',
			'--post_status=publish',
			'--post_title=E2E listing teasers',
			`--post_content=<!-- wp:newspack-blocks/homepage-articles {"specificMode":true,"specificPosts":[${ ids.live },${ ids.upcoming },${ ids.ended }]} /-->`,
			'--meta_input={"_newspack_live_e2e":1}',
			'--porcelain'
		);
		await page.goto( wp( 'post', 'url', teasers ) );
		await expectBadges( page );
	} );

	test( 'block theme: the post title of Query Loops', async ( { page } ) => {
		try {
			wp( 'theme', 'activate', 'newspack-block-theme' );
			await page.goto( archive );
			expect( await page.content() ).toContain( '/themes/newspack-block-theme/' );
			await expectBadges( page );
			// Inside the title heading, before the link.
			await expect( item( page, BADGES.live.title ).locator( '.wp-block-post-title > .newspack-live-badge + a' ) ).toHaveText(
				BADGES.live.title
			);
		} finally {
			wp( 'theme', 'activate', DEFAULT_THEME );
		}
	} );
} );
