/**
 * Collections as related coverage, with the Newspack Collections module
 * enabled in the sandbox.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { cleanE2E, createLive, permalink, setMeta } from '../utils/live';
import { wp } from '../utils/wp-cli';

const MARK = '--meta_input={"_newspack_live_e2e":1}';

/**
 * Creates a marked post in a Collection.
 *
 * @param title Title.
 * @param term  The Collection's term.
 * @param date  Post date.
 * @return Post ID.
 */
function postIn( title: string, term: number, date: string ): number {
	const id = Number( wp( 'post', 'create', '--post_status=publish', `--post_title=${ title }`, `--post_date=${ date }`, MARK, '--porcelain' ) );
	wp( 'post', 'term', 'set', String( id ), 'newspack_collection_taxonomy', String( term ), '--by=id' );
	return id;
}

test.describe( 'collections', () => {
	test.afterAll( () => cleanE2E() );

	test( 'a live with a Collection source lists up to 4 of its posts, never itself', async ( { page } ) => {
		const active = wp( 'eval', 'echo \\Newspack_Live\\Integrations\\Collections::available() ? "yes" : "no";' );
		test.skip( active !== 'yes', 'Newspack Collections is not active in this site.' );

		const collection = Number(
			wp( 'post', 'create', '--post_type=newspack_collection', '--post_status=publish', '--post_title=E2E collection', MARK, '--porcelain' )
		);
		const term = Number( wp( 'eval', `echo (int) \\Newspack\\Collections\\Sync::get_term_linked_to_collection( ${ collection } );` ) );
		expect( term ).toBeGreaterThan( 0 );
		const titles = [ 1, 2, 3, 4, 5 ].map( i => `E2E collection story ${ i }` );
		titles.forEach( ( title, i ) => postIn( title, term, `2026-01-0${ i + 1 } 10:00:00` ) );

		const live = createLive( { title: 'E2E live in a collection' } );
		wp( 'post', 'term', 'set', String( live ), 'newspack_collection_taxonomy', String( term ), '--by=id' );
		setMeta( live, '_newspack_live_related_source', 'collection' );
		setMeta( live, '_newspack_live_related_collection', collection );

		await page.goto( permalink( live ) );
		const related = page.getByRole( 'region', { name: 'Related coverage' } );
		const items = related.locator( '.newspack-live__related-title' );
		await expect( items ).toHaveText( [ titles[ 4 ], titles[ 3 ], titles[ 2 ], titles[ 1 ] ] );
		await expect( related ).not.toContainText( 'E2E live in a collection' );

		const results = await new AxeBuilder( { page } ).include( '.newspack-live' ).analyze();
		expect( results.violations.filter( violation => [ 'serious', 'critical' ].includes( violation.impact ?? '' ) ) ).toEqual( [] );
	} );
} );
