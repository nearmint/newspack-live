<?php
/**
 * Newspack Ads integration.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * The aside ad placement. The feed holds editorial entries only.
 */
final class Newspack_Ads {

	public const ASIDE_PLACEMENT = 'newspack_live_aside';
	public const ASIDE_HOOK      = 'newspack_live_aside_ad';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_placements' ], 20 );
		add_action( 'newspack_live_aside_end', [ __CLASS__, 'render_aside_slot' ] );
	}

	/**
	 * Registers the aside placement with Newspack Ads.
	 *
	 * @return void
	 */
	public static function register_placements(): void {
		if ( ! class_exists( '\Newspack_Ads\Placements' ) || ! method_exists( '\Newspack_Ads\Placements', 'register_placement' ) ) {
			return;
		}
		\Newspack_Ads\Placements::register_placement(
			self::ASIDE_PLACEMENT,
			[
				'name'        => __( 'Live aside', 'newspack-live' ),
				'description' => __( 'Ad in the live sidebar', 'newspack-live' ),
				'hook_name'   => self::ASIDE_HOOK,
			]
		);
	}

	/**
	 * Whether Newspack Ads allows a placement on a live ("Hide all ads", global suppression rules,
	 * per-post placement suppression).
	 *
	 * @param int    $live_id   Live ID.
	 * @param string $placement Placement key.
	 * @return bool
	 */
	public static function placement_allowed( int $live_id, string $placement ): bool {
		if ( get_post_meta( $live_id, 'newspack_ads_suppress_ads', true ) ) {
			return false;
		}
		if ( function_exists( 'newspack_ads_should_show_ads' ) && ! newspack_ads_should_show_ads( $live_id ) ) {
			return false;
		}
		$suppressed = get_post_meta( $live_id, 'newspack_ads_suppress_ads_placements', true );
		return ! ( is_array( $suppressed ) && in_array( $placement, $suppressed, true ) );
	}

	/**
	 * Aside slot HTML: the placement's output, filtered, wrapped only when not empty.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function capture( int $live_id ): string {
		ob_start();
		/**
		 * Output hook of the live aside ad slot (the Newspack Ads placement attaches here).
		 *
		 * @since 1.2.0
		 */
		do_action( self::ASIDE_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed constant: newspack_live_aside_ad.
		$html = (string) ob_get_clean();

		/**
		 * Filters the HTML of the live aside ad slot before it is wrapped.
		 *
		 * @since 1.2.0
		 *
		 * @param string $html       Captured placement output.
		 * @param int    $slot_index Slot index: always 0 (the aside) since the feed has no slots.
		 * @param int    $live_id    Live ID.
		 */
		$html = (string) apply_filters( 'newspack_live_ad_html', $html, 0, $live_id );
		if ( '' === trim( $html ) ) {
			return '';
		}
		return '<div class="newspack-live__ad newspack-live__ad--aside">' . $html . '</div>';
	}

	/**
	 * Aside slot at the end of the aside.
	 *
	 * @param int $live_id Live ID.
	 * @return void
	 */
	public static function render_aside_slot( $live_id ): void {
		if ( ! self::placement_allowed( (int) $live_id, self::ASIDE_PLACEMENT ) ) {
			return;
		}
		echo self::capture( (int) $live_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ad markup from Newspack Ads placements (trusted), wrapper escaped in capture().
	}
}
