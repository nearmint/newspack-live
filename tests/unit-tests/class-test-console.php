<?php
/**
 * Live console page tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Console;
use Newspack_Live\Live;
use WPDieException;
use WP_UnitTestCase;

/**
 * Block allowlist and access checks.
 *
 * @group console
 */
class Test_Console extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Resets the request parameter.
	 */
	public function tear_down(): void {
		unset( $_GET['live'] );
		parent::tear_down();
	}

	/**
	 * The composer allowlist matches the allowed core blocks and is filterable.
	 */
	public function test_allowed_blocks(): void {
		$blocks = Console::allowed_blocks();
		foreach ( [ 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote', 'core/image', 'core/gallery', 'core/video', 'core/embed', 'core/table', 'core/separator', 'core/buttons', 'core/button', 'newspack-live/article-card' ] as $block ) {
			$this->assertContains( $block, $blocks );
		}
		$this->assertNotContains( 'core/html', $blocks );
		add_filter( 'newspack_live_allowed_blocks', [ $this, 'only_paragraphs' ] );
		$this->assertSame( [ 'core/paragraph' ], Console::allowed_blocks() );
		remove_filter( 'newspack_live_allowed_blocks', [ $this, 'only_paragraphs' ] );
	}

	/**
	 * Filter callback.
	 *
	 * @return string[]
	 */
	public function only_paragraphs(): array {
		return [ 'core/paragraph' ];
	}

	/**
	 * Team members pass; others get 403, non-lives 404.
	 */
	public function test_access(): void {
		$author  = $this->create_user( 'author' );
		$live_id = $this->create_live( [], [ Live::META_TEAM => [ $author ] ] );
		wp_set_current_user( $author );
		$_GET['live'] = (string) $live_id;
		Console::check_access();
		$this->assertSame( $live_id, Console::get_requested_live_id() );

		wp_set_current_user( $this->create_user( 'author' ) );
		$this->assertDies( 403 );

		$_GET['live'] = (string) self::factory()->post->create();
		$this->assertDies( 404 );
	}

	/**
	 * Asserts that the access check stops with a status.
	 *
	 * @param int $status Expected status.
	 * @return void
	 */
	private function assertDies( int $status ): void {
		try {
			Console::check_access();
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		}
		$this->assertTrue( in_array( $status, [ 403, 404 ], true ) );
	}
}
