/**
 * Yoast SEO path of the SEO integration test (`@yoast`): run with `npm run test:e2e:yoast` while Yoast SEO is active.
 * Excluded from the default run, because the sandbox doesn't keep Yoast installed.
 */

/**
 * External dependencies
 */
import { test, expect, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import { addEntry, cleanE2E, createLive, permalink, setState } from '../utils/live';
import { wp } from '../utils/wp-cli';

/**
 * The `LiveBlogPosting` piece of Yoast's schema graph.
 *
 * @param page Page.
 * @return Piece.
 */
async function yoastLiveBlog( page: Page ): Promise< Record< string, unknown > > {
	const graph = JSON.parse( ( await page.locator( 'script.yoast-schema-graph' ).textContent() ) ?? '{}' )[ '@graph' ] as Array<
		Record< string, unknown >
	>;
	return graph.find( piece => piece[ '@type' ] === 'LiveBlogPosting' ) ?? {};
}

const withQuery = ( url: string, query: string ) => `${ url }${ url.includes( '?' ) ? '&' : '?' }${ query }`;

test.describe( 'Yoast SEO @yoast', () => {
	test.beforeAll( () => {
		expect( wp( 'plugin', 'is-active', 'wordpress-seo' ), 'Yoast SEO must be active' ).toBe( '' );
	} );
	test.afterAll( () => cleanE2E() );

	test( 'with Yoast: LiveBlogPosting with coverage and updates, canonical, entry Open Graph', async ( { page } ) => {
		const live = createLive( { title: 'E2E Yoast live' } );
		const first = addEntry( live, { title: 'Polls open', time: '-20 minutes' } );
		const second = addEntry( live, { title: 'Turnout is high', time: '-10 minutes' } );
		await page.goto( permalink( live ) );

		// Yoast prints the graph; the plugin adds its pieces to it instead of a second JSON-LD block.
		await expect( page.locator( 'script.newspack-live-schema' ) ).toHaveCount( 0 );
		const piece = await yoastLiveBlog( page );
		expect( piece.coverageStartTime ).toBeTruthy();
		expect( piece.coverageEndTime ).toBeUndefined();
		expect( ( piece.liveBlogUpdate as Array< { headline: string } > ).map( update => update.headline ) ).toEqual( [
			'Turnout is high',
			'Polls open',
		] );
		await expect( page.locator( 'link[rel="canonical"]' ) ).toHaveAttribute( 'href', permalink( live ) );

		await page.goto( withQuery( permalink( live ), `live-before=${ second }` ) );
		await expect( page.locator( 'link[rel="canonical"]' ) ).toHaveAttribute( 'href', withQuery( permalink( live ), `live-before=${ second }` ) );

		await page.goto( withQuery( permalink( live ), `live-entry=${ first }` ) );
		await expect( page.locator( 'link[rel="canonical"]' ) ).toHaveAttribute( 'href', permalink( live ) );
		await expect( page.locator( 'meta[property="og:title"]' ) ).toHaveAttribute( 'content', 'Polls open' );
		await expect( page.locator( 'meta[property="og:url"]' ) ).toHaveAttribute( 'content', new RegExp( `live-entry=${ first }` ) );
		await expect( page.locator( 'meta[property="og:title"]' ) ).toHaveCount( 1 );

		setState( live, 'end' );
		await page.goto( permalink( live ) );
		expect( ( await yoastLiveBlog( page ) ).coverageEndTime ).toBeTruthy();
	} );
} );
