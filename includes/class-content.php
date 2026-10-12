<?php
/**
 * Private content pipeline.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Renders entry and description content without `the_content`,
 * so third-party content inserters (e.g. Super Cool Ad Inserter) never inject into entries.
 */
final class Content {

	/**
	 * Renders block content through core's content filters, in core's order.
	 *
	 * @param string $content Raw post content.
	 * @return string HTML.
	 */
	public static function render( string $content ): string {
		global $wp_embed;

		if ( '' === trim( $content ) ) {
			return '';
		}

		add_filter( 'render_block_data', [ __CLASS__, 'disable_image_lightbox' ] );
		try {
			if ( $wp_embed instanceof \WP_Embed ) {
				$content = $wp_embed->run_shortcode( $content );
				$content = $wp_embed->autoembed( $content );
			}
			$has_blocks = has_blocks( $content );
			$content    = do_blocks( $content );
			$content    = wptexturize( $content );
			$content    = convert_smilies( $content );
			if ( ! $has_blocks ) {
				// Classic content (e.g. a context box written without blocks) still needs paragraphs.
				$content = wpautop( $content );
			}
			$content = shortcode_unautop( $content );
			$content = wp_filter_content_tags( $content, 'newspack_live' );
			$content = do_shortcode( $content );
		} finally {
			remove_filter( 'render_block_data', [ __CLASS__, 'disable_image_lightbox' ] );
		}

		/**
		 * Filters rendered entry and description content.
		 *
		 * This is the plugin's private equivalent of `the_content`, which is never applied.
		 *
		 * @since 1.2.0
		 *
		 * @param string $content Rendered HTML.
		 */
		return (string) apply_filters( 'newspack_live_content', $content );
	}

	/**
	 * Escapes rendered content late: the post allowlist plus the elements that
	 * oEmbed providers output (iframes and their widget scripts), which `wp_kses_post()` strips.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public static function kses( string $html ): string {
		// Before kses: disallowed SVG elements go with their content (kses would keep their text).
		if ( false !== stripos( $html, 'svg' ) ) {
			$html = self::sanitize_svg( $html );
		}
		$html = wp_kses( $html, self::allowed_html() );
		if ( false !== stripos( $html, '<script' ) || false !== stripos( $html, '</script' ) ) {
			$html = self::strip_inline_scripts( $html );
		}
		// After kses too: its output is normalized (`< svg` and similar are real tags by now), and SVG
		// elements outside an `<svg>` go (an orphan `<title>` would hide the rest of the page).
		return preg_match( '#<(?:' . implode( '|', self::SVG_ELEMENTS ) . ')\b#i', $html ) ? self::sanitize_svg( $html ) : $html;
	}

	/**
	 * Keeps only external scripts loaded over HTTPS with an empty body (oEmbed provider widgets):
	 * HTML comments holding script tags go first (removing a tag inside
	 * one could leave it unclosed), then a script with inline code or another `src`, or a stray
	 * opening or closing script tag, is removed (its text stays as plain text when the tag was
	 * unpaired).
	 *
	 * @param string $html Kses output.
	 * @return string
	 */
	public static function strip_inline_scripts( string $html ): string {
		$kept = [];
		$html = (string) preg_replace_callback(
			'#<!--.*?(?:-->|$)#s',
			static fn( array $match ): string => false !== stripos( $match[0], 'script' ) ? '' : $match[0],
			$html
		);
		$html = (string) preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			static function ( array $match ) use ( &$kept ): string {
				if ( '' !== trim( $match[2] ) || ! preg_match( '#\ssrc\s*=\s*(["\']?)\s*https://#i', ' ' . $match[1] ) ) {
					return '';
				}
				$kept[] = '<script' . $match[1] . '></script>';
				return "\x00" . ( count( $kept ) - 1 ) . "\x00";
			},
			$html
		);
		$html = (string) preg_replace( '#</?script\b[^>]*>#i', '', $html );
		return (string) preg_replace_callback( "#\x00(\d+)\x00#", static fn( array $match ): string => $kept[ (int) $match[1] ] ?? '', $html );
	}

	/**
	 * Decorative SVG elements kept in entries; everything else in an `<svg>` is removed
	 * with its content (script, foreignObject, use, image, style, a, iframe, …).
	 */
	public const SVG_ELEMENTS = [ 'svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'ellipse', 'title', 'desc' ];

	/**
	 * Presentational attributes kept on them (lowercase, as the HTML parser reports them), plus
	 * every `stroke-*`. No href, no on* handler, no style.
	 */
	public const SVG_ATTRIBUTES = [ 'viewbox', 'width', 'height', 'fill', 'stroke', 'd', 'points', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'transform', 'aria-hidden', 'role', 'focusable' ];

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property names.

	/**
	 * Sanitizes every `<svg>` of (kses-normalized) HTML to the decorative subset. The HTML
	 * is parsed once as a DOM, so text in attributes, comments and scripts is never taken for a
	 * tag; in each top-level `<svg>`, unknown elements are removed with their content, attributes
	 * outside the presentational list are removed, and so is any value referencing `url(`,
	 * `javascript:` or `data:` (after decoding entities and removing whitespace). SVG elements
	 * outside an `<svg>` are removed with their content. HTML without any of them comes back
	 * unchanged.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function sanitize_svg( string $html ): string {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return (string) preg_replace( '#<svg\b.*?</svg>#is', '', $html );
		}
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8"?><div id="nl-svg-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $loaded ? $document->getElementById( 'nl-svg-root' ) : null;
		if ( ! $root ) {
			return '';
		}
		$svgs    = [];
		$orphans = [];
		foreach ( self::SVG_ELEMENTS as $name ) {
			foreach ( $root->getElementsByTagName( $name ) as $element ) {
				$in_svg = false;
				for ( $parent = $element->parentNode; $parent && $parent !== $root; $parent = $parent->parentNode ) {
					if ( $parent instanceof \DOMElement && 'svg' === strtolower( $parent->localName ) ) {
						$in_svg = true;
						break;
					}
				}
				// Top-level svgs are cleaned (nested ones with their ancestor); other SVG elements
				// outside an svg are removed.
				if ( 'svg' === $name && ! $in_svg ) {
					$svgs[] = $element;
				} elseif ( 'svg' !== $name && ! $in_svg ) {
					$orphans[] = $element;
				}
			}
		}
		if ( ! $svgs && ! $orphans ) {
			return $html;
		}
		foreach ( $orphans as $orphan ) {
			if ( $orphan->parentNode ) {
				$orphan->parentNode->removeChild( $orphan );
			}
		}
		foreach ( $svgs as $svg ) {
			self::clean_svg_attributes( $svg );
			self::clean_svg_node( $svg );
		}
		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $document->saveHTML( $child );
		}
		return $out;
	}

	/**
	 * Recursively removes disallowed elements and attributes under a node.
	 *
	 * @param \DOMNode $node Node.
	 * @return void
	 */
	private static function clean_svg_node( \DOMNode $node ): void {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				continue;
			}
			if ( ! $child instanceof \DOMElement || ! in_array( strtolower( $child->localName ), self::SVG_ELEMENTS, true ) ) {
				$node->removeChild( $child );
				continue;
			}
			self::clean_svg_attributes( $child );
			self::clean_svg_node( $child );
		}
	}

	/**
	 * Removes the attributes of an SVG element outside the presentational list, and values that
	 * reference a URL (namespaced ones such as xlink:href or xml:base included).
	 *
	 * @param \DOMElement $element Element.
	 * @return void
	 */
	private static function clean_svg_attributes( \DOMElement $element ): void {
		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			$name  = strtolower( $attribute->nodeName );
			$value = strtolower( (string) preg_replace( '/[\s\x00-\x1f]+/', '', html_entity_decode( $attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			$named = in_array( $name, self::SVG_ATTRIBUTES, true ) || str_starts_with( $name, 'stroke-' );
			if ( ! $named || str_contains( $value, 'url(' ) || str_contains( $value, 'javascript:' ) || str_contains( $value, 'data:' ) ) {
				$element->removeAttributeNode( $attribute );
			}
		}
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Allowed HTML for rendered content.
	 *
	 * @return array
	 */
	public static function allowed_html(): array {
		static $allowed = null;
		if ( null === $allowed ) {
			$allowed           = wp_kses_allowed_html( 'post' );
			$allowed['iframe'] = [
				'src'             => true,
				'title'           => true,
				'width'           => true,
				'height'          => true,
				'frameborder'     => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'loading'         => true,
				'referrerpolicy'  => true,
				'sandbox'         => true,
				'class'           => true,
				'style'           => true,
				'name'            => true,
				'data-secret'     => true,
				'marginwidth'     => true,
				'marginheight'    => true,
				'scrolling'       => true,
			];
			// Decorative SVG subset; sanitize_svg() has already cleaned every <svg>.
			$svg_attributes = array_fill_keys( self::SVG_ATTRIBUTES, true );
			foreach ( [ 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'stroke-opacity' ] as $stroke ) {
				$svg_attributes[ $stroke ] = true;
			}
			foreach ( self::SVG_ELEMENTS as $element ) {
				$allowed[ $element ] = $svg_attributes;
			}
			$allowed['script'] = [
				'src'     => true,
				'async'   => true,
				'defer'   => true,
				'type'    => true,
				'charset' => true,
				'id'      => true,
			];

			/**
			 * Filters the HTML allowed in rendered entries and live descriptions.
			 *
			 * @since 1.2.0
			 *
			 * @param array $allowed Allowed elements and attributes, as for wp_kses().
			 */
			$allowed = (array) apply_filters( 'newspack_live_allowed_html', $allowed );
		}
		return $allowed;
	}

	/**
	 * Disables the image lightbox inside entries: it relies on Interactivity API directives,
	 * which aren't hydrated in HTML inserted after load.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	public static function disable_image_lightbox( $block ) {
		if ( is_array( $block ) && isset( $block['blockName'] ) && 'core/image' === $block['blockName'] ) {
			$block['attrs']['lightbox'] = [ 'enabled' => false ];
		}
		return $block;
	}

	/**
	 * Plain text of block content, for teasers and structured data.
	 *
	 * @param string $content Raw post content.
	 * @param int    $length  Maximum length in characters (0 = no limit).
	 * @return string
	 */
	public static function plain_text( string $content, int $length = 0 ): string {
		$text = excerpt_remove_blocks( $content );
		$text = wp_strip_all_tags( strip_shortcodes( $text ), true );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		if ( $length > 0 && mb_strlen( $text ) > $length ) {
			$text = rtrim( mb_substr( $text, 0, $length - 1 ) ) . '…';
		}
		return $text;
	}
}
