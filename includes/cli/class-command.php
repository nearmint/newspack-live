<?php
/**
 * WP-CLI commands.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\CLI;

use Newspack_Live\Cron;
use Newspack_Live\E2E;
use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Post_Types;
use Newspack_Live\Questions;
use WP_CLI;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics and test helpers for Newspack Live.
 *
 * Commands that modify content only touch posts marked `_newspack_live_e2e` or `_newspack_live_demo`.
 */
final class Command {

	/**
	 * Marker of e2e test content.
	 */
	public const E2E_META = '_newspack_live_e2e';

	/**
	 * Marker of demo content.
	 */
	public const DEMO_META = '_newspack_live_demo';

	/**
	 * Registers the command group.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'newspack-live', self::class );
	}

	/**
	 * Shows the state, revision, counts and team of a live.
	 *
	 * ## OPTIONS
	 *
	 * <live>
	 * : Live post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live doctor 46
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function doctor( array $args, array $assoc_args ): void {
		$live_id = $this->get_live_id( $args[0] ?? 0 );
		wp_cache_delete( $live_id, 'post_meta' );

		$rev     = Live::get_rev( $live_id );
		$counts  = Entries::count( $live_id );
		$removed = Live::get_removed( $live_id );
		$max_rev = $counts['max_rev'];
		foreach ( $removed as $item ) {
			$max_rev = max( $max_rev, $item['rev'] );
		}
		$warning = Live::get_auto_end_warning( $live_id );
		$team    = array_map(
			static function ( int $user_id ): string {
				$user = get_userdata( $user_id );
				return $user ? $user->user_login : (string) $user_id;
			},
			Live::get_team( $live_id )
		);

		$report = [
			'live'              => $live_id,
			'title'             => get_the_title( $live_id ),
			'state'             => Live::get_state( $live_id ),
			'rev'               => $rev,
			'max_entry_rev'     => $max_rev,
			'consistent'        => $max_rev <= $rev ? 'yes' : 'no',
			'start'             => $this->format_time( Live::get_start( $live_id ) ),
			'end'               => $this->format_time( Live::get_end( $live_id ) ),
			'activity_at'       => $this->format_time( (int) get_post_meta( $live_id, Live::META_ACTIVITY_AT, true ) ),
			'last_update'       => $this->format_time( (int) get_post_meta( $live_id, Live::META_LAST_UPDATE, true ) ),
			'auto_end_hours'    => Live::get_auto_end_hours( $live_id ),
			'auto_end_warning'  => $warning['show'] ? 'yes' : 'no',
			'questions_open'    => Live::are_questions_open( $live_id ) ? 'yes' : 'no',
			'entries_published' => $counts['publish'],
			'entries_draft'     => $counts['draft'],
			'entries_pending'   => $counts['pending'],
			'pinned'            => $counts['pinned'],
			'removed_log'       => count( $removed ),
			'questions'         => wp_json_encode( Questions::count( $live_id ) ),
			'team'              => implode( ', ', $team ),
		];

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $report ) );
			return;
		}
		$rows = [];
		foreach ( $report as $field => $value ) {
			$rows[] = [
				'field' => $field,
				'value' => $value,
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
		if ( $max_rev > $rev ) {
			WP_CLI::warning( 'An entry carries a revision newer than the live.' );
		}
	}

	/**
	 * Creates a published live.
	 *
	 * ## OPTIONS
	 *
	 * [--title=<title>]
	 * : Post title.
	 * ---
	 * default: Test live
	 * ---
	 *
	 * [--state=<state>]
	 * : Initial state.
	 * ---
	 * default: live
	 * options:
	 *   - draft
	 *   - upcoming
	 *   - live
	 *   - ended
	 * ---
	 *
	 * [--author=<user>]
	 * : Post author (login or ID). Defaults to the first administrator.
	 *
	 * [--content=<content>]
	 * : Description (post content).
	 *
	 * [--context-html=<html>]
	 * : Context (paragraphs, lists, bold, italic, links).
	 *
	 * [--excerpt=<excerpt>]
	 * : Manual excerpt.
	 *
	 * [--category=<slug>]
	 * : Category slug.
	 *
	 * [--e2e]
	 * : Mark as e2e test content (else demo content).
	 *
	 * [--porcelain]
	 * : Output only the new post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live create-live --title="Election night" --state=live --e2e --porcelain
	 *
	 * @subcommand create-live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function create_live( array $args, array $assoc_args ): void {
		$state  = $assoc_args['state'] ?? 'live';
		$author = $this->resolve_user( $assoc_args['author'] ?? '' );

		$live_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => 'post',
					'post_status'  => 'draft' === $state ? 'draft' : 'publish',
					'post_title'   => sanitize_text_field( $assoc_args['title'] ?? 'Test live' ),
					'post_content' => wp_kses_post( $assoc_args['content'] ?? '' ),
					'post_excerpt' => sanitize_text_field( $assoc_args['excerpt'] ?? '' ),
					'post_author'  => $author,
				]
			),
			true
		);
		if ( is_wp_error( $live_id ) ) {
			WP_CLI::error( $live_id );
		}
		update_post_meta( $live_id, isset( $assoc_args['e2e'] ) ? self::E2E_META : self::DEMO_META, 1 );
		if ( isset( $assoc_args['category'] ) ) {
			$category = get_category_by_slug( sanitize_title( (string) $assoc_args['category'] ) );
			if ( ! $category ) {
				WP_CLI::error( sprintf( 'Category %s not found.', $assoc_args['category'] ) );
			}
			wp_set_post_categories( $live_id, [ $category->term_id ] );
		}
		update_post_meta( $live_id, Live::META_ENABLED, 1 );
		if ( isset( $assoc_args['context-html'] ) ) {
			update_post_meta( $live_id, \Newspack_Live\Context::META, (string) $assoc_args['context-html'] );
		}

		if ( 'upcoming' === $state ) {
			update_post_meta( $live_id, Live::META_SCHEDULED_START, time() + DAY_IN_SECONDS );
		} elseif ( 'live' === $state || 'ended' === $state ) {
			update_post_meta( $live_id, Live::META_SCHEDULED_START, time() + DAY_IN_SECONDS );
			$this->check( Live::apply_action( $live_id, 'go_live' ) );
			// The scheduled start only served to reach the upcoming state; `_start` is now authoritative.
			delete_post_meta( $live_id, Live::META_SCHEDULED_START );
			if ( 'ended' === $state ) {
				$this->check( Live::apply_action( $live_id, 'end' ) );
			}
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $live_id );
			return;
		}
		WP_CLI::success( sprintf( 'Created live %d (%s).', $live_id, Live::get_state( $live_id ) ) );
	}

	/**
	 * Adds an entry to a live.
	 *
	 * ## OPTIONS
	 *
	 * <live>
	 * : Live post ID.
	 *
	 * [--title=<title>]
	 * : Entry title.
	 *
	 * [--content=<content>]
	 * : Entry content (block markup or plain text, wrapped in a paragraph).
	 *
	 * [--pinned]
	 * : Pin the entry.
	 *
	 * [--time=<time>]
	 * : Entry time: UTC timestamp, or a relative expression such as "-30 minutes".
	 *
	 * [--status=<status>]
	 * : Entry status.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - pending
	 * ---
	 *
	 * [--author=<user>]
	 * : Entry author (login or ID). Defaults to the live's author.
	 *
	 *
	 * [--question-ids=<ids>]
	 * : Comma-separated IDs of answered questions (Q&A entry); they are marked answered.
	 *
	 * [--label=<label>]
	 * : Entry label: breaking, photos, video, key-point, or custom:<id> for a custom label of the live.
	 *
	 * [--porcelain]
	 * : Output only the new entry ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live add-entry 46 --title="Polls are closed" --pinned
	 *
	 * @subcommand add-entry
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function add_entry( array $args, array $assoc_args ): void {
		$live_id = $this->get_live_id( $args[0] ?? 0, true );
		$content = (string) ( $assoc_args['content'] ?? '' );
		if ( '' !== $content && ! str_contains( $content, '<!-- wp:' ) ) {
			$content = "<!-- wp:paragraph -->\n<p>" . esc_html( $content ) . "</p>\n<!-- /wp:paragraph -->";
		}

		$data = [
			'live_id' => $live_id,
			'title'   => $assoc_args['title'] ?? '',
			'content' => $content,
			'status'  => $assoc_args['status'] ?? 'publish',
			'pinned'  => isset( $assoc_args['pinned'] ),
			'author'  => isset( $assoc_args['author'] ) ? $this->resolve_user( $assoc_args['author'] ) : (int) get_post_field( 'post_author', $live_id ),
		];
		if ( isset( $assoc_args['label'] ) ) {
			$data['label'] = (string) $assoc_args['label'];
		}
		if ( isset( $assoc_args['time'] ) ) {
			$time         = (string) $assoc_args['time'];
			$data['time'] = ctype_digit( $time ) ? (int) $time : (int) strtotime( $time );
		}
		$question_ids = [];
		if ( isset( $assoc_args['question-ids'] ) ) {
			$question_ids = array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['question-ids'] ) ) );
			foreach ( $question_ids as $question_id ) {
				if ( ! Questions::is_question( $question_id ) || $live_id !== (int) get_post_field( 'post_parent', $question_id ) ) {
					WP_CLI::error( sprintf( 'Question %d does not belong to live %d.', $question_id, $live_id ) );
				}
			}
			$data['question_ids'] = $question_ids;
		}

		$entry_id = $this->check( Entries::save( $data ) );
		$this->copy_marker( $live_id, (int) $entry_id );
		foreach ( $question_ids as $question_id ) {
			$this->check( Questions::set_status( (int) $question_id, 'nl_answered', (int) $entry_id ) );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $entry_id );
			return;
		}
		WP_CLI::success( sprintf( 'Added entry %d (rev %d).', $entry_id, Entries::get_rev( (int) $entry_id ) ) );
	}

	/**
	 * Adds a reader question to a live.
	 *
	 * ## OPTIONS
	 *
	 * <live>
	 * : Live post ID.
	 *
	 * <text>
	 * : Question text.
	 *
	 * [--name=<name>]
	 * : Display name.
	 * ---
	 * default: Reader
	 * ---
	 *
	 * [--status=<status>]
	 * : Question status.
	 * ---
	 * default: nl_new
	 * options:
	 *   - nl_new
	 *   - nl_shortlisted
	 *   - nl_answered
	 *   - nl_rejected
	 *   - nl_spam
	 * ---
	 *
	 * [--user=<user>]
	 * : Reader user (login or ID) for a registered reader.
	 *
	 * [--porcelain]
	 * : Output only the new question ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live add-question 46 "When will results be final?" --name=Ana
	 *
	 * @subcommand add-question
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function add_question( array $args, array $assoc_args ): void {
		$live_id = $this->get_live_id( $args[0] ?? 0, true );
		$user_id = isset( $assoc_args['user'] ) ? $this->resolve_user( $assoc_args['user'] ) : 0;

		$question_id = $this->check( Questions::create( $live_id, (string) ( $args[1] ?? '' ), (string) ( $assoc_args['name'] ?? 'Reader' ), $user_id, $assoc_args['status'] ?? 'nl_new' ) );
		$this->copy_marker( $live_id, (int) $question_id );

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $question_id );
			return;
		}
		WP_CLI::success( sprintf( 'Added question %d.', $question_id ) );
	}

	/**
	 * Applies a state action to a live.
	 *
	 * ## OPTIONS
	 *
	 * <live>
	 * : Live post ID.
	 *
	 * <action>
	 * : State action.
	 * ---
	 * options:
	 *   - go_live
	 *   - end
	 *   - reopen
	 *   - keep_live
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live set-state 46 end
	 *
	 * @subcommand set-state
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function set_state( array $args ): void {
		$live_id = $this->get_live_id( $args[0] ?? 0, true );
		$state   = $this->check( Live::apply_action( $live_id, (string) ( $args[1] ?? '' ) ) );
		WP_CLI::success( sprintf( 'Live %d is now %s (rev %d).', $live_id, $state, Live::get_rev( $live_id ) ) );
	}

	/**
	 * Sets (or deletes) a Newspack Live meta value on a live, entry or question, e.g. to simulate time.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID (live, entry or question).
	 *
	 * <key>
	 * : Meta key: `_newspack_live_*` or `newspack_ads_suppress_ads`.
	 *
	 * [<value>]
	 * : Value. JSON arrays and objects are decoded. Relative times ("-7 hours") are accepted with --time.
	 *
	 * [--time]
	 * : Parse the value as a time expression and store a UTC timestamp.
	 *
	 * [--delete]
	 * : Delete the meta instead.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live set-meta 46 _newspack_live_activity_at "-7 hours" --time
	 *
	 * @subcommand set-meta
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function set_meta( array $args, array $assoc_args ): void {
		$post_id = absint( $args[0] ?? 0 );
		$key     = (string) ( $args[1] ?? '' );
		$this->assert_marked( $post_id );
		if ( ! str_starts_with( $key, '_newspack_live_' ) && 'newspack_ads_suppress_ads' !== $key ) {
			WP_CLI::error( 'Only _newspack_live_* keys and newspack_ads_suppress_ads can be set.' );
		}

		if ( isset( $assoc_args['delete'] ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			$raw = (string) ( $args[2] ?? '' );
			if ( isset( $assoc_args['time'] ) ) {
				$value = ctype_digit( $raw ) ? (int) $raw : (int) strtotime( $raw );
			} else {
				$decoded = json_decode( $raw, true );
				$value   = is_array( $decoded ) ? $decoded : $raw;
			}
			update_post_meta( $post_id, $key, $value );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		WP_CLI::success( sprintf( 'Updated %s on %d.', $key, $post_id ) );
	}

	/**
	 * Clears the question rate limits (used between e2e tests).
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live reset-rate-limits
	 *
	 * @subcommand reset-rate-limits
	 *
	 * @return void
	 */
	public function reset_rate_limits(): void {
		Questions::reset_rate_limits();
		WP_CLI::success( 'Question rate limits cleared.' );
	}

	/**
	 * Runs the hourly or daily maintenance job now.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job.
	 * ---
	 * options:
	 *   - hourly
	 *   - daily
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live run-cron hourly
	 *
	 * @subcommand run-cron
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function run_cron( array $args ): void {
		$result = 'daily' === ( $args[0] ?? '' ) ? Cron::run_daily() : Cron::run_hourly();
		WP_CLI::success( (string) wp_json_encode( $result ) );
	}

	/**
	 * Deletes e2e test content, found by its creation marker only: posts marked
	 * `_newspack_live_e2e` or written by e2e users, with their entries and questions.
	 * Never selects by post type or status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-live clean-e2e
	 *
	 * @subcommand clean-e2e
	 *
	 * @return void
	 */
	public function clean_e2e(): void {
		$deleted = 0;
		// Each round deletes what it found (and the children of e2e lives): the next one finds the rest.
		for ( $round = 0; $round < 100; $round++ ) {
			// Only content an e2e run created, by its marker; never by type or status.
			$ids = E2E::content_ids( 100 );
			if ( ! $ids ) {
				break;
			}
			foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
				$deleted += $this->delete_with_children( $id );
			}
		}

		WP_CLI::success( sprintf( 'Deleted %d e2e post(s).', $deleted ) );
	}

	/**
	 * Deletes a post and, for a live, all its entries and questions.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of deleted posts.
	 */
	private function delete_with_children( int $post_id ): int {
		$deleted = 0;
		for ( $round = 0; $round < 100; $round++ ) {
			$children = get_posts(
				[
					'post_type'           => [ Post_Types::ENTRY, Post_Types::QUESTION ],
					'post_parent'         => $post_id,
					'post_status'         => array_merge( [ 'publish', 'draft', 'pending', 'trash', 'future', 'private' ], Post_Types::QUESTION_STATUSES ),
					'posts_per_page'      => 100,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
				]
			);
			if ( ! $children ) {
				break;
			}
			foreach ( $children as $child_id ) {
				if ( wp_delete_post( (int) $child_id, true ) ) {
					++$deleted;
				}
			}
		}
		global $coauthors_plus;
		if ( 'guest-author' === get_post_type( $post_id ) && isset( $coauthors_plus->guest_authors ) && method_exists( $coauthors_plus->guest_authors, 'delete' ) ) {
			// Co-Authors Plus also removes the guest author's term.
			return $deleted + ( true === $coauthors_plus->guest_authors->delete( $post_id ) ? 1 : 0 );
		}
		if ( get_post( $post_id ) && wp_delete_post( $post_id, true ) ) {
			++$deleted;
		}
		return $deleted;
	}

	/**
	 * Validates a live ID argument.
	 *
	 * @param mixed $value         Raw ID.
	 * @param bool  $require_marker Whether the live must be e2e or demo content.
	 * @return int
	 */
	private function get_live_id( $value, bool $require_marker = false ): int {
		$live_id = absint( $value );
		if ( ! Live::is_live( $live_id ) ) {
			WP_CLI::error( sprintf( 'Post %d is not a live.', $live_id ) );
		}
		if ( $require_marker ) {
			$this->assert_marked( $live_id );
		}
		return $live_id;
	}

	/**
	 * Refuses to touch content not created for tests or demos.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function assert_marked( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
		}
		$marked_id = in_array( $post->post_type, [ Post_Types::ENTRY, Post_Types::QUESTION ], true ) ? (int) $post->post_parent : $post_id;
		if ( ! get_post_meta( $marked_id, self::E2E_META, true ) && ! get_post_meta( $marked_id, self::DEMO_META, true ) ) {
			WP_CLI::error( sprintf( 'Post %d is not e2e or demo content; refusing to modify it.', $post_id ) );
		}
	}

	/**
	 * Copies the e2e or demo marker of a live to a child post.
	 *
	 * @param int $live_id  Live ID.
	 * @param int $child_id Entry or question ID.
	 * @return void
	 */
	private function copy_marker( int $live_id, int $child_id ): void {
		foreach ( [ self::E2E_META, self::DEMO_META ] as $meta ) {
			if ( get_post_meta( $live_id, $meta, true ) ) {
				update_post_meta( $child_id, $meta, 1 );
			}
		}
	}

	/**
	 * Resolves a user login or ID; defaults to the first administrator.
	 *
	 * @param mixed $value Login or ID.
	 * @return int User ID.
	 */
	private function resolve_user( $value ): int {
		if ( '' === (string) $value ) {
			$admins = get_users(
				[
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				]
			);
			return $admins ? (int) $admins[0] : 0;
		}
		$user = ctype_digit( (string) $value ) ? get_userdata( (int) $value ) : get_user_by( 'login', (string) $value );
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User %s not found.', $value ) );
		}
		return (int) $user->ID;
	}

	/**
	 * Exits with an error on WP_Error, else returns the value.
	 *
	 * @param mixed $result Result.
	 * @return mixed
	 */
	private function check( $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}
		return $result;
	}

	/**
	 * Formats a timestamp for display (UTC ISO 8601), '-' for 0.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private function format_time( int $timestamp ): string {
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : '-';
	}
}
