<?php
/**
 * Public REST controller.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\REST;

use Newspack_Live\Assets;
use Newspack_Live\Dates;
use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Integrations;
use Newspack_Live\Live;
use Newspack_Live\Post_Types;
use Newspack_Live\Questions;
use Newspack_Live\Question_Submission;
use Newspack_Live\Renderer;
use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reader routes: status, entries (delta, pages) and question submission.
 *
 * GET handlers never write and return nothing user-specific, so anonymous
 * responses stay cacheable.
 */
final class REST_Public extends WP_REST_Controller {

	/**
	 * Route namespace.
	 */
	public const NAMESPACE = 'newspack-live/v1';

	/**
	 * Maximum number of changed entries in one delta.
	 */
	public const MAX_DELTA = 100;

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = self::NAMESPACE;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		( new self() )->register_routes();
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$id_arg = [
			'id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];

		register_rest_route(
			self::NAMESPACE,
			'/lives/(?P<id>\d+)/status',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_status' ],
					'permission_callback' => [ $this, 'can_read_live' ],
					'args'                => $id_arg,
				],
				'schema' => [ $this, 'get_status_schema' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/lives/(?P<id>\d+)/entries',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_entries' ],
					'permission_callback' => [ $this, 'can_read_live' ],
					'args'                => array_merge(
						$id_arg,
						[
							'since'    => [
								'type'    => 'integer',
								'minimum' => 0,
							],
							'until'    => [
								'type'    => 'integer',
								'minimum' => 0,
							],
							'before'   => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'per_page' => [
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => Feed::MAX_PER_PAGE,
								'default' => Feed::PER_PAGE,
							],
						]
					),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/lives/(?P<id>\d+)/questions',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_question' ],
					'permission_callback' => [ $this, 'can_read_live' ],
					'args'                => array_merge(
						$id_arg,
						[
							// No length limits here: the §8.4 pipeline validates in order (closed, login, honeypot, …).
							'question'     => [ 'type' => 'string' ],
							'display_name' => [ 'type' => 'string' ],
							'website'      => [ 'type' => 'string' ],
							'ts'           => [ 'type' => 'string' ],
						]
					),
				],
			]
		);
	}

	/**
	 * The live must exist, be live-enabled and be readable (published, or `read_post`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_read_live( $request ) {
		$live = get_post( (int) $request['id'] );
		if ( ! $live instanceof WP_Post || ! Live::is_live( $live ) ) {
			return new WP_Error( 'newspack_live_not_found', __( 'Live not found.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		if ( 'publish' !== $live->post_status && ! current_user_can( 'read_post', $live->ID ) ) {
			return new WP_Error( 'newspack_live_not_found', __( 'Live not found.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		if ( post_password_required( $live ) ) {
			return new WP_Error( 'newspack_live_forbidden', __( 'This live is password protected.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Front-end poll interval in seconds (filter clamped to 30–120).
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function poll_interval( int $live_id ): int {
		/**
		 * Filters the front-end polling interval of a live, in seconds.
		 *
		 * @since 1.2.0
		 *
		 * @param int $interval Seconds. Default 30, clamped to 30–120.
		 * @param int $live_id  Live ID.
		 */
		$interval = (int) apply_filters( 'newspack_live_poll_interval', 30, $live_id );
		return max( 30, min( 120, $interval ) );
	}

	/**
	 * Status payload.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	public static function build_status( int $live_id ): array {
		$state    = Live::get_state( $live_id );
		$settings = Live::get_settings( $live_id );
		$updated  = Live::get_last_update( $live_id );
		return [
			'state'          => $state,
			'rev'            => Live::get_rev( $live_id ),
			'last_update'    => $updated,
			'updated_label'  => $updated > 0 ? Dates::updated_label( $updated ) : '',
			// The label's parts, so open pages correct it after midnight.
			'updated'        => $updated > 0 ? Dates::parts( $updated ) + [ 'full' => Dates::updated_full( $updated ) ] : null,
			'badge_label'    => Live::STATE_UPCOMING === $state ? Renderer::upcoming_label( $live_id ) : '',
			'poll_interval'  => self::poll_interval( $live_id ),
			'questions_open' => in_array( $state, [ Live::STATE_UPCOMING, Live::STATE_LIVE ], true ) && $settings['questions_open'],
			'questions_mode' => $settings['questions_mode'],
			'gated'          => Renderer::is_gated( $live_id ),
			// Fresh signed timestamp for the ask form: cached pages may carry an old one.
			'question_ts'    => Questions::sign_timestamp( $live_id, time() ),
		] + self::build_manager_status( $live_id );
	}

	/**
	 * Extra status fields for users who manage the live (editor panel): auto-end warning and hosts.
	 * Authenticated responses are never cached, so this doesn't leak to other readers.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	private static function build_manager_status( int $live_id ): array {
		if ( ! current_user_can( 'newspack_live_manage_live', $live_id ) ) {
			return [];
		}
		$warning = Live::get_auto_end_warning( $live_id );
		return [
			'auto_end' => [
				'warning' => $warning['show'],
				'at'      => $warning['at'],
				'hours'   => $warning['hours'],
				'message' => self::auto_end_message( $warning['at'], $warning['hours'] ),
			],
			'hosts'    => array_column( Integrations\Co_Authors_Plus::get_hosts( $live_id ), 'name' ),
		];
	}

	/**
	 * Auto-end warning copy.
	 *
	 * @param int $at    Auto-end timestamp.
	 * @param int $hours Auto-end delay.
	 * @return string
	 */
	public static function auto_end_message( int $at, int $hours ): string {
		if ( $at <= 0 ) {
			return '';
		}
		/* translators: 1: time, 2: number of hours. */
		return sprintf( _n( 'This live will end automatically at %1$s because nothing has been posted for %2$d hour.', 'This live will end automatically at %1$s because nothing has been posted for %2$d hours.', $hours, 'newspack-live' ), Dates::time( $at ), $hours );
	}

	/**
	 * GET /lives/{id}/status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) {
		return new WP_REST_Response( self::build_status( (int) $request['id'] ) );
	}

	/**
	 * GET /lives/{id}/entries: delta (`since` + `until`) or older page (`before`). Newest first only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_entries( $request ) {
		$live_id = (int) $request['id'];
		if ( Renderer::is_restricted( $live_id ) ) {
			return new WP_Error( 'newspack_live_restricted', __( 'This content is restricted.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		if ( null !== $request['since'] || null !== $request['until'] ) {
			return $this->get_delta( $live_id, (int) $request['since'], (int) $request['until'] );
		}
		return $this->get_page( $live_id, (int) $request['before'], (int) $request['per_page'] );
	}

	/**
	 * Delta between two revisions.
	 *
	 * @param int $live_id Live ID.
	 * @param int $since   Known revision.
	 * @param int $until   Target revision (≤ current).
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_delta( int $live_id, int $since, int $until ) {
		$current = Live::get_rev( $live_id );
		if ( $until > $current || $until < $since ) {
			return new WP_Error( 'newspack_live_invalid_range', __( 'Invalid revision range.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		$data = [
			'until'     => $until,
			'reset'     => $since < Live::get_removed_floor( $live_id ),
			'entries'   => [],
			'removed'   => [],
			'pinned_id' => Feed::get_pinned_id( $live_id ),
			'state'     => Live::get_state( $live_id ),
			'assets'    => [],
		];
		if ( $data['reset'] || $until === $since ) {
			return new WP_REST_Response( $data );
		}

		$changed = self::get_changed_ids( $live_id, $since, $until );
		if ( count( $changed ) >= self::MAX_DELTA ) {
			// Too many changes for one delta: the client reloads the page.
			$data['reset'] = true;
			return new WP_REST_Response( $data );
		}
		self::register_front_assets();
		$before = Assets::snapshot();
		foreach ( $changed as $entry_id ) {
			$data['entries'][] = self::entry_payload( $entry_id );
		}
		foreach ( Live::get_removed( $live_id ) as $item ) {
			if ( $item['rev'] > $since && $item['rev'] <= $until ) {
				$data['removed'][] = $item['id'];
			}
		}
		$data['removed'] = array_values( array_unique( array_diff( $data['removed'], $changed ) ) );
		$context_rev = Live::get_context_rev( $live_id );
		if ( $context_rev > $since && $context_rev <= $until ) {
			// The live's content (Context card) changed in this range.
			$data['context'] = [ 'html' => Renderer::render_context( $live_id ) ];
		}
		$data['assets'] = Assets::describe( Assets::diff( $before ) );
		return new WP_REST_Response( $data );
	}

	/**
	 * Registers front-end assets before entries are recorded, unless turned off.
	 *
	 * @return void
	 */
	private static function register_front_assets(): void {
		/**
		 * Filters whether public REST routes fire `wp_enqueue_scripts` before rendering entries, so
		 * inserted entries also get assets that themes and plugins register there. When false,
		 * inserted entries only get handles registered earlier, and these routes
		 * read the entry cache without filling it (their asset lists may be incomplete).
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( (bool) apply_filters( 'newspack_live_rest_enqueue_assets', true ) ) {
			Assets::register_front_assets();
		}
	}

	/**
	 * Published entries whose revision is in (since, until], oldest change first (bounded).
	 *
	 * @param int $live_id Live ID.
	 * @param int $since   Lower bound (exclusive).
	 * @param int $until   Upper bound (inclusive).
	 * @return int[]
	 */
	public static function get_changed_ids( int $live_id, int $since, int $until ): array {
		$query = new \WP_Query(
			[
				'post_type'              => Post_Types::ENTRY,
				'post_parent'            => $live_id,
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_DELTA,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- scoped to one live's entries (parent index).
				'meta_key'               => Entries::META_REV,
				'orderby'                => 'meta_value_num',
				'order'                  => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- revision range on one live's entries.
				'meta_query'             => [
					[
						'key'     => Entries::META_REV,
						'value'   => [ $since + 1, $until ],
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Entry payload: `{ id, rev, time, html, pinned, is_qa }`.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	public static function entry_payload( int $entry_id ): array {
		$entry   = get_post( $entry_id );
		$live_id = (int) $entry->post_parent;
		return [
			'id'     => $entry_id,
			'rev'    => Entries::get_rev( $entry_id ),
			'time'   => Renderer::entry_time( $entry ),
			'html'   => Renderer::render_entry( $entry_id, [ 'show_author' => (bool) Live::get_settings( $live_id )['show_entry_author'] ] ),
			'pinned' => Entries::is_pinned( $entry_id ),
			'is_qa'  => [] !== Entries::get_question_ids( $entry_id ),
		];
	}

	/**
	 * A page of entries, newest first, continuing after a cursor.
	 *
	 * @param int $live_id  Live ID.
	 * @param int $cursor   Cursor entry, 0 for the first page.
	 * @param int $per_page Page size.
	 * @return WP_REST_Response
	 */
	private function get_page( int $live_id, int $cursor, int $per_page ) {
		$page = Feed::get_page( $live_id, 'desc', $cursor, $per_page );
		self::register_front_assets();
		$before = Assets::snapshot();
		if ( $page['ids'] ) {
			_prime_post_caches( $page['ids'], true, true );
		}
		$entries = array_map( [ __CLASS__, 'entry_payload' ], $page['ids'] );

		$last = $page['ids'] ? (int) end( $page['ids'] ) : 0;
		return new WP_REST_Response(
			[
				'entries'     => $entries,
				'has_more'    => $page['has_more'],
				'next_before' => $page['has_more'] ? $last : 0,
				'assets'      => Assets::describe( Assets::diff( $before ) ),
			]
		);
	}

	/**
	 * POST /lives/{id}/questions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_question( $request ) {
		$result = Question_Submission::submit( (int) $request['id'], $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( [ 'ok' => true ], 201 );
	}

	/**
	 * Status schema.
	 *
	 * @return array
	 */
	public function get_status_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'newspack-live-status',
			'type'       => 'object',
			'properties' => [
				'state'          => [
					'type' => 'string',
					'enum' => [ Live::STATE_DRAFT, Live::STATE_UPCOMING, Live::STATE_LIVE, Live::STATE_ENDED ],
				],
				'rev'            => [ 'type' => 'integer' ],
				'last_update'    => [ 'type' => 'integer' ],
				'updated_label'  => [ 'type' => 'string' ],
				'updated'        => [
					'type'       => [ 'object', 'null' ],
					'properties' => [
						'iso'       => [ 'type' => 'string' ],
						'day'       => [ 'type' => 'string' ],
						'clock'     => [ 'type' => 'string' ],
						'date'      => [ 'type' => 'string' ],
						'date_year' => [ 'type' => 'string' ],
						'full'      => [ 'type' => 'string' ],
					],
				],
				'badge_label'    => [ 'type' => 'string' ],
				'poll_interval'  => [ 'type' => 'integer' ],
				'questions_open' => [ 'type' => 'boolean' ],
				'questions_mode' => [
					'type' => 'string',
					'enum' => [ 'off', 'anyone', 'registered' ],
				],
				'gated'          => [ 'type' => 'boolean' ],
				'question_ts'    => [ 'type' => 'string' ],
				'auto_end'       => [
					'description' => __( 'Auto-end warning (users who manage the live only).', 'newspack-live' ),
					'type'        => 'object',
					'properties'  => [
						'warning' => [ 'type' => 'boolean' ],
						'at'      => [ 'type' => 'integer' ],
						'hours'   => [ 'type' => 'integer' ],
						'message' => [ 'type' => 'string' ],
					],
				],
				'hosts'          => [
					'description' => __( 'Host names (users who manage the live only).', 'newspack-live' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Entry schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'newspack-live-entry',
			'type'       => 'object',
			'properties' => [
				'id'     => [ 'type' => 'integer' ],
				'rev'    => [ 'type' => 'integer' ],
				'time'   => [ 'type' => 'integer' ],
				'html'   => [ 'type' => 'string' ],
				'pinned' => [ 'type' => 'boolean' ],
				'is_qa'  => [ 'type' => 'boolean' ],
			],
		];
	}
}
