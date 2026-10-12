<?php
/**
 * Homepage Posts badge.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Integrations;

use Newspack_Live\Listings;

defined( 'ABSPATH' ) || exit;

/**
 * State badge on live teasers of Newspack's Homepage Posts block, on classic and
 * block themes.
 */
final class Homepage_Posts {

	/**
	 * Newspack's Homepage Posts block.
	 */
	public const BLOCK = 'newspack-blocks/homepage-articles';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'newspack_blocks_content_loop_entry_wrapper_start', [ __CLASS__, 'render_badge' ], 10, 2 );
	}

	/**
	 * Prints the badge in a Homepage Posts teaser, before its title (self-styled: no stylesheet to
	 * load). The badge gets its own line with a bottom margin: the category line or the title
	 * that follows is a block with no top margin.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $attributes Block attributes.
	 * @return void
	 */
	public static function render_badge( $post_id, $attributes = [] ): void {
		$badge = Listings::badge( (int) $post_id );
		if ( '' === $badge ) {
			return;
		}
		echo '<div class="newspack-live-badge-line" style="margin:0 0 0.5em">' . $badge . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Listings::badge().
	}
}
