<?php
/**
 * Rendering safety: embeds, password protection, Content Gate, content filters.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Renderer;
use Newspack_Live\Templates;
use WP_UnitTestCase;

/**
 * Rendering regression tests.
 *
 * @group render
 */
class Test_Render_Safety extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Live ID.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Creates a live with one entry.
	 */
	public function set_up(): void {
		parent::set_up();
		Feed::flush();
		$editor        = $this->create_user( 'editor' );
		$this->live_id = $this->create_live(
			[
				'post_author'  => $editor,
				'post_content' => '<!-- wp:paragraph --><p>Secret description</p><!-- /wp:paragraph -->',
			],
			[ \Newspack_Live\Context::META => '<p>Secret context</p>' ]
		);
		wp_set_current_user( $editor );
		Entries::save(
			[
				'live_id' => $this->live_id,
				'title'   => 'Secret entry',
			]
		);
	}

	/**
	 * Provider HTML (iframe + script) survives the renderer.
	 */
	public function test_embeds_survive(): void {
		add_filter( 'pre_oembed_result', [ $this, 'fake_oembed' ], 10, 2 );
		$entry_id = Entries::save(
			[
				'live_id' => $this->live_id,
				'title'   => 'Embed',
				'content' => "<!-- wp:embed {\"url\":\"https://video.example/watch/1\",\"type\":\"video\",\"providerNameSlug\":\"example\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://video.example/watch/1\n</div></figure>\n<!-- /wp:embed -->",
			]
		);
		$html = Renderer::render_entry( $entry_id );
		remove_filter( 'pre_oembed_result', [ $this, 'fake_oembed' ], 10 );
		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringContainsString( '<script', $html );
	}

	/**
	 * Fake oEmbed provider response.
	 *
	 * @param mixed  $result Result.
	 * @param string $url    URL.
	 * @return mixed
	 */
	public function fake_oembed( $result, $url ) {
		if ( str_starts_with( $url, 'https://video.example/' ) ) {
			return '<iframe src="https://video.example/embed/1" title="Video"></iframe><script async src="https://video.example/widget.js"></script>';
		}
		return $result;
	}

	/**
	 * Password-protected lives show the header and the password form only.
	 */
	public function test_password_protected(): void {
		wp_update_post(
			[
				'ID'            => $this->live_id,
				'post_password' => 'secret',
			]
		);
		$html = Renderer::render_live( $this->live_id );
		$this->assertStringContainsString( 'post_password', $html );
		$this->assertStringNotContainsString( 'Secret entry', $html );
		$this->assertStringNotContainsString( 'Secret context', $html );
		$this->assertStringNotContainsString( 'Secret description', $html );
	}

	/**
	 * Restricted lives render the header, never the feed, the description or the Context card.
	 */
	public function test_restricted(): void {
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$html = Renderer::render_live( $this->live_id );
		remove_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->assertStringContainsString( 'newspack-live__header', $html );
		$this->assertStringNotContainsString( 'Secret entry', $html );
		$this->assertStringNotContainsString( 'Secret context', $html );
		$this->assertStringNotContainsString( 'Secret description', $html );
		$this->assertStringNotContainsString( 'newspack-live__ask', $html );
	}

	/**
	 * On generic classic themes the layout replaces the content, survives later filters and nested calls.
	 */
	public function test_content_replacement(): void {
		$this->go_to( get_permalink( $this->live_id ) );
		$GLOBALS['wp_query']->in_the_loop = true;
		the_post();
		add_filter( 'the_content', [ $this, 'nested_content_call' ], 20 );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $this->live_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		remove_filter( 'the_content', [ $this, 'nested_content_call' ], 20 );
		$this->assertSame( 1, substr_count( $html, 'class="newspack-live-content"' ), 'Nested the_content calls never render a second layout.' );
		$this->assertStringContainsString( 'Nested', $html );
		$this->assertStringContainsString( 'Secret entry', $html );
		$this->assertStringNotContainsString( 'newspack-live-layout-', $html );
		$this->assertStringNotContainsString( 'newspack-live__title', $html, 'The theme prints the title.' );
		$this->assertFalse( has_filter( 'the_content', [ Templates::class, 'restore_content' ] ) );
	}

	/**
	 * Simulates a later filter that renders another content through `the_content`.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function nested_content_call( $content ) {
		remove_filter( 'the_content', [ $this, 'nested_content_call' ], 20 );
		$nested = apply_filters( 'the_content', 'Nested' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		add_filter( 'the_content', [ $this, 'nested_content_call' ], 20 );
		return $content . $nested;
	}
}
