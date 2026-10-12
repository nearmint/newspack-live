/**
 * Co-Authors Plus byline, SEO, ads and homepage badge.
 */

/**
 * External dependencies
 */
import { test, expect } from '@playwright/test';

/**
 * Internal dependencies
 */
import { BASE_URL } from '../utils/env';
import { addEntry, cleanE2E, createLive, permalink, setMeta, setState } from '../utils/live';
import { wp } from '../utils/wp-cli';

/**
 * Sets an e2e Guest Contributor (Newspack's replacement for CAP guest authors, role
 * `contributor_no_edit`) as the second host of a live. The user is created once and kept, like the
 * other e2e users.
 *
 * @param liveId Live ID.
 */
function addGuestHost( liveId: number ): void {
	wp(
		'eval',
		`global $coauthors_plus;
		$guest = get_user_by( 'login', 'nl-e2e-guest' );
		if ( ! $guest ) {
			$id = wp_insert_user( [ 'user_login' => 'nl-e2e-guest', 'user_pass' => wp_generate_password( 24 ), 'display_name' => 'Guest Reporter', 'role' => 'contributor_no_edit' ] );
			update_user_meta( $id, '_newspack_live_e2e', 1 );
			$guest = get_userdata( $id );
		}
		$author = get_userdata( (int) get_post_field( 'post_author', ${ liveId } ) );
		$coauthors_plus->add_coauthors( ${ liveId }, [ $author->user_nicename, $guest->user_nicename ] );`
	);
}

test.describe( 'integrations', () => {
	test.afterAll( () => cleanE2E() );

	test( 'the byline lists the Co-Authors Plus hosts, Newspack guest contributors included', async ( { page } ) => {
		const live = createLive( { title: 'E2E co-hosts', author: 'nl-e2e-editor' } );
		addGuestHost( live );
		await page.goto( permalink( live ) );
		const byline = page.locator( '.newspack-live__byline' );
		await expect( byline ).toContainText( 'Live hosted by' );
		await expect( byline ).toContainText( 'Guest Reporter' );
	} );

	test( 'JSON-LD LiveBlogPosting, canonical and entry Open Graph', async ( { page } ) => {
		const live = createLive( { title: 'E2E schema' } );
		const first = addEntry( live, { title: 'Polls open', time: '-20 minutes' } );
		addEntry( live, { title: 'Turnout is high', time: '-10 minutes' } );
		await page.goto( permalink( live ) );

		const json = JSON.parse( ( await page.locator( 'script.newspack-live-schema' ).textContent() ) ?? '{}' );
		expect( json[ '@type' ] ).toBe( 'LiveBlogPosting' );
		expect( json.headline ).toBe( 'E2E schema' );
		expect( json.coverageStartTime ).toBeTruthy();
		expect( json.coverageEndTime ).toBeUndefined();
		expect( json.liveBlogUpdate.map( ( update: { headline: string } ) => update.headline ) ).toEqual( [ 'Turnout is high', 'Polls open' ] );
		await expect( page.locator( 'link[rel="canonical"]' ) ).toHaveAttribute( 'href', permalink( live ) );

		await page.goto( `${ permalink( live ) }${ permalink( live ).includes( '?' ) ? '&' : '?' }live-entry=${ first }` );
		await expect( page.locator( 'meta[property="og:title"]' ).last() ).toHaveAttribute( 'content', 'Polls open' );
		await expect( page.locator( 'link[rel="canonical"]' ) ).toHaveAttribute( 'href', permalink( live ) );

		setState( live, 'end' );
		await page.goto( permalink( live ) );
		const ended = JSON.parse( ( await page.locator( 'script.newspack-live-schema' ).textContent() ) ?? '{}' );
		expect( ended.coverageEndTime ).toBeTruthy();
	} );

	test( 'the feed holds editorial entries only, on load and in older pages; the aside keeps its ad', async ( { page } ) => {
		const live = createLive( { title: 'E2E editorial feed' } );
		for ( let i = 1; i <= 17; i++ ) {
			addEntry( live, { title: `Update ${ i }`, time: `-${ 100 - i } minutes` } );
		}
		await page.goto( permalink( live ) );
		const entries = page.locator( '.newspack-live__entries' );
		await expect( entries.locator( '> .newspack-live-entry' ) ).toHaveCount( 15 );
		await expect( entries.locator( '> :not(.newspack-live-entry)' ) ).toHaveCount( 0 );
		await expect( page.locator( '.newspack-live__feed .newspack-live__ad, .newspack-live__feed .newspack-live__prompt' ) ).toHaveCount( 0 );
		await expect( page.locator( '.newspack-live__aside .newspack-live__ad' ) ).toHaveCount( 1 );

		await page.getByRole( 'link', { name: 'Load older updates' } ).click();
		await expect( entries.locator( '> .newspack-live-entry' ) ).toHaveCount( 17 );
		await expect( entries.locator( '> :not(.newspack-live-entry)' ) ).toHaveCount( 0 );

		// Newspack Ads' "Hide all ads" post setting.
		setMeta( live, 'newspack_ads_suppress_ads', 1 );
		await page.reload();
		await expect( page.locator( '.newspack-live-entry' ).first() ).toBeVisible();
		await expect( page.locator( '.newspack-live__ad' ) ).toHaveCount( 0 );
	} );

	test( 'the homepage shows "● Live" while the live is live, "Live ended" after it ends', async ( { page } ) => {
		const live = createLive( { title: 'E2E homepage badge', category: 'politics' } );
		addEntry( live, { title: 'First update' } );
		await page.goto( BASE_URL );
		const card = page
			.locator( 'article:not(:has(article))' )
			.filter( { has: page.getByRole( 'link', { name: 'E2E homepage badge' } ) } )
			.first();
		await expect( card.locator( '.newspack-live-badge--live' ) ).toHaveText( /Live/ );

		setState( live, 'end' );
		await page.reload();
		await expect( card.locator( '.newspack-live-badge--ended' ) ).toHaveText( 'Live ended' );
	} );
} );
