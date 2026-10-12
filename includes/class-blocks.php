<?php
/**
 * Block registration.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's blocks from the build output.
 */
final class Blocks {

	/**
	 * Block folders under build/blocks/: the live page block (placed by the templates, hidden from
	 * the inserter) and the article card of entries.
	 */
	public const BLOCKS = [ 'live', 'article-card' ];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_missing_build_notice' ] );
	}

	/**
	 * Whether the blocks are built.
	 *
	 * @return bool
	 */
	public static function is_built(): bool {
		return file_exists( NEWSPACK_LIVE_DIR . 'build/blocks/live/block.json' );
	}

	/**
	 * Registers every block that is built.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::BLOCKS as $block ) {
			$dir = NEWSPACK_LIVE_DIR . 'build/blocks/' . $block;
			if ( file_exists( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}

	/**
	 * Admin notice on an unbuilt checkout.
	 *
	 * @return void
	 */
	public static function render_missing_build_notice(): void {
		if ( self::is_built() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Newspack Live assets are not built: run "npm run build" in the plugin folder.', 'newspack-live' )
		);
	}
}
