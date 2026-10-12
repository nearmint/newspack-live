<?php
/**
 * Capabilities: role grants and live-scoped meta capabilities.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the plugin's primitive and live-scoped meta capabilities.
 */
final class Capabilities {

	/**
	 * Primitive capabilities granted per role by the setup routine.
	 */
	public const ROLE_CAPS = [
		'administrator' => [
			'newspack_live_manage',
			'newspack_live_post_entries',
			'newspack_live_publish_entries',
			'newspack_live_edit_others_entries',
			'newspack_live_moderate',
		],
		'editor'        => [
			'newspack_live_manage',
			'newspack_live_post_entries',
			'newspack_live_publish_entries',
			'newspack_live_edit_others_entries',
			'newspack_live_moderate',
		],
		'author'        => [
			'newspack_live_manage',
			'newspack_live_post_entries',
			'newspack_live_publish_entries',
			'newspack_live_moderate',
		],
		'contributor'   => [
			'newspack_live_post_entries',
		],
	];

	/**
	 * Every primitive capability of the plugin.
	 */
	public const PRIMITIVE_CAPS = [
		'newspack_live_manage',
		'newspack_live_post_entries',
		'newspack_live_publish_entries',
		'newspack_live_edit_others_entries',
		'newspack_live_moderate',
	];

	/**
	 * Team-scoped meta capabilities => the primitive capability they require.
	 */
	private const TEAM_CAPS = [
		'newspack_live_post_to_live'    => 'newspack_live_post_entries',
		'newspack_live_publish_to_live' => 'newspack_live_publish_entries',
		'newspack_live_moderate_live'   => 'newspack_live_moderate',
	];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'map_meta_cap', [ __CLASS__, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * Adds the primitive capabilities to the roles (setup routine).
	 *
	 * @return void
	 */
	public static function add_role_caps(): void {
		foreach ( self::ROLE_CAPS as $role_name => $caps ) {
			if ( function_exists( 'wpcom_vip_add_role_caps' ) ) {
				wpcom_vip_add_role_caps( $role_name, $caps );
				continue;
			}
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes the primitive capabilities from every role (uninstall).
	 *
	 * @return void
	 */
	public static function remove_role_caps(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::PRIMITIVE_CAPS as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Maps the plugin's meta capabilities and refines entry/question post capabilities.
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra arguments (object ID first).
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		// Callers pass IDs, posts, or unrelated objects (e.g. WP_Block_Editor_Context): only IDs and posts count.
		$object_id = 0;
		if ( isset( $args[0] ) && $args[0] instanceof WP_Post ) {
			$object_id = $args[0]->ID;
		} elseif ( isset( $args[0] ) && is_numeric( $args[0] ) ) {
			$object_id = (int) $args[0];
		}
		$user_id = (int) $user_id;

		// get_post( 0 ) would resolve to the global post: never refine a check without an object.
		if ( $object_id <= 0 && in_array( $cap, [ 'edit_post', 'delete_post', 'read_post' ], true ) ) {
			return $caps;
		}
		if ( $object_id <= 0 && str_starts_with( (string) $cap, 'newspack_live_' ) && ! in_array( $cap, self::PRIMITIVE_CAPS, true ) ) {
			return [ 'do_not_allow' ];
		}

		switch ( $cap ) {
			case 'newspack_live_manage_live':
				return self::map_manage_live( $user_id, $object_id );
			case 'newspack_live_post_to_live':
			case 'newspack_live_publish_to_live':
			case 'newspack_live_moderate_live':
				return self::map_team_cap( self::TEAM_CAPS[ $cap ], $user_id, $object_id );
			case 'newspack_live_edit_entry':
				return self::map_edit_entry( $user_id, $object_id );
			case 'edit_post':
			case 'delete_post':
			case 'read_post':
				return self::map_post_cap( $caps, $cap, $user_id, $object_id );
		}
		return $caps;
	}

	/**
	 * `newspack_live_manage_live`: `newspack_live_manage` + `edit_post` on the live.
	 *
	 * @param int $user_id User ID.
	 * @param int $live_id Live ID.
	 * @return string[]
	 */
	private static function map_manage_live( int $user_id, int $live_id ): array {
		if ( ! Live::is_live( $live_id ) ) {
			return [ 'do_not_allow' ];
		}
		return array_values( array_unique( array_merge( [ 'newspack_live_manage' ], map_meta_cap( 'edit_post', $user_id, $live_id ) ) ) );
	}

	/**
	 * Team-scoped capability: the primitive capability + team membership, unless the user
	 * has `edit_others_posts` (editors and administrators bypass team checks).
	 *
	 * @param string $primitive Required primitive capability.
	 * @param int    $user_id   User ID.
	 * @param int    $live_id   Live ID.
	 * @return string[]
	 */
	private static function map_team_cap( string $primitive, int $user_id, int $live_id ): array {
		if ( ! Live::is_live( $live_id ) ) {
			return [ 'do_not_allow' ];
		}
		if ( user_can( $user_id, 'edit_others_posts' ) || Live::is_team_member( $live_id, $user_id ) ) {
			return [ $primitive ];
		}
		return [ 'do_not_allow' ];
	}

	/**
	 * `newspack_live_edit_entry`: own entry + `newspack_live_post_to_live`, or
	 * `newspack_live_edit_others_entries`. Contributors only edit their own unpublished entries.
	 *
	 * @param int $user_id  User ID.
	 * @param int $entry_id Entry ID.
	 * @return string[]
	 */
	private static function map_edit_entry( int $user_id, int $entry_id ): array {
		$entry = get_post( $entry_id );
		if ( ! $entry instanceof WP_Post || Post_Types::ENTRY !== $entry->post_type || ! Live::is_live( (int) $entry->post_parent ) ) {
			return [ 'do_not_allow' ];
		}
		if ( $user_id > 0 && (int) $entry->post_author === $user_id ) {
			if ( 'publish' === $entry->post_status && ! user_can( $user_id, 'newspack_live_publish_entries' ) ) {
				return [ 'do_not_allow' ];
			}
			return map_meta_cap( 'newspack_live_post_to_live', $user_id, (int) $entry->post_parent );
		}
		return [ 'newspack_live_edit_others_entries' ];
	}

	/**
	 * Refines core post capabilities for entries and questions so the full editor and core
	 * REST respect the live rules.
	 *
	 * @param string[] $caps      Capabilities computed by core.
	 * @param string   $cap       `edit_post`, `delete_post` or `read_post`.
	 * @param int      $user_id   User ID.
	 * @param int      $object_id Post ID.
	 * @return string[]
	 */
	private static function map_post_cap( array $caps, string $cap, int $user_id, int $object_id ): array {
		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return $caps;
		}
		if ( Post_Types::ENTRY === $post->post_type ) {
			if ( 'read_post' === $cap && 'publish' === $post->post_status ) {
				return [ 'newspack_live_post_entries' ];
			}
			return self::map_edit_entry( $user_id, $object_id );
		}
		if ( Post_Types::QUESTION === $post->post_type ) {
			return self::map_team_cap( 'newspack_live_moderate', $user_id, (int) $post->post_parent );
		}
		return $caps;
	}
}
