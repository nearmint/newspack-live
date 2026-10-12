<?php
/**
 * Private (console) REST controller.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\REST;

use Newspack_Live\Content;
use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Labels;
use Newspack_Live\Live;
use Newspack_Live\Live_Meta;
use Newspack_Live\Post_Types;
use Newspack_Live\Question_Groups;
use Newspack_Live\Questions;
use Newspack_Live\Renderer;
use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Console routes. Every route checks a live- or entry-scoped capability; the
 * entry's live must match the route's live. Cookie auth + `X-WP-Nonce`.
 */
final class REST_Private extends WP_REST_Controller {

	/**
	 * Questions per page in the inbox.
	 */
	public const QUESTIONS_PER_PAGE = 50;

	/**
	 * Published entries returned by a console bootstrap.
	 */
	public const BOOTSTRAP_ENTRIES = Feed::MAX_PER_PAGE;

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = REST_Public::NAMESPACE;

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
	 * Arguments shared by entry create and update.
	 *
	 * @return array
	 */
	private function entry_args(): array {
		return [
			'title'        => [
				'type'      => 'string',
				'maxLength' => 500,
			],
			'content'      => [ 'type' => 'string' ],
			'time'         => [
				'type'    => [ 'integer', 'null' ],
				'minimum' => 1,
			],
			'pinned'       => [ 'type' => 'boolean' ],
			'author'       => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'status'       => [
				'type' => 'string',
				'enum' => Entries::STATUSES,
			],
			'question_ids' => [
				'type'     => 'array',
				'items'    => [ 'type' => 'integer' ],
				'maxItems' => Entries::MAX_QUESTIONS,
			],
			'compose_ms'   => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			// A preset slug, `custom:<id>` or ''; checked by Entries::save().
			'label'        => [
				'type'      => 'string',
				'maxLength' => 40,
			],
		];
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$live  = '/lives/(?P<id>\d+)';
		$entry = '/entries/(?P<entry_id>\d+)';
		$id    = [
			'id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];
		$eid   = [
			'entry_id' => [
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			],
		];

		$routes = [
			[
				$live . '/console',
				WP_REST_Server::READABLE,
				'get_console',
				'newspack_live_post_to_live',
				array_merge(
					$id,
					[
						'since'        => [
							'type'    => 'integer',
							'minimum' => 0,
						],
						// Drafts loaded with "Load more": refresh them down to this one.
						'drafts_until' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
					] 
				),
			],
			[ $live . '/presence/leave', WP_REST_Server::CREATABLE, 'leave_presence', 'newspack_live_post_to_live', $id ],
			[
				$live . '/console/entries',
				WP_REST_Server::READABLE,
				'get_console_entries',
				'newspack_live_post_to_live',
				array_merge(
					$id,
					[
						'list'   => [
							'type'     => 'string',
							'enum'     => [ 'feed', 'drafts' ],
							'required' => true,
						],
						'before' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					]
				),
			],
			[
				'/team-candidates',
				WP_REST_Server::READABLE,
				'get_team_candidates',
				null,
				[
					'search'  => [
						'type'      => 'string',
						'maxLength' => 100,
					],
					'include' => [
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
						'maxItems' => 100,
					],
				],
			],
			[
				$live . '/labels',
				WP_REST_Server::CREATABLE,
				'create_label',
				'newspack_live_publish_to_live',
				array_merge(
					$id,
					[
						'text' => [
							'type'      => 'string',
							'maxLength' => 200,
							'required'  => true,
						],
					]
				),
			],
			[
				$live . '/labels/(?P<label_id>[a-z0-9]{8})',
				WP_REST_Server::DELETABLE,
				'delete_label',
				'newspack_live_publish_to_live',
				array_merge(
					$id,
					[
						'label_id' => [
							'type'     => 'string',
							'pattern'  => '^[a-z0-9]{8}$',
							'required' => true,
						],
					]
				),
			],
			[ $live . '/entries', WP_REST_Server::CREATABLE, 'create_entry', 'newspack_live_post_to_live', array_merge( $id, $this->entry_args() ) ],
			[ $entry, WP_REST_Server::EDITABLE, 'update_entry', 'newspack_live_edit_entry', array_merge( $eid, $this->entry_args() ) ],
			[
				$entry . '/flags',
				WP_REST_Server::CREATABLE,
				'update_flags',
				'newspack_live_edit_entry',
				array_merge(
					$eid,
					[
						'pinned' => [ 'type' => 'boolean' ],
					]
				),
			],
			[
				$entry . '/status',
				WP_REST_Server::CREATABLE,
				'update_status',
				'newspack_live_edit_entry',
				array_merge(
					$eid,
					[
						'status' => [
							'type'     => 'string',
							'enum'     => [ 'publish', 'draft', 'trash' ],
							'required' => true,
						],
					] 
				),
			],
			[
				$live . '/state',
				WP_REST_Server::CREATABLE,
				'update_state',
				'newspack_live_manage_live',
				array_merge(
					$id,
					[
						'action' => [
							'type'     => 'string',
							'enum'     => Live::ACTIONS,
							'required' => true,
						],
					] 
				),
			],
			[
				$live . '/settings',
				WP_REST_Server::CREATABLE,
				'update_settings',
				'newspack_live_moderate_live',
				array_merge(
					$id,
					[
						'questions_open' => [
							'type'     => 'boolean',
							'required' => true,
						],
					] 
				),
			],
			[
				$live . '/questions',
				WP_REST_Server::READABLE,
				'get_questions',
				'newspack_live_moderate_live',
				array_merge(
					$id,
					[
						'status' => [
							'type'    => 'string',
							'enum'    => array_merge( [ 'all' ], Post_Types::QUESTION_STATUSES ),
							'default' => 'nl_new',
						],
						'search' => [
							'type'      => 'string',
							'maxLength' => 100,
						],
						// Cursors (question IDs): the page after `before`, or (refresh) everything down to `until`.
						'before' => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
						'until'  => [
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						],
					]
				),
			],
			[
				$live . '/questions/(?P<question_id>\d+)/ungroup',
				WP_REST_Server::CREATABLE,
				'ungroup_question',
				'newspack_live_moderate_live',
				array_merge(
					$id,
					[
						'question_id' => [
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						],
					]
				),
			],
			[
				$live . '/questions/bulk',
				WP_REST_Server::CREATABLE,
				'bulk_questions',
				'newspack_live_moderate_live',
				array_merge(
					$id,
					[
						'ids'    => [
							'type'     => 'array',
							'items'    => [ 'type' => 'integer' ],
							'minItems' => 1,
							'maxItems' => self::QUESTIONS_PER_PAGE,
							'required' => true,
						],
						'status' => [
							'type'     => 'string',
							'enum'     => [ 'nl_new', 'nl_shortlisted', 'nl_rejected', 'nl_spam' ],
							'required' => true,
						],
					] 
				),
			],
		];

		foreach ( $routes as [ $route, $methods, $callback, $cap, $args ] ) {
			register_rest_route(
				$this->namespace,
				$route,
				[
					'methods'             => $methods,
					'callback'            => [ $this, $callback ],
					'permission_callback' => null === $cap ? [ $this, 'can_manage' ] : $this->permission( $cap ),
					'args'                => $args,
				]
			);
		}
	}

	/**
	 * Builds a permission callback for a live- or entry-scoped meta capability.
	 *
	 * @param string $cap Meta capability.
	 * @return callable
	 */
	private function permission( string $cap ): callable {
		return function ( WP_REST_Request $request ) use ( $cap ) {
			return $this->check( $request, $cap );
		};
	}

	/**
	 * Checks a meta capability against the route's live or entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $cap     Meta capability.
	 * @return true|WP_Error
	 */
	private function check( WP_REST_Request $request, string $cap ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be signed in.', 'newspack-live' ), [ 'status' => 401 ] );
		}
		if ( 'newspack_live_edit_entry' === $cap ) {
			$entry = get_post( (int) $request['entry_id'] );
			if ( ! Entries::is_entry( $entry ) || ! Live::is_live( (int) $entry->post_parent ) ) {
				return new WP_Error( 'newspack_live_entry_not_found', __( 'Entry not found.', 'newspack-live' ), [ 'status' => 404 ] );
			}
			$object_id = $entry->ID;
		} else {
			$object_id = (int) $request['id'];
			if ( ! Live::is_live( $object_id ) ) {
				return new WP_Error( 'newspack_live_not_found', __( 'Live not found.', 'newspack-live' ), [ 'status' => 404 ] );
			}
		}
		if ( ! current_user_can( $cap, $object_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Permission for the team candidates search.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be signed in.', 'newspack-live' ), [ 'status' => 401 ] );
		}
		return current_user_can( 'newspack_live_manage' ) ? true : new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'newspack-live' ), [ 'status' => 403 ] );
	}

	/**
	 * Console list excerpt: the title, else the text.
	 *
	 * @param WP_Post $entry Entry.
	 * @return string
	 */
	private static function console_excerpt( WP_Post $entry ): string {
		if ( '' !== $entry->post_title ) {
			return $entry->post_title;
		}
		return Content::plain_text( $entry->post_content, 80 );
	}

	/**
	 * Console entry shape.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	public static function console_entry( int $entry_id ): array {
		$entry  = get_post( $entry_id );
		$author = get_userdata( (int) $entry->post_author );
		return [
			'id'           => $entry_id,
			'rev'          => Entries::get_rev( $entry_id ),
			'status'       => $entry->post_status,
			'title'        => $entry->post_title,
			'content'      => $entry->post_content,
			'excerpt'      => self::console_excerpt( $entry ),
			'time'         => '0000-00-00 00:00:00' === $entry->post_date_gmt ? 0 : Renderer::entry_time( $entry ),
			'modified'     => (int) strtotime( $entry->post_modified_gmt . ' UTC' ),
			'author'       => [
				'id'   => (int) $entry->post_author,
				'name' => $author instanceof WP_User ? $author->display_name : '',
			],
			'pinned'       => Entries::is_pinned( $entry_id ),
			'question_ids' => Entries::get_question_ids( $entry_id ),
			'label'        => Labels::get_value( $entry_id ),
			'permalink'    => Renderer::entry_permalink( (int) $entry->post_parent, $entry_id ),
			'edit_link'    => (string) get_edit_post_link( $entry_id, 'raw' ),
			'can_edit'     => current_user_can( 'newspack_live_edit_entry', $entry_id ),
		];
	}

	/**
	 * GET /lives/{id}/console: bootstrap (no `since`) or refresh.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_console( $request ) {
		$live_id = (int) $request['id'];
		$since   = null === $request['since'] ? null : (int) $request['since'];
		$user_id = get_current_user_id();

		// Read once: the response's rev must match the range actually queried.
		$rev   = Live::get_rev( $live_id );
		$reset = false;
		if ( null === $since ) {
			$page      = Feed::get_page( $live_id, 'desc', 0, self::BOOTSTRAP_ENTRIES, false );
			$published = $page['ids'];
			$removed   = [];
		} else {
			$published = REST_Public::get_changed_ids( $live_id, $since, $rev );
			$reset     = count( $published ) >= REST_Public::MAX_DELTA || $since < Live::get_removed_floor( $live_id );
			$removed   = [];
			foreach ( Live::get_removed( $live_id ) as $item ) {
				if ( $item['rev'] > $since ) {
					$removed[] = $item['id'];
				}
			}
			$removed = array_values( array_unique( array_diff( $removed, $published ) ) );
		}
		$drafts_page = self::get_draft_ids( $live_id, $user_id, (int) $request['drafts_until'], true );
		$drafts      = $drafts_page['ids'];
		if ( $published || $drafts ) {
			_prime_post_caches( array_merge( $published, $drafts ), true, true );
		}

		$warning = Live::get_auto_end_warning( $live_id );
		$data    = [
			'rev'            => $rev,
			'reset'          => $reset,
			'live'           => self::live_payload( $live_id ),
			'entries'        => array_map( [ __CLASS__, 'console_entry' ], $published ),
			'removed'        => $removed,
			'drafts'         => array_map( [ __CLASS__, 'console_entry' ], $drafts ),
			'drafts_more'    => $drafts_page['has_more'],
			'drafts_total'   => self::count_drafts( $live_id, $user_id ),
			'question_count' => Questions::count( $live_id ),
			'auto_end'       => [
				'warning' => $warning['show'],
				'at'      => $warning['at'],
				'hours'   => $warning['hours'],
				'message' => REST_Public::auto_end_message( $warning['at'], $warning['hours'] ),
			],
		];
		if ( null === $since ) {
			$data['team']     = self::team_payload( $live_id );
			$data['has_more'] = $page['has_more'];
			$data['user']     = self::user_payload( $live_id );
		}
		return new WP_REST_Response( $data );
	}

	/**
	 * Live state and settings for the console.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	private static function live_payload( int $live_id ): array {
		$post = get_post( $live_id );
		return [
			'id'             => $live_id,
			'title'          => get_the_title( $live_id ),
			'status'         => $post->post_status,
			'state'          => Live::get_state( $live_id ),
			'url'            => (string) get_permalink( $live_id ),
			'edit_url'       => (string) get_edit_post_link( $live_id, 'raw' ),
			'settings'       => Live::get_settings( $live_id ),
			'start'          => Live::get_start( $live_id ),
			'end'            => Live::get_end( $live_id ),
			'last_update'    => Live::get_last_update( $live_id ),
			'upcoming_label' => Live::STATE_UPCOMING === Live::get_state( $live_id ) ? Renderer::upcoming_label( $live_id ) : '',
			// Custom entry labels with their "in use" flag: teammates' new labels show up on refresh.
			'labels'         => Labels::payload( $live_id ),
		];
	}

	/**
	 * Capabilities of the current user on the live.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	private static function user_payload( int $live_id ): array {
		$user = wp_get_current_user();
		return [
			'id'              => $user->ID,
			'name'            => $user->display_name,
			'can_publish'     => current_user_can( 'newspack_live_publish_to_live', $live_id ),
			'can_manage'      => current_user_can( 'newspack_live_manage_live', $live_id ),
			'can_moderate'    => current_user_can( 'newspack_live_moderate_live', $live_id ),
			'can_edit_others' => current_user_can( 'newspack_live_edit_others_entries' ),
			'can_upload'      => current_user_can( 'upload_files' ),
		];
	}

	/**
	 * Team members (ID, name, avatar), hosts flagged.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	private static function team_payload( int $live_id ): array {
		$hosts = Live::get_host_ids( $live_id );
		$team  = [];
		foreach ( Live::get_team( $live_id ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			$team[] = [
				'id'     => $user->ID,
				'name'   => $user->display_name,
				'avatar' => (string) get_avatar_url( $user->ID, [ 'size' => 48 ] ),
				'host'   => in_array( $user->ID, $hosts, true ),
			];
		}
		return $team;
	}

	/**
	 * Drafts and pending entries of the live, newest first (all of them for users who can edit
	 * others' entries, else the user's own), one page of 50 after a cursor, or everything down to
	 * the cursor (`$through`, refresh of pages already loaded).
	 *
	 * @param int  $live_id Live ID.
	 * @param int  $user_id User ID.
	 * @param int  $cursor  Cursor entry ID (0: first page).
	 * @param bool $through Whether to return everything down to the cursor.
	 * @return array{ids: int[], has_more: bool}
	 */
	private static function get_draft_ids( int $live_id, int $user_id, int $cursor = 0, bool $through = false ): array {
		$author = current_user_can( 'newspack_live_edit_others_entries' ) ? 0 : $user_id;
		return Feed::get_status_page( $live_id, [ 'pending', 'draft' ], $author, $cursor, $through, self::BOOTSTRAP_ENTRIES );
	}

	/**
	 * Number of drafts and pending entries the user sees (the Drafts tab count).
	 *
	 * @param int $live_id Live ID.
	 * @param int $user_id User ID.
	 * @return int
	 */
	private static function count_drafts( int $live_id, int $user_id ): int {
		$args = [
			'post_type'              => Post_Types::ENTRY,
			'post_parent'            => $live_id,
			'post_status'            => [ 'pending', 'draft' ],
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
		$author = current_user_can( 'newspack_live_edit_others_entries' ) ? 0 : $user_id;
		if ( $author ) {
			$args['author'] = $author;
		}
		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * POST /lives/{id}/presence/leave: the console page was closed (who's online).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function leave_presence( $request ) {
		\Newspack_Live\Presence::leave( (int) $request['id'], get_current_user_id() );
		return new WP_REST_Response( [ 'left' => true ] );
	}

	/**
	 * GET /lives/{id}/console/entries?list=feed|drafts&before=: the next 50 entries of a console
	 * list after a cursor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_console_entries( $request ) {
		$live_id = (int) $request['id'];
		$page    = 'drafts' === $request['list']
			? self::get_draft_ids( $live_id, get_current_user_id(), (int) $request['before'] )
			: Feed::get_page( $live_id, 'desc', (int) $request['before'], self::BOOTSTRAP_ENTRIES, false );
		if ( $page['ids'] ) {
			_prime_post_caches( $page['ids'], true, true );
		}
		return new WP_REST_Response(
			[
				'items'    => array_map( [ __CLASS__, 'console_entry' ], $page['ids'] ),
				'has_more' => $page['has_more'],
			]
		);
	}

	/**
	 * GET /team-candidates?search=: users who can post entries (the core users endpoint requires `list_users`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_team_candidates( $request ) {
		$search  = trim( (string) $request['search'] );
		$include = Live_Meta::sanitize_id_list( (array) $request['include'] );
		$args    = [
			'capability' => 'newspack_live_post_entries',
			'number'     => 20,
			'orderby'    => 'display_name',
			'fields'     => [ 'ID', 'display_name' ],
		];
		if ( $include ) {
			$args['include'] = array_slice( $include, 0, 100 );
			$args['number']  = count( $args['include'] );
		}
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'display_name' ];
		}
		$users = [];
		foreach ( ( new WP_User_Query( $args ) )->get_results() as $user ) {
			$users[] = [
				'id'     => (int) $user->ID,
				'name'   => $user->display_name,
				'avatar' => (string) get_avatar_url( (int) $user->ID, [ 'size' => 48 ] ),
			];
		}
		return new WP_REST_Response( $users );
	}

	/**
	 * Extracts entry fields present in the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function entry_fields( WP_REST_Request $request ): array {
		$fields = [];
		foreach ( [ 'title', 'content', 'time', 'pinned', 'author', 'status', 'question_ids', 'compose_ms', 'label' ] as $field ) {
			if ( $request->has_param( $field ) ) {
				$fields[ $field ] = $request->get_param( $field );
			}
		}
		return $fields;
	}

	/**
	 * Field-level checks shared by create and update.
	 *
	 * @param int   $live_id  Live ID.
	 * @param array $fields   Fields.
	 * @param int   $entry_id Entry being updated, 0 on create.
	 * @return true|WP_Error
	 */
	private function check_fields( int $live_id, array $fields, int $entry_id ) {
		$status = $fields['status'] ?? ( $entry_id ? get_post_status( $entry_id ) : 'publish' );
		if ( 'publish' === $status && ! current_user_can( 'newspack_live_publish_to_live', $live_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to publish to this live.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		if ( isset( $fields['author'] ) && (int) $fields['author'] !== ( $entry_id ? (int) get_post_field( 'post_author', $entry_id ) : get_current_user_id() ) ) {
			if ( ! current_user_can( 'newspack_live_edit_others_entries' ) ) {
				return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to change the author.', 'newspack-live' ), [ 'status' => 403 ] );
			}
			if ( ! Live::is_team_member( $live_id, (int) $fields['author'] ) && ! user_can( (int) $fields['author'], 'edit_others_posts' ) ) {
				return new WP_Error( 'newspack_live_invalid_author', __( 'The author must be on the live team.', 'newspack-live' ), [ 'status' => 400 ] );
			}
		}
		// Pinning can displace a published pin: it is a publishing action.
		$pin_change = ! empty( $fields['pinned'] ) && ! ( $entry_id && Entries::is_pinned( $entry_id ) );
		if ( $pin_change && ! current_user_can( 'newspack_live_publish_to_live', $live_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to pin entries on this live.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		if ( ! empty( $fields['question_ids'] ) && ! current_user_can( 'newspack_live_moderate_live', $live_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to answer questions on this live.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Marks answered questions after a Q&A entry is published.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	private static function mark_answered( int $entry_id ): void {
		if ( 'publish' !== get_post_status( $entry_id ) ) {
			return;
		}
		// Members first, heads last: a head then only releases members that weren't quoted.
		$ids = Entries::get_question_ids( $entry_id );
		usort( $ids, static fn( int $a, int $b ) => (int) ! get_post_meta( $a, Question_Groups::META_GROUP, true ) <=> (int) ! get_post_meta( $b, Question_Groups::META_GROUP, true ) );
		foreach ( $ids as $question_id ) {
			Questions::set_status( $question_id, 'nl_answered', $entry_id );
		}
	}

	/**
	 * Converts a model result to a REST response.
	 *
	 * @param int|WP_Error $result Entry ID or error.
	 * @param int          $status HTTP status on success.
	 * @return WP_REST_Response|WP_Error
	 */
	private function entry_response( $result, int $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::mark_answered( (int) $result );
		$entry = get_post( (int) $result );
		return new WP_REST_Response(
			[
				'entry' => $entry ? self::console_entry( (int) $result ) : [ 'id' => (int) $result ],
				'rev'   => $entry ? Live::get_rev( (int) $entry->post_parent ) : 0,
			],
			$status
		);
	}

	/**
	 * Retries a write once when the live lock times out.
	 *
	 * @param callable $write Write.
	 * @return mixed
	 */
	private static function with_retry( callable $write ) {
		$result = $write();
		if ( is_wp_error( $result ) && 'newspack_live_try_again' === $result->get_error_code() ) {
			$result = $write();
		}
		return $result;
	}

	/**
	 * POST /lives/{id}/entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_entry( $request ) {
		$live_id = (int) $request['id'];
		$fields  = $this->entry_fields( $request );
		$check   = $this->check_fields( $live_id, $fields, 0 );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$fields['live_id'] = $live_id;
		$fields['type']    = Entries::TYPE_UPDATE;
		$fields['author']  = $fields['author'] ?? get_current_user_id();
		return $this->entry_response( self::with_retry( static fn() => Entries::save( $fields ) ), 201 );
	}

	/**
	 * POST /lives/{id}/labels: creates a custom label.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_label( $request ) {
		$live_id = (int) $request['id'];
		$label   = self::with_retry( static fn() => Labels::create( $live_id, (string) $request['text'] ) );
		if ( is_wp_error( $label ) ) {
			return $label;
		}
		return new WP_REST_Response(
			[
				'label'  => [
					'id'    => $label['id'],
					'value' => Labels::CUSTOM_PREFIX . $label['id'],
					'text'  => $label['text'],
					'used'  => false,
				],
				'labels' => Labels::payload( $live_id ),
			],
			201
		);
	}

	/**
	 * DELETE /lives/{id}/labels/{label_id}: deletes a custom label no entry of the live uses.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_label( $request ) {
		$live_id = (int) $request['id'];
		$label   = (string) $request['label_id'];
		$result  = self::with_retry( static fn() => Labels::delete( $live_id, $label ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'deleted' => true,
				'labels'  => Labels::payload( $live_id ),
			]
		);
	}

	/**
	 * PUT /entries/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_entry( $request ) {
		$entry_id = (int) $request['entry_id'];
		$live_id  = (int) get_post_field( 'post_parent', $entry_id );
		$fields   = $this->entry_fields( $request );
		$check    = $this->check_fields( $live_id, $fields, $entry_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		unset( $fields['type'] );
		return $this->entry_response( self::with_retry( static fn() => Entries::save( $fields, $entry_id ) ) );
	}

	/**
	 * POST /entries/{id}/flags.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_flags( $request ) {
		$entry_id = (int) $request['entry_id'];
		$flags    = [];
		foreach ( [ 'pinned' ] as $flag ) {
			if ( $request->has_param( $flag ) ) {
				$flags[ $flag ] = (bool) $request->get_param( $flag );
			}
		}
		if ( ! $flags ) {
			return new WP_Error( 'newspack_live_no_flags', __( 'Nothing to change.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( ! empty( $flags['pinned'] ) && ! current_user_can( 'newspack_live_publish_to_live', (int) get_post_field( 'post_parent', $entry_id ) ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to pin entries on this live.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		return $this->entry_response( self::with_retry( static fn() => Entries::set_flags( $entry_id, $flags ) ) );
	}

	/**
	 * POST /entries/{id}/status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_status( $request ) {
		$entry_id = (int) $request['entry_id'];
		$status   = (string) $request['status'];
		$live_id  = (int) get_post_field( 'post_parent', $entry_id );
		if ( 'publish' === $status && ! current_user_can( 'newspack_live_publish_to_live', $live_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to publish to this live.', 'newspack-live' ), [ 'status' => 403 ] );
		}
		return $this->entry_response( self::with_retry( static fn() => Entries::transition( $entry_id, $status ) ) );
	}

	/**
	 * POST /lives/{id}/state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_state( $request ) {
		$live_id = (int) $request['id'];
		$action  = (string) $request['action'];
		$result  = self::with_retry( static fn() => Live::apply_action( $live_id, $action ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'live' => self::live_payload( $live_id ),
				'rev'  => Live::get_rev( $live_id ),
			]
		);
	}

	/**
	 * POST /lives/{id}/settings: questions toggle.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( $request ) {
		$live_id = (int) $request['id'];
		$open    = (bool) $request['questions_open'];
		$result  = self::with_retry( static fn() => Live::set_questions_open( $live_id, $open ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			[
				'live' => self::live_payload( $live_id ),
				'rev'  => Live::get_rev( $live_id ),
			]
		);
	}

	/**
	 * Question shape for the inbox. Text and names are plain text: clients render them as text.
	 *
	 * @param WP_Post $question Question.
	 * @return array
	 */
	private static function question_payload( WP_Post $question ): array {
		return [
			'id'           => $question->ID,
			'text'         => $question->post_content,
			'name'         => Questions::get_display_name( $question->ID ),
			'registered'   => (int) $question->post_author > 0,
			'time'         => (int) strtotime( $question->post_date_gmt . ' UTC' ),
			'status'       => $question->post_status,
			'answer_entry' => (int) get_post_meta( $question->ID, Questions::META_ANSWER_ENTRY, true ),
		];
	}

	/**
	 * GET /lives/{id}/questions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_questions( $request ) {
		$status = (string) $request['status'];
		$search  = trim( (string) $request['search'] );
		$live_id = (int) $request['id'];
		$args    = [
			'post_type'           => Post_Types::QUESTION,
			'post_parent'         => $live_id,
			'post_status'         => 'all' === $status ? Post_Types::QUESTION_STATUSES : $status,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];
		if ( '' === $search ) {
			// Group members are listed under their group's first question.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- console inbox, one live's questions, paged.
			$args['meta_query'] = [
				[
					'key'     => Question_Groups::META_GROUP,
					'compare' => 'NOT EXISTS',
				],
			];
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		// Cursor paging on the reader feed's keyset: the page after `before`, or (refresh)
		// everything from the newest down to `until`.
		$cursor_of = static function ( int $id ) use ( $live_id ): ?WP_Post {
			$post = $id > 0 ? get_post( $id ) : null;
			return $post instanceof WP_Post && Post_Types::QUESTION === $post->post_type && $live_id === (int) $post->post_parent ? $post : null;
		};
		$until = $cursor_of( (int) $request['until'] );
		if ( $until ) {
			$found    = Feed::posts_down_to( $args, $until );
			$posts    = $found['posts'];
			$has_more = $found['has_more'];
		} else {
			$before = $cursor_of( (int) $request['before'] );
			if ( (int) $request['before'] > 0 && ! $before ) {
				$posts    = [];
				$has_more = false;
			} else {
				$query    = new \WP_Query( array_merge( $args, [ 'posts_per_page' => self::QUESTIONS_PER_PAGE + 1 ], Feed::keyset_args( $before, 'before' ) ) );
				$has_more = count( $query->posts ) > self::QUESTIONS_PER_PAGE;
				$posts    = array_slice( $query->posts, 0, self::QUESTIONS_PER_PAGE );
			}
		}
		$members = Question_Groups::members( wp_list_pluck( $posts, 'ID' ) );
		$items   = [];
		foreach ( $posts as $post ) {
			$item = self::question_payload( $post );
			// Only members in the head's status (a member moved on its own has left the group).
			$item['similar'] = array_map(
				[ __CLASS__, 'question_payload' ],
				array_values( array_filter( $members[ $post->ID ] ?? [], static fn( WP_Post $member ) => $member->post_status === $post->post_status ) )
			);
			$items[]         = $item;
		}
		return new WP_REST_Response(
			[
				'items'    => $items,
				'has_more' => $has_more,
				'count'    => Questions::count( $live_id ),
			]
		);
	}

	/**
	 * POST /lives/{id}/questions/{question_id}/ungroup: takes a question out of its group.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ungroup_question( $request ) {
		$question_id = (int) $request['question_id'];
		if ( ! Questions::is_question( $question_id ) || (int) $request['id'] !== (int) get_post_field( 'post_parent', $question_id ) ) {
			return new WP_Error( 'newspack_live_invalid_question', __( 'A selected question does not belong to this live.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		return new WP_REST_Response( [ 'ungrouped' => Question_Groups::ungroup( $question_id ) ] );
	}

	/**
	 * POST /lives/{id}/questions/bulk.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_questions( $request ) {
		$live_id = (int) $request['id'];
		$ids     = Live_Meta::sanitize_id_list( $request['ids'] );
		foreach ( $ids as $question_id ) {
			if ( ! Questions::is_question( $question_id ) || $live_id !== (int) get_post_field( 'post_parent', $question_id ) ) {
				return new WP_Error( 'newspack_live_invalid_question', __( 'A selected question does not belong to this live.', 'newspack-live' ), [ 'status' => 400 ] );
			}
		}
		// A member moved without its head leaves the group (search lists members on their own).
		foreach ( $ids as $question_id ) {
			$head = (int) get_post_meta( $question_id, Question_Groups::META_GROUP, true );
			if ( $head > 0 && ! in_array( $head, $ids, true ) ) {
				Question_Groups::detach( $question_id );
			}
		}
		// A group moves as one: its first question carries its members.
		foreach ( Question_Groups::members( $ids ) as $members ) {
			$ids = array_merge( $ids, wp_list_pluck( $members, 'ID' ) );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		// Members first, heads last: a head then only releases members left behind.
		usort( $ids, static fn( int $a, int $b ) => (int) ! get_post_meta( $a, Question_Groups::META_GROUP, true ) <=> (int) ! get_post_meta( $b, Question_Groups::META_GROUP, true ) );
		foreach ( $ids as $question_id ) {
			$result = Questions::set_status( $question_id, (string) $request['status'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return new WP_REST_Response(
			[
				'updated' => $ids,
				'count'   => Questions::count( $live_id ),
			]
		);
	}
}
