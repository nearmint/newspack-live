<?php
/**
 * Inline asset data for inserted entries, plural tables for the view module.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Assets;
use Newspack_Live\Renderer;
use WP_UnitTestCase;

/**
 * Hardening II.
 */
class Test_Inline_Assets extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Removes the fixtures.
	 */
	public function tear_down(): void {
		wp_deregister_script( 'nl-test-probe' );
		wp_deregister_script( 'nl-test-inline-only' );
		wp_deregister_style( 'nl-test-style' );
		unset( $GLOBALS['l10n']['newspack-live'] );
		parent::tear_down();
	}

	/**
	 * Server side: described assets carry inline data, in WordPress' print order parts.
	 */
	public function test_describe_includes_inline_data(): void {
		wp_register_script( 'nl-test-probe', 'https://example.test/probe.js', [], '1', true );
		wp_localize_script( 'nl-test-probe', 'nlProbe', [ 'label' => 'ok' ] );
		wp_add_inline_script( 'nl-test-probe', 'window.nlBefore = 1;', 'before' );
		wp_add_inline_script( 'nl-test-probe', 'window.nlAfter = 1;' );
		wp_register_script( 'nl-test-inline-only', false, [], '1', true );
		wp_add_inline_script( 'nl-test-inline-only', 'window.nlInlineOnly = 1;' );
		wp_register_style( 'nl-test-style', 'https://example.test/probe.css', [], '1' );
		wp_add_inline_style( 'nl-test-style', '.nl-probe{color:red}' );

		$items = Assets::describe(
			[
				'styles'  => [ 'nl-test-style' ],
				'scripts' => [ 'nl-test-probe', 'nl-test-inline-only' ],
			]
		);
		$by    = array_column( $items, null, 'handle' );

		$this->assertStringContainsString( 'var nlProbe = {"label":"ok"};', $by['nl-test-probe']['data'] );
		$this->assertSame( 'window.nlBefore = 1;', $by['nl-test-probe']['before'] );
		$this->assertSame( 'window.nlAfter = 1;', $by['nl-test-probe']['after'] );
		$this->assertSame( '', $by['nl-test-inline-only']['src'], 'Inline-only handles are delivered for their inline code.' );
		$this->assertSame( 'window.nlInlineOnly = 1;', $by['nl-test-inline-only']['after'] );
		$this->assertSame( '.nl-probe{color:red}', $by['nl-test-style']['after'] );
		$this->assertArrayNotHasKey( 'translations', $by['nl-test-probe'], 'No text domain set.' );
		$this->assertArrayNotHasKey( 'before', $by['nl-test-style'] );
	}

	/**
	 * Handles that themes and plugins register on `wp_enqueue_scripts` (which REST requests don't fire)
	 * are resolved by firing it once, only when needed.
	 */
	public function test_late_registered_handles(): void {
		// A REST render: `wp_enqueue_scripts` hasn't run yet.
		$this->assertSame( 0, did_action( 'wp_enqueue_scripts' ) );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_late' ] );
		Assets::register_front_assets();
		$before = Assets::snapshot();
		wp_enqueue_script( 'nl-test-probe' );
		$items = Assets::describe( Assets::diff( $before ) );
		remove_action( 'wp_enqueue_scripts', [ $this, 'register_late' ] );
		wp_dequeue_script( 'nl-test-probe' );
		$this->assertSame( [ 'nl-test-probe' ], array_column( $items, 'handle' ) );
	}

	/**
	 * A page render before `wp_enqueue_scripts` (block themes) doesn't fire it nor fill the entry
	 * cache; a cache hit there re-enqueues the recorded handles, which WordPress queues until they
	 * are registered.
	 */
	public function test_page_render_before_enqueue_scripts(): void {
		$this->assertSame( 0, did_action( 'wp_enqueue_scripts' ) );
		$live  = $this->create_live();
		$entry = \Newspack_Live\Entries::save(
			[
				'live_id' => $live,
				'title'   => 'Early render',
				'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		add_filter( 'render_block', [ $this, 'enqueue_unregistered' ] );
		$html = Renderer::render_entry( $entry );
		remove_filter( 'render_block', [ $this, 'enqueue_unregistered' ] );
		$this->assertStringContainsString( 'Early render', $html );
		$this->assertSame( 0, did_action( 'wp_enqueue_scripts' ), 'Not fired during a page render.' );
		$this->assertFalse( wp_cache_get( $this->cache_key( $entry ), Renderer::CACHE_GROUP ), 'Not cached before wp_enqueue_scripts.' );

		// Registered later by `wp_enqueue_scripts`, as WordPress does on the page: still enqueued.
		wp_register_script( 'nl-test-probe', 'https://example.test/late.js', [], '1', true );
		$this->assertTrue( wp_script_is( 'nl-test-probe', 'enqueued' ) );
		wp_dequeue_script( 'nl-test-probe' );
		wp_deregister_script( 'nl-test-probe' );

		// A warm cache (filled by a REST render) is used before `wp_enqueue_scripts` too.
		wp_cache_set(
			$this->cache_key( $entry ),
			[
				'html'   => '<article>cached</article>',
				'assets' => [
					'styles'  => [],
					'scripts' => [ 'nl-test-probe' ],
				],
			],
			Renderer::CACHE_GROUP
		);
		$this->assertSame( '<article>cached</article>', Renderer::render_entry( $entry ) );
		wp_register_script( 'nl-test-probe', 'https://example.test/late.js', [], '1', true );
		$this->assertTrue( wp_script_is( 'nl-test-probe', 'enqueued' ) );
		wp_dequeue_script( 'nl-test-probe' );
	}

	/**
	 * Rendered entry cache key (mirrors Renderer::render_entry()).
	 *
	 * @param int $entry_id Entry ID.
	 * @return string
	 */
	private function cache_key( int $entry_id ): string {
		return sprintf(
			'entry_v%d_%d_%d_%s_%d_%s',
			Renderer::ENTRY_MARKUP_VERSION,
			$entry_id,
			\Newspack_Live\Entries::get_rev( $entry_id ),
			\Newspack_Live\Dates::day( time() ),
			0,
			wp_cache_get_last_changed( \Newspack_Live\Post_Types::PEOPLE_CACHE )
		);
	}

	/**
	 * Fixture: enqueues a handle nobody registered yet.
	 *
	 * @param string $html Block HTML.
	 * @return string
	 */
	public function enqueue_unregistered( $html ) {
		wp_enqueue_script( 'nl-test-probe' );
		return $html;
	}

	/**
	 * Fixture: registers a script on `wp_enqueue_scripts`.
	 */
	public function register_late(): void {
		wp_register_script( 'nl-test-probe', 'https://example.test/late.js', [], '1', true );
	}

	/**
	 * Server side: the view module gets every plural form and the plural index of each count.
	 */
	public function test_plural_table_for_polish(): void {
		$mo = new \MO();
		$mo->set_header( 'Plural-Forms', 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);' );
		$mo->add_entry(
			new \Translation_Entry(
				[
					'singular'     => '%d new update',
					'plural'       => '%d new updates',
					'translations' => [ '%d nowa aktualizacja', '%d nowe aktualizacje', '%d nowych aktualizacji' ],
				]
			)
		);
		$GLOBALS['l10n']['newspack-live'] = $mo; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test fixture: a Polish translation.

		// The button's string ("%d new update"; the announcement adds "available").
		$announce = Renderer::plurals()['newUpdates'];
		$this->assertCount( 200, $announce['table'] );
		$this->assertEqualsCanonicalizing( [ '%d nowa aktualizacja', '%d nowe aktualizacje', '%d nowych aktualizacji' ], $announce['forms'] );
		$form = static fn( int $n ) => $announce['forms'][ $announce['table'][ $n ] ];
		$this->assertSame( '%d nowa aktualizacja', $form( 1 ) );
		$this->assertSame( '%d nowe aktualizacje', $form( 2 ) );
		$this->assertSame( '%d nowych aktualizacji', $form( 5 ) );
		$this->assertSame( '%d nowych aktualizacji', $form( 12 ) );
		$this->assertSame( '%d nowe aktualizacje', $form( 22 ) );
		$this->assertSame( '%d nowych aktualizacji', $form( 112 ) );
	}

	/**
	 * Untranslated: English forms and the English rule.
	 */
	public function test_plural_table_untranslated(): void {
		$new_updates = Renderer::plurals()['newUpdates'];
		$this->assertSame( [ '%d new updates', '%d new update' ], $new_updates['forms'] );
		$this->assertSame( [ 0, 1, 0 ], array_slice( $new_updates['table'], 0, 3 ) );
		$this->assertSame( [ '%d new updates available', '%d new update available' ], Renderer::plurals()['announce']['forms'] );
	}
}
