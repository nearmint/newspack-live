<?php
/**
 * Share disclosures and heading levels.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Renderer;
use WP_UnitTestCase;

/**
 * Share disclosures and heading levels.
 */
class Test_Share_Headings extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Share URLs encode the shared URL for every network.
	 */
	public function test_share_urls(): void {
		$url = 'http://example.test/live/?live-entry=12#live-entry-12';
		foreach ( [ 'x', 'facebook', 'linkedin', 'whatsapp', 'bluesky' ] as $network ) {
			$share = Renderer::share_url( $network, $url, 'A & B' );
			wp_parse_str( (string) wp_parse_url( $share, PHP_URL_QUERY ), $query );
			$this->assertStringContainsString( $url, implode( ' ', $query ), $network );
		}
		wp_parse_str( (string) wp_parse_url( Renderer::share_url( 'x', $url, 'Polls close' ), PHP_URL_QUERY ), $x );
		$this->assertSame( 'Polls close', $x['text'] );
		wp_parse_str( (string) wp_parse_url( Renderer::share_url( 'whatsapp', $url, '' ), PHP_URL_QUERY ), $whatsapp );
		$this->assertSame( $url, $whatsapp['text'] );
	}

	/**
	 * Share controls are `<details>` disclosures with real links, so they work without JavaScript.
	 */
	public function test_share_without_javascript(): void {
		$html = Renderer::render_share( 'http://example.test/live/', 'Election <night>', 'entry' );
		$this->assertMatchesRegularExpression( '#^\s*<details class="newspack-live-share newspack-live-share--entry" name="newspack-live-share">#', $html );
		$this->assertStringContainsString( '<summary class="newspack-live-share__button newspack-live-entry__share" data-action="share"', $html );
		$this->assertSame( 5, substr_count( $html, 'class="newspack-live-share__link"' ) );
		$this->assertStringContainsString( 'href="https://x.com/intent/post?', $html );
		$this->assertMatchesRegularExpression( '#data-action="copy-link"[^>]*hidden>#', $html, 'Copy needs JavaScript.' );
		$this->assertMatchesRegularExpression( '#<span class="newspack-live__sr-only">\s*Share: Election &lt;night&gt;\s*</span>#', $html, 'Accessible name: "Share: {title}".' );
		$this->assertMatchesRegularExpression( '#<svg class="newspack-live-share__svg"[^>]*aria-hidden="true" focusable="false">#', $html, 'Icon only, hidden from assistive technology.' );
		$this->assertStringNotContainsString( 'newspack-live-share__label', $html, 'No visible "Share" text on cards.' );
		$header = Renderer::render_share( 'http://example.test/', 'Live', 'header' );
		$this->assertStringContainsString( '<span class="newspack-live-share__label">Share</span>', $header, 'The header keeps its "Share" text button.' );
		$this->assertStringNotContainsString( '<svg', $header );
	}

	/**
	 * Heading levels are clamped to h3–h4 on save, whatever the path.
	 */
	public function test_heading_levels(): void {
		$this->assertSame(
			"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Default h2</h3>\n<!-- /wp:heading -->",
			Entries::normalize_headings( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Default h2</h2>\n<!-- /wp:heading -->" )
		);
		$this->assertStringContainsString( '{"level":4}', Entries::normalize_headings( '<!-- wp:heading {"level":6} --><h6 class="wp-block-heading">Six</h6><!-- /wp:heading -->' ) );
		$this->assertStringContainsString( '<h4 class="wp-block-heading">Six</h4>', Entries::normalize_headings( '<!-- wp:heading {"level":6} --><h6 class="wp-block-heading">Six</h6><!-- /wp:heading -->' ) );
		$this->assertSame( '<h3>Classic</h3><p>x</p><h4 id="a">Small</h4>', Entries::normalize_headings( '<h1>Classic</h1><p>x</p><h5 id="a">Small</h5>' ) );
		$this->assertStringContainsString( '<h3>Raw</h3>', Entries::normalize_headings( "<!-- wp:html -->\n<h2>Raw</h2>\n<!-- /wp:html -->" ) );

		$accordion = '<!-- wp:accordion-heading {"headingLevel":2} --><h2 class="wp-block-accordion-heading">Owned by the block</h2><!-- /wp:accordion-heading -->';
		$this->assertSame( $accordion, Entries::normalize_headings( $accordion ), 'Other blocks own their markup.' );

		$untouched = "<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">Fine</h4>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Text with <strong>h2</strong>.</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( $untouched, Entries::normalize_headings( $untouched ) );

		$live  = $this->create_live();
		$entry = Entries::save(
			[
				'live_id' => $live,
				'content' => '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Pasted</h2><!-- /wp:heading -->',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		$this->assertStringContainsString( '<h3 class="wp-block-heading">Pasted</h3>', get_post_field( 'post_content', $entry ) );

		// Core paths (REST, full editor) too.
		wp_update_post(
			[
				'ID'           => $entry,
				'post_content' => '<h1>From the full editor</h1>',
			]
		);
		$this->assertSame( '<h3>From the full editor</h3>', get_post_field( 'post_content', $entry ) );
	}

	/**
	 * Yoast SEO filters (tested in the sandbox with Yoast 28.6): article type, coverage and
	 * updates in the article piece, canonical, entry Open Graph.
	 */
	public function test_yoast_filters(): void {
		$editor = $this->create_user( 'editor' );
		$live   = $this->create_live( [ 'post_author' => $editor ] );
		$first  = Entries::save(
			[
				'live_id' => $live,
				'title'   => 'Q&amp;A first',
				'content' => '<!-- wp:paragraph --><p>Body text.</p><!-- /wp:paragraph -->',
				'author'  => $editor,
				'time'    => time() - 600,
			]
		);
		$second = Entries::save(
			[
				'live_id' => $live,
				'title'   => 'Second',
				'author'  => $editor,
			]
		);
		$post   = self::factory()->post->create();

		// Yoast passes an indexable object; a post ID works too.
		$this->assertSame( 'LiveBlogPosting', \Newspack_Live\SEO::yoast_article_type( 'Article', (object) [ 'object_id' => $live ] ) );
		$this->assertSame( 'Article', \Newspack_Live\SEO::yoast_article_type( 'Article', (object) [ 'object_id' => $post ] ) );
		$this->assertSame( 'LiveBlogPosting', \Newspack_Live\SEO::yoast_article_type( 'Article', $live ) );

		$this->go_to( get_permalink( $live ) );
		$piece = \Newspack_Live\SEO::yoast_article( [ '@type' => 'LiveBlogPosting' ] );
		$this->assertArrayHasKey( 'coverageStartTime', $piece );
		$this->assertSame( [ 'Second', 'Q&A first' ], array_column( $piece['liveBlogUpdate'], 'headline' ) );
		$this->assertSame( 'Article', \Newspack_Live\SEO::yoast_entry_title( 'Article' ), 'Not an entry permalink.' );

		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->assertArrayNotHasKey( 'liveBlogUpdate', \Newspack_Live\SEO::yoast_article( [] ) );
		remove_filter( 'newspack_is_post_restricted', '__return_true' );

		$this->go_to( add_query_arg( 'live-before', $second, get_permalink( $live ) ) );
		$this->assertStringContainsString( 'live-before=' . $second, \Newspack_Live\SEO::yoast_canonical( get_permalink( $live ) ) );

		$this->go_to( add_query_arg( 'live-entry', $first, get_permalink( $live ) ) );
		$this->assertSame( 'Q&A first', \Newspack_Live\SEO::yoast_entry_title( 'Live title' ) );
		$this->assertSame( 'Body text.', \Newspack_Live\SEO::yoast_entry_description( 'Live description' ) );
		$this->assertStringContainsString( 'live-entry=' . $first, \Newspack_Live\SEO::yoast_entry_url( get_permalink( $live ) ) );
		$this->assertSame( get_permalink( $live ), \Newspack_Live\SEO::yoast_canonical( get_permalink( $live ) ) );

		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->assertSame( 'Live title', \Newspack_Live\SEO::yoast_entry_title( 'Live title' ), 'Gated: no entry text.' );
		remove_filter( 'newspack_is_post_restricted', '__return_true' );

		// Password-protected lives, for readers without the password.
		wp_update_post(
			[
				'ID'            => $live,
				'post_password' => 'secret',
			]
		);
		$this->go_to( add_query_arg( 'live-entry', $first, get_permalink( $live ) ) );
		$this->assertSame( 'Live title', \Newspack_Live\SEO::yoast_entry_title( 'Live title' ) );
		$this->assertSame( 'Live description', \Newspack_Live\SEO::yoast_entry_description( 'Live description' ) );
		$this->assertArrayNotHasKey( 'liveBlogUpdate', \Newspack_Live\SEO::yoast_article( [] ) );
	}
}
