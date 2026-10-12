<?php
/**
 * End-to-end test helpers, active only with NEWSPACK_LIVE_E2E.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Test-only behaviour switched on by the constant NEWSPACK_LIVE_E2E: fake ads in empty slots.
 */
final class E2E {

	/**
	 * Marker set when e2e runs create content (post meta) or users (user meta).
	 */
	public const MARKER = '_newspack_live_e2e';

	/**
	 * Posts an e2e run created, by marker only: the meta set at creation, or an author that is
	 * an e2e user (posts created in the browser by e2e users, such as editor auto-drafts). Never by
	 * post type or status: every type and every status is searched.
	 *
	 * @param int $limit Maximum number of IDs.
	 * @return int[]
	 */
	public static function content_ids( int $limit = 100 ): array {
		$base = [
			'post_type'           => array_values( get_post_types() ),
			'post_status'         => array_values( get_post_stati() ),
			'posts_per_page'      => $limit,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'orderby'             => 'ID',
			'order'               => 'ASC',
		];
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- test cleanup, batched.
		$ids     = get_posts( $base + [ 'meta_key' => self::MARKER ] );
		$authors = get_users(
			[
				'fields'   => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the e2e users (a handful).
				'meta_key' => self::MARKER,
			]
		);
		if ( $authors ) {
			$ids = array_merge( $ids, get_posts( $base + [ 'author__in' => array_map( 'intval', $authors ) ] ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}


	/**
	 * Hooks the class into WordPress when the constant is on.
	 *
	 * @return void
	 */
	public static function init(): void {
		/*
		 * NEWSPACK_LIVE_E2E (bool, default false): set by the Playwright global setup and removed by its
		 * teardown. Never defined in production.
		 */
		if ( defined( 'NEWSPACK_LIVE_E2E' ) && NEWSPACK_LIVE_E2E ) {
			add_filter( 'newspack_live_ad_html', [ __CLASS__, 'fake_ad' ], 10, 2 );
			add_action( 'send_headers', [ __CLASS__, 'send_header' ] );
			add_action( 'init', [ __CLASS__, 'register_probe_block' ] );
			// Registered like most theme and plugin assets: on `wp_enqueue_scripts`, which REST
			// requests don't fire.
			add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_probe_script' ] );
		}
	}

	/**
	 * Marks responses as served in e2e mode, so the Playwright setup can wait until PHP has picked
	 * up the constant (OPcache revalidates `wp-config.php` only every few seconds).
	 *
	 * @return void
	 */
	public static function send_header(): void {
		header( 'X-Newspack-Live-E2E: 1' );
	}

	/**
	 * Test block whose script needs its localized data and inline code.
	 *
	 * @return void
	 */
	public static function register_probe_block(): void {
		register_block_type(
			'newspack-live-e2e/inline-probe',
			[
				'render_callback' => [ __CLASS__, 'render_probe' ],
			]
		);
	}

	/**
	 * Inline-only script with localized data and inline code: marks probes with the data's label.
	 *
	 * @return void
	 */
	public static function register_probe_script(): void {
		wp_register_script( 'nl-e2e-probe', false, [], NEWSPACK_LIVE_VERSION, true );
		wp_localize_script( 'nl-e2e-probe', 'nlProbe', [ 'label' => 'ok' ] );
		// Marks the probes present now and those inserted later (entry markup carries no inline script).
		wp_add_inline_script( 'nl-e2e-probe', 'window.nlProbeMark = function () { document.querySelectorAll( ".nl-inline-probe:not([data-ready])" ).forEach( function ( el ) { el.dataset.ready = window.nlProbe.label; } ); }; window.nlProbeMark(); new MutationObserver( window.nlProbeMark ).observe( document.body, { childList: true, subtree: true } );' );
	}

	/**
	 * Probe markup, marked by the probe script.
	 *
	 * @return string
	 */
	public static function render_probe(): string {
		wp_enqueue_script( 'nl-e2e-probe' );
		return '<div class="nl-inline-probe"></div>';
	}

	/**
	 * Fills empty ad slots with a recognizable fake ad.
	 *
	 * @param string $html       Slot HTML.
	 * @param int    $slot_index Slot index.
	 * @return string
	 */
	public static function fake_ad( $html, $slot_index ) {
		return '' !== trim( (string) $html ) ? $html : '<div class="nl-test-ad">Ad</div>';
	}
}
