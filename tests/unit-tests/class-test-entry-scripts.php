<?php
/**
 * No scripts in entries from users without `unfiltered_html`.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Content;
use Newspack_Live\Entries;
use Newspack_Live\Live;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Every write path (Entries::save() as used by the console, the console route, core REST as used by
 * the block editor) for contributor, author, editor and administrator, with an XSS payload set.
 * WP-CLI with `--user` is covered by the e2e security spec (it runs the real command).
 *
 * @group security
 */
class Test_Entry_Scripts extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * XSS payloads (a title and the content of one entry each).
	 *
	 * @return string[]
	 */
	public static function payloads(): array {
		return [
			'<p>a</p><script>alert(1)</script>',
			'<p>b</p><SCRIPT SRC="https://evil.example/x.js"></SCRIPT>',
			'<p>c</p><scr<script>ipt>alert(1)</script>',
			'<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->',
			'<img src="x" onerror="alert(1)">',
			'<p onclick="alert(1)">d</p>',
			'<details open ontoggle="alert(1)"><summary>e</summary></details>',
			'<svg onload="alert(1)"><circle r="1"/></svg>',
			'<a href="javascript:alert(1)">f</a>',
			'<a href="JaVaScRiPt:alert(1)">g</a>',
			'<a href="&#106;avascript:alert(1)">h</a>',
			'<a href=" javascript:alert(1)">i</a>',
			'<a href="java&#x09;script:alert(1)">j</a>',
			'<iframe src="javascript:alert(1)"></iframe>',
			'<form action="javascript:alert(1)"><button>k</button></form>',
			'<!-- wp:paragraph --><p><a href="javascript:alert(1)">l</a></p><!-- /wp:paragraph -->',
		];
	}

	/**
	 * Whether HTML carries a script element, an event handler attribute or a javascript: URL.
	 *
	 * @param string $html HTML.
	 * @return string Empty when clean, else what was found.
	 */
	public static function dangerous( string $html ): string {
		if ( preg_match( '/<\s*script\b/i', $html ) ) {
			return 'script';
		}
		if ( preg_match( '/<[^>]*[\s"\'\/]on[a-z]+\s*=/i', $html ) ) {
			return 'handler';
		}
		$decoded = preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 ) );
		if ( false !== stripos( (string) $decoded, 'javascript:' ) ) {
			return 'javascript:';
		}
		return '';
	}

	/**
	 * A live with the user on its team.
	 *
	 * @param int $user_id User ID.
	 * @return int Live ID.
	 */
	private function live_for( int $user_id ): int {
		return $this->create_live( [], [ Live::META_TEAM => [ $user_id ] ] );
	}

	/**
	 * Status a role can save with.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	private static function status_for( string $role ): string {
		return 'contributor' === $role ? 'draft' : 'publish';
	}

	/**
	 * Saves a payload through a path as the current user; returns the stored entry.
	 *
	 * @param string $path    `save`, `console` or `core`.
	 * @param int    $live_id Live ID.
	 * @param string $role    Role.
	 * @param string $payload Payload.
	 * @return \WP_Post
	 */
	private function write( string $path, int $live_id, string $role, string $payload ): \WP_Post {
		$status = self::status_for( $role );
		if ( 'save' === $path ) {
			$entry_id = Entries::save(
				[
					'live_id' => $live_id,
					'title'   => 'T ' . $payload,
					'content' => $payload,
					'status'  => $status,
					'author'  => get_current_user_id(),
				]
			);
		} elseif ( 'console' === $path ) {
			$request = new WP_REST_Request( 'POST', '/newspack-live/v1/lives/' . $live_id . '/entries' );
			$request->set_body_params(
				[
					'title'   => 'T ' . $payload,
					'content' => $payload,
					'status'  => $status,
				]
			);
			$response = rest_do_request( $request );
			$this->assertSame( 201, $response->get_status(), "console / $role: " . wp_json_encode( $response->get_data() ) );
			$entry_id = $response->get_data()['entry']['id'];
		} else {
			$request = new WP_REST_Request( 'POST', '/wp/v2/newspack-live-entries' );
			$request->set_body_params(
				[
					'title'   => 'T ' . $payload,
					'content' => $payload,
					'parent'  => $live_id,
					'status'  => $status,
				]
			);
			$response = rest_do_request( $request );
			$this->assertSame( 201, $response->get_status(), "core / $role: " . wp_json_encode( $response->get_data() ) );
			$entry_id = $response->get_data()['id'];
		}
		$this->assertIsInt( $entry_id, "$path / $role" );
		return get_post( $entry_id );
	}

	/**
	 * Contributors and authors (no `unfiltered_html`) never store a script, handler or javascript:
	 * URL, on any path, in the content or the title.
	 */
	public function test_untrusted_roles_store_no_script(): void {
		foreach ( [ 'contributor', 'author' ] as $role ) {
			$user_id = $this->create_user( $role );
			wp_set_current_user( $user_id );
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
			$live_id = $this->live_for( $user_id );
			foreach ( [ 'save', 'console', 'core' ] as $path ) {
				foreach ( self::payloads() as $payload ) {
					$entry = $this->write( $path, $live_id, $role, $payload );
					$this->assertSame( '', self::dangerous( $entry->post_content ), "$role / $path content: $payload → {$entry->post_content}" );
					$this->assertSame( '', self::dangerous( $entry->post_title ), "$role / $path title: $payload → {$entry->post_title}" );
				}
			}
		}
	}

	/**
	 * Editors and administrators with `unfiltered_html` keep core's behavior on save (their markup
	 * is stored as written).
	 */
	public function test_trusted_roles_keep_core_behavior(): void {
		foreach ( [ 'editor', 'administrator' ] as $role ) {
			$user_id = $this->create_user( $role );
			if ( is_multisite() ) {
				grant_super_admin( $user_id );
			}
			wp_set_current_user( $user_id );
			$this->assertTrue( current_user_can( 'unfiltered_html' ) );
			$live_id = $this->live_for( $user_id );
			foreach ( [ 'save', 'console', 'core' ] as $path ) {
				$entry = $this->write( $path, $live_id, $role, '<p>a</p><script>alert(1)</script>' );
				$this->assertStringContainsString( '<script>alert(1)</script>', $entry->post_content, "$role / $path" );
			}
		}
	}

	/**
	 * A user without `unfiltered_html` (an administrator who lost it, as on multisite) is filtered too.
	 */
	public function test_capability_not_role_decides(): void {
		$user_id = $this->create_user( 'administrator' );
		$deny    = static fn( $caps, $cap ) => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$live_id = $this->live_for( $user_id );
		foreach ( [ 'save', 'console', 'core' ] as $path ) {
			foreach ( self::payloads() as $payload ) {
				$entry = $this->write( $path, $live_id, 'administrator', $payload );
				$this->assertSame( '', self::dangerous( $entry->post_content ), "$path: $payload → {$entry->post_content}" );
			}
		}
		remove_filter( 'map_meta_cap', $deny, 10 );
	}

	/**
	 * Without core's KSES filters (removed by other code), our own entry check still applies.
	 */
	public function test_entry_check_without_core_filters(): void {
		$user_id = $this->create_user( 'author' );
		wp_set_current_user( $user_id );
		kses_remove_filters();
		$live_id = $this->live_for( $user_id );
		foreach ( self::payloads() as $payload ) {
			$entry = $this->write( 'save', $live_id, 'author', $payload );
			$this->assertSame( '', self::dangerous( $entry->post_content ), "$payload → {$entry->post_content}" );
		}
		kses_init_filters();
	}

	/**
	 * The render allowlist applies to everyone: no inline script body, no handler, no javascript:
	 * URL in rendered entries, even from an administrator; embed scripts with a `src` stay.
	 */
	public function test_render_has_no_inline_script(): void {
		foreach ( self::payloads() as $payload ) {
			$html = Content::kses( $payload );
			$this->assertDoesNotMatchRegularExpression( '/<script\b[^>]*>\s*[^<\s]/i', $html, $payload );
			$this->assertDoesNotMatchRegularExpression( '/<[^>]*[\s"\'\/]on[a-z]+\s*=/i', $html, $payload );
			$this->assertStringNotContainsStringIgnoringCase( 'javascript:', (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 ) ), $payload );
		}
		$embed = '<blockquote class="twitter-tweet"><a href="https://twitter.com/x/status/1">Tweet</a></blockquote><script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
		$this->assertSame( $embed, Content::kses( $embed ), 'oEmbed widget scripts (src, empty body) are kept.' );
		$this->assertSame( '<p>x</p>', Content::kses( '<p>x</p><script src="https://a.example/x.js">alert(1)</script>' ), 'A script with both src and a body is dropped.' );
		$this->assertSame( '<p>x</p>alert(1)', Content::kses( '<p>x</p><script>alert(1)' ), 'An unclosed script tag is dropped.' );
		$commented = Content::kses( '<p>x</p><!--<script src="https://a.example/x.js">--></script><p>after</p>' );
		$this->assertStringNotContainsString( '<!--', $commented, 'No comment is left unclosed.' );
		$this->assertStringNotContainsString( '<script', $commented );
		$this->assertStringContainsString( '<p>after</p>', $commented );
		$this->assertStringNotContainsString( '<!--', Content::strip_inline_scripts( '<p>x</p><!--<script src="https://a.example/x.js">--></script><p>after</p>' ), 'Comments holding script tags go whole.' );
		$this->assertSame( '<p>x</p>', Content::kses( '<p>x</p><script src="http://a.example/x.js"></script>' ), 'Only HTTPS script sources are kept.' );
		$this->assertSame( '<p>x</p><!-- note -->', Content::kses( '<p>x</p><!-- note -->' ), 'Other comments stay.' );
	}

	/**
	 * Revisions and autosaves of an entry are filtered like the entry.
	 */
	public function test_revisions_filtered(): void {
		$user_id = $this->create_user( 'author' );
		wp_set_current_user( $user_id );
		kses_remove_filters();
		$entry    = $this->write( 'save', $this->live_for( $user_id ), 'author', '<p>clean</p>' );
		$revision = _wp_put_post_revision(
			[
				'ID'           => $entry->ID,
				'post_title'   => 'T <img src=x onerror=alert(1)>',
				'post_content' => '<p>a</p><script>alert(1)</script>',
				'post_type'    => 'newspack_live_entry',
				'post_parent'  => $entry->post_parent,
				'post_author'  => $user_id,
				'post_status'  => 'publish',
			],
			true
		);
		kses_init_filters();
		$this->assertIsInt( $revision );
		$this->assertSame( '', self::dangerous( get_post_field( 'post_content', $revision ) ) );
		$this->assertSame( '', self::dangerous( get_post_field( 'post_title', $revision ) ) );
	}
}
