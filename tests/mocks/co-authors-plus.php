<?php
/**
 * Stub of Co-Authors Plus' get_coauthors().
 *
 * Tests set $GLOBALS['newspack_live_test_coauthors'][ $post_id ] to a list of co-author objects;
 * otherwise the post author is returned, like CAP without co-author terms.
 *
 * @package Newspack_Live
 */

if ( ! function_exists( 'get_coauthors' ) ) {
	/**
	 * Returns the co-authors of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	function get_coauthors( $post_id = 0 ) {
		$post_id = (int) $post_id;
		if ( isset( $GLOBALS['newspack_live_test_coauthors'][ $post_id ] ) ) {
			return $GLOBALS['newspack_live_test_coauthors'][ $post_id ];
		}
		$user = get_userdata( (int) get_post_field( 'post_author', $post_id ) );
		if ( ! $user ) {
			return [];
		}
		$user->type = 'wpuser';
		return [ $user ];
	}
}
