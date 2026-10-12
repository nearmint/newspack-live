<?php
/**
 * Newspack Ads (aside only) and SEO tests; the badges are in Test_Listings.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Integrations\Homepage_Posts;
use Newspack_Live\Integrations\Newspack_Ads;
use Newspack_Live\Live;
use Newspack_Live\Renderer;
use Newspack_Live\SEO;
use WP_UnitTestCase;

/**
 * Integrations and SEO.
 *
 * @group seo
 */
class Test_Ads_Seo extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Live ID.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Entry IDs, oldest first.
	 *
	 * @var int[]
	 */
	private array $ids = [];

	/**
	 * Creates a live with 12 entries.
	 */
	public function set_up(): void {
		parent::set_up();
		Feed::flush();
		$editor = $this->create_user( 'editor' );
		$this->live_id = $this->create_live( [ 'post_author' => $editor ] );
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->ids[] = Entries::save(
				[
					'live_id' => $this->live_id,
					'title'   => "Update $i",
					'author'  => $editor,
					'time'    => time() - ( 100 - $i ) * 60,
				]
			);
		}
		add_filter( 'newspack_live_ad_html', [ $this, 'fake_ad' ], 10, 2 );
	}

	/**
	 * Removes the fake ad.
	 */
	public function tear_down(): void {
		remove_filter( 'newspack_live_ad_html', [ $this, 'fake_ad' ], 10 );
		parent::tear_down();
	}

	/**
	 * Fake ad in empty slots.
	 *
	 * @param string $html       HTML.
	 * @param int    $slot_index Slot index.
	 * @return string
	 */
	public function fake_ad( $html, $slot_index ) {
		return '<div class="nl-test-ad">Ad ' . (int) $slot_index . '</div>';
	}

	/**
	 * The feed holds editorial entries only: no ad slot between entries, on the page or in REST
	 * pages; the aside keeps its ad.
	 */
	public function test_feed_has_no_ad_slots(): void {
		$html = Renderer::render_live( $this->live_id );
		$this->assertStringNotContainsString( 'data-after=', $html );
		$this->assertSame( 1, substr_count( $html, 'nl-test-ad' ) );
		$this->assertStringContainsString( 'newspack-live__ad--aside', $html );

		$request = new \WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $this->live_id . '/entries' );
		$request->set_query_params(
			[
				'before'   => $this->ids[7],
				'per_page' => 10,
			]
		);
		$data = rest_do_request( $request )->get_data();
		$this->assertArrayNotHasKey( 'ads', $data );
		$this->assertCount( 7, $data['entries'] );
	}

	/**
	 * "Hide all ads" and Newspack Ads' per-post placement suppression remove the aside ad.
	 */
	public function test_aside_suppression(): void {
		update_post_meta( $this->live_id, 'newspack_ads_suppress_ads', 1 );
		$this->assertStringNotContainsString( 'nl-test-ad', Renderer::render_live( $this->live_id ) );
		delete_post_meta( $this->live_id, 'newspack_ads_suppress_ads' );
		update_post_meta( $this->live_id, 'newspack_ads_suppress_ads_placements', [ Newspack_Ads::ASIDE_PLACEMENT ] );
		$this->assertStringNotContainsString( 'nl-test-ad', Renderer::render_live( $this->live_id ) );
	}

	/**
	 * JSON-LD shape, escaping, coverage times.
	 */
	public function test_schema(): void {
		wp_update_post(
			[
				'ID'         => $this->live_id,
				'post_title' => 'Results </script><script>alert(1)</script>',
			]
		);
		$schema = SEO::schema( $this->live_id );
		$this->assertSame( 'LiveBlogPosting', $schema['@type'] );
		$this->assertArrayHasKey( 'coverageStartTime', $schema );
		$this->assertArrayNotHasKey( 'coverageEndTime', $schema );
		$this->assertCount( 12, $schema['liveBlogUpdate'] );
		$this->assertSame( 'Update 12', $schema['liveBlogUpdate'][0]['headline'] );
		$this->assertStringContainsString( 'live-entry=' . $this->ids[11], $schema['liveBlogUpdate'][0]['url'] );

		$this->go_to( get_permalink( $this->live_id ) );
		ob_start();
		SEO::print_head();
		$head = ob_get_clean();
		$this->assertStringNotContainsString( '</script><script>', substr( $head, 0, -10 ) );
		$json = preg_replace( '#^<script[^>]*>|</script>\s*$#', '', trim( $head ) );
		$this->assertIsArray( json_decode( $json, true ) );

		Live::apply_action( $this->live_id, 'end' );
		$this->assertArrayHasKey( 'coverageEndTime', SEO::schema( $this->live_id ) );
	}

	/**
	 * Canonical: cursor pages are self-referencing; entry pages point to the live.
	 */
	public function test_canonical_and_open_graph(): void {
		$this->go_to( add_query_arg( 'live-before', $this->ids[5], get_permalink( $this->live_id ) ) );
		$this->assertStringContainsString( 'live-before=' . $this->ids[5], (string) wp_get_canonical_url( $this->live_id ) );

		$this->go_to( add_query_arg( 'live-entry', $this->ids[3], get_permalink( $this->live_id ) ) );
		$this->assertSame( get_permalink( $this->live_id ), wp_get_canonical_url( $this->live_id ) );
		ob_start();
		SEO::print_head();
		$head = ob_get_clean();
		$this->assertStringContainsString( '<meta property="og:title" content="Update 4" />', $head );
		$this->assertStringContainsString( 'name="twitter:title"', $head );
	}

	/**
	 * SEO output can be disabled.
	 */
	public function test_seo_filter(): void {
		add_filter( 'newspack_live_seo_enabled', '__return_false' );
		$this->go_to( get_permalink( $this->live_id ) );
		ob_start();
		SEO::print_head();
		$this->assertSame( '', ob_get_clean() );
		remove_filter( 'newspack_live_seo_enabled', '__return_false' );
	}

	/**
	 * The live's modified date moves on updates, throttled to once a minute.
	 */
	public function test_modified_bump(): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2020-01-01 00:00:00' ], [ 'ID' => $this->live_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
		clean_post_cache( $this->live_id );
		delete_post_meta( $this->live_id, SEO::META_BUMPED_AT );
		SEO::bump_modified( $this->live_id );
		$this->assertGreaterThan( '2020-01-01 00:00:00', get_post_field( 'post_modified_gmt', $this->live_id ) );

		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2020-01-01 00:00:00' ], [ 'ID' => $this->live_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test setup.
		clean_post_cache( $this->live_id );
		SEO::bump_modified( $this->live_id );
		$this->assertSame( '2020-01-01 00:00:00', get_post_field( 'post_modified_gmt', $this->live_id ), 'Throttled.' );
	}

	/**
	 * Updates published in the same second are counted by ID, like the feed orders them.
	 */
	public function test_same_second_numbering(): void {
		$same = gmdate( 'Y-m-d H:i:s', time() - 30 );
		foreach ( [ $this->ids[10], $this->ids[11] ] as $id ) {
			wp_update_post(
				[
					'ID'            => $id,
					'post_date_gmt' => $same,
					'post_date'     => get_date_from_gmt( $same ),
				]
			);
		}
		Feed::flush();
		$this->assertSame( 10, Feed::count_older( $this->live_id, $this->ids[10] ) );
		$this->assertSame( 11, Feed::count_older( $this->live_id, $this->ids[11] ) );
	}

	/**
	 * Gated lives expose no update content in JSON-LD or entry Open Graph tags.
	 */
	public function test_gated_seo(): void {
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->go_to( add_query_arg( 'live-entry', $this->ids[3], get_permalink( $this->live_id ) ) );
		ob_start();
		SEO::print_head();
		$head = ob_get_clean();
		remove_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->assertStringNotContainsString( 'og:title', $head );
		$this->assertStringNotContainsString( 'liveBlogUpdate', $head );
		$this->assertStringNotContainsString( 'Update 4', $head );
	}

	/**
	 * Canonical follows the rendered view: an entry permalink wins over a cursor.
	 */
	public function test_canonical_follows_view(): void {
		$url = add_query_arg(
			[
				'live-entry'  => $this->ids[3],
				'live-before' => $this->ids[5],
			],
			get_permalink( $this->live_id )
		);
		$this->go_to( $url );
		$this->assertSame( get_permalink( $this->live_id ), wp_get_canonical_url( $this->live_id ) );
		$this->go_to( add_query_arg( 'live-before', $this->ids[5], get_permalink( $this->live_id ) ) );
		$this->assertSame( add_query_arg( 'live-before', $this->ids[5], get_permalink( $this->live_id ) ), wp_get_canonical_url( $this->live_id ), 'The cursor page points to itself.' );
	}

	/**
	 * JSON-LD headline is plain text; entry authors are hidden when the live hides them.
	 */
	public function test_schema_text_and_authors(): void {
		wp_update_post(
			[
				'ID'         => $this->live_id,
				'post_title' => "Voters' night & results",
			]
		);
		$schema = SEO::schema( $this->live_id );
		$this->assertSame( "Voters' night & results", $schema['headline'] );
		$this->assertArrayHasKey( 'author', $schema['liveBlogUpdate'][0] );
		update_post_meta( $this->live_id, '_newspack_live_show_entry_author', 0 );
		$this->assertArrayNotHasKey( 'author', SEO::schema( $this->live_id )['liveBlogUpdate'][0] );
	}

	/**
	 * Entities stored by kses in entry titles are decoded in JSON-LD.
	 */
	public function test_schema_entry_entities(): void {
		wp_update_post(
			[
				'ID'         => $this->ids[11],
				'post_title' => 'Q&amp;A',
			]
		);
		$this->assertSame( 'Q&A', SEO::schema( $this->live_id )['liveBlogUpdate'][0]['headline'] );
	}

	/**
	 * The teaser badge is self-styled; printing it enqueues no plugin stylesheet.
	 */
	public function test_badge_is_self_styled(): void {
		ob_start();
		Homepage_Posts::render_badge( $this->live_id );
		$badge = (string) ob_get_clean();
		$this->assertStringContainsString( 'newspack-live-badge--live" style="', $badge );
		$this->assertStringContainsString( 'background:#c8102e', $badge );
		$this->assertStringStartsWith( '<div class="newspack-live-badge-line" style="margin:0 0 0.5em"><span class="newspack-live-badge', $badge, 'Own line, spaced from the category line.' );
		$this->assertFalse( wp_style_is( 'newspack-live-badge', 'registered' ) );
		$this->assertSame( [], array_values( array_filter( wp_styles()->queue, static fn( $handle ) => str_starts_with( $handle, 'newspack-live' ) ) ) );
	}
}
