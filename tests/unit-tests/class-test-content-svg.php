<?php
/**
 * The decorative SVG subset kept in entries. Moved out of the Campaigns tests
 * when the Campaigns integration was removed.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Content;
use WP_UnitTestCase;

/**
 * Decorative SVG.
 */
class Test_Content_SVG extends WP_UnitTestCase {
	/**
	 * The decorative subset is kept with its presentational attributes.
	 */
	public function test_svg_subset_kept(): void {
		$svg  = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" role="img"><title>Star</title><desc>A star</desc><g fill="#c8102e" stroke="#000" stroke-width="2" stroke-linecap="round" transform="rotate(10)"><path d="M12 2l3 7h7l-6 4 2 7-6-4-6 4 2-7-6-4h7z"/><circle cx="12" cy="12" r="2"/><rect x="1" y="1" rx="1" ry="1" width="2" height="2"/><line x1="0" y1="0" x2="4" y2="4"/><polyline points="0,0 1,1"/><polygon points="0,0 1,1 1,0"/><ellipse cx="2" cy="2" rx="1" ry="2"/></g></svg>';
		$html = Content::kses( '<p>Icon:</p>' . $svg );
		foreach ( [ '<svg', 'viewbox="0 0 24 24"', '<title>Star</title>', '<desc>A star</desc>', 'stroke-width="2"', 'stroke-linecap="round"', 'transform="rotate(10)"', '<path d="M12', '<circle', '<rect', '<line', '<polyline', '<polygon', '<ellipse', 'aria-hidden="true"', 'focusable="false"', 'role="img"' ] as $kept ) {
			$this->assertStringContainsString( strtolower( $kept ), strtolower( $html ), $kept );
		}
		$this->assertStringContainsString( '<p>Icon:</p>', $html );
	}

	/**
	 * Security cases, each stripped: script, on* handlers, javascript: and data: URLs,
	 * foreignObject, use with an external href, style with url(), nested svg.
	 */
	public function test_svg_security(): void {
		$cases = [
			'script'        => [ '<svg><script>alert(1)</script><path d="M0 0"/></svg>', [ '<script', 'alert(1)' ] ],
			'on handlers'   => [ '<svg onload="alert(1)"><path d="M0 0" onclick="alert(2)" onmouseover="alert(3)"/></svg>', [ 'onload', 'onclick', 'onmouseover', 'alert' ] ],
			'javascript'    => [ '<svg><path d="M0 0" fill="javascript:alert(1)" stroke="&#106;avascript:alert(2)"/><a href="javascript:alert(3)"><rect width="1" height="1"/></a></svg>', [ 'javascript', 'href', 'alert' ] ],
			'data'          => [ '<svg><path d="M0 0" fill="data:image/svg+xml;base64,PHN2Zz4="/><image href="data:image/png;base64,AAAA"/></svg>', [ 'data:', '<image', 'href' ] ],
			'foreignObject' => [ '<svg><foreignObject width="100" height="100"><iframe src="https://evil.example"></iframe><p>html</p></foreignObject></svg>', [ 'foreignobject', '<iframe', 'evil.example', 'html' ] ],
			'use href'      => [ '<svg><use href="https://evil.example/sprite.svg#icon"/><use xlink:href="https://evil.example/x.svg#a"/></svg>', [ '<use', 'evil.example', 'href' ] ],
			'style url()'   => [ '<svg style="background:url(https://evil.example/t.png)"><style>path{fill:url(https://evil.example/f)}</style><path d="M0 0" style="fill:red" fill="url(#g)"/></svg>', [ 'style', 'url(', 'evil.example' ] ],
			'nested svg'    => [ '<svg><svg onload="alert(1)"><script>alert(2)</script><svg><foreignObject><img src=x onerror=alert(3)></foreignObject></svg></svg></svg>', [ 'onload', '<script', 'foreignobject', '<img', 'onerror', 'alert' ] ],
		];
		foreach ( $cases as $case => [ $input, $forbidden ] ) {
			$html = strtolower( Content::kses( $input ) );
			foreach ( $forbidden as $needle ) {
				$this->assertStringNotContainsString( strtolower( $needle ), $html, "{$case}: {$needle}" );
			}
		}
		// Content after an unclosed <svg> doesn't escape the sanitizer.
		$this->assertStringNotContainsString( '<script', Content::kses( '<svg><path d="M0 0"/><script>alert(1)</script>' ) );
		// Outside SVG, nothing changes (the oEmbed rules still apply).
		$this->assertSame( '<p>Text</p>', Content::kses( '<p>Text</p>' ) );
	}

	/**
	 * Review cases: `<svg` text in attributes, comments and scripts is not a tag (the rest of the
	 * entry is kept); malformed tags normalized by kses are still sanitized; namespaced attributes
	 * are removed.
	 */
	public function test_svg_parsing_edges(): void {
		$html = Content::kses( '<figure class="wp-block-image"><img src="https://x.test/a.png" alt="The <svg&gt; tag"/></figure><p>Second paragraph.</p><h2>Heading</h2><p>Third.</p>' );
		$this->assertStringContainsString( '<p>Second paragraph.</p>', $html );
		$this->assertStringContainsString( '<h2>Heading</h2>', $html );
		$this->assertStringContainsString( '<p>Third.</p>', $html );
		// (Core kses itself drops that image: a `<` in an attribute value breaks its tag matching.)

		foreach ( [ '< svg>', "<\x01svg>", '<\\0svg>' ] as $open ) {
			$out = strtolower( Content::kses( $open . '<a href="https://ok.test"><path d="M0" fill="url(https://evil.test/x#a)"/></a><iframe src="https://evil.test"></iframe><script>alert(2)</script></svg>' ) );
			// Whatever becomes an <svg> is clean; anything left outside one follows the oEmbed rules.
			preg_match_all( '#<svg\b.*?</svg>#s', $out, $svgs );
			foreach ( $svgs[0] as $svg ) {
				foreach ( [ 'href', 'evil.test', '<iframe', '<script', 'alert' ] as $needle ) {
					$this->assertStringNotContainsString( $needle, $svg, $open . ': ' . $needle );
				}
			}
		}

		$out = strtolower( Content::kses( '<svg xml:base="data:x" xlink:href="javascript:alert(1)"><path d="M0" xlink:href="https://evil.test"/></svg>' ) );
		$this->assertStringNotContainsString( 'xml:base', $out );
		$this->assertStringNotContainsString( 'href', $out );
		$this->assertStringNotContainsString( 'evil.test', $out );

		$this->assertStringContainsString( '<p>kept</p>', Content::kses( '<p>kept</p><svg><path d="M0"/></svg><!-- a comment with </svg> --><p>after</p>' ) );
		$this->assertStringContainsString( '<p>after</p>', Content::kses( '<p>kept</p><svg><path d="M0"/></svg><p>after</p>' ) );
	}

	/**
	 * SVG elements outside an `<svg>` are removed (an unclosed `<title>` would hide the rest of the
	 * page); inside one they are kept.
	 */
	public function test_svg_orphans(): void {
		$this->assertSame( '<p>a</p><p>b</p>', Content::kses( '<p>a</p><title>x</title><p>b</p>' ) );
		$this->assertStringNotContainsString( '<title', Content::kses( '<p>a</p><title>swallow<p>b</p>' ) );
		$this->assertSame( '<p>x</p>', Content::kses( '<p>x<desc>d</desc></p>' ) );
		$this->assertSame( '<svg><title>ok</title></svg>', Content::kses( '<svg><title>ok</title></svg><title>bad</title>' ) );
		$this->assertSame( '<p>No SVG here.</p>', Content::kses( '<p>No SVG here.</p>' ) );
	}
}
