<?php
/**
 * Live model: state, settings, team, revision counter and removal log.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A live is a regular post with live coverage enabled.
 */
final class Live {

	public const META_ENABLED         = '_newspack_live_enabled';
	public const META_SCHEDULED_START = '_newspack_live_scheduled_start';
	public const META_AUTO_END_HOURS  = '_newspack_live_auto_end_hours';
	public const META_TEAM            = '_newspack_live_team';
	public const META_START           = '_newspack_live_start';
	public const META_END             = '_newspack_live_end';
	public const META_ACTIVITY_AT     = '_newspack_live_activity_at';
	public const META_QUESTIONS_OPEN  = '_newspack_live_questions_open';
	public const META_REV             = '_newspack_live_rev';
	public const META_LAST_UPDATE     = '_newspack_live_last_update';
	public const META_REMOVED         = '_newspack_live_removed';
	public const META_REMOVED_FLOOR   = '_newspack_live_removed_floor';
	public const META_CONTEXT_REV     = '_newspack_live_context_rev';
	public const META_CONTEXT_PENDING = '_newspack_live_context_pending';

	/**
	 * One-off cron event retrying a Context bump that couldn't take the lock (args: live ID).
	 */
	public const CONTEXT_EVENT = 'newspack_live_context_bump';

	/**
	 * Context bump attempts in the saving request, and the lock wait of each (seconds).
	 */
	private const CONTEXT_ATTEMPTS     = 3;
	private const CONTEXT_LOCK_TIMEOUT = 1;

	/**
	 * Cron retries of a Context bump before giving up (the next write of the live still applies it).
	 */
	private const CONTEXT_MAX_RETRIES = 10;

	/**
	 * Request memo of teams, by blog and live ID.
	 *
	 * @var array<string, int[]>
	 */
	private static array $teams = [];

	/**
	 * Depth of write() calls in progress (nested writes happen through hooks fired by a write).
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Whether a revision write is in progress in this request.
	 *
	 * @return bool
	 */
	public static function is_writing(): bool {
		return self::$writing > 0;
	}

	/**
	 * Hooks the class into WordPress (team memo invalidation).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'set_object_terms', [ __CLASS__, 'on_set_object_terms' ], 10, 4 );
		add_action( 'added_post_meta', [ __CLASS__, 'on_team_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_team_meta' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'on_team_meta' ], 10, 3 );
		add_action( 'clean_post_cache', [ __CLASS__, 'on_clean_post_cache' ] );
		add_action( self::CONTEXT_EVENT, [ __CLASS__, 'retry_context_bump' ] );
	}

	/**
	 * Bumps the revision for a Context change, without depending on another write: a few short
	 * attempts at the lock, then a one-off cron event a few seconds later (retried, bounded). The
	 * change stays flagged meanwhile, so any earlier write of the live carries it too.
	 *
	 * @param int $live_id Live ID.
	 * @param int $timeout Lock wait per attempt (seconds).
	 * @return bool Whether the revision was bumped.
	 */
	public static function bump_context( int $live_id, int $timeout = self::CONTEXT_LOCK_TIMEOUT ): bool {
		if ( self::is_writing() ) {
			// Inside a write (hooks): that write carries the flagged change with its own revision.
			update_post_meta( $live_id, self::META_CONTEXT_PENDING, 1 );
			return true;
		}
		for ( $attempt = 0; $attempt < self::CONTEXT_ATTEMPTS; $attempt++ ) {
			$result = self::write(
				$live_id,
				static fn( int $rev ) => update_post_meta( $live_id, self::META_CONTEXT_REV, $rev ),
				$timeout
			);
			if ( ! is_wp_error( $result ) ) {
				return true;
			}
		}
		$tries = (int) get_post_meta( $live_id, self::META_CONTEXT_PENDING, true );
		update_post_meta( $live_id, self::META_CONTEXT_PENDING, $tries + 1 );
		if ( $tries < self::CONTEXT_MAX_RETRIES && ! wp_next_scheduled( self::CONTEXT_EVENT, [ $live_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::CONTEXT_EVENT, [ $live_id ] );
		}
		return false;
	}

	/**
	 * Cron: retries a pending Context bump (nothing to do when a write already carried it).
	 *
	 * @param int $live_id Live ID.
	 * @return void
	 */
	public static function retry_context_bump( $live_id ): void {
		$live_id = (int) $live_id;
		if ( $live_id <= 0 || ! get_post_meta( $live_id, self::META_CONTEXT_PENDING, true ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $live_id ) ) {
			// Unpublished meanwhile: no readers; publishing renders the current Context.
			delete_post_meta( $live_id, self::META_CONTEXT_PENDING );
			return;
		}
		self::bump_context( $live_id, self::LOCK_TIMEOUT );
	}

	/**
	 * Revision at which the Context card (its meta) last changed, or 0.
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_context_rev( int $live_id ): int {
		return (int) get_post_meta( $live_id, self::META_CONTEXT_REV, true );
	}

	/**
	 * States.
	 */
	public const STATE_NONE     = 'none';
	public const STATE_DRAFT    = 'draft';
	public const STATE_UPCOMING = 'upcoming';
	public const STATE_LIVE     = 'live';
	public const STATE_ENDED    = 'ended';

	/**
	 * State actions.
	 */
	public const ACTIONS = [ 'go_live', 'end', 'reopen', 'keep_live' ];

	/**
	 * Seconds to wait for the per-live named lock.
	 */
	public const LOCK_TIMEOUT = 10;

	/**
	 * Removal log retention, in seconds.
	 */
	public const REMOVED_TTL = DAY_IN_SECONDS;

	/**
	 * Warning window before an auto-end, in seconds.
	 */
	public const AUTO_END_WARNING = HOUR_IN_SECONDS;

	/**
	 * Post types that can become lives.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		/**
		 * Filters the post types that can host live coverage.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $post_types Post type names. Default `[ 'post' ]`.
		 */
		$post_types = apply_filters( 'newspack_live_post_types', [ 'post' ] );
		return is_array( $post_types ) ? array_values( array_filter( $post_types, 'is_string' ) ) : [ 'post' ];
	}

	/**
	 * Whether a post is a live (right post type and live coverage enabled).
	 *
	 * @param int|WP_Post|null $post Post or post ID.
	 * @return bool
	 */
	public static function is_live( $post ): bool {
		// get_post( 0 ) would return the global post.
		if ( ! $post instanceof WP_Post && (int) $post <= 0 ) {
			return false;
		}
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		return (bool) get_post_meta( $post->ID, self::META_ENABLED, true );
	}

	/**
	 * Effective start timestamp: `_start`, else `_scheduled_start`, else the post publish time.
	 *
	 * @param int $live_id Live ID.
	 * @return int UTC timestamp.
	 */
	public static function get_start( int $live_id ): int {
		$start = (int) get_post_meta( $live_id, self::META_START, true );
		if ( $start > 0 ) {
			return $start;
		}
		$scheduled = (int) get_post_meta( $live_id, self::META_SCHEDULED_START, true );
		if ( $scheduled > 0 ) {
			return $scheduled;
		}
		$post = get_post( $live_id );
		if ( ! $post instanceof WP_Post || '0000-00-00 00:00:00' === $post->post_date_gmt ) {
			return 0;
		}
		return (int) strtotime( $post->post_date_gmt . ' UTC' );
	}

	/**
	 * Persisted end timestamp (0 = not ended).
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_end( int $live_id ): int {
		return (int) get_post_meta( $live_id, self::META_END, true );
	}

	/**
	 * Auto-end delay in hours (0 = never).
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_auto_end_hours( int $live_id ): int {
		return Live_Meta::sanitize_auto_end_hours( get_post_meta( $live_id, self::META_AUTO_END_HOURS, true ) );
	}

	/**
	 * Timestamp at which the live ends automatically without activity, or 0.
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_auto_end_at( int $live_id ): int {
		$hours = self::get_auto_end_hours( $live_id );
		if ( 0 === $hours ) {
			return 0;
		}
		$ref = max( (int) get_post_meta( $live_id, self::META_ACTIVITY_AT, true ), self::get_start( $live_id ) );
		return $ref + $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Computes the state at read time. Never relies on cron.
	 *
	 * @param int      $live_id Live ID.
	 * @param int|null $now     Current UTC timestamp (tests).
	 * @return string One of the STATE_* constants.
	 */
	public static function get_state( int $live_id, ?int $now = null ): string {
		$post = get_post( $live_id );
		if ( ! self::is_live( $post ) ) {
			return self::STATE_NONE;
		}
		if ( 'publish' !== $post->post_status ) {
			return self::STATE_DRAFT;
		}
		$now = null === $now ? time() : $now;
		$end = self::get_end( $live_id );
		if ( $end > 0 && $end <= $now ) {
			return self::STATE_ENDED;
		}
		if ( self::get_start( $live_id ) > $now ) {
			return self::STATE_UPCOMING;
		}
		$auto_end_at = self::get_auto_end_at( $live_id );
		if ( $auto_end_at > 0 && $auto_end_at <= $now ) {
			return self::STATE_ENDED;
		}
		return self::STATE_LIVE;
	}

	/**
	 * Whether the live reads as ended only because of inactivity, with `_end` not yet persisted.
	 *
	 * @param int      $live_id Live ID.
	 * @param int|null $now     Current UTC timestamp.
	 * @return bool
	 */
	public static function is_virtually_ended( int $live_id, ?int $now = null ): bool {
		$now = null === $now ? time() : $now;
		$end = self::get_end( $live_id );
		if ( $end > 0 && $end <= $now ) {
			return false;
		}
		return self::STATE_ENDED === self::get_state( $live_id, $now );
	}

	/**
	 * Whether the auto-end warning applies: live, auto-end on, less than an hour left.
	 *
	 * @param int      $live_id Live ID.
	 * @param int|null $now     Current UTC timestamp.
	 * @return array{show: bool, at: int, hours: int}
	 */
	public static function get_auto_end_warning( int $live_id, ?int $now = null ): array {
		$now   = null === $now ? time() : $now;
		$at    = self::get_auto_end_at( $live_id );
		$hours = self::get_auto_end_hours( $live_id );
		$show  = self::STATE_LIVE === self::get_state( $live_id, $now ) && $at > 0 && $at - $now < self::AUTO_END_WARNING;
		return [
			'show'  => $show,
			'at'    => $at,
			'hours' => $hours,
		];
	}

	/**
	 * Settings with defaults applied.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	public static function get_settings( int $live_id ): array {
		$settings = [];
		foreach ( Live_Meta::definitions() as $key => $definition ) {
			$value = metadata_exists( 'post', $live_id, $key ) ? get_post_meta( $live_id, $key, true ) : $definition['default'];
			$name  = substr( $key, strlen( '_newspack_live_' ) );

			$settings[ $name ] = call_user_func( $definition['sanitize'], $value );
		}
		$settings['questions_open'] = self::are_questions_open( $live_id );
		$settings['hide_ads']       = (bool) get_post_meta( $live_id, 'newspack_ads_suppress_ads', true );
		return $settings;
	}

	/**
	 * Whether the console's questions toggle is open (independent of the state).
	 *
	 * @param int $live_id Live ID.
	 * @return bool
	 */
	public static function are_questions_open( int $live_id ): bool {
		if ( ! metadata_exists( 'post', $live_id, self::META_QUESTIONS_OPEN ) ) {
			return true;
		}
		return (bool) get_post_meta( $live_id, self::META_QUESTIONS_OPEN, true );
	}

	/**
	 * Current revision (possibly from the object cache).
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_rev( int $live_id ): int {
		return (int) get_post_meta( $live_id, self::META_REV, true );
	}

	/**
	 * Timestamp of the last reader-visible change, falling back to the post modified time.
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_last_update( int $live_id ): int {
		$last = (int) get_post_meta( $live_id, self::META_LAST_UPDATE, true );
		if ( $last > 0 ) {
			return $last;
		}
		$post = get_post( $live_id );
		return $post instanceof WP_Post ? (int) strtotime( $post->post_modified_gmt . ' UTC' ) : 0;
	}

	/**
	 * Removal log entries.
	 *
	 * @param int $live_id Live ID.
	 * @return array<int, array{id: int, rev: int, t: int}>
	 */
	public static function get_removed( int $live_id ): array {
		$removed = get_post_meta( $live_id, self::META_REMOVED, true );
		if ( ! is_array( $removed ) ) {
			return [];
		}
		$items = [];
		foreach ( $removed as $item ) {
			if ( is_array( $item ) && isset( $item['id'], $item['rev'], $item['t'] ) ) {
				$items[] = [
					'id'  => (int) $item['id'],
					'rev' => (int) $item['rev'],
					't'   => (int) $item['t'],
				];
			}
		}
		return $items;
	}

	/**
	 * Highest revision whose removal-log items were pruned. Clients that know an older revision
	 * may have missed removals and must reload (`reset`).
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_removed_floor( int $live_id ): int {
		return (int) get_post_meta( $live_id, self::META_REMOVED_FLOOR, true );
	}

	/**
	 * User IDs of the hosts: co-authors (Co-Authors Plus) or the post author.
	 *
	 * @param int $live_id Live ID.
	 * @return int[]
	 */
	public static function get_host_ids( int $live_id ): array {
		$ids = [];
		foreach ( Integrations\Co_Authors_Plus::get_hosts( $live_id ) as $host ) {
			if ( $host['user_id'] > 0 ) {
				$ids[] = $host['user_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Team: hosts ∪ `_newspack_live_team`.
	 *
	 * @param int $live_id Live ID.
	 * @return int[]
	 */
	public static function get_team( int $live_id ): array {
		$key = get_current_blog_id() . ':' . $live_id;
		if ( ! isset( self::$teams[ $key ] ) ) {
			$team                = Live_Meta::sanitize_id_list( get_post_meta( $live_id, self::META_TEAM, true ) );
			self::$teams[ $key ] = array_values( array_unique( array_merge( self::get_host_ids( $live_id ), $team ) ) );
		}
		return self::$teams[ $key ];
	}

	/**
	 * Clears the request memo of teams (all lives, or one).
	 *
	 * @param int|null $live_id Live ID.
	 * @return void
	 */
	public static function flush_team( $live_id = null ): void {
		if ( null === $live_id ) {
			self::$teams = [];
			return;
		}
		unset( self::$teams[ get_current_blog_id() . ':' . (int) $live_id ] );
	}

	/**
	 * Clears a live's team memo when its co-authors change (Co-Authors Plus `author` terms).
	 *
	 * @param int    $object_id Post ID.
	 * @param array  $terms     Terms.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 * @return void
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( 'author' === $taxonomy ) {
			self::flush_team( (int) $object_id );
		}
	}

	/**
	 * Clears a live's team memo when its team meta changes.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public static function on_team_meta( $meta_ids, $object_id, $meta_key ): void {
		if ( self::META_TEAM === $meta_key ) {
			self::flush_team( (int) $object_id );
		}
	}

	/**
	 * Clears a live's team memo when the post changes (e.g. its author).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_clean_post_cache( $post_id ): void {
		self::flush_team( (int) $post_id );
	}

	/**
	 * Whether a user belongs to the team of a live.
	 *
	 * @param int $live_id Live ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_team_member( int $live_id, int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::get_team( $live_id ), true );
	}

	/**
	 * Runs a callback while holding the per-live MySQL named lock.
	 *
	 * @param int      $live_id  Live ID.
	 * @param callable $callback Callback; its return value is passed through.
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @return mixed|WP_Error Callback result, or `newspack_live_try_again` (503) when the lock times out.
	 */
	public static function with_lock( int $live_id, callable $callback, int $timeout = self::LOCK_TIMEOUT ) {
		global $wpdb;

		// On HyperDB/LudicrousDB hosts, reads may go to replicas: the lock and counter must hit the primary.
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$lock = 'newspack_live_' . $live_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- named lock serializing revision writes.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, $timeout ) );
		if ( '1' !== (string) $acquired ) {
			return new WP_Error(
				'newspack_live_try_again',
				__( 'The live is busy. Please try again.', 'newspack-live' ),
				[ 'status' => 503 ]
			);
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releases the named lock taken above.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
	}

	/**
	 * Reads the revision counter from the database, bypassing the object cache.
	 *
	 * Only call while holding the lock.
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	private static function read_rev_from_db( int $live_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the revision counter must be read from the primary, not the cache.
		$rev = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1',
				$wpdb->postmeta,
				$live_id,
				self::META_REV
			)
		);
		return (int) $rev;
	}

	/**
	 * Performs a reader-visible write under the lock and bumps the revision.
	 *
	 * Order: read the counter from the database, compute `rev + 1`, run the write (which stamps
	 * the new revision on the affected entry), then store the live's revision last and fire
	 * `newspack_live_updated` once the lock is released.
	 *
	 * @param int      $live_id  Live ID.
	 * @param callable $callback Receives the new revision; returns a result or a WP_Error (no bump).
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @return mixed|WP_Error Callback result.
	 */
	public static function write( int $live_id, callable $callback, int $timeout = self::LOCK_TIMEOUT ) {
		$new_rev = 0;
		++self::$writing;
		try {
			$result = self::with_lock(
				$live_id,
				static function () use ( $live_id, $callback, &$new_rev ) {
					// Meta read before the lock (e.g. the removal log) may be stale: reload it.
					wp_cache_delete( $live_id, 'post_meta' );
					self::persist_virtual_end( $live_id );

					$new_rev = self::read_rev_from_db( $live_id ) + 1;
					$result  = $callback( $new_rev );
					if ( is_wp_error( $result ) ) {
						$new_rev = 0;
						return $result;
					}

					// A Context change that missed the lock earlier ships with this revision.
					if ( get_post_meta( $live_id, self::META_CONTEXT_PENDING, true ) ) {
						update_post_meta( $live_id, self::META_CONTEXT_REV, $new_rev );
						delete_post_meta( $live_id, self::META_CONTEXT_PENDING );
					}

					$now = time();
					update_post_meta( $live_id, self::META_LAST_UPDATE, $now );
					update_post_meta( $live_id, self::META_ACTIVITY_AT, $now );
					// Written last, so /status never reports a revision whose entry isn't stamped yet.
					update_post_meta( $live_id, self::META_REV, $new_rev );
					wp_cache_delete( $live_id, 'post_meta' );
					return $result;
				},
				$timeout
			);
		} finally {
			--self::$writing;
		}

		if ( $new_rev > 0 && ! is_wp_error( $result ) ) {
			/**
			 * Fires after every reader-visible change of a live (new revision).
			 *
			 * Hosts can use it to purge page caches.
			 *
			 * @since 1.2.0
			 *
			 * @param int $live_id Live ID.
			 * @param int $rev     New revision.
			 */
			do_action( 'newspack_live_updated', $live_id, $new_rev );
		}
		return $result;
	}

	/**
	 * Appends an item to the removal log. Call from inside a write().
	 *
	 * @param int $live_id  Live ID.
	 * @param int $entry_id Removed entry ID.
	 * @param int $rev      Revision of the removal.
	 * @return void
	 */
	public static function log_removal( int $live_id, int $entry_id, int $rev ): void {
		$removed   = self::get_removed( $live_id );
		$removed[] = [
			'id'  => $entry_id,
			'rev' => $rev,
			't'   => time(),
		];
		update_post_meta( $live_id, self::META_REMOVED, $removed );
	}

	/**
	 * Removes removal-log items older than 24 hours.
	 *
	 * @param int      $live_id Live ID.
	 * @param int|null $now     Current UTC timestamp.
	 * @return int Number of pruned items.
	 */
	public static function prune_removed( int $live_id, ?int $now = null ): int {
		$now    = null === $now ? time() : $now;
		$result = self::with_lock(
			$live_id,
			static function () use ( $live_id, $now ) {
				wp_cache_delete( $live_id, 'post_meta' );
				$removed = self::get_removed( $live_id );
				$kept    = array_values(
					array_filter(
						$removed,
						static fn( $item ) => $item['t'] > $now - self::REMOVED_TTL
					)
				);
				$pruned  = count( $removed ) - count( $kept );
				if ( $pruned > 0 ) {
					$floor = self::get_removed_floor( $live_id );
					foreach ( $removed as $item ) {
						if ( $item['t'] <= $now - self::REMOVED_TTL ) {
							$floor = max( $floor, $item['rev'] );
						}
					}
					update_post_meta( $live_id, self::META_REMOVED_FLOOR, $floor );
					if ( $kept ) {
						update_post_meta( $live_id, self::META_REMOVED, $kept );
					} else {
						delete_post_meta( $live_id, self::META_REMOVED );
					}
				}
				return $pruned;
			}
		);
		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Persists a virtual auto-end: `_end` = inactivity reference + delay.
	 *
	 * Called by the hourly cron and before every write; never during a GET request.
	 *
	 * @param int      $live_id Live ID.
	 * @param int|null $now     Current UTC timestamp.
	 * @return bool Whether `_end` was written.
	 */
	public static function persist_virtual_end( int $live_id, ?int $now = null ): bool {
		if ( ! self::is_virtually_ended( $live_id, $now ) ) {
			return false;
		}
		update_post_meta( $live_id, self::META_END, self::get_auto_end_at( $live_id ) );
		return true;
	}

	/**
	 * Whether a state action is allowed in the current state.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $action  Action.
	 * @return bool
	 */
	public static function can_apply_action( int $live_id, string $action ): bool {
		$state = self::get_state( $live_id );
		return match ( $action ) {
			'go_live'   => self::STATE_UPCOMING === $state,
			'end'       => self::STATE_LIVE === $state,
			'reopen'    => self::STATE_ENDED === $state,
			'keep_live' => self::STATE_LIVE === $state,
			default     => false,
		};
	}

	/**
	 * Applies a state action. State changes bump the revision; "Keep live" only
	 * refreshes the inactivity reference.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $action  One of ACTIONS.
	 * @return string|WP_Error New state.
	 */
	public static function apply_action( int $live_id, string $action ) {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'newspack_live_invalid_action', __( 'Unknown live action.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( ! self::is_live( $live_id ) ) {
			return new WP_Error( 'newspack_live_not_live', __( 'This post is not a live.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		if ( ! self::can_apply_action( $live_id, $action ) ) {
			if ( self::STATE_DRAFT === self::get_state( $live_id ) ) {
				$message = __( 'Publish the post to go live.', 'newspack-live' );
			} else {
				$message = __( 'This action is not available in the current state of the live.', 'newspack-live' );
			}
			return new WP_Error( 'newspack_live_invalid_state', $message, [ 'status' => 409 ] );
		}

		if ( 'keep_live' === $action ) {
			$result = self::with_lock(
				$live_id,
				static function () use ( $live_id ) {
					wp_cache_delete( $live_id, 'post_meta' );
					update_post_meta( $live_id, self::META_ACTIVITY_AT, time() );
					return true;
				}
			);
			return is_wp_error( $result ) ? $result : self::get_state( $live_id );
		}

		$result = self::write(
			$live_id,
			static function () use ( $live_id, $action ) {
				$now = time();
				switch ( $action ) {
					case 'go_live':
						update_post_meta( $live_id, self::META_START, $now );
						update_post_meta( $live_id, self::META_END, 0 );
						break;
					case 'end':
						update_post_meta( $live_id, self::META_END, $now );
						break;
					case 'reopen':
						update_post_meta( $live_id, self::META_END, 0 );
						break;
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Ending sets the activity time too (every reader-visible write does), which is harmless: `_end` wins.
		return self::get_state( $live_id );
	}

	/**
	 * Opens or closes reader questions (a reader-visible change).
	 *
	 * @param int  $live_id Live ID.
	 * @param bool $open    Whether questions are open.
	 * @return true|WP_Error
	 */
	public static function set_questions_open( int $live_id, bool $open ) {
		return self::write(
			$live_id,
			static function () use ( $live_id, $open ) {
				update_post_meta( $live_id, self::META_QUESTIONS_OPEN, $open ? 1 : 0 );
				return true;
			}
		);
	}
}
