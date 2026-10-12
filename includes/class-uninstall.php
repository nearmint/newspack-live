<?php
/**
 * Uninstall routine.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Removes capabilities, options and cron events; deletes content (entries, questions, labels, every
 * `_newspack_live_*` meta and transient) only on request.
 */
final class Uninstall {

	/**
	 * Post meta keys outside the live settings: entry and question data (also removed with their
	 * posts), and the e2e and demo markers of the tooling.
	 */
	public const POST_META_KEYS = [
		'_newspack_live_activity_at',
		'_newspack_live_answer_entry',
		'_newspack_live_compose_ms',
		'_newspack_live_context',
		'_newspack_live_context_pending',
		'_newspack_live_context_rev',
		'_newspack_live_demo',
		'_newspack_live_demo_key',
		'_newspack_live_display_name',
		'_newspack_live_e2e',
		'_newspack_live_end',
		'_newspack_live_entry_label',
		'_newspack_live_fixed_time',
		'_newspack_live_ip_hash',
		'_newspack_live_labels',
		'_newspack_live_last_update',
		'_newspack_live_modified_bumped_at',
		'_newspack_live_pinned',
		'_newspack_live_published_at',
		'_newspack_live_q_group',
		'_newspack_live_q_tokens',
		'_newspack_live_q_ungrouped',
		'_newspack_live_question_ids',
		'_newspack_live_questions_open',
		'_newspack_live_removed',
		'_newspack_live_removed_floor',
		'_newspack_live_rev',
		'_newspack_live_start',
		'_newspack_live_touch_pending',
		'_newspack_live_type',
	];

	/**
	 * User meta keys: the e2e and demo markers.
	 */
	public const USER_META_KEYS = [
		'_newspack_live_e2e',
		'_newspack_live_demo',
	];

	/**
	 * Options owned by the plugin.
	 */
	public const OPTIONS = [
		Setup::OPTION_VERSION,
		Questions::OPTION_RL_GENERATION,
		Questions::OPTION_RETENTION_CURSOR,
	];

	/**
	 * Runs the uninstall routine.
	 *
	 * @param bool $delete_data Whether to delete entries, questions, labels and live meta.
	 * @return void
	 */
	public static function run_everywhere( bool $delete_data ): void {
		if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			self::run( $delete_data );
			return;
		}
		// A network-activated plugin is uninstalled once: clean each site (user meta is network-wide).
		$offset = 0;
		do {
			$sites = get_sites(
				[
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				]
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::run( $delete_data );
				restore_current_blog();
			}
			$offset += 100;
			$more    = 100 === count( $sites );
		} while ( $more );
	}

	/**
	 * Runs the uninstall routine on the current site.
	 *
	 * @param bool $delete_data Whether to delete entries, questions, labels and live meta.
	 * @return void
	 */
	public static function run( bool $delete_data ): void {
		// uninstall.php doesn't load the plugin: statuses and the taxonomy must be registered to query them.
		if ( ! post_type_exists( Post_Types::ENTRY ) ) {
			Post_Types::register();
		}
		Capabilities::remove_role_caps();
		Cron::unschedule();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		if ( $delete_data ) {
			self::delete_data();
		}
	}

	/**
	 * Deletes every entry, question and label, and the live meta.
	 *
	 * @return void
	 */
	private static function delete_data(): void {
		foreach ( [ Post_Types::ENTRY, Post_Types::QUESTION ] as $post_type ) {
			for ( $round = 0; $round < 1000; $round++ ) {
				$ids = get_posts(
					[
						'post_type'           => $post_type,
						'post_status'         => array_merge( [ 'publish', 'draft', 'pending', 'trash', 'private', 'future', 'auto-draft' ], Post_Types::QUESTION_STATUSES ),
						'posts_per_page'      => 100,
						'fields'              => 'ids',
						'no_found_rows'       => true,
						'ignore_sticky_posts' => true,
					]
				);
				if ( ! $ids ) {
					break;
				}
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
			}
		}

		// The e2e and demo markers of users.
		foreach ( self::USER_META_KEYS as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}

		$keys = array_merge(
			array_keys( Live_Meta::definitions() ),
			self::POST_META_KEYS,
			[
				Live::META_START,
				Live::META_END,
				Live::META_ACTIVITY_AT,
				Live::META_QUESTIONS_OPEN,
				Live::META_REV,
				Live::META_LAST_UPDATE,
				Live::META_REMOVED,
				Live::META_REMOVED_FLOOR,
				Live::META_CONTEXT_REV,
				Live::META_CONTEXT_PENDING,
				'_newspack_live_modified_bumped_at',
			]
		);
		foreach ( array_unique( $keys ) as $key ) {
			delete_post_meta_by_key( $key );
		}
		self::delete_transients();
	}

	/**
	 * Deletes the plugin's transients (question rate limits, console presence). Their
	 * keys hold hashed IPs and user IDs, so they can't be listed: one prefix-scoped query on the
	 * options table, at uninstall only (the one exception to the no-direct-query rule). With a persistent
	 * object cache they live in the cache and expire within ten minutes.
	 *
	 * @return void
	 */
	private static function delete_transients(): void {
		global $wpdb;
		$names = [];
		foreach ( [ '_transient_', '_transient_timeout_' ] as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall only: transient keys are hashed and can't be listed.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix . 'newspack_live_' ) . '%' ) );
			$names = array_merge( $names, (array) $found );
		}
		foreach ( $names as $name ) {
			delete_option( (string) $name );
		}
	}
}
