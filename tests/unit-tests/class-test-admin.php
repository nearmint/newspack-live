<?php
/**
 * Admin tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Admin;
use Newspack_Live\Live;
use WP_UnitTestCase;

/**
 * Lives list, column and row action.
 *
 * @group admin
 */
class Test_Admin extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * The Live column shows the state; the row action links to the console for team members.
	 */
	public function test_column_and_row_action(): void {
		$author  = $this->create_user( 'author' );
		$live_id = $this->create_live( [ 'post_author' => $author ] );
		ob_start();
		Admin::render_column( 'newspack_live', $live_id );
		$this->assertStringContainsString( 'newspack-live-admin-badge--live', ob_get_clean() );

		$post_id = self::factory()->post->create();
		ob_start();
		Admin::render_column( 'newspack_live', $post_id );
		$this->assertStringNotContainsString( 'badge', ob_get_clean() );

		wp_set_current_user( $author );
		$this->assertArrayHasKey( 'newspack_live_console', Admin::row_actions( [], get_post( $live_id ) ) );
		wp_set_current_user( $this->create_user( 'author' ) );
		$this->assertArrayNotHasKey( 'newspack_live_console', Admin::row_actions( [], get_post( $live_id ) ) );
		$this->assertStringContainsString( 'page=newspack-live-console&live=' . $live_id, Admin::console_url( $live_id ) );
	}

	/**
	 * The Lives list filters the posts list to live-enabled posts.
	 */
	public function test_lives_list_filter(): void {
		$live_id = $this->create_live();
		$post_id = self::factory()->post->create();
		set_current_screen( 'edit-post' );
		$GLOBALS['pagenow']           = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulates the posts list screen.
		$_GET[ Admin::LIST_VAR ]      = '1';
		$query                        = new \WP_Query();
		$GLOBALS['wp_the_query']      = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- makes the query the main query.
		$posts                        = $query->query(
			[
				'post_type' => 'post',
				'fields'    => 'ids',
			]
		);
		unset( $_GET[ Admin::LIST_VAR ] );
		set_current_screen( 'front' );
		$this->assertContains( $live_id, $posts );
		$this->assertNotContains( $post_id, $posts );
	}

	/**
	 * Managers get the auto-end warning and hosts in /status; anonymous readers don't.
	 */
	public function test_manager_status_fields(): void {
		$editor  = $this->create_user( 'editor' );
		$live_id = $this->create_live(
			[ 'post_author' => $editor ],
			[
				Live::META_START          => time() - 8 * HOUR_IN_SECONDS,
				Live::META_ACTIVITY_AT    => time() - 330 * MINUTE_IN_SECONDS,
				Live::META_AUTO_END_HOURS => 6,
			]
		);
		$anonymous = rest_do_request( '/newspack-live/v1/lives/' . $live_id . '/status' )->get_data();
		$this->assertArrayNotHasKey( 'auto_end', $anonymous );
		wp_set_current_user( $editor );
		$data = rest_do_request( '/newspack-live/v1/lives/' . $live_id . '/status' )->get_data();
		$this->assertTrue( $data['auto_end']['warning'] );
		$this->assertStringContainsString( 'because nothing has been posted for 6 hours.', $data['auto_end']['message'] );
		$this->assertNotEmpty( $data['hosts'] );
	}

	/**
	 * Live settings meta requires the manage capability.
	 */
	public function test_settings_meta_auth(): void {
		$contributor = $this->create_user( 'contributor' );
		$post_id     = self::factory()->post->create(
			[
				'post_author' => $contributor,
				'post_status' => 'draft',
			]
		);
		wp_set_current_user( $contributor );
		$this->assertFalse( \Newspack_Live\Live_Meta::auth( true, '_newspack_live_enabled', $post_id ) );
		$author = $this->create_user( 'author' );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_author' => $author,
			]
		);
		wp_set_current_user( $author );
		$this->assertTrue( \Newspack_Live\Live_Meta::auth( true, '_newspack_live_enabled', $post_id ) );
	}

	/**
	 * Status links of the Lives list keep the filter.
	 */
	public function test_view_links_keep_filter(): void {
		$this->assertSame(
			'href="' . esc_url( 'http://example.org/wp-admin/edit.php?post_status=draft&newspack_live=1' ) . '"',
			Admin::add_list_var_to_href( [ 'href="http://example.org/wp-admin/edit.php?post_status=draft"', '"', 'http://example.org/wp-admin/edit.php?post_status=draft' ] )
		);
	}
}
