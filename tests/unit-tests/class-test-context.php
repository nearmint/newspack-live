<?php
/**
 * The Context field and the description.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Context;
use Newspack_Live\Live;
use Newspack_Live\Renderer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Meta registration (auth, sanitize, REST), Context card, description render.
 *
 * @group render
 */
class Test_Context extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Registers the meta again (the test library unregisters every meta key after each test).
	 */
	public function set_up(): void {
		parent::set_up();
		Context::register();
		// The post type keeps its REST controller, and the controller its schema: built by an earlier
		// test without the key otherwise.
		$schema = new \ReflectionProperty( \WP_REST_Controller::class, 'schema' );
		$schema->setValue( get_post_type_object( 'post' )->get_rest_controller(), null );
	}

	/**
	 * Resets the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'the_content' );
		parent::tear_down();
	}

	/**
	 * The meta is registered for REST with an auth callback and the allowlist.
	 */
	public function test_registration(): void {
		$meta = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( Context::META, $meta );
		$this->assertSame( [ 'edit' ], $meta[ Context::META ]['show_in_rest']['schema']['context'] );
		$this->assertSame( [ Context::class, 'sanitize' ], $meta[ Context::META ]['sanitize_callback'] );
		$this->assertSame( '', $meta[ Context::META ]['default'] );

		$live = $this->create_live( [ 'post_author' => $this->create_user( 'author' ) ] );
		wp_set_current_user( $this->create_user( 'editor' ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $live, Context::META ) );
		$other_author = $this->create_user( 'author' );
		wp_set_current_user( $other_author );
		$this->assertFalse( current_user_can( 'edit_post_meta', $live, Context::META ), "Another author can't edit this live." );
		wp_set_current_user( $this->create_user( 'contributor' ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $live, Context::META ), 'Contributors never edit live settings.' );
	}

	/**
	 * Only line breaks, bold, italic and links stay, in one bulleted list: paragraphs and list items
	 * (nested and numbered ones included) become bullets.
	 */
	public function test_sanitize(): void {
		$this->assertSame(
			'<ul><li>Polls close at <strong>8</strong>, <em>maybe</em> <a href="https://example.com" target="_blank" rel="noopener">later</a>.<br>Then:</li><li>Mayor</li><li>Nested</li><li>First</li></ul>',
			Context::sanitize( '<!-- wp:paragraph --><p class="x" style="color:red">Polls close at <strong>8</strong>, <em>maybe</em> <a href="https://example.com" target="_blank" rel="noopener" onclick="alert(1)">later</a>.<br />Then:</p><!-- /wp:paragraph --><ul class="wp-block-list"><li>Mayor<ul><li>Nested</li></ul></li><li> </li></ul><ol><li>First</li></ol>' )
		);
		$this->assertSame( '<ul><li>Text alert(1)</li></ul>', Context::sanitize( '<p>Text <script>alert(1)</script> <img src="x" onerror="y"></p>' ) );
		$this->assertStringNotContainsString( 'javascript:', Context::sanitize( '<p><a href="javascript:alert(1)">link</a></p>' ) );
		$this->assertSame( '', Context::sanitize( '<p> </p><p>&nbsp;</p>' ) );
		$this->assertSame( '', Context::sanitize( [ 'not', 'a', 'string' ] ) );
		$this->assertSame( '<ul><li>Plain</li></ul>', Context::sanitize( 'Plain' ) );
		$this->assertSame( '<ul><li>Line one</li><li>Line two</li></ul>', Context::sanitize( "Line one\n\nLine two" ) );
		$this->assertSame( "<ul><li>One item\n\nstill one</li></ul>", Context::sanitize( "<ul><li>One item\n\nstill one</li></ul>" ), 'One bullet per item, like the editor.' );
		$this->assertSame( '<ul><li>Café &amp; “more”</li></ul>', Context::sanitize( '<p>Café &amp; “more”</p>' ) );
		$this->assertSame( Context::sanitize( '<p>A</p><p>B</p>' ), Context::sanitize( Context::sanitize( '<p>A</p><p>B</p>' ) ), 'Stable.' );
	}

	/**
	 * Through the core REST route: an editor saves it sanitized; a contributor can't.
	 */
	public function test_rest(): void {
		$live = $this->create_live( [ 'post_status' => 'draft' ] );
		wp_set_current_user( $this->create_user( 'editor' ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $live );
		$request->set_body_params( [ 'meta' => [ Context::META => '<p>Background <script>x</script><strong>now</strong>.</p>' ] ] );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '<ul><li>Background x<strong>now</strong>.</li></ul>', get_post_meta( $live, Context::META, true ) );
		$this->assertSame( '<ul><li>Background x<strong>now</strong>.</li></ul>', $response->get_data()['meta'][ Context::META ] );

		$contributor = $this->create_user( 'contributor' );
		wp_update_post(
			[
				'ID'          => $live,
				'post_author' => $contributor,
			]
		);
		wp_set_current_user( $contributor );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $live );
		$request->set_body_params( [ 'meta' => [ Context::META => '<p>Changed</p>' ] ] );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( '<ul><li>Background x<strong>now</strong>.</li></ul>', get_post_meta( $live, Context::META, true ) );

		// Readers never get it from core REST (`edit` context only): it is gated like the feed.
		wp_update_post(
			[
				'ID'          => $live,
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );
		$public = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $live ) );
		$this->assertSame( 200, $public->get_status() );
		$this->assertArrayNotHasKey( Context::META, (array) ( $public->get_data()['meta'] ?? [] ) );
	}

	/**
	 * The page: the description (post content) under the header, before the Context card, through
	 * the plugin's pipeline (never `the_content`); both hidden when empty.
	 */
	public function test_description_and_card(): void {
		add_filter( 'the_content', static fn( $content ) => $content . 'FROM-THE-CONTENT-FILTER' );
		$live = $this->create_live(
			[ 'post_content' => "<!-- wp:paragraph -->\n<p>A short introduction to the night.</p>\n<!-- /wp:paragraph -->" ],
			[ Context::META => '<p>What is at stake: <strong>the mayor</strong>.</p><ul><li>Results after 9 pm</li></ul>' ]
		);
		$html = Renderer::render_live( $live );
		// WordPress 7 adds a class to rendered paragraphs.
		$this->assertMatchesRegularExpression( '#<div class="newspack-live__description">\s*<p[^>]*>A short introduction to the night\.</p>\s*</div>#', $html );
		$this->assertStringNotContainsString( 'FROM-THE-CONTENT-FILTER', $html );
		$description = strpos( $html, 'newspack-live__description' );
		$this->assertGreaterThan( strpos( $html, '</header>' ), $description, 'Under the header.' );
		$this->assertLessThan( strpos( $html, 'id="newspack-live-context"' ), $description, 'Before the Context card.' );
		// A Context saved as paragraphs is printed as bullets.
		$this->assertStringContainsString( '<div class="newspack-live__context-content" id="newspack-live-context-panel"><ul><li>What is at stake: <strong>the mayor</strong>.</li><li>Results after 9 pm</li></ul></div>', $html );

		$empty = $this->create_live( [ 'post_content' => '' ] );
		$html  = Renderer::render_live( $empty );
		$this->assertStringNotContainsString( 'newspack-live__description', $html );
		$this->assertStringContainsString( '<section class="newspack-live__context" id="newspack-live-context" hidden></section>', $html );
	}

	/**
	 * The "Context" box shows for users who manage lives, on live-capable post types only.
	 */
	public function test_box(): void {
		$live = $this->create_live();
		wp_set_current_user( $this->create_user( 'editor' ) );
		$this->assertTrue( Context::has_box( get_post( $live ) ) );
		$this->assertFalse( Context::has_box( get_post( self::factory()->post->create( [ 'post_type' => 'page' ] ) ) ) );
		$this->assertFalse( Context::has_box( null ) );
		wp_set_current_user( $this->create_user( 'contributor' ) );
		$this->assertFalse( Context::has_box( get_post( $live ) ) );
		$this->assertFalse( Live::is_live( self::factory()->post->create() ) );
	}
}
