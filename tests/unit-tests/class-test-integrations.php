<?php
/**
 * Co-Authors Plus integration and plugin bootstrap branches.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Integrations\Co_Authors_Plus;
use Newspack_Live\Live;
use Newspack_Live\Plugin;
use Newspack_Live\Post_Types;
use WP_UnitTestCase;

/**
 * Hosts with and without CAP, and the missing-Newspack notice.
 *
 * @group integrations
 */
class Test_Integrations extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Resets the CAP stub.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['newspack_live_test_coauthors'] );
		\Newspack_Live\Live::flush_team();
		parent::tear_down();
	}

	/**
	 * CAP present: WP users and guest authors, linked accounts count as team members.
	 */
	public function test_hosts_with_cap(): void {
		$owner   = $this->create_user( 'author' );
		$linked  = $this->create_user( 'author' );
		$live_id = $this->create_live( [ 'post_author' => $owner ] );

		$user       = get_userdata( $owner );
		$user->type = 'wpuser';
		$guest      = (object) [
			'ID'             => 9999,
			'display_name'   => 'Guest Host',
			'user_nicename'  => 'guest-host',
			'type'           => 'guest-author',
			'linked_account' => get_userdata( $linked )->user_login,
		];
		$unlinked   = (object) [
			'ID'            => 9998,
			'display_name'  => 'Unlinked Guest',
			'user_nicename' => 'unlinked-guest',
			'type'          => 'guest-author',
		];

		$GLOBALS['newspack_live_test_coauthors'][ $live_id ] = [ $user, $guest, $unlinked ];
		\Newspack_Live\Live::flush_team( $live_id );

		$hosts = Co_Authors_Plus::get_hosts( $live_id );
		$this->assertSame( [ $user->display_name, 'Guest Host', 'Unlinked Guest' ], array_column( $hosts, 'name' ) );
		$this->assertSame( [ 'wpuser', 'guest-author', 'guest-author' ], array_column( $hosts, 'type' ) );
		$this->assertSame( [ $owner, $linked ], Live::get_host_ids( $live_id ) );
		$this->assertTrue( Live::is_team_member( $live_id, $linked ) );
	}

	/**
	 * CAP absent: the post author is the only host.
	 */
	public function test_hosts_without_cap(): void {
		$owner   = $this->create_user( 'author' );
		$live_id = $this->create_live( [ 'post_author' => $owner ] );
		$hosts   = Co_Authors_Plus::get_hosts_from_post_author( $live_id );
		$this->assertCount( 1, $hosts );
		$this->assertSame( $owner, $hosts[0]['user_id'] );
		$this->assertSame( get_author_posts_url( $owner ), $hosts[0]['url'] );
	}

	/**
	 * Entries and questions are excluded from CAP.
	 */
	public function test_cap_exclusion(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invokes Co-Authors Plus' filter to test our callback.
		$types = apply_filters( 'coauthors_supported_post_types', [ 'post', Post_Types::ENTRY, Post_Types::QUESTION ] );
		$this->assertSame( [ 'post' ], $types );
	}

	/**
	 * The missing-Newspack notice renders for administrators only.
	 */
	public function test_missing_newspack_notice(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );
		ob_start();
		Plugin::render_missing_newspack_notice();
		$this->assertStringContainsString( 'requires the Newspack plugin', ob_get_clean() );

		wp_set_current_user( $this->create_user( 'editor' ) );
		ob_start();
		Plugin::render_missing_newspack_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
