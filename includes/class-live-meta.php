<?php
/**
 * Live settings meta registration.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the live settings meta that the post editor panel reads and writes.
 *
 * Server-managed keys (state, revision, removal log, …) are deliberately not registered
 * for REST: the block editor would write stale values back on every post save.
 */
final class Live_Meta {

	/**
	 * Allowed auto-end values, in hours (0 = never).
	 */
	public const AUTO_END_HOURS = [ 0, 6, 12, 24, 48 ];

	/**
	 * Allowed question modes.
	 */
	public const QUESTION_MODES = [ 'off', 'anyone', 'registered' ];

	/**
	 * Allowed related coverage sources.
	 */
	public const RELATED_SOURCES = [ 'primary-category', 'tag', 'manual', 'collection' ];

	/**
	 * Maximum number of manual related posts.
	 */
	public const MAX_RELATED_POSTS = 4;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 20 );
		add_action( 'added_post_meta', [ __CLASS__, 'on_enabled_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_enabled_meta' ], 10, 4 );
	}

	/**
	 * Definitions of the editor-managed settings meta.
	 *
	 * @return array<string, array> Meta key => [ type, default, sanitize callback, REST schema ].
	 */
	public static function definitions(): array {
		return [
			'_newspack_live_enabled'            => [
				'type'     => 'boolean',
				'default'  => false,
				'sanitize' => 'rest_sanitize_boolean',
			],
			'_newspack_live_scheduled_start'    => [
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			],
			'_newspack_live_auto_end_hours'     => [
				'type'     => 'integer',
				'default'  => 24,
				'sanitize' => [ __CLASS__, 'sanitize_auto_end_hours' ],
				'schema'   => [
					'type' => 'integer',
					'enum' => self::AUTO_END_HOURS,
				],
			],
			'_newspack_live_team'               => [
				'type'     => 'array',
				'default'  => [],
				'sanitize' => [ __CLASS__, 'sanitize_id_list' ],
				'schema'   => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
			'_newspack_live_questions_mode'     => [
				'type'     => 'string',
				'default'  => 'anyone',
				'sanitize' => [ __CLASS__, 'sanitize_questions_mode' ],
				'schema'   => [
					'type' => 'string',
					'enum' => self::QUESTION_MODES,
				],
			],
			'_newspack_live_question_prompt'    => [
				'type'     => 'string',
				'default'  => '',
				'sanitize' => 'sanitize_text_field',
			],
			'_newspack_live_show_entry_author'  => [
				'type'     => 'boolean',
				'default'  => true,
				'sanitize' => 'rest_sanitize_boolean',
			],
			'_newspack_live_related_source'     => [
				'type'     => 'string',
				'default'  => 'primary-category',
				'sanitize' => [ __CLASS__, 'sanitize_related_source' ],
				'schema'   => [
					'type' => 'string',
					'enum' => self::RELATED_SOURCES,
				],
			],
			'_newspack_live_related_tag'        => [
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			],
			// A Newspack Collection (post ID) as the related source.
			'_newspack_live_related_collection' => [
				'type'     => 'integer',
				'default'  => 0,
				'sanitize' => 'absint',
			],
			'_newspack_live_related_posts'      => [
				'type'     => 'array',
				'default'  => [],
				'sanitize' => [ __CLASS__, 'sanitize_related_posts' ],
				'schema'   => [
					'type'     => 'array',
					'maxItems' => self::MAX_RELATED_POSTS,
					'items'    => [ 'type' => 'integer' ],
				],
			],
		];
	}

	/**
	 * Registers the settings meta on every live-capable post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( Live::post_types() as $post_type ) {
			foreach ( self::definitions() as $key => $definition ) {
				$show_in_rest = true;
				if ( isset( $definition['schema'] ) ) {
					$show_in_rest = [ 'schema' => $definition['schema'] ];
				}
				register_post_meta(
					$post_type,
					$key,
					[
						'type'              => $definition['type'],
						'single'            => true,
						'default'           => $definition['default'],
						'sanitize_callback' => $definition['sanitize'],
						'auth_callback'     => [ __CLASS__, 'auth' ],
						'show_in_rest'      => $show_in_rest,
					]
				);
			}
		}
	}

	/**
	 * Meta auth callback: the user must be able to edit the live post.
	 *
	 * @param bool   $allowed   Whether the user can add the meta. Unused.
	 * @param string $meta_key  Meta key. Unused.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public static function auth( $allowed, $meta_key, $object_id ): bool {
		// Live coverage settings are a managing action: authors and above, on posts they can edit.
		return current_user_can( 'newspack_live_manage' ) && current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Creates the revision counter when live coverage is enabled on a post.
	 *
	 * @param int    $meta_id    Meta ID. Unused.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_enabled_meta( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( '_newspack_live_enabled' !== $meta_key || ! rest_sanitize_boolean( $meta_value ) ) {
			return;
		}
		if ( ! metadata_exists( 'post', (int) $object_id, Live::META_REV ) ) {
			add_post_meta( (int) $object_id, Live::META_REV, 0, true );
		}
	}

	/**
	 * Sanitizes the auto-end hours.
	 *
	 * @param mixed $value Raw value.
	 * @return int One of AUTO_END_HOURS, 24 when invalid.
	 */
	public static function sanitize_auto_end_hours( $value ): int {
		$value = (int) $value;
		return in_array( $value, self::AUTO_END_HOURS, true ) ? $value : 24;
	}

	/**
	 * Sanitizes the question mode.
	 *
	 * @param mixed $value Raw value.
	 * @return string One of QUESTION_MODES, `anyone` when invalid.
	 */
	public static function sanitize_questions_mode( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::QUESTION_MODES, true ) ? $value : 'anyone';
	}

	/**
	 * Sanitizes the related coverage source.
	 *
	 * @param mixed $value Raw value.
	 * @return string One of RELATED_SOURCES, `primary-category` when invalid.
	 */
	public static function sanitize_related_source( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, self::RELATED_SOURCES, true ) ? $value : 'primary-category';
	}

	/**
	 * Sanitizes a list of IDs: positive, unique integers.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public static function sanitize_id_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	/**
	 * Sanitizes the manual related posts: at most MAX_RELATED_POSTS IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public static function sanitize_related_posts( $value ): array {
		return array_slice( self::sanitize_id_list( $value ), 0, self::MAX_RELATED_POSTS );
	}
}
