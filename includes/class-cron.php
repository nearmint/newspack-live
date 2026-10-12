<?php
/**
 * Scheduled jobs.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly and daily maintenance. Jobs are idempotent and batch-limited.
 */
final class Cron {

	public const HOURLY = 'newspack_live_hourly';
	public const DAILY  = 'newspack_live_daily';

	/**
	 * Maximum number of items per job run.
	 */
	public const BATCH = 100;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOURLY, [ __CLASS__, 'run_hourly' ] );
		add_action( self::DAILY, [ __CLASS__, 'run_daily' ] );
	}

	/**
	 * Schedules the recurring events if missing.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	/**
	 * Removes every scheduled event of the plugin.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOURLY );
		wp_clear_scheduled_hook( self::DAILY );
		// One-off Context bump retries carry the live ID: clear every instance.
		wp_unschedule_hook( Live::CONTEXT_EVENT );
		wp_unschedule_hook( Entries::TOUCH_EVENT );
	}

	/**
	 * Hourly job: setup check, virtual auto-ends, first activity, removal-log pruning.
	 *
	 * @return array{ended: int, pruned: int} Counts (for WP-CLI and tests).
	 */
	public static function run_hourly(): array {
		Setup::maybe_run();

		$ended  = 0;
		$pruned = 0;
		foreach ( self::get_open_lives() as $live_id ) {
			$result = Live::with_lock(
				$live_id,
				static function () use ( $live_id ) {
					wp_cache_delete( $live_id, 'post_meta' );
					$ended = Live::persist_virtual_end( $live_id );
					// First activity: a live that went live without any write starts its inactivity clock at its start.
					if ( ! $ended && Live::STATE_LIVE === Live::get_state( $live_id ) && ! (int) get_post_meta( $live_id, Live::META_ACTIVITY_AT, true ) ) {
						update_post_meta( $live_id, Live::META_ACTIVITY_AT, Live::get_start( $live_id ) );
					}
					return $ended;
				}
			);
			if ( true === $result ) {
				++$ended;
			}
		}
		foreach ( self::get_lives_with_removals() as $live_id ) {
			$pruned += Live::prune_removed( $live_id );
		}
		return [
			'ended'  => $ended,
			'pruned' => $pruned,
		];
	}

	/**
	 * Daily job: question retention and IP hash purge. Reschedules itself once if a batch was full.
	 *
	 * @return array{deleted: int, cleared: int}
	 */
	public static function run_daily(): array {
		$deleted = Questions::delete_expired( self::BATCH );
		$cleared = Questions::clear_old_ip_hashes( self::BATCH );
		if ( $deleted >= self::BATCH || $cleared >= self::BATCH ) {
			// Core ignores a duplicate single event scheduled within 10 minutes of an existing one.
			wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::DAILY );
		}
		return [
			'deleted' => $deleted,
			'cleared' => $cleared,
		];
	}

	/**
	 * Published lives without a persisted end (bounded).
	 *
	 * @return int[]
	 */
	private static function get_open_lives(): array {
		$ids = get_posts(
			[
				'post_type'           => Live::post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => self::BATCH,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hourly cron, bounded.
				'meta_query'          => [
					'relation' => 'AND',
					[
						'key'   => Live::META_ENABLED,
						'value' => '1',
					],
					[
						'relation' => 'OR',
						[
							'key'     => Live::META_END,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'   => Live::META_END,
							'value' => '0',
						],
					],
				],
			]
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Lives with a removal log (bounded).
	 *
	 * @return int[]
	 */
	private static function get_lives_with_removals(): array {
		$ids = get_posts(
			[
				'post_type'           => Live::post_types(),
				'post_status'         => 'any',
				'posts_per_page'      => self::BATCH,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- hourly cron, bounded.
				'meta_key'            => Live::META_REMOVED,
			]
		);
		return array_map( 'intval', $ids );
	}
}
