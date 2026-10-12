<?php
/**
 * Entry labels.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Error;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Entry labels: presets, the live's custom labels and the label of each entry.
 *
 * An entry stores a preset slug or `custom:<id>` in `_newspack_live_entry_label`; a live stores its
 * custom labels in `_newspack_live_labels`. "Your questions" is never stored: answers to reader
 * questions get it at render time.
 */
final class Labels {

	/**
	 * Entry meta: a preset slug or `custom:<id>`.
	 */
	public const ENTRY_META = '_newspack_live_entry_label';

	/**
	 * Live meta: the custom labels, a list of `[ 'id' => string, 'text' => string ]`.
	 */
	public const LIVE_META = '_newspack_live_labels';

	/**
	 * Prefix of custom label values.
	 */
	public const CUSTOM_PREFIX = 'custom:';

	/**
	 * The label of answers to reader questions (derived, locked).
	 */
	public const YOUR_QUESTIONS = 'your-questions';

	/**
	 * The label that gives the card the Context card's border.
	 */
	public const KEY_POINT = 'key-point';

	/**
	 * Presets an editor can pick, in menu order.
	 */
	public const SELECTABLE = [ 'breaking', 'photos', 'video', self::KEY_POINT ];

	/**
	 * Presets shown in the accent (red) color.
	 */
	public const ACCENT = [ 'breaking', self::KEY_POINT ];

	/**
	 * Maximum length of a custom label, in characters, after trimming.
	 */
	public const MAX_LENGTH = 24;

	/**
	 * Maximum number of custom labels per live.
	 */
	public const MAX_CUSTOM = 50;

	/**
	 * Entries scanned for the "in use" flags of the console (the delete route checks exactly).
	 */
	public const USAGE_SCAN = 100;

	/**
	 * Cache "last changed" group of the entry labels: part of the "in use" cache key.
	 */
	public const USAGE_CACHE = 'newspack_live_label_usage';

	/**
	 * Entry statuses that keep a custom label in use (trashed entries can be restored).
	 */
	private const USAGE_STATUSES = [ 'publish', 'draft', 'pending', 'trash' ];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 20 );
		add_action( 'added_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 3 );
	}

	/**
	 * Registers the entry label and the custom labels meta. Neither is shown in core REST: labels
	 * are written through `Entries::save()` and the label routes, which enforce the rules.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_meta(
			Post_Types::ENTRY,
			self::ENTRY_META,
			[
				'type'              => 'string',
				'description'       => __( 'Entry label: a preset slug or custom:<id>.', 'newspack-live' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ __CLASS__, 'sanitize_value' ],
				'auth_callback'     => [ __CLASS__, 'auth_entry' ],
				'show_in_rest'      => false,
			]
		);
		foreach ( Live::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::LIVE_META,
				[
					'type'              => 'array',
					'description'       => __( 'Custom entry labels of the live.', 'newspack-live' ),
					'single'            => true,
					'default'           => [],
					'sanitize_callback' => [ __CLASS__, 'sanitize_custom_list' ],
					'auth_callback'     => [ __CLASS__, 'auth_live' ],
					'show_in_rest'      => false,
				]
			);
		}
	}

	/**
	 * Presets: slug => display name (current locale), English name, accent color.
	 *
	 * @return array<string, array{name: string, source: string, accent: bool}>
	 */
	public static function presets(): array {
		return [
			'breaking'           => [
				'name'   => _x( 'Breaking', 'entry label', 'newspack-live' ),
				'source' => 'Breaking',
				'accent' => true,
			],
			'photos'             => [
				'name'   => _x( 'Photos', 'entry label', 'newspack-live' ),
				'source' => 'Photos',
				'accent' => false,
			],
			'video'              => [
				'name'   => _x( 'Video', 'entry label', 'newspack-live' ),
				'source' => 'Video',
				'accent' => false,
			],
			self::KEY_POINT      => [
				'name'   => _x( 'Key point', 'entry label', 'newspack-live' ),
				'source' => 'Key point',
				'accent' => true,
			],
			self::YOUR_QUESTIONS => [
				'name'   => _x( 'Your questions', 'entry label', 'newspack-live' ),
				'source' => 'Your questions',
				'accent' => false,
			],
		];
	}

	/**
	 * Sanitizes a stored entry label: a selectable preset or a well-formed custom value, else ''.
	 * "Your questions" is never stored.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_value( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( in_array( $value, self::SELECTABLE, true ) || self::is_custom_value( $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Whether a value has the custom label form `custom:<8 lowercase letters or digits>`.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_custom_value( string $value ): bool {
		return (bool) preg_match( '/^custom:[a-z0-9]{8}$/', $value );
	}

	/**
	 * Sanitizes the custom labels list: well-formed, unique IDs; texts cleaned and capped.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, array{id: string, text: string}>
	 */
	public static function sanitize_custom_list( $value ): array {
		$list = [];
		$seen = [];
		foreach ( is_array( $value ) ? $value : [] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['text'] ) ) {
				continue;
			}
			$id   = (string) $item['id'];
			$text = self::clean_text( (string) $item['text'] );
			if ( ! self::is_custom_value( self::CUSTOM_PREFIX . $id ) || isset( $seen[ $id ] ) || '' === $text || mb_strlen( $text ) > self::MAX_LENGTH ) {
				continue;
			}
			$seen[ $id ] = true;
			$list[]      = [
				'id'   => $id,
				'text' => $text,
			];
			if ( count( $list ) >= self::MAX_CUSTOM ) {
				break;
			}
		}
		return $list;
	}

	/**
	 * Meta auth: editing an entry's label is editing the entry.
	 *
	 * @param bool   $allowed   Whether allowed so far.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Entry ID.
	 * @return bool
	 */
	public static function auth_entry( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'newspack_live_edit_entry', (int) $object_id );
	}

	/**
	 * Meta auth: the custom labels of a live belong to the people who can publish in it.
	 *
	 * @param bool   $allowed   Whether allowed so far.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Live ID.
	 * @return bool
	 */
	public static function auth_live( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'newspack_live_publish_to_live', (int) $object_id );
	}

	/**
	 * Marks the label usage as changed when an entry label is written or deleted.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s). Unused.
	 * @param int       $object_id Post ID. Unused.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public static function on_meta_change( $meta_ids, $object_id, $meta_key ): void {
		if ( self::ENTRY_META === $meta_key ) {
			wp_cache_set_last_changed( self::USAGE_CACHE );
		}
	}

	/**
	 * Cleans a custom label text: no tags, no line breaks, single spaces, trimmed.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( string $text ): string {
		return trim( sanitize_text_field( $text ) );
	}

	/**
	 * Custom labels of a live.
	 *
	 * @param int $live_id Live ID.
	 * @return array<int, array{id: string, text: string}>
	 */
	public static function get_custom( int $live_id ): array {
		return self::sanitize_custom_list( get_post_meta( $live_id, self::LIVE_META, true ) );
	}

	/**
	 * Text of a custom label of a live.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $value   `custom:<id>`.
	 * @return string|null Null when the live has no such label.
	 */
	public static function custom_text( int $live_id, string $value ): ?string {
		if ( ! self::is_custom_value( $value ) ) {
			return null;
		}
		$id = substr( $value, strlen( self::CUSTOM_PREFIX ) );
		foreach ( self::get_custom( $live_id ) as $label ) {
			if ( $label['id'] === $id ) {
				return $label['text'];
			}
		}
		return null;
	}

	/**
	 * Whether an entry answers reader questions.
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool
	 */
	public static function is_answer( int $entry_id ): bool {
		return [] !== Entries::get_question_ids( $entry_id );
	}

	/**
	 * Label value of an entry as the console sees it: `your-questions` on answers, else the stored value.
	 *
	 * @param int $entry_id Entry ID.
	 * @return string
	 */
	public static function get_value( int $entry_id ): string {
		if ( self::is_answer( $entry_id ) ) {
			return self::YOUR_QUESTIONS;
		}
		return self::sanitize_value( get_post_meta( $entry_id, self::ENTRY_META, true ) );
	}

	/**
	 * Label shown on an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array{slug: string, text: string, accent: bool}|null Null without a label, or when the
	 *                                                              stored value is unknown or dangling.
	 */
	public static function resolve( int $entry_id ): ?array {
		$value = self::get_value( $entry_id );
		if ( '' === $value ) {
			return null;
		}
		$presets = self::presets();
		if ( isset( $presets[ $value ] ) ) {
			return [
				'slug'   => $value,
				'text'   => $presets[ $value ]['name'],
				'accent' => $presets[ $value ]['accent'],
			];
		}
		$text = self::custom_text( (int) get_post_field( 'post_parent', $entry_id ), $value );
		if ( null === $text ) {
			return null;
		}
		return [
			'slug'   => 'custom',
			'text'   => $text,
			'accent' => false,
		];
	}

	/**
	 * Validates the `label` field of an entry write.
	 *
	 * @param string|null $label     Requested value, null when the field isn't sent.
	 * @param int         $live_id   Live ID.
	 * @param bool        $is_answer Whether the entry is (or becomes) an answer to reader questions.
	 * @param string      $current   The entry's stored label ('' for a new entry).
	 * @return true|WP_Error
	 */
	public static function validate_for_entry( ?string $label, int $live_id, bool $is_answer, string $current = '' ) {
		if ( null === $label ) {
			return true;
		}
		if ( $is_answer ) {
			if ( self::YOUR_QUESTIONS === $label ) {
				return true;
			}
			return new WP_Error( 'newspack_live_label_locked', __( 'Answers to reader questions always carry the "Your questions" label.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		// An unchanged value is kept even when its custom label is gone (it renders nothing): saving
		// an entry never fails because of a label nobody touched.
		if ( '' === $label || $label === $current || in_array( $label, self::SELECTABLE, true ) || null !== self::custom_text( $live_id, $label ) ) {
			return true;
		}
		return new WP_Error( 'newspack_live_invalid_label', __( 'Invalid label.', 'newspack-live' ), [ 'status' => 400 ] );
	}

	/**
	 * Whether a write sets a custom label the entry didn't have.
	 *
	 * @param string|null $label   Requested value, null when not sent.
	 * @param string      $current Stored value.
	 * @return bool
	 */
	public static function is_new_custom( ?string $label, string $current ): bool {
		return null !== $label && $label !== $current && self::is_custom_value( $label );
	}

	/**
	 * Writes the label of an entry (inside its save). Answers never keep a stored label.
	 *
	 * @param int         $entry_id  Entry ID.
	 * @param string|null $label     Validated value, null to leave it.
	 * @param bool        $is_answer Whether the entry is an answer.
	 * @return void
	 */
	public static function store( int $entry_id, ?string $label, bool $is_answer ): void {
		if ( $is_answer || '' === $label ) {
			if ( metadata_exists( 'post', $entry_id, self::ENTRY_META ) ) {
				delete_post_meta( $entry_id, self::ENTRY_META );
			}
			return;
		}
		if ( null !== $label ) {
			update_post_meta( $entry_id, self::ENTRY_META, $label );
		}
	}

	/**
	 * Names a new custom label can't take, lowercased: presets in English, in the current locale and
	 * in the site locale (readers see the latter), and the live's custom labels.
	 *
	 * @param int $live_id Live ID.
	 * @return string[]
	 */
	public static function taken_names( int $live_id ): array {
		$names = self::preset_names();
		foreach ( self::get_custom( $live_id ) as $label ) {
			$names[] = mb_strtolower( $label['text'] );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Preset names, lowercased: English, the current locale (an editor's user locale in wp-admin and
	 * REST) and the site locale.
	 *
	 * @return string[]
	 */
	public static function preset_names(): array {
		$names = [];
		foreach ( self::presets() as $preset ) {
			$names[] = mb_strtolower( $preset['source'] );
			$names[] = mb_strtolower( $preset['name'] );
		}
		$site = get_locale();
		if ( determine_locale() !== $site && switch_to_locale( $site ) ) {
			foreach ( self::presets() as $preset ) {
				$names[] = mb_strtolower( $preset['name'] );
			}
			restore_previous_locale();
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Validates a new custom label text.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $text    Raw text.
	 * @return string|WP_Error Cleaned text.
	 */
	public static function validate_text( int $live_id, string $text ) {
		$text = self::clean_text( $text );
		if ( '' === $text ) {
			return new WP_Error( 'newspack_live_label_empty', __( 'Enter a label.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			return new WP_Error(
				'newspack_live_label_too_long',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Use %d characters or fewer.', 'newspack-live' ), self::MAX_LENGTH ),
				[ 'status' => 400 ]
			);
		}
		if ( in_array( mb_strtolower( $text ), self::taken_names( $live_id ), true ) ) {
			return new WP_Error( 'newspack_live_label_exists', __( 'This live already has this label.', 'newspack-live' ), [ 'status' => 409 ] );
		}
		return $text;
	}

	/**
	 * Creates a custom label on a live, under the live's lock (no revision bump: nothing changes
	 * for readers until an entry uses it).
	 *
	 * @param int    $live_id Live ID.
	 * @param string $text    Raw text.
	 * @return array{id: string, text: string}|WP_Error
	 */
	public static function create( int $live_id, string $text ) {
		return Live::with_lock(
			$live_id,
			static function () use ( $live_id, $text ) {
				wp_cache_delete( $live_id, 'post_meta' );
				$text = self::validate_text( $live_id, $text );
				if ( is_wp_error( $text ) ) {
					return $text;
				}
				$list = self::get_custom( $live_id );
				if ( count( $list ) >= self::MAX_CUSTOM ) {
					return new WP_Error(
						'newspack_live_label_limit',
						/* translators: %d: maximum number of custom labels. */
						sprintf( __( 'A live can have at most %d custom labels. Delete one you no longer use.', 'newspack-live' ), self::MAX_CUSTOM ),
						[ 'status' => 409 ]
					);
				}
				$label  = [
					'id'   => self::new_id( array_column( $list, 'id' ) ),
					'text' => $text,
				];
				$list[] = $label;
				update_post_meta( $live_id, self::LIVE_META, $list );
				return $label;
			}
		);
	}

	/**
	 * Deletes a custom label of a live, only while no entry of the live uses it.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $id      Label ID (without the prefix).
	 * @return true|WP_Error
	 */
	public static function delete( int $live_id, string $id ) {
		return Live::with_lock(
			$live_id,
			static function () use ( $live_id, $id ) {
				wp_cache_delete( $live_id, 'post_meta' );
				$list = self::get_custom( $live_id );
				$keep = array_values( array_filter( $list, static fn( array $label ): bool => $label['id'] !== $id ) );
				if ( count( $keep ) === count( $list ) ) {
					return new WP_Error( 'newspack_live_label_not_found', __( 'Label not found.', 'newspack-live' ), [ 'status' => 404 ] );
				}
				if ( self::is_used( $live_id, self::CUSTOM_PREFIX . $id ) ) {
					return new WP_Error( 'newspack_live_label_in_use', __( 'This label is used by an entry of this live (trashed entries included), so it can\'t be deleted.', 'newspack-live' ), [ 'status' => 409 ] );
				}
				update_post_meta( $live_id, self::LIVE_META, $keep );
				return true;
			}
		);
	}

	/**
	 * A new custom label ID, unique among the given ones.
	 *
	 * @param string[] $taken IDs in use.
	 * @return string
	 */
	private static function new_id( array $taken ): string {
		do {
			$id = strtolower( wp_generate_password( 8, false, false ) );
		} while ( in_array( $id, $taken, true ) );
		return $id;
	}

	/**
	 * Whether an entry of the live (any status, trash included) carries a label value. Exact and
	 * uncached: used by the delete route under the lock.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $value   Label value.
	 * @return bool
	 */
	public static function is_used( int $live_id, string $value ): bool {
		$query = new WP_Query(
			[
				'post_type'              => Post_Types::ENTRY,
				'post_parent'            => $live_id,
				'post_status'            => self::USAGE_STATUSES,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped to the entries of one live (parent + type index), one row, admin write path.
				'meta_query'             => [
					[
						'key'   => self::ENTRY_META,
						'value' => $value,
					],
				],
			]
		);
		return [] !== $query->posts;
	}

	/**
	 * Custom label values used by entries of a live, for the console's "in use" flags. Scans the
	 * latest labeled entries (USAGE_SCAN), object-cached until an entry label changes; beyond the
	 * scan a label may look unused, and the delete route refuses it.
	 *
	 * @param int $live_id Live ID.
	 * @return string[] `custom:<id>` values.
	 */
	public static function used_values( int $live_id ): array {
		$key    = 'label_usage_' . $live_id . '_' . wp_cache_get_last_changed( self::USAGE_CACHE );
		$cached = wp_cache_get( $key, Renderer::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$query = new WP_Query(
			[
				'post_type'              => Post_Types::ENTRY,
				'post_parent'            => $live_id,
				'post_status'            => self::USAGE_STATUSES,
				'posts_per_page'         => self::USAGE_SCAN,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped to the entries of one live (parent + type index), bounded, object-cached.
				'meta_query'             => [
					[
						'key'     => self::ENTRY_META,
						'value'   => self::CUSTOM_PREFIX,
						'compare' => 'LIKE',
					],
				],
			]
		);
		$ids = array_map( 'intval', $query->posts );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		$values = [];
		foreach ( $ids as $entry_id ) {
			$values[] = (string) get_post_meta( $entry_id, self::ENTRY_META, true );
		}
		$values = array_values( array_unique( array_filter( $values, [ __CLASS__, 'is_custom_value' ] ) ) );
		wp_cache_set( $key, $values, Renderer::CACHE_GROUP, HOUR_IN_SECONDS );
		return $values;
	}

	/**
	 * Custom labels of a live for the console, with their "in use" flag.
	 *
	 * @param int $live_id Live ID.
	 * @return array<int, array{id: string, value: string, text: string, used: bool}>
	 */
	public static function payload( int $live_id ): array {
		$custom = self::get_custom( $live_id );
		if ( ! $custom ) {
			return [];
		}
		$used = self::used_values( $live_id );
		return array_map(
			static fn( array $label ): array => [
				'id'    => $label['id'],
				'value' => self::CUSTOM_PREFIX . $label['id'],
				'text'  => $label['text'],
				'used'  => in_array( self::CUSTOM_PREFIX . $label['id'], $used, true ),
			],
			$custom
		);
	}

	/**
	 * Presets for the console: the selectable ones in menu order, plus the names a custom label
	 * can't take (client-side check; the server checks again).
	 *
	 * @return array{presets: array<int, array{value: string, name: string}>, taken: string[], your_questions: string, max_length: int}
	 */
	public static function console_config(): array {
		$presets = self::presets();
		$taken   = self::preset_names();
		return [
			'presets'        => array_map(
				static fn( string $slug ): array => [
					'value' => $slug,
					'name'  => $presets[ $slug ]['name'],
				],
				self::SELECTABLE
			),
			'taken'          => array_values( array_unique( $taken ) ),
			'your_questions' => $presets[ self::YOUR_QUESTIONS ]['name'],
			'max_length'     => self::MAX_LENGTH,
		];
	}
}
