<?php
/**
 * UI: "Live" menu, New live, Context card, time labels, the REST asset filter.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Admin;
use Newspack_Live\Assets;
use Newspack_Live\Dates;
use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Renderer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Live menu, New live, Context card and time labels.
 */
class Test_Live_UI extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Resets globals the admin screens read.
	 */
	public function tear_down(): void {
		unset( $_GET['newspack_live'], $_GET['_wpnonce'] );
		$GLOBALS['pagenow']     = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$GLOBALS['plugin_page'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$GLOBALS['taxnow']      = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Submenus of the "Live" menu a user can open, by title.
	 *
	 * @param string $role Role.
	 * @return string[]
	 */
	private function menu_for( string $role ): array {
		global $menu, $submenu;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( $this->create_user( $role ) );
		$menu    = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$submenu = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		Admin::register_menus();
		$visible = [];
		foreach ( $submenu[ Admin::lives_slug() ] ?? [] as $item ) {
			if ( current_user_can( $item[1] ) ) {
				$visible[] = $item[0];
			}
		}
		// Right after Posts (5), before position 6 (Newspack Collections uses it).
		$this->assertSame( Admin::lives_slug(), $menu['5.1'][2] ?? '', 'Top-level "Live" menu right after Posts.' );
		$this->assertArrayNotHasKey( 6, array_filter( $menu, static fn( $item ) => Admin::lives_slug() === ( $item[2] ?? '' ) ) );
		return $visible;
	}

	/**
	 * Each submenu shows only to users who can use it; nothing for subscribers.
	 */
	public function test_menu_capabilities(): void {
		foreach ( [ 'administrator', 'editor', 'author' ] as $role ) {
			$this->assertSame( [ 'Lives', 'New live' ], $this->menu_for( $role ), $role );
		}
		$this->assertSame( [ 'Lives' ], $this->menu_for( 'contributor' ) );
		$this->assertSame( [], $this->menu_for( 'subscriber' ), 'No accessible submenu: WordPress hides the top-level menu.' );
		$this->assertFalse( current_user_can( 'newspack_live_post_entries' ) );
	}

	/**
	 * The "Live" menu opens on its screens, the console and a live's editor, with the right item.
	 */
	public function test_menu_highlighting(): void {
		global $pagenow, $plugin_page, $taxnow, $post;
		wp_set_current_user( $this->create_user( 'editor' ) );

		$pagenow                  = 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$_GET['newspack_live']    = '1';
		$this->assertSame( Admin::lives_slug(), Admin::highlight_menu( 'edit.php' ) );
		$this->assertSame( Admin::lives_slug(), Admin::highlight_submenu( null ) );

		$pagenow = 'post-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( Admin::lives_slug(), Admin::highlight_menu( 'edit.php' ) );
		$this->assertSame( Admin::new_live_slug(), Admin::highlight_submenu( 'post-new.php' ) );
		unset( $_GET['newspack_live'] );

		$pagenow = 'edit-tags.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$taxnow  = 'category'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( 'edit.php', Admin::highlight_menu( 'edit.php' ), 'Other taxonomies are untouched.' );

		$pagenow     = 'admin.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$plugin_page = Admin::CONSOLE_PAGE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( Admin::lives_slug(), Admin::highlight_menu( 'options.php' ) );
		$this->assertSame( Admin::lives_slug(), Admin::highlight_submenu( null ) );
		$plugin_page = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.

		$pagenow = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$post    = get_post( $this->create_live() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( Admin::lives_slug(), Admin::highlight_menu( 'edit.php' ) );
		$this->assertSame( Admin::lives_slug(), Admin::highlight_submenu( 'edit.php' ) );
		$post = get_post( self::factory()->post->create() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$this->assertSame( 'edit.php', Admin::highlight_menu( 'edit.php' ), 'A regular post keeps Posts.' );
	}

	/**
	 * Opens core's new post screen as "New live" would.
	 *
	 * @param string $nonce Nonce to send.
	 * @return int Auto-draft ID.
	 */
	private function open_new_live( string $nonce ): int {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$GLOBALS['pagenow']    = 'post-new.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture.
		$_GET['newspack_live'] = '1';
		$_GET['_wpnonce']      = $nonce;
		return get_default_post_to_edit( 'post', true )->ID;
	}

	/**
	 * "New live" opens the editor on a draft with Live enabled, default settings, empty Context;
	 * forged or unauthorized requests get a plain post.
	 */
	public function test_new_live(): void {
		wp_set_current_user( $this->create_user( 'author' ) );
		$this->assertStringContainsString( 'post-new.php?newspack_live=1&_wpnonce=', Admin::new_live_slug() );
		$live = $this->open_new_live( wp_create_nonce( Admin::NEW_NONCE ) );
		$this->assertTrue( Live::is_live( $live ) );
		$this->assertSame( '', get_post_field( 'post_content', $live ) );
		$this->assertSame( '', get_post_meta( $live, \Newspack_Live\Context::META, true ) );
		$this->assertSame( 'auto-draft', get_post_status( $live ) );
		$this->assertSame( '', get_post_meta( $live, Live::META_SCHEDULED_START, true ), 'Default settings.' );

		$this->assertFalse( Live::is_live( $this->open_new_live( 'forged' ) ), 'Forged nonce.' );

		wp_set_current_user( $this->create_user( 'contributor' ) );
		$this->assertFalse( Live::is_live( $this->open_new_live( wp_create_nonce( Admin::NEW_NONCE ) ) ), 'No newspack_live_manage.' );
	}

	/**
	 * One Context card above the ask form, hidden when empty; an empty aside isn't printed.
	 */
	public function test_context_card_and_layout(): void {
		$category = self::factory()->category->create( [ 'name' => 'Alone' ] );
		$live     = $this->create_live(
			[ 'post_category' => [ $category ] ],
			[ \Newspack_Live\Context::META => '<p>What is at stake.</p>' ]
		);
		Entries::save(
			[
				'live_id' => $live,
				'title'   => 'An update',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		$html = Renderer::render_live( $live );
		$this->assertMatchesRegularExpression( '#<h2 class="newspack-live__context-title"[^>]*><button[^>]*aria-expanded="true"[^>]*><span[^>]*>Context</span>#', $html );
		$this->assertLessThan( strpos( $html, 'id="newspack-live-ask"' ), strpos( $html, 'id="newspack-live-context"' ), 'Context above the ask form.' );
		// Both titles are disclosure buttons, open by default.
		$this->assertStringContainsString( 'aria-controls="newspack-live-context-panel"', $html );
		$this->assertMatchesRegularExpression( '#<h2 class="newspack-live__section-title" id="newspack-live-ask-title"><button type="button" class="newspack-live__expander" data-action="toggle" aria-expanded="true" aria-controls="newspack-live-ask-panel">#', $html );
		$this->assertStringNotContainsString( 'newspack-live__toggle', $html );
		$this->assertLessThan( strpos( $html, 'id="newspack-live-feed"' ), strpos( $html, 'id="newspack-live-ask"' ) );
		$this->assertStringNotContainsString( 'Updated', substr( $html, (int) strpos( $html, 'id="newspack-live-context"' ), 400 ) );
		$this->assertStringNotContainsString( 'newspack-live__aside', $html, 'Nothing for the aside: no empty column.' );
		$this->assertStringNotContainsString( 'newspack-live__layout--aside', $html, 'Single-column layout without an aside.' );

		update_post_meta( $live, \Newspack_Live\Context::META, '' );
		$this->assertStringContainsString( '<section class="newspack-live__context" id="newspack-live-context" hidden></section>', Renderer::render_live( $live ) );
	}

	/**
	 * The time opens the card's top row, then "Pinned", then the share
	 * icon at the end edge.
	 */
	public function test_time_in_card_row(): void {
		$live  = $this->create_live();
		$entry = Entries::save(
			[
				'live_id' => $live,
				'title'   => 'Pinned one',
				'pinned'  => true,
				'author'  => $this->create_user( 'editor' ),
			]
		);
		$html = Renderer::render_entry( $entry );
		$this->assertMatchesRegularExpression( '#^<article[^>]*>\s*<div class="newspack-live-entry__card">\s*<header class="newspack-live-entry__meta">\s*<time class="newspack-live-entry__time" datetime="[^"]+"[^>]*>.*?</time>\s*<span class="newspack-live-entry__pinned">Pinned</span>\s*<details class="newspack-live-share newspack-live-share--entry"#s', $html );
	}

	/**
	 * Labels in a non-UTC site timezone and the site's time format.
	 */
	public function test_time_labels(): void {
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'time_format', 'g:i A' );
		// 2026-10-06 03:30 UTC = Oct 5, 11:30 PM in New York.
		$now = (int) strtotime( '2026-10-06 03:30:00 UTC' );
		$this->assertSame( '10:48 PM', Dates::entry_label( (int) strtotime( '2026-10-06 02:48:00 UTC' ), $now ) );
		$this->assertSame( 'Yesterday, 10:48 PM', Dates::entry_label( (int) strtotime( '2026-10-05 02:48:00 UTC' ), $now ) );
		$this->assertSame( 'Sep 27, 10:48 PM', Dates::entry_label( (int) strtotime( '2026-09-28 02:48:00 UTC' ), $now ) );
		$this->assertSame( 'Sep 27, 2025, 10:48 PM', Dates::entry_label( (int) strtotime( '2025-09-28 02:48:00 UTC' ), $now ) );

		$element = Dates::time_element( (int) strtotime( '2026-10-06 02:48:00 UTC' ) );
		$this->assertStringContainsString( 'data-day="2026-10-05"', $element, 'Site date, not the UTC one.' );
		$this->assertStringContainsString( 'data-clock="10:48 PM"', $element );
		$this->assertStringContainsString( 'data-date-year="Oct 5, 2026"', $element );
		$this->assertMatchesRegularExpression( '#<span class="newspack-live__sr-only">[^<]*2026[^<]*10:48 PM</span>#', $element, 'Full date for assistive technology.' );
	}

	/**
	 * The header "Updated" line in a non-UTC site timezone and the site's time format, with a
	 * fixed clock: today, yesterday, this year, another year; the `<time>` carries what the view module
	 * needs after midnight, and `/status` sends the same parts.
	 */
	public function test_header_updated_labels(): void {
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'time_format', 'g:i a' );
		// 2026-10-06 03:30 UTC = Oct 5, 11:30 pm in New York.
		$now = (int) strtotime( '2026-10-06 03:30:00 UTC' );
		$this->assertSame( 'Updated 8:57 am', Dates::updated_label( (int) strtotime( '2026-10-05 12:57:00 UTC' ), $now ) );
		$this->assertSame( 'Updated yesterday at 8:57 am', Dates::updated_label( (int) strtotime( '2026-10-04 12:57:00 UTC' ), $now ) );
		$this->assertSame( 'Updated Sep 27 at 8:57 am', Dates::updated_label( (int) strtotime( '2026-09-27 12:57:00 UTC' ), $now ) );
		$this->assertSame( 'Updated Sep 27, 2025 at 8:57 am', Dates::updated_label( (int) strtotime( '2025-09-27 12:57:00 UTC' ), $now ) );
		// 11:30 pm in New York is already Oct 6 in UTC: still "today" for the site.
		$this->assertSame( 'Updated 11:10 pm', Dates::updated_label( (int) strtotime( '2026-10-06 03:10:00 UTC' ), $now ) );

		$element = Dates::updated_element( (int) strtotime( '2026-10-06 03:10:00 UTC' ) );
		$this->assertStringContainsString( 'class="newspack-live__updated"', $element );
		$this->assertStringContainsString( 'data-day="2026-10-05"', $element );
		$this->assertStringContainsString( 'data-clock="11:10 pm"', $element );
		$this->assertStringContainsString( 'data-format="updated"', $element );
		$this->assertMatchesRegularExpression( '#<span class="newspack-live__sr-only">Updated [^<]*2026 at 11:10 pm</span>#', $element );

		$live = $this->create_live();
		update_post_meta( $live, Live::META_LAST_UPDATE, (int) strtotime( '2026-10-06 03:10:00 UTC' ) );
		$status = \Newspack_Live\REST\REST_Public::build_status( $live );
		$this->assertSame( '2026-10-05', $status['updated']['day'] );
		$this->assertSame( '11:10 pm', $status['updated']['clock'] );
		$this->assertStringStartsWith( 'Updated ', $status['updated']['full'] );
	}

	/**
	 * A1: `newspack_live_rest_enqueue_assets` turns off `wp_enqueue_scripts` in public REST renders.
	 */
	public function test_rest_enqueue_assets_filter(): void {
		$live = $this->create_live();
		Entries::save(
			[
				'live_id' => $live,
				'title'   => 'One',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/entries' );
		$request->set_query_params( [ 'per_page' => 5 ] );

		add_filter( 'newspack_live_rest_enqueue_assets', '__return_false' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		remove_filter( 'newspack_live_rest_enqueue_assets', '__return_false' );
		$this->assertSame( 0, did_action( 'wp_enqueue_scripts' ), 'Off: not fired.' );
		$this->assertFalse( Assets::can_cache_recordings(), 'Off: the route does not fill the entry cache.' );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 1, did_action( 'wp_enqueue_scripts' ), 'Default: fired once.' );
		$this->assertTrue( Assets::can_cache_recordings(), 'Default: complete lists, cacheable.' );
	}

	/**
	 * Draft lives don't bump on content changes; a change that missed the lock ships with the next write.
	 */
	public function test_context_rev_drafts_and_pending(): void {
		$draft = $this->create_live( [ 'post_status' => 'draft' ] );
		$rev   = Live::get_rev( $draft );
		wp_update_post(
			[
				'ID'           => $draft,
				'post_content' => 'Draft background.',
			]
		);
		$this->assertSame( $rev, Live::get_rev( $draft ), 'No readers yet: no bump.' );

		$live = $this->create_live();
		update_post_meta( $live, Live::META_CONTEXT_PENDING, 1 );
		$entry = Entries::save(
			[
				'live_id' => $live,
				'title'   => 'Next write',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		$this->assertIsInt( $entry );
		$this->assertSame( Live::get_rev( $live ), Live::get_context_rev( $live ), 'The pending change ships with the next revision.' );
		$this->assertSame( '', get_post_meta( $live, Live::META_CONTEXT_PENDING, true ) );
	}
}
