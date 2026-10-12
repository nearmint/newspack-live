<?php
/**
 * Newspack Collections as a related-coverage source.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Integrations\Collections;
use Newspack_Live\Related;
use WP_UnitTestCase;

/**
 * Collection source: up to 4 of its posts, never the live itself nor another live; hidden and
 * replaced by the primary category while the module is inactive. Collections is stubbed in
 * tests/mocks/newspack-collections.php.
 *
 * @group integrations
 */
class Test_Collections extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Turns the module off again.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['newspack_live_test_collections'] );
		wp_cache_flush();
		parent::tear_down();
	}

	/**
	 * A published Collection with its linked term.
	 *
	 * @param string $status Collection status.
	 * @return array{0: int, 1: int} Collection ID and term ID.
	 */
	private function collection( string $status = 'publish' ): array {
		$collection = self::factory()->post->create(
			[
				'post_type'   => 'newspack_collection',
				'post_status' => $status,
				'post_title'  => 'Election guide',
			]
		);
		$term       = self::factory()->term->create( [ 'taxonomy' => 'newspack_collection_taxonomy' ] );
		update_post_meta( $collection, '_newspack_collection_term_id', $term );
		return [ $collection, $term ];
	}

	/**
	 * The aside lists up to 4 posts of the Collection, newest first, never the live or another live.
	 */
	public function test_collection_source(): void {
		$GLOBALS['newspack_live_test_collections'] = true;
		[ $collection, $term ] = $this->collection();
		$category = self::factory()->category->create();
		$live     = $this->create_live(
			[ 'post_category' => [ $category ] ],
			[
				'_newspack_live_related_source'     => 'collection',
				'_newspack_live_related_collection' => $collection,
			]
		);
		wp_set_object_terms( $live, [ $term ], 'newspack_collection_taxonomy' );
		$other_live = $this->create_live();
		wp_set_object_terms( $other_live, [ $term ], 'newspack_collection_taxonomy' );
		$posts = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$posts[] = self::factory()->post->create( [ 'post_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS + $i * 60 ) ] );
			wp_set_object_terms( end( $posts ), [ $term ], 'newspack_collection_taxonomy' );
		}
		self::factory()->post->create( [ 'post_category' => [ $category ] ] );

		$related = Related::get( $live );
		$this->assertSame( array_slice( array_reverse( $posts ), 0, 4 ), $related );
		$this->assertNotContains( $live, $related );
		$this->assertNotContains( $other_live, $related );
		$this->assertSame( [ $collection => 'Election guide' ], Collections::choices() );
		[ $draft ] = $this->collection( 'draft' );
		$this->assertSame( 'Election guide (not published)', Collections::choices( $draft )[ $draft ], 'The saved Collection is always offered.' );
		$this->assertArrayNotHasKey( $live, Collections::choices( $live ), 'Not a Collection.' );

		// Rendered in the aside.
		$html = \Newspack_Live\Renderer::render_live( $live );
		$this->assertStringContainsString( get_the_title( $posts[5] ), $html );
		$this->assertStringNotContainsString( get_permalink( $collection ), $html );
	}

	/**
	 * Module inactive: no choices, and a Collection source falls back to the primary category;
	 * an unpublished or unlinked Collection lists nothing.
	 */
	public function test_inactive_and_invalid(): void {
		[ $collection, $term ] = $this->collection();
		$category = self::factory()->category->create();
		$in_cat   = self::factory()->post->create( [ 'post_category' => [ $category ] ] );
		$tagged   = self::factory()->post->create();
		wp_set_object_terms( $tagged, [ $term ], 'newspack_collection_taxonomy' );
		$live = $this->create_live(
			[ 'post_category' => [ $category ] ],
			[
				'_newspack_live_related_source'     => 'collection',
				'_newspack_live_related_collection' => $collection,
			]
		);

		$this->assertSame( [], Collections::choices() );
		$this->assertSame( [ $in_cat ], Related::get( $live ), 'Falls back to the primary category.' );

		$GLOBALS['newspack_live_test_collections'] = true;
		wp_cache_flush();
		$this->assertSame( [ $tagged ], Related::get( $live ) );

		[ $draft ] = $this->collection( 'draft' );
		update_post_meta( $live, '_newspack_live_related_collection', $draft );
		$this->assertSame( [], Related::get( $live ), 'An unpublished Collection lists nothing.' );
		update_post_meta( $live, '_newspack_live_related_collection', self::factory()->post->create() );
		$this->assertSame( [], Related::get( $live ), 'Not a Collection.' );
		$this->assertSame( 'collection', \Newspack_Live\Live::get_settings( $live )['related_source'] );
	}
}
