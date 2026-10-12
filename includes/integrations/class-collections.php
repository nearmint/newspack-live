<?php
/**
 * Newspack Collections as a related-coverage source: the aside lists up to 4 posts
 * of the chosen Collection, excluding the live itself.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Collections.
 */
final class Collections {

	/**
	 * Most Collections offered in the Live coverage panel (newest first).
	 */
	public const PICKER_LIMIT = 100;

	/**
	 * Whether the Newspack Collections module is installed and active.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( '\Newspack\Optional_Modules\Collections' )
			&& class_exists( '\Newspack\Collections\Post_Type' )
			&& class_exists( '\Newspack\Collections\Collection_Taxonomy' )
			&& class_exists( '\Newspack\Collections\Sync' )
			&& \Newspack\Optional_Modules\Collections::is_module_active();
	}

	/**
	 * The collections taxonomy (posts ↔ collections).
	 *
	 * @return string
	 */
	public static function taxonomy(): string {
		return self::available() ? (string) \Newspack\Collections\Collection_Taxonomy::get_taxonomy() : '';
	}

	/**
	 * Published Collections for the panel picker (ID => title), newest first, plus the Collection a
	 * live already uses when it isn't among them (older, or no longer published: marked as such).
	 *
	 * @param int $current The live's saved Collection (0: none).
	 * @return array<int, string>
	 */
	public static function choices( int $current = 0 ): array {
		if ( ! self::available() ) {
			return [];
		}
		$posts = get_posts(
			[
				'post_type'              => \Newspack\Collections\Post_Type::get_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => self::PICKER_LIMIT,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);
		$choices = [];
		foreach ( $posts as $post ) {
			$choices[ (int) $post->ID ] = self::label( $post );
		}
		$saved = $current > 0 && ! isset( $choices[ $current ] ) ? get_post( $current ) : null;
		if ( $saved && \Newspack\Collections\Post_Type::get_post_type() === $saved->post_type ) {
			$choices[ $current ] = 'publish' === $saved->post_status
				? self::label( $saved )
				/* translators: %s: Collection title. */
				: sprintf( __( '%s (not published)', 'newspack-live' ), self::label( $saved ) );
		}
		return $choices;
	}

	/**
	 * A Collection's title as stored (no theme filters, no "Protected:" prefix).
	 *
	 * @param \WP_Post $post Collection.
	 * @return string
	 */
	private static function label( \WP_Post $post ): string {
		$title = html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' );
		/* translators: %d: Collection ID. */
		return '' !== trim( $title ) ? $title : sprintf( __( 'Collection #%d', 'newspack-live' ), (int) $post->ID );
	}

	/**
	 * The term that tags the posts of a published Collection, or null.
	 *
	 * @param int $collection_id Collection ID.
	 * @return \WP_Term|null
	 */
	public static function term( int $collection_id ): ?\WP_Term {
		if ( ! self::available() || $collection_id <= 0 ) {
			return null;
		}
		$collection = get_post( $collection_id );
		if ( ! $collection || \Newspack\Collections\Post_Type::get_post_type() !== $collection->post_type || 'publish' !== $collection->post_status ) {
			return null;
		}
		$term_id = (int) \Newspack\Collections\Sync::get_term_linked_to_collection( $collection_id );
		$term    = $term_id ? get_term( $term_id, self::taxonomy() ) : null;
		return $term instanceof \WP_Term ? $term : null;
	}
}
