<?php
/**
 * Question model: storage, statuses, counts and retention.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reader questions are private posts of type `newspack_live_q`.
 */
final class Questions {

	public const META_DISPLAY_NAME = '_newspack_live_display_name';
	public const META_IP_HASH      = '_newspack_live_ip_hash';
	public const META_ANSWER_ENTRY = '_newspack_live_answer_entry';

	/**
	 * Cache group and last-changed key of the per-status counts.
	 */
	public const COUNTS_CACHE = 'newspack_live_questions';

	/**
	 * Maximum question length, in characters.
	 */
	public const MAX_LENGTH = 500;

	/**
	 * Maximum display name length, in characters.
	 */
	public const MAX_NAME_LENGTH = 50;

	/**
	 * Statuses deleted by the retention job (unanswered questions).
	 */
	public const UNANSWERED_STATUSES = [ 'nl_new', 'nl_shortlisted', 'nl_rejected', 'nl_spam' ];

	/**
	 * Days after which IP hashes are cleared and unanswered questions of ended lives are deleted.
	 */
	public const RETENTION_DAYS = 30;

	/**
	 * Option holding the rate-limit generation. Resetting the limits bumps it, so older
	 * transients are ignored and expire on their own (no direct database access).
	 */
	public const OPTION_RL_GENERATION = 'newspack_live_rl_generation';

	/**
	 * Option holding the `_end` timestamp up to which ended lives have been purged.
	 */
	public const OPTION_RETENTION_CURSOR = 'newspack_live_retention_cursor';

	/**
	 * Hooks the class into WordPress: count cache invalidation on every question write.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'clean_post_cache', [ __CLASS__, 'on_clean_post_cache' ], 10, 2 );
	}

	/**
	 * Any change to a question (insert, status, delete, meta through core) invalidates the counts.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function on_clean_post_cache( $post_id, $post ): void {
		if ( $post instanceof WP_Post && Post_Types::QUESTION === $post->post_type ) {
			wp_cache_set_last_changed( self::COUNTS_CACHE );
		}
	}

	/**
	 * Current rate-limit generation.
	 *
	 * @return int
	 */
	public static function get_rate_limit_generation(): int {
		return (int) get_option( self::OPTION_RL_GENERATION, 0 );
	}

	/**
	 * Clears every question rate limit (WP-CLI `reset-rate-limits`, used by e2e tests).
	 *
	 * @return void
	 */
	public static function reset_rate_limits(): void {
		update_option( self::OPTION_RL_GENERATION, self::get_rate_limit_generation() + 1, false );
	}

	/**
	 * Signs a timestamp for the ask form (anti-spam timing check).
	 *
	 * @param int $live_id   Live ID.
	 * @param int $timestamp UTC timestamp.
	 * @return string `{timestamp}.{signature}`.
	 */
	public static function sign_timestamp( int $live_id, int $timestamp ): string {
		return $timestamp . '.' . wp_hash( 'newspack_live_ts|' . $live_id . '|' . $timestamp, 'nonce' );
	}

	/**
	 * Verifies a signed timestamp.
	 *
	 * @param int    $live_id Live ID.
	 * @param string $signed  `{timestamp}.{signature}`.
	 * @return int The timestamp, or 0 when missing or invalid.
	 */
	public static function verify_timestamp( int $live_id, string $signed ): int {
		$parts = explode( '.', $signed, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return 0;
		}
		$timestamp = (int) $parts[0];
		if ( $timestamp < time() - DAY_IN_SECONDS ) {
			// A signed timestamp is valid for a day, so a captured one can't be replayed forever.
			return 0;
		}
		return hash_equals( self::sign_timestamp( $live_id, $timestamp ), $signed ) ? $timestamp : 0;
	}

	/**
	 * Stores a question. Validation of reader submissions happens in the REST pipeline.
	 *
	 * @param int    $live_id      Live ID.
	 * @param string $text         Plain text (≤ 500 characters).
	 * @param string $display_name Display name (≤ 50 characters).
	 * @param int    $user_id      Reader user ID, 0 when anonymous.
	 * @param string $status       Question status.
	 * @param string $ip_hash      Hashed IP (`wp_hash( $ip )`), or ''.
	 * @return int|WP_Error Question ID.
	 */
	public static function create( int $live_id, string $text, string $display_name, int $user_id = 0, string $status = 'nl_new', string $ip_hash = '' ) {
		if ( ! Live::is_live( $live_id ) ) {
			return new WP_Error( 'newspack_live_not_live', __( 'This post is not a live.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, Post_Types::QUESTION_STATUSES, true ) ) {
			return new WP_Error( 'newspack_live_invalid_status', __( 'Invalid question status.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		$text         = trim( sanitize_textarea_field( $text ) );
		$display_name = trim( sanitize_text_field( $display_name ) );
		if ( '' === $text || mb_strlen( $text ) > self::MAX_LENGTH || '' === $display_name || mb_strlen( $display_name ) > self::MAX_NAME_LENGTH ) {
			return new WP_Error(
				'newspack_live_invalid_length',
				__( 'Your question must be between 1 and 500 characters, and your name between 1 and 50.', 'newspack-live' ),
				[ 'status' => 400 ]
			);
		}

		$question_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => Post_Types::QUESTION,
					'post_parent'  => $live_id,
					'post_status'  => $status,
					'post_title'   => '',
					'post_content' => $text,
					'post_author'  => $user_id,
				]
			),
			true
		);
		if ( is_wp_error( $question_id ) ) {
			return $question_id;
		}
		update_post_meta( $question_id, self::META_DISPLAY_NAME, $display_name );
		if ( '' !== $ip_hash ) {
			update_post_meta( $question_id, self::META_IP_HASH, $ip_hash );
		}
		if ( 'nl_spam' !== $status ) {
			Question_Groups::assign( (int) $question_id );
		}
		wp_cache_set_last_changed( self::COUNTS_CACHE );
		return (int) $question_id;
	}

	/**
	 * Whether a post is a question.
	 *
	 * @param mixed $post Post or ID.
	 * @return bool
	 */
	public static function is_question( $post ): bool {
		$post = get_post( $post );
		return $post instanceof WP_Post && Post_Types::QUESTION === $post->post_type;
	}

	/**
	 * Changes the status of a question.
	 *
	 * @param int    $question_id Question ID.
	 * @param string $status      New status.
	 * @param int    $entry_id    Answer entry when the status is `nl_answered`.
	 * @return true|WP_Error
	 */
	public static function set_status( int $question_id, string $status, int $entry_id = 0 ) {
		if ( ! self::is_question( $question_id ) ) {
			return new WP_Error( 'newspack_live_question_not_found', __( 'Question not found.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $status, Post_Types::QUESTION_STATUSES, true ) ) {
			return new WP_Error( 'newspack_live_invalid_status', __( 'Invalid question status.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		$result = wp_update_post(
			[
				'ID'          => $question_id,
				'post_status' => $status,
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'nl_answered' === $status && $entry_id > 0 ) {
			update_post_meta( $question_id, self::META_ANSWER_ENTRY, $entry_id );
		}
		// A group head that is answered, rejected or spam releases its members still open.
		Question_Groups::release_open_members( $question_id );
		if ( in_array( $status, self::UNANSWERED_STATUSES, true ) ) {
			// The retention cursor may already be past this live: rewind it so the question isn't kept forever.
			$end = Live::get_end( (int) get_post_field( 'post_parent', $question_id ) );
			if ( $end > 0 && $end < (int) get_option( self::OPTION_RETENTION_CURSOR, 0 ) ) {
				update_option( self::OPTION_RETENTION_CURSOR, $end, false );
			}
		}
		return true;
	}

	/**
	 * Display name of a question's author.
	 *
	 * @param int $question_id Question ID.
	 * @return string
	 */
	public static function get_display_name( int $question_id ): string {
		return (string) get_post_meta( $question_id, self::META_DISPLAY_NAME, true );
	}

	/**
	 * Counts the questions of a live per status, cached until the next question write.
	 *
	 * @param int $live_id Live ID.
	 * @return array<string, int>
	 */
	public static function count( int $live_id ): array {
		$key    = 'counts_' . $live_id . '_' . wp_cache_get_last_changed( self::COUNTS_CACHE );
		$cached = wp_cache_get( $key, self::COUNTS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = [];
		foreach ( Post_Types::QUESTION_STATUSES as $status ) {
			$query = new \WP_Query(
				[
					'post_type'              => Post_Types::QUESTION,
					'post_parent'            => $live_id,
					'post_status'            => $status,
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				]
			);

			$counts[ $status ] = (int) $query->found_posts;
		}
		// Invalidated by the last-changed key on every question write.
		wp_cache_set( $key, $counts, self::COUNTS_CACHE, HOUR_IN_SECONDS );
		return $counts;
	}

	/**
	 * Deletes up to $limit unanswered questions of lives ended more than RETENTION_DAYS ago.
	 *
	 * @param int $limit Maximum number of deletions.
	 * @return int Number of deleted questions.
	 */
	public static function delete_expired( int $limit = 100 ): int {
		/**
		 * Filters how many days after a live ends its unanswered questions are deleted.
		 *
		 * @since 1.2.0
		 *
		 * @param int $days Days. Default 30.
		 */
		$days    = max( 1, (int) apply_filters( 'newspack_live_question_retention_days', self::RETENTION_DAYS ) );
		$cutoff  = time() - $days * DAY_IN_SECONDS;
		$cursor  = (int) get_option( self::OPTION_RETENTION_CURSOR, 0 );
		$start   = $cursor;
		$deleted = 0;

		// Lives are processed oldest `_end` first from a persistent cursor, so every ended live is
		// eventually reached; a reopened and re-ended live gets a later `_end` and is reached again.
		for ( $page = 1; $page <= 3 && $deleted < $limit; $page++ ) {
			$live_ids = get_posts(
				[
					'post_type'           => Live::post_types(),
					'post_status'         => 'any',
					'posts_per_page'      => 100,
					'paged'               => $page,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily cron, bounded batches.
					'meta_key'            => Live::META_END,
					'orderby'             => 'meta_value_num',
					'order'               => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily cron, bounded batches.
					'meta_query'          => [
						[
							'key'     => Live::META_END,
							'value'   => [ max( 1, $start ), $cutoff ],
							'compare' => 'BETWEEN',
							'type'    => 'NUMERIC',
						],
					],
				]
			);
			foreach ( $live_ids as $live_id ) {
				$live_id      = (int) $live_id;
				$question_ids = get_posts(
					[
						'post_type'           => Post_Types::QUESTION,
						'post_parent'         => $live_id,
						'post_status'         => self::UNANSWERED_STATUSES,
						'posts_per_page'      => $limit - $deleted + 1,
						'fields'              => 'ids',
						'no_found_rows'       => true,
						'ignore_sticky_posts' => true,
					]
				);
				$batch        = array_slice( $question_ids, 0, $limit - $deleted );
				$failed = false;
				foreach ( $batch as $question_id ) {
					if ( wp_delete_post( (int) $question_id, true ) ) {
						++$deleted;
					} else {
						$failed = true;
					}
				}
				if ( $failed || count( $question_ids ) > count( $batch ) ) {
					// Questions left on this live: resume here next run.
					break 2;
				}
				$cursor = max( $cursor, Live::get_end( $live_id ) );
				if ( $deleted >= $limit ) {
					break 2;
				}
			}
			if ( count( $live_ids ) < 100 ) {
				break;
			}
		}
		update_option( self::OPTION_RETENTION_CURSOR, $cursor, false );
		return $deleted;
	}

	/**
	 * Clears up to $limit IP hashes older than RETENTION_DAYS.
	 *
	 * @param int $limit Maximum number of questions processed.
	 * @return int Number of cleared hashes.
	 */
	public static function clear_old_ip_hashes( int $limit = 100 ): int {
		$ids = get_posts(
			[
				'post_type'           => Post_Types::QUESTION,
				'post_status'         => Post_Types::QUESTION_STATUSES,
				'posts_per_page'      => $limit,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'date_query'          => [
					[
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ),
					],
				],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily cron on question posts only, bounded.
				'meta_key'            => self::META_IP_HASH,
			]
		);
		foreach ( $ids as $id ) {
			delete_post_meta( (int) $id, self::META_IP_HASH );
		}
		return count( $ids );
	}
}
