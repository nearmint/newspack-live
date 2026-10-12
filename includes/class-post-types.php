<?php
/**
 * Entry and question post types and question statuses.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the content types of Newspack Live.
 */
final class Post_Types {

	/**
	 * Entry post type.
	 */
	public const ENTRY = 'newspack_live_entry';

	/**
	 * Question post type (post type names are limited to 20 characters).
	 */
	public const QUESTION = 'newspack_live_q';

	/**
	 * Question statuses.
	 */
	public const QUESTION_STATUSES = [ 'nl_new', 'nl_shortlisted', 'nl_answered', 'nl_rejected', 'nl_spam' ];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
		add_action( 'profile_update', [ __CLASS__, 'profile_updated' ], 10, 2 );
		add_action( 'deleted_user', [ __CLASS__, 'people_changed' ] );
		// Not `added_post_meta`: a new question isn't quoted in any cached entry yet, and readers
		// must not be able to invalidate the cache of every live by asking questions.
		add_action( 'updated_post_meta', [ __CLASS__, 'question_meta_changed' ], 10, 3 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'question_meta_changed' ], 10, 3 );
	}

	/**
	 * Cache "last changed" group of the names shown in entries (authors, question display names):
	 * part of the rendered entry cache key.
	 */
	public const PEOPLE_CACHE = 'newspack_live_people';

	/**
	 * Marks the names shown in entries as changed (a user profile was updated).
	 *
	 * @return void
	 */
	public static function people_changed(): void {
		wp_cache_set_last_changed( self::PEOPLE_CACHE );
	}

	/**
	 * Marks the names as changed when a user's display name changed (not on every profile save:
	 * readers' accounts are updated often).
	 *
	 * @param int      $user_id       User ID.
	 * @param \WP_User $old_user_data User before the update.
	 * @return void
	 */
	public static function profile_updated( $user_id, $old_user_data ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old_user_data instanceof \WP_User || $user->display_name !== $old_user_data->display_name ) {
			self::people_changed();
		}
	}

	/**
	 * Marks the names as changed when a question's display name changes.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public static function question_meta_changed( $meta_ids, $object_id, $meta_key ): void {
		if ( Questions::META_DISPLAY_NAME === $meta_key ) {
			self::people_changed();
		}
	}

	/**
	 * Registers post types and statuses.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Users are network-wide: a rename must reach every site's entry cache.
		wp_cache_add_global_groups( [ self::PEOPLE_CACHE ] );
		self::register_entry_type();
		self::register_question_type();
		self::register_question_statuses();
	}

	/**
	 * Registers the entry post type.
	 *
	 * @return void
	 */
	private static function register_entry_type(): void {
		register_post_type(
			self::ENTRY,
			[
				'labels'                => [
					'name'          => __( 'Live entries', 'newspack-live' ),
					'singular_name' => __( 'Live entry', 'newspack-live' ),
					'add_new_item'  => __( 'Add live entry', 'newspack-live' ),
					'edit_item'     => __( 'Edit live entry', 'newspack-live' ),
					'new_item'      => __( 'New live entry', 'newspack-live' ),
					'view_item'     => __( 'View live entry', 'newspack-live' ),
					'search_items'  => __( 'Search live entries', 'newspack-live' ),
					'not_found'     => __( 'No live entries found.', 'newspack-live' ),
				],
				'public'                => false,
				'publicly_queryable'    => false,
				'exclude_from_search'   => true,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_admin_bar'     => false,
				'show_in_rest'          => true,
				'rest_base'             => 'newspack-live-entries',
				'rest_controller_class' => REST\Entries_Posts_Controller::class,
				'supports'              => [ 'title', 'editor', 'author', 'revisions', 'custom-fields' ],
				'hierarchical'          => false,
				'has_archive'           => false,
				'rewrite'               => false,
				'query_var'             => false,
				'can_export'            => true,
				'delete_with_user'      => false,
				'map_meta_cap'          => true,
				'capabilities'          => [
					'edit_post'              => 'edit_newspack_live_entry',
					'read_post'              => 'read_newspack_live_entry',
					'delete_post'            => 'delete_newspack_live_entry',
					'edit_posts'             => 'newspack_live_post_entries',
					'create_posts'           => 'newspack_live_post_entries',
					'delete_posts'           => 'newspack_live_post_entries',
					'publish_posts'          => 'newspack_live_publish_entries',
					'edit_published_posts'   => 'newspack_live_publish_entries',
					'delete_published_posts' => 'newspack_live_publish_entries',
					'edit_others_posts'      => 'newspack_live_edit_others_entries',
					'delete_others_posts'    => 'newspack_live_edit_others_entries',
					'read_private_posts'     => 'newspack_live_edit_others_entries',
					'edit_private_posts'     => 'newspack_live_edit_others_entries',
					'delete_private_posts'   => 'newspack_live_edit_others_entries',
					'read'                   => 'read',
				],
			]
		);
	}

	/**
	 * Registers the private question post type.
	 *
	 * @return void
	 */
	private static function register_question_type(): void {
		register_post_type(
			self::QUESTION,
			[
				'labels'              => [
					'name'          => __( 'Live questions', 'newspack-live' ),
					'singular_name' => __( 'Live question', 'newspack-live' ),
				],
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'supports'            => [ 'author' ],
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'map_meta_cap'        => true,
				'capabilities'        => [
					'edit_post'              => 'edit_newspack_live_q',
					'read_post'              => 'read_newspack_live_q',
					'delete_post'            => 'delete_newspack_live_q',
					'edit_posts'             => 'newspack_live_moderate',
					'create_posts'           => 'newspack_live_moderate',
					'delete_posts'           => 'newspack_live_moderate',
					'publish_posts'          => 'newspack_live_moderate',
					'edit_published_posts'   => 'newspack_live_moderate',
					'delete_published_posts' => 'newspack_live_moderate',
					'edit_others_posts'      => 'newspack_live_moderate',
					'delete_others_posts'    => 'newspack_live_moderate',
					'read_private_posts'     => 'newspack_live_moderate',
					'edit_private_posts'     => 'newspack_live_moderate',
					'delete_private_posts'   => 'newspack_live_moderate',
					'read'                   => 'read',
				],
			]
		);
	}

	/**
	 * Registers the non-public question statuses.
	 *
	 * @return void
	 */
	private static function register_question_statuses(): void {
		$labels = [
			'nl_new'         => _x( 'New', 'question status', 'newspack-live' ),
			'nl_shortlisted' => _x( 'Shortlisted', 'question status', 'newspack-live' ),
			'nl_answered'    => _x( 'Answered', 'question status', 'newspack-live' ),
			'nl_rejected'    => _x( 'Rejected', 'question status', 'newspack-live' ),
			'nl_spam'        => _x( 'Spam', 'question status', 'newspack-live' ),
		];
		foreach ( $labels as $status => $label ) {
			register_post_status(
				$status,
				[
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => false,
					'protected'                 => true,
					'private'                   => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				]
			);
		}
	}
}
