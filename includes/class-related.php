<?php
/**
 * Related coverage.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Related coverage of a live: primary category, tag, manual selection or a
 * Newspack Collection (the primary category while the Collections module is inactive).
 */
final class Related {

	/**
	 * Number of related posts shown.
	 */
	public const COUNT = 4;

	/**
	 * Primary category of a post: Newspack's primary category when enabled, else the first category.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Term|null
	 */
	public static function get_primary_category( int $post_id ): ?\WP_Term {
		if ( class_exists( '\Newspack\Primary_Category' ) && method_exists( '\Newspack\Primary_Category', 'get' ) ) {
			$term = \Newspack\Primary_Category::get( $post_id );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		$terms = get_the_category( $post_id );
		return $terms ? $terms[0] : null;
	}

	/**
	 * Related post IDs.
	 *
	 * @param int $live_id Live ID.
	 * @return int[]
	 */
	public static function get( int $live_id ): array {
		$settings = Live::get_settings( $live_id );
		$source   = $settings['related_source'];

		if ( 'manual' === $source ) {
			$ids = array_values(
				array_filter(
					$settings['related_posts'],
					static fn( int $id ) => $id !== $live_id && 'publish' === get_post_status( $id )
				)
			);
			return array_slice( $ids, 0, self::COUNT );
		}

		if ( 'collection' === $source && Integrations\Collections::available() ) {
			$collection = (int) $settings['related_collection'];
			$term       = Integrations\Collections::term( $collection );
			if ( ! $term instanceof \WP_Term ) {
				return [];
			}
			$key = sprintf( 'related_%d_collection_%d', $live_id, $term->term_id );
			$ids = wp_cache_get( $key, Renderer::CACHE_GROUP );
			if ( ! is_array( $ids ) ) {
				$ids = self::query( $live_id, $term->taxonomy, $term->term_id );
				wp_cache_set( $key, $ids, Renderer::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			}
			return $ids;
		}

		if ( 'tag' === $source ) {
			$term     = $settings['related_tag'] > 0 ? get_term( $settings['related_tag'], 'post_tag' ) : null;
			$taxonomy = 'post_tag';
		} else {
			// Primary category (also the fallback of a Collection source while the module is inactive).
			$term     = self::get_primary_category( $live_id );
			$taxonomy = 'category';
		}
		if ( ! $term instanceof \WP_Term ) {
			return [];
		}

		$key = sprintf( 'related_%d_%s_%d', $live_id, $taxonomy, $term->term_id );
		$ids = wp_cache_get( $key, Renderer::CACHE_GROUP );
		if ( ! is_array( $ids ) ) {
			$ids = self::query( $live_id, $taxonomy, $term->term_id );
			wp_cache_set( $key, $ids, Renderer::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		}
		return $ids;
	}

	/**
	 * Latest published posts of a term, excluding lives and the current post.
	 *
	 * Lives are filtered in PHP from a slightly larger page instead of a meta query across all posts.
	 *
	 * @param int    $live_id  Live ID.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return int[]
	 */
	private static function query( int $live_id, string $taxonomy, int $term_id ): array {
		$candidates = get_posts(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => self::COUNT * 3,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => false,
				'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- single term, bounded.
					[
						'taxonomy'         => $taxonomy,
						'terms'            => [ $term_id ],
						'include_children' => false,
					],
				],
			]
		);
		if ( $candidates ) {
			update_meta_cache( 'post', array_map( 'intval', $candidates ) );
		}
		$ids = [];
		foreach ( $candidates as $id ) {
			$id = (int) $id;
			if ( $id === $live_id || Live::is_live( $id ) ) {
				continue;
			}
			$ids[] = $id;
			if ( count( $ids ) >= self::COUNT ) {
				break;
			}
		}
		return $ids;
	}
}
