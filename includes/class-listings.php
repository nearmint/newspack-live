<?php
/**
 * State badges of lives in post listings.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Lives are listed like articles, with a state badge before their title: "● Live",
 * "Upcoming live" or "Live ended". The badge is self-styled (inline styles), so listings load no
 * plugin stylesheet; `the_title` is never filtered.
 */
final class Listings {

	/**
	 * Inline styles of the badge (white text: every background has a contrast of at least 4.5:1).
	 */
	private const BADGE_STYLE = 'display:inline-flex;align-items:center;gap:0.4em;padding:0.2em 0.75em;border-radius:999px;color:#fff;font-size:0.75em;font-weight:700;letter-spacing:0.04em;line-height:1.4;text-transform:uppercase;vertical-align:middle;white-space:nowrap;';
	private const DOT_STYLE   = 'display:inline-block;width:0.6em;height:0.6em;border-radius:50%;background:currentcolor';

	/**
	 * Badge background per state.
	 */
	private const COLORS = [
		Live::STATE_LIVE     => '#c8102e',
		Live::STATE_UPCOMING => '#1d4ed8',
		Live::STATE_ENDED    => '#4b5563',
	];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'newspack_theme_categories', [ __CLASS__, 'theme_categories' ] );
		add_filter( 'render_block_core/post-title', [ __CLASS__, 'post_title_block' ], 10, 3 );
	}

	/**
	 * Badge HTML of a post, or '' when it isn't a published live.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function badge( int $post_id ): string {
		$state = Live::get_state( $post_id );
		if ( ! isset( self::COLORS[ $state ] ) ) {
			return '';
		}
		$style = esc_attr( self::BADGE_STYLE . 'background:' . self::COLORS[ $state ] );
		switch ( $state ) {
			case Live::STATE_LIVE:
				return '<span class="newspack-live-badge newspack-live-badge--live" style="' . $style . '"><span class="newspack-live-badge__dot" style="' . esc_attr( self::DOT_STYLE ) . '" aria-hidden="true"></span>' . esc_html_x( 'Live', 'listing badge', 'newspack-live' ) . '</span>';
			case Live::STATE_UPCOMING:
				return '<span class="newspack-live-badge newspack-live-badge--upcoming" style="' . $style . '">' . esc_html_x( 'Upcoming live', 'listing badge', 'newspack-live' ) . '</span>';
			default:
				return '<span class="newspack-live-badge newspack-live-badge--ended" style="' . $style . '">' . esc_html_x( 'Live ended', 'listing badge', 'newspack-live' ) . '</span>';
		}
	}

	/**
	 * Classic Newspack themes (newspack-theme and its child themes): archives and search results print
	 * the category line right above the title through `newspack_categories()`; the badge opens it.
	 * Not on the post's own page (lives have their own header).
	 *
	 * @param string $categories Category links HTML (filtered by the theme with `wp_kses_post()`).
	 * @return string
	 */
	public static function theme_categories( $categories ) {
		$post_id = (int) get_the_ID();
		if ( ! is_string( $categories ) || $post_id <= 0 || ( is_singular() && get_queried_object_id() === $post_id ) ) {
			return $categories;
		}
		$badge = self::badge( $post_id );
		return '' === $badge ? $categories : $badge . ' ' . $categories;
	}

	/**
	 * Block themes: the post title of listings (Query Loops on archives, search, home, related posts).
	 * The post template passes only `postId`/`postType` to its blocks (no `queryId`), so like the
	 * classic path: everywhere but the post's own page.
	 *
	 * @param string    $content  Block HTML.
	 * @param array     $block    Parsed block.
	 * @param \WP_Block $instance Block instance.
	 * @return string
	 */
	public static function post_title_block( $content, $block, $instance = null ) {
		if ( ! is_string( $content ) || ! $instance instanceof \WP_Block || ! isset( $instance->context['postId'] ) ) {
			return $content;
		}
		$post_id = (int) $instance->context['postId'];
		if ( is_singular() && get_queried_object_id() === $post_id ) {
			return $content;
		}
		$badge = self::badge( $post_id );
		if ( '' === $badge ) {
			return $content;
		}
		// Inside the heading, before the title (and its link).
		return (string) preg_replace_callback(
			'#^(\s*<h[1-6][^>]*>)#',
			static fn( array $match ): string => $match[1] . $badge . ' ',
			$content,
			1
		);
	}
}
