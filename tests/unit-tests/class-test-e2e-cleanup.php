<?php
/**
 * E2E cleanup selection: only content an e2e run created, by its creation marker; never by
 * post type or status.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\E2E;
use Newspack_Live\Post_Types;
use WP_UnitTestCase;

/**
 * E2E cleanup.
 */
class Test_E2E_Cleanup extends WP_UnitTestCase {

	/**
	 * Marked posts (meta set at creation) and posts written by e2e users are selected, whatever
	 * their type and status; look-alikes without a marker (same type, same status) are not.
	 */
	public function test_selects_by_marker_only(): void {
		$editor   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$e2e_user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $e2e_user, E2E::MARKER, 1 );
		register_post_type( 'nl_test_newsletter' );

		$marked_page     = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'meta_input' => [ E2E::MARKER => 1 ],
			]
		);
		$marked_question = self::factory()->post->create(
			[
				'post_type'   => Post_Types::QUESTION,
				'post_status' => 'nl_spam',
				'meta_input'  => [ E2E::MARKER => 1 ],
			]
		);
		$e2e_auto_draft  = self::factory()->post->create(
			[
				'post_type'   => 'nl_test_newsletter',
				'post_status' => 'auto-draft',
				'post_author' => $e2e_user,
			]
		);
		$e2e_trashed     = self::factory()->post->create(
			[
				'post_status' => 'trash',
				'post_author' => $e2e_user,
			]
		);

		// Same types and statuses, no marker: never selected.
		$other_auto_draft = self::factory()->post->create(
			[
				'post_type'   => 'nl_test_newsletter',
				'post_status' => 'auto-draft',
				'post_author' => $editor,
			]
		);
		$other_trashed    = self::factory()->post->create(
			[
				'post_status' => 'trash',
				'post_author' => $editor,
			]
		);
		$other_page       = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other_question   = self::factory()->post->create(
			[
				'post_type'   => Post_Types::QUESTION,
				'post_status' => 'nl_spam',
			]
		);

		$ids = E2E::content_ids();
		foreach ( [ $marked_page, $marked_question, $e2e_auto_draft, $e2e_trashed ] as $id ) {
			$this->assertContains( $id, $ids );
		}
		foreach ( [ $other_auto_draft, $other_trashed, $other_page, $other_question ] as $id ) {
			$this->assertNotContains( $id, $ids );
		}
		unregister_post_type( 'nl_test_newsletter' );
	}
}
