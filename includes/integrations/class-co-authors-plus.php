<?php
/**
 * Co-Authors Plus integration.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Integrations;

use Newspack_Live\Post_Types;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Hosts of a live ("Live hosted by …") and exclusion of the plugin's post types from CAP.
 */
final class Co_Authors_Plus {

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'coauthors_supported_post_types', [ __CLASS__, 'exclude_post_types' ] );
	}

	/**
	 * Whether Co-Authors Plus is available.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'get_coauthors' );
	}

	/**
	 * Removes entries and questions from the post types CAP manages.
	 *
	 * @param mixed $post_types Post types.
	 * @return mixed
	 */
	public static function exclude_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}
		return array_values( array_diff( $post_types, [ Post_Types::ENTRY, Post_Types::QUESTION ] ) );
	}

	/**
	 * Hosts of a live, in byline order.
	 *
	 * @param int $live_id Live ID.
	 * @return array<int, array{user_id: int, name: string, url: string, type: string}>
	 */
	public static function get_hosts( int $live_id ): array {
		if ( ! self::is_active() ) {
			return self::get_hosts_from_post_author( $live_id );
		}
		$hosts = [];
		foreach ( (array) get_coauthors( $live_id ) as $coauthor ) {
			$host = self::normalize_coauthor( $coauthor );
			if ( null !== $host ) {
				$hosts[] = $host;
			}
		}
		return $hosts;
	}

	/**
	 * Hosts when Co-Authors Plus is absent: the post author.
	 *
	 * @param int $live_id Live ID.
	 * @return array<int, array{user_id: int, name: string, url: string, type: string}>
	 */
	public static function get_hosts_from_post_author( int $live_id ): array {
		$post = get_post( $live_id );
		if ( ! $post ) {
			return [];
		}
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user instanceof WP_User ) {
			return [];
		}
		return [
			[
				'user_id' => $user->ID,
				'name'    => $user->display_name,
				'url'     => get_author_posts_url( $user->ID, $user->user_nicename ),
				'type'    => 'wpuser',
			],
		];
	}

	/**
	 * Normalizes a CAP co-author object (WP user or guest author).
	 *
	 * Guest authors count as team members only through their linked WordPress account.
	 *
	 * @param mixed $coauthor Co-author object.
	 * @return array{user_id: int, name: string, url: string, type: string}|null
	 */
	private static function normalize_coauthor( $coauthor ): ?array {
		if ( ! is_object( $coauthor ) || empty( $coauthor->display_name ) ) {
			return null;
		}
		$type = isset( $coauthor->type ) && 'guest-author' === $coauthor->type ? 'guest-author' : 'wpuser';

		$user_id = 0;
		if ( 'wpuser' === $type ) {
			$user_id = (int) $coauthor->ID;
		} elseif ( ! empty( $coauthor->linked_account ) ) {
			$linked  = get_user_by( 'login', (string) $coauthor->linked_account );
			$user_id = $linked instanceof WP_User ? $linked->ID : 0;
		}

		$nicename = isset( $coauthor->user_nicename ) ? (string) $coauthor->user_nicename : '';
		return [
			'user_id' => $user_id,
			'name'    => (string) $coauthor->display_name,
			'url'     => get_author_posts_url( (int) $coauthor->ID, $nicename ),
			'type'    => $type,
		];
	}
}
