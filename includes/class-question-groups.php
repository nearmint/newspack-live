<?php
/**
 * Similar-question grouping: deterministic, no AI. Normalized token sets (lowercase,
 * accents and punctuation stripped, stop words of the site language removed, English fallback)
 * compared with the Jaccard index within a live.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Groups: a member stores the ID of the group's first question (`META_GROUP`); the first question
 * stores nothing, so it lists like a single question with its members attached.
 */
final class Question_Groups {

	/**
	 * Group of a question (its head's ID); absent on heads and single questions.
	 */
	public const META_GROUP = '_newspack_live_q_group';

	/**
	 * Normalized tokens of a question (space-separated), computed once on insert.
	 */
	public const META_TOKENS = '_newspack_live_q_tokens';

	/**
	 * Set when an editor ungroups a question: it is never grouped again automatically.
	 */
	public const META_UNGROUPED = '_newspack_live_q_ungrouped';

	/**
	 * Minimum Jaccard similarity of two token sets.
	 */
	public const THRESHOLD = 0.6;

	/**
	 * Recent questions of the live compared with a new one.
	 */
	private const CANDIDATES = 100;

	/**
	 * Most members loaded for one page of the inbox.
	 */
	private const MAX_MEMBERS = 500;

	/**
	 * Statuses a question can join a group in (not answered, rejected or spam).
	 */
	private const OPEN_STATUSES = [ 'nl_new', 'nl_shortlisted' ];

	/**
	 * Languages with a bundled stop-word list.
	 */
	public const LANGUAGES = [ 'en', 'es', 'fr', 'pt', 'de' ];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete' ], 10, 2 );
	}

	/**
	 * A deleted head (eraser, retention, cleanup) hands its group over to its oldest member.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function on_delete( $post_id, $post ): void {
		if ( $post instanceof WP_Post && Post_Types::QUESTION === $post->post_type && ! get_post_meta( (int) $post_id, self::META_GROUP, true ) ) {
			self::promote( (int) $post_id );
		}
	}

	/**
	 * Members of a head that are still open leave the group when the head is answered, rejected or
	 * marked as spam (e.g. a question that joined while the answer was being written): they show
	 * again in the inbox on their own.
	 *
	 * @param int $head_id Head question ID.
	 * @return void
	 */
	public static function release_open_members( int $head_id ): void {
		if ( in_array( (string) get_post_status( $head_id ), self::OPEN_STATUSES, true ) ) {
			return;
		}
		foreach ( self::members( [ $head_id ] )[ $head_id ] ?? [] as $member ) {
			if ( in_array( $member->post_status, self::OPEN_STATUSES, true ) ) {
				delete_post_meta( $member->ID, self::META_GROUP );
			}
		}
	}

	/**
	 * Detaches a member from its group (no automatic regrouping flag).
	 *
	 * @param int $question_id Question ID.
	 * @return void
	 */
	public static function detach( int $question_id ): void {
		delete_post_meta( $question_id, self::META_GROUP );
	}

	/**
	 * Makes the oldest member of a head the new head of the others.
	 *
	 * @param int $head_id Head question ID.
	 * @return bool Whether the head had members.
	 */
	private static function promote( int $head_id ): bool {
		$members = self::members( [ $head_id ] )[ $head_id ] ?? [];
		if ( ! $members ) {
			return false;
		}
		$new_head = array_shift( $members );
		delete_post_meta( $new_head->ID, self::META_GROUP );
		foreach ( $members as $member ) {
			update_post_meta( $member->ID, self::META_GROUP, $new_head->ID );
		}
		wp_cache_set_last_changed( Questions::COUNTS_CACHE );
		return true;
	}

	/**
	 * Loaded stop-word lists, by language.
	 *
	 * @var array<string, array<string, true>>
	 */
	private static array $stop_words = [];

	/**
	 * Stop words of a language (the site language by default), English when there is no list for it.
	 *
	 * @param string $language Two-letter code, or '' for the site language.
	 * @return array<string, true>
	 */
	public static function stop_words( string $language = '' ): array {
		$language = '' !== $language ? $language : substr( (string) get_locale(), 0, 2 );
		$language = in_array( $language, self::LANGUAGES, true ) ? $language : 'en';
		if ( ! isset( self::$stop_words[ $language ] ) ) {
			$words = require NEWSPACK_LIVE_DIR . 'includes/stopwords/' . $language . '.php';
			/**
			 * Filters the stop words ignored by similar-question grouping.
			 *
			 * @since 1.2.0
			 *
			 * @param string[] $words    Stop words (lowercase, without accents).
			 * @param string   $language Two-letter language code.
			 */
			$words = (array) apply_filters( 'newspack_live_question_stop_words', $words, $language );

			self::$stop_words[ $language ] = array_fill_keys( array_map( 'strval', $words ), true );
		}
		return self::$stop_words[ $language ];
	}

	/**
	 * Normalized token set of a text.
	 *
	 * @param string $text     Text.
	 * @param string $language Two-letter code, or '' for the site language.
	 * @return string[] Unique tokens, sorted.
	 */
	public static function tokens( string $text, string $language = '' ): array {
		$text   = remove_accents( mb_strtolower( wp_strip_all_tags( $text ) ) );
		$words  = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop   = self::stop_words( $language );
		$tokens = [];
		foreach ( (array) $words as $word ) {
			if ( strlen( $word ) > 1 && ! isset( $stop[ $word ] ) ) {
				$tokens[ $word ] = true;
			}
		}
		$tokens = array_keys( $tokens );
		sort( $tokens );
		return $tokens;
	}

	/**
	 * Jaccard similarity of two token sets.
	 *
	 * @param string[] $a Tokens.
	 * @param string[] $b Tokens.
	 * @return float 0 to 1.
	 */
	public static function similarity( array $a, array $b ): float {
		if ( ! $a || ! $b ) {
			return 0.0;
		}
		$union = count( array_unique( array_merge( $a, $b ) ) );
		return $union > 0 ? count( array_intersect( $a, $b ) ) / $union : 0.0;
	}

	/**
	 * Groups a new question with the most similar open question of its live (ties: the oldest).
	 * The new question takes the group's status (a group moves as one).
	 *
	 * @param int $question_id Question ID.
	 * @return int Group head ID, or 0 when not grouped.
	 */
	public static function assign( int $question_id ): int {
		$question = get_post( $question_id );
		if ( ! $question instanceof WP_Post || get_post_meta( $question_id, self::META_UNGROUPED, true ) ) {
			return 0;
		}
		$tokens = self::tokens( $question->post_content );
		update_post_meta( $question_id, self::META_TOKENS, implode( ' ', $tokens ) );
		if ( ! $tokens ) {
			return 0;
		}
		$candidates = get_posts(
			[
				'post_type'           => Post_Types::QUESTION,
				'post_parent'         => (int) $question->post_parent,
				'post_status'         => self::OPEN_STATUSES,
				'posts_per_page'      => self::CANDIDATES,
				// The most recent open questions (ties go to the oldest of them, see below).
				'orderby'             => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);
		$best  = null;
		$head  = 0;
		$score = 0.0;
		foreach ( $candidates as $candidate ) {
			if ( $candidate->ID === $question_id ) {
				continue;
			}
			// A candidate counts through its group, which must still be open.
			$group = (int) get_post_meta( $candidate->ID, self::META_GROUP, true );
			$group = $group > 0 && get_post( $group ) ? $group : (int) $candidate->ID;
			if ( ! in_array( (string) get_post_status( $group ), self::OPEN_STATUSES, true ) ) {
				continue;
			}
			$other = (string) get_post_meta( $candidate->ID, self::META_TOKENS, true );
			$other = '' !== $other ? explode( ' ', $other ) : self::tokens( $candidate->post_content );
			$value = self::similarity( $tokens, $other );
			// `>=` while going from newest to oldest: equal scores go to the oldest question.
			if ( $value >= self::THRESHOLD && $value >= $score ) {
				$best  = $candidate;
				$head  = $group;
				$score = $value;
			}
		}
		if ( ! $best ) {
			return 0;
		}
		update_post_meta( $question_id, self::META_GROUP, $head );
		$status = (string) get_post_status( $head );
		if ( in_array( $status, self::OPEN_STATUSES, true ) && $status !== $question->post_status ) {
			Questions::set_status( $question_id, $status );
		}
		return $head;
	}

	/**
	 * Members of groups, by head ID (oldest first).
	 *
	 * @param int[] $head_ids Head question IDs.
	 * @return array<int, WP_Post[]>
	 */
	public static function members( array $head_ids ): array {
		$head_ids = array_values( array_filter( array_map( 'intval', $head_ids ) ) );
		if ( ! $head_ids ) {
			return [];
		}
		$posts = get_posts(
			[
				'post_type'           => Post_Types::QUESTION,
				'post_status'         => Post_Types::QUESTION_STATUSES,
				'posts_per_page'      => self::MAX_MEMBERS,
				'orderby'             => [
					'date' => 'ASC',
					'ID'   => 'ASC',
				],
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one query for a page of the inbox.
				'meta_query'          => [
					[
						'key'     => self::META_GROUP,
						'value'   => $head_ids,
						'compare' => 'IN',
					],
				],
			]
		);
		$members = array_fill_keys( $head_ids, [] );
		foreach ( $posts as $post ) {
			$members[ (int) get_post_meta( $post->ID, self::META_GROUP, true ) ][] = $post;
		}
		return $members;
	}

	/**
	 * Takes a question out of its group. Ungrouping a head promotes its oldest member.
	 *
	 * @param int $question_id Question ID.
	 * @return bool Whether it was grouped.
	 */
	public static function ungroup( int $question_id ): bool {
		update_post_meta( $question_id, self::META_UNGROUPED, 1 );
		$head = (int) get_post_meta( $question_id, self::META_GROUP, true );
		if ( $head > 0 ) {
			delete_post_meta( $question_id, self::META_GROUP );
			wp_cache_set_last_changed( Questions::COUNTS_CACHE );
			return true;
		}
		return self::promote( $question_id );
	}
}
