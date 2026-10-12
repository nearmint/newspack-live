<?php
/**
 * Tests for the plugin bootstrap.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Plugin;
use WP_UnitTestCase;

/**
 * Plugin bootstrap tests.
 *
 * @group core
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * The plugin is loaded and its constants are defined.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'NEWSPACK_LIVE_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	/**
	 * Newspack is detected through the stubbed class.
	 */
	public function test_newspack_is_detected(): void {
		$this->assertTrue( Plugin::is_newspack_active() );
	}

	/**
	 * The loaded action fired during bootstrap.
	 */
	public function test_loaded_action_fired(): void {
		$this->assertSame( 1, did_action( 'newspack_live_loaded' ) );
	}
}
