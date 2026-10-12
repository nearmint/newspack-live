<?php
/**
 * Template selection tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Templates;
use WP_UnitTestCase;

/**
 * Query vars, block template, content replacement guard.
 *
 * @group render
 */
class Test_Templates extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * The two front-end query vars are registered.
	 */
	public function test_query_vars(): void {
		$vars = apply_filters( 'query_vars', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		foreach ( [ 'live-entry', 'live-before' ] as $var ) {
			$this->assertContains( $var, $vars );
		}
	}

	/**
	 * The block template is registered for block themes.
	 */
	public function test_block_template_registered(): void {
		$registry = \WP_Block_Templates_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( Templates::BLOCK_TEMPLATE ) );
		$template = $registry->get_registered( Templates::BLOCK_TEMPLATE );
		$this->assertStringContainsString( '<!-- wp:newspack-live/live', $template->content );
		$this->assertStringContainsString( '"slug":"header"', $template->content );
	}

	/**
	 * Non-live singular pages keep their hierarchy and content.
	 */
	public function test_non_live_untouched(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 0, Templates::get_current_live_id() );
		$this->assertSame( [ 'single.php' ], Templates::template_hierarchy( [ 'single.php' ] ) );
		$this->assertSame( 'content', Templates::replace_content( 'content' ) );
	}

	/**
	 * A live is detected on its singular page.
	 */
	public function test_live_detected(): void {
		$live_id = $this->create_live();
		$this->go_to( get_permalink( $live_id ) );
		$this->assertSame( $live_id, Templates::get_current_live_id() );
	}
}
