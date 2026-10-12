<?php
/**
 * Capability tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use WP_UnitTestCase;

/**
 * Role × team matrix, editor bypass and contributor limits.
 *
 * @group caps
 */
class Test_Capabilities extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Primitive capabilities per role.
	 */
	public function test_role_caps(): void {
		$expected = [
			'administrator' => [ true, true, true, true, true ],
			'editor'        => [ true, true, true, true, true ],
			'author'        => [ true, true, true, false, true ],
			'contributor'   => [ false, true, false, false, false ],
			'subscriber'    => [ false, false, false, false, false ],
		];
		$caps     = [ 'newspack_live_manage', 'newspack_live_post_entries', 'newspack_live_publish_entries', 'newspack_live_edit_others_entries', 'newspack_live_moderate' ];
		foreach ( $expected as $role => $grants ) {
			$user_id = $this->create_user( $role );
			foreach ( $caps as $i => $cap ) {
				$this->assertSame( $grants[ $i ], user_can( $user_id, $cap ), "$role / $cap" );
			}
		}
	}

	/**
	 * Team-scoped meta capabilities for every role, on and off the team.
	 */
	public function test_team_matrix(): void {
		$owner   = $this->create_user( 'author' );
		$live_id = $this->create_live( [ 'post_author' => $owner ] );

		$cases = [
			// role, on team, post_to, publish_to, moderate.
			[ 'administrator', false, true, true, true ],
			[ 'editor', false, true, true, true ],
			[ 'author', true, true, true, true ],
			[ 'author', false, false, false, false ],
			[ 'contributor', true, true, false, false ],
			[ 'contributor', false, false, false, false ],
			[ 'subscriber', true, false, false, false ],
		];
		foreach ( $cases as [ $role, $on_team, $post, $publish, $moderate ] ) {
			$user_id = $this->create_user( $role );
			if ( $on_team ) {
				update_post_meta( $live_id, Live::META_TEAM, [ $user_id ] );
			} else {
				delete_post_meta( $live_id, Live::META_TEAM );
			}
			$label = $role . ( $on_team ? ' on team' : ' off team' );
			$this->assertSame( $post, user_can( $user_id, 'newspack_live_post_to_live', $live_id ), "$label post_to_live" );
			$this->assertSame( $publish, user_can( $user_id, 'newspack_live_publish_to_live', $live_id ), "$label publish_to_live" );
			$this->assertSame( $moderate, user_can( $user_id, 'newspack_live_moderate_live', $live_id ), "$label moderate_live" );
		}
	}

	/**
	 * Hosts (co-authors, or the post author) are always on the team.
	 */
	public function test_hosts_are_on_team(): void {
		$owner   = $this->create_user( 'author' );
		$cohost  = $this->create_user( 'author' );
		$live_id = $this->create_live( [ 'post_author' => $owner ] );
		$this->assertTrue( user_can( $owner, 'newspack_live_publish_to_live', $live_id ) );
		$this->assertFalse( user_can( $cohost, 'newspack_live_publish_to_live', $live_id ) );

		$user       = get_userdata( $cohost );
		$user->type = 'wpuser';
		$GLOBALS['newspack_live_test_coauthors'][ $live_id ] = [ get_userdata( $owner ), $user ];
		// The stub has no taxonomy write to clear the team memo, as Co-Authors Plus would.
		\Newspack_Live\Live::flush_team( $live_id );
		$this->assertTrue( user_can( $cohost, 'newspack_live_publish_to_live', $live_id ) );
		unset( $GLOBALS['newspack_live_test_coauthors'][ $live_id ] );
		\Newspack_Live\Live::flush_team( $live_id );
	}

	/**
	 * Manage requires newspack_live_manage and edit_post on the live.
	 */
	public function test_manage_live(): void {
		$owner   = $this->create_user( 'author' );
		$other   = $this->create_user( 'author' );
		$editor  = $this->create_user( 'editor' );
		$contrib = $this->create_user( 'contributor' );
		$live_id = $this->create_live( [ 'post_author' => $owner ] );
		$this->assertTrue( user_can( $owner, 'newspack_live_manage_live', $live_id ) );
		$this->assertFalse( user_can( $other, 'newspack_live_manage_live', $live_id ) );
		$this->assertTrue( user_can( $editor, 'newspack_live_manage_live', $live_id ) );
		$this->assertFalse( user_can( $contrib, 'newspack_live_manage_live', $live_id ) );
	}

	/**
	 * Meta capabilities are denied on posts that aren't lives.
	 */
	public function test_not_a_live(): void {
		$admin   = $this->create_user( 'administrator' );
		$post_id = self::factory()->post->create();
		$this->assertFalse( user_can( $admin, 'newspack_live_post_to_live', $post_id ) );
		$this->assertFalse( user_can( $admin, 'newspack_live_manage_live', $post_id ) );
	}

	/**
	 * Entry editing: own entries, others' entries, contributor limits, team scope.
	 */
	public function test_edit_entry(): void {
		$owner   = $this->create_user( 'author' );
		$author  = $this->create_user( 'author' );
		$contrib = $this->create_user( 'contributor' );
		$editor  = $this->create_user( 'editor' );
		$live_id = $this->create_live( [ 'post_author' => $owner ], [ Live::META_TEAM => [ $author, $contrib ] ] );

		$author_entry = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Author entry',
				'author'  => $author,
			]
		);
		$contrib_draft = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Contributor draft',
				'author'  => $contrib,
				'status'  => 'pending',
			]
		);

		$this->assertTrue( user_can( $author, 'newspack_live_edit_entry', $author_entry ) );
		$this->assertTrue( user_can( $author, 'edit_post', $author_entry ) );
		$this->assertFalse( user_can( $owner, 'newspack_live_edit_entry', $author_entry ), 'Authors cannot edit others\' entries.' );
		$this->assertTrue( user_can( $editor, 'newspack_live_edit_entry', $author_entry ) );
		$this->assertTrue( user_can( $editor, 'delete_post', $author_entry ) );

		$this->assertTrue( user_can( $contrib, 'newspack_live_edit_entry', $contrib_draft ) );
		Entries::transition( $contrib_draft, 'publish' );
		$this->assertFalse( user_can( $contrib, 'newspack_live_edit_entry', $contrib_draft ), 'Contributors cannot edit published entries.' );

		// Leaving the team removes access to own entries.
		update_post_meta( $live_id, Live::META_TEAM, [] );
		$this->assertFalse( user_can( $author, 'newspack_live_edit_entry', $author_entry ) );
	}

	/**
	 * Published entries are readable with the base capability; others follow the edit rules.
	 */
	public function test_read_entry(): void {
		$owner       = $this->create_user( 'author' );
		$contrib     = $this->create_user( 'contributor' );
		$subscriber  = $this->create_user( 'subscriber' );
		$live_id     = $this->create_live( [ 'post_author' => $owner ] );
		$published   = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Published',
				'author'  => $owner,
			]
		);
		$owner_draft = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Draft',
				'author'  => $owner,
				'status'  => 'draft',
			]
		);
		$this->assertTrue( user_can( $contrib, 'read_post', $published ) );
		$this->assertFalse( user_can( $subscriber, 'read_post', $published ) );
		$this->assertFalse( user_can( $contrib, 'read_post', $owner_draft ) );
		$this->assertTrue( user_can( $owner, 'read_post', $owner_draft ) );
	}

	/**
	 * Question capabilities are scoped to moderators of the live.
	 */
	public function test_question_caps(): void {
		$owner       = $this->create_user( 'author' );
		$other       = $this->create_user( 'author' );
		$live_id     = $this->create_live( [ 'post_author' => $owner ] );
		$question_id = Questions::create( $live_id, 'Why?', 'Ana' );
		$this->assertTrue( user_can( $owner, 'edit_post', $question_id ) );
		$this->assertFalse( user_can( $other, 'edit_post', $question_id ) );
	}

	/**
	 * Capabilities are removed from every role.
	 */
	public function test_remove_role_caps(): void {
		\Newspack_Live\Capabilities::remove_role_caps();
		$this->assertFalse( get_role( 'editor' )->has_cap( 'newspack_live_manage' ) );
		\Newspack_Live\Capabilities::add_role_caps();
		$this->assertTrue( get_role( 'editor' )->has_cap( 'newspack_live_manage' ) );
	}

	/**
	 * Checks without an object ID never resolve to the global post.
	 */
	public function test_no_object_id(): void {
		$editor  = $this->create_user( 'editor' );
		$live_id = $this->create_live();
		$GLOBALS['post'] = get_post( $live_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulates a global post to prove it is ignored.
		$this->assertFalse( user_can( $editor, 'newspack_live_post_to_live' ) );
		$this->assertFalse( user_can( $editor, 'newspack_live_edit_entry' ) );
		$this->assertFalse( Live::is_live( 0 ) );
		unset( $GLOBALS['post'] );
	}

	/**
	 * Non-ID arguments (objects passed by other code) are ignored without warnings.
	 */
	public function test_object_arguments(): void {
		$editor  = $this->create_user( 'editor' );
		$live_id = $this->create_live( [ 'post_author' => $editor ] );
		$this->assertTrue( user_can( $editor, 'newspack_live_post_to_live', get_post( $live_id ) ) );
		$this->assertFalse( user_can( $editor, 'newspack_live_post_to_live', new \WP_Block_Editor_Context() ) );
		$this->assertTrue( user_can( $editor, 'edit_posts', new \WP_Block_Editor_Context() ) );
	}
}
