<?php
/**
 * Test helpers to create lives and users.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Live;

/**
 * Factory helpers shared by the test cases.
 */
trait Live_Factory {

	/**
	 * Creates a published, live-enabled post.
	 *
	 * @param array $args Post arguments.
	 * @param array $meta Meta to set.
	 * @return int Live ID.
	 */
	protected function create_live( array $args = [], array $meta = [] ): int {
		$live_id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				],
				$args
			)
		);
		update_post_meta( $live_id, Live::META_ENABLED, 1 );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $live_id, $key, $value );
		}
		return $live_id;
	}

	/**
	 * Creates a user with a role.
	 *
	 * @param string $role Role.
	 * @return int User ID.
	 */
	protected function create_user( string $role ): int {
		return self::factory()->user->create( [ 'role' => $role ] );
	}
}
