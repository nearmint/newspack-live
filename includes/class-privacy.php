<?php
/**
 * Personal data exporter and eraser.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Exports and erases the questions of registered readers, found by email → user → `post_author`,
 * and every stored list holding the user's ID: live teams, the question rate limit and
 * console presence.
 */
final class Privacy {

	/**
	 * Questions per page of export or erasure.
	 */
	public const PER_PAGE = 50;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['newspack-live']       = [
			'exporter_friendly_name' => __( 'Live questions', 'newspack-live' ),
			'callback'               => [ __CLASS__, 'export' ],
		];
		$exporters['newspack-live-lists'] = [
			'exporter_friendly_name' => __( 'Live teams and activity', 'newspack-live' ),
			'callback'               => [ __CLASS__, 'export_lists' ],
		];
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['newspack-live']       = [
			'eraser_friendly_name' => __( 'Live questions', 'newspack-live' ),
			'callback'             => [ __CLASS__, 'erase' ],
		];
		$erasers['newspack-live-lists'] = [
			'eraser_friendly_name' => __( 'Live teams and activity', 'newspack-live' ),
			'callback'             => [ __CLASS__, 'erase_lists' ],
		];
		return $erasers;
	}

	/**
	 * Question IDs of a user, one page (erasure always reads page 1, since items are deleted).
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page.
	 * @return int[]
	 */
	private static function question_ids( int $user_id, int $page ): array {
		$ids = get_posts(
			[
				'post_type'           => Post_Types::QUESTION,
				'post_status'         => Post_Types::QUESTION_STATUSES,
				'author'              => $user_id,
				'posts_per_page'      => self::PER_PAGE,
				'paged'               => max( 1, $page ),
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Exports the questions of the user with this email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array{data: array, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user instanceof WP_User ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$ids  = self::question_ids( $user->ID, (int) $page );
		$data = [];
		foreach ( $ids as $id ) {
			$question = get_post( $id );
			$data[]   = [
				'group_id'    => 'newspack-live-questions',
				'group_label' => __( 'Live questions', 'newspack-live' ),
				'item_id'     => 'newspack-live-question-' . $id,
				'data'        => [
					[
						'name'  => __( 'Live', 'newspack-live' ),
						'value' => (string) get_post_field( 'post_title', (int) $question->post_parent ),
					],
					[
						'name'  => __( 'Question', 'newspack-live' ),
						'value' => $question->post_content,
					],
					[
						'name'  => __( 'Display name', 'newspack-live' ),
						'value' => Questions::get_display_name( $id ),
					],
					[
						'name'  => __( 'Date', 'newspack-live' ),
						'value' => $question->post_date_gmt,
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		];
	}

	/**
	 * Erases (deletes) the questions of the user with this email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused: deleted items shift the pages, so page 1 is always read.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		$user     = get_user_by( 'email', (string) $email );
		$removed  = false;
		$retained = false;
		$done     = true;
		$messages = [];
		if ( $user instanceof WP_User ) {
			$ids     = self::question_ids( $user->ID, 1 );
			$answers = [];
			foreach ( $ids as $id ) {
				$answers[] = (int) get_post_meta( $id, Questions::META_ANSWER_ENTRY, true );
				if ( wp_delete_post( $id, true ) ) {
					$removed = true;
				} else {
					$retained = true;
				}
			}
			// Answers quoted the question and the reader's name: re-render them without it.
			foreach ( array_unique( array_filter( $answers ) ) as $entry_id ) {
				Entries::touch( $entry_id );
			}
			// Stop when a batch removed nothing (failed deletions would otherwise loop).
			$done = count( $ids ) < self::PER_PAGE || ! $removed;
			if ( array_filter( $answers ) ) {
				$messages[] = __( 'Answers written by the newsroom may still mention the reader by name in their own text; review them if needed.', 'newspack-live' );
			}
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * Posts whose list meta (a serialized array of user IDs) holds a user, one page. The REGEXP
	 * matches the ID as a value of the serialized array (`{i:0;i:5;i:1;i:7;}`: pairs of key and value),
	 * never as a key, so erasing (which always reads page 1) can't stall on false positives;
	 * membership is still checked on the unserialized list.
	 *
	 * @param string   $meta_key   Meta key.
	 * @param string[] $post_types Post types.
	 * @param int      $user_id    User ID.
	 * @param int      $page       Page.
	 * @return array{ids: int[], more: bool} Matching post IDs, and whether more candidates follow.
	 */
	private static function posts_listing( string $meta_key, array $post_types, int $user_id, int $page ): array {
		$candidates = get_posts(
			[
				'post_type'           => $post_types,
				'post_status'         => array_values( get_post_stati() ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- privacy requests only (rare, admin-triggered).
				'meta_query'          => [
					[
						'key'     => $meta_key,
						'value'   => '\\{(i:[0-9]+;i:[0-9]+;)*i:[0-9]+;i:' . $user_id . ';',
						'compare' => 'REGEXP', // phpcs:ignore WordPressVIPMinimum.Performance.RegexpCompare.compare_compare -- privacy requests only (rare, admin-triggered); LIKE would also match array keys.
					],
				],
				'posts_per_page'      => self::PER_PAGE,
				'paged'               => max( 1, $page ),
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => false,
			]
		);
		$ids = [];
		foreach ( $candidates as $id ) {
			if ( in_array( $user_id, array_map( 'intval', (array) get_post_meta( (int) $id, $meta_key, true ) ), true ) ) {
				$ids[] = (int) $id;
			}
		}
		return [
			'ids'  => $ids,
			'more' => count( $candidates ) === self::PER_PAGE,
		];
	}

	/**
	 * Post types that can be lives (team lists live on them).
	 *
	 * @return string[]
	 */
	private static function live_post_types(): array {
		return array_values( array_diff( get_post_types(), [ Post_Types::ENTRY, Post_Types::QUESTION, 'revision', 'nav_menu_item', 'attachment' ] ) );
	}

	/**
	 * Exports the lists holding the user: live team memberships (rate-limit slots and presence are
	 * short-lived and only erased).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused: every live is exported in the first call (team lists are short).
	 * @return array{data: array, done: bool}
	 */
	public static function export_lists( $email, $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', (string) $email );
		$data = [];
		if ( ! $user instanceof WP_User ) {
			return [
				'data' => $data,
				'done' => true,
			];
		}
		$live_page = 1;
		do {
			$lives = self::posts_listing( Live::META_TEAM, self::live_post_types(), $user->ID, $live_page );
			foreach ( $lives['ids'] as $live_id ) {
				$data[] = [
					'group_id'    => 'newspack-live-teams',
					'group_label' => __( 'Live teams', 'newspack-live' ),
					'item_id'     => 'newspack-live-team-' . $live_id,
					'data'        => [
						[
							'name'  => __( 'Live team member', 'newspack-live' ),
							'value' => (string) get_post_field( 'post_title', $live_id ),
						],
					],
				];
			}
			++$live_page;
		} while ( $lives['more'] && $live_page <= 100 );
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Removes the user from every stored list: live teams, the question rate limit and console
	 * presence. Entries and lives stay (they belong to the
	 * newsroom); only the user's ID is taken out.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, unused: lists shrink as the user is removed, so page 1 is always read.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_lists( $email, $page = 1 ): array {
		unset( $page );
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		if ( ! $user instanceof WP_User ) {
			return [
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			];
		}
		Question_Submission::forget_user( $user->ID );
		if ( class_exists( Presence::class ) ) {
			Presence::forget_user( $user->ID );
		}
		$found = self::posts_listing( Live::META_TEAM, self::live_post_types(), $user->ID, 1 );
		foreach ( $found['ids'] as $post_id ) {
			$list    = array_values( array_diff( array_map( 'intval', (array) get_post_meta( $post_id, Live::META_TEAM, true ) ), [ $user->ID ] ) );
			$removed = (bool) update_post_meta( $post_id, Live::META_TEAM, $list ) || $removed;
		}
		// Another batch only when this one changed something (no endless loop on failures).
		$more = $found['more'] && $found['ids'];
		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => ! $more,
		];
	}
}
