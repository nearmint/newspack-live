<?php
/**
 * The Context of a live.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The Context card's content lives in post meta, edited in a "Context" box below the block editor;
 * the post content is the live's description.
 */
final class Context {

	/**
	 * Meta key of the Context (sanitized HTML: one bulleted list with bold, italic and links).
	 */
	public const META = '_newspack_live_context';

	/**
	 * ID of the meta box below the block editor.
	 */
	public const BOX = 'newspack-live-context';

	/**
	 * Formatting accepted on input: paragraphs, line breaks, bold, italic, links, bulleted and numbered
	 * lists. The stored and printed Context is one bulleted list (to_list()).
	 */
	public const ALLOWED_HTML = [
		'p'      => [],
		'br'     => [],
		'strong' => [],
		'b'      => [],
		'em'     => [],
		'i'      => [],
		'a'      => [
			'href'   => true,
			'target' => true,
			'rel'    => true,
		],
		'ul'     => [],
		'ol'     => [],
		'li'     => [],
	];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 20 );
		add_action( 'added_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 4 );
		add_action( 'updated_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 4 );
		add_action( 'deleted_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 4 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ], 10, 2 );
	}

	/**
	 * Registers the meta on every live-capable post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( Live::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META,
				[
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'sanitize_callback' => [ __CLASS__, 'sanitize' ],
					'auth_callback'     => [ Live_Meta::class, 'auth' ],
					// Edit context only: the Context is gated like the feed, and core would
					// return it to anyone in the `view` context, password-protected lives included.
					'show_in_rest'      => [
						'schema' => [
							'type'    => 'string',
							'context' => [ 'edit' ],
						],
					],
				]
			);
		}
	}

	/**
	 * Keeps the allowed formatting only and makes it one bulleted list; block comment
	 * delimiters are dropped (the editor rebuilds its blocks from the HTML).
	 *
	 * @param mixed $value Raw value.
	 * @return string HTML (`<ul>` of `<li>`), '' when there is no text.
	 */
	public static function sanitize( $value ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', is_string( $value ) ? $value : '' );
		return self::to_list( trim( wp_kses( $html, self::ALLOWED_HTML ) ) );
	}

	/**
	 * Turns sanitized Context HTML into one bulleted list: each paragraph and each list item (nested
	 * ones included, flattened) becomes a bullet; loose text becomes a bullet per paragraph break.
	 * Older Contexts (paragraphs, numbered lists) are read this way too.
	 *
	 * @param string $html Sanitized HTML.
	 * @return string `<ul>` of `<li>`, '' when there is no text.
	 */
	public static function to_list( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $doc->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $root instanceof \DOMElement ) {
			return '';
		}
		$items = [];
		self::collect_items( $root, $items );
		$items = array_values( array_filter( array_map( 'trim', $items ), [ __CLASS__, 'has_text' ] ) );
		return $items ? '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>' : '';
	}

	/**
	 * Collects the bullets of an element's children (see to_list()).
	 *
	 * @param \DOMNode $parent Parent node.
	 * @param string[] $items  Bullets (HTML), appended to.
	 * @return void
	 */
	private static function collect_items( \DOMNode $parent, array &$items ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties.
		$inline = '';
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			$name = $node instanceof \DOMElement ? strtolower( $node->nodeName ) : '';
			if ( in_array( $name, [ 'p', 'li', 'ul', 'ol' ], true ) ) {
				self::add_inline( $inline, $items );
				$inline = '';
				if ( 'p' === $name ) {
					$items[] = self::inner_html( $node );
				} elseif ( 'li' === $name ) {
					// One bullet per item (like the editor), then its nested items.
					$items[] = self::inner_html( $node, [ 'ul', 'ol' ] );
					foreach ( iterator_to_array( $node->childNodes ) as $child ) {
						if ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), [ 'ul', 'ol' ], true ) ) {
							self::collect_items( $child, $items );
						}
					}
				} else {
					self::collect_items( $node, $items );
				}
				continue;
			}
			$inline .= (string) $node->ownerDocument->saveHTML( $node );
		}
		self::add_inline( $inline, $items );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Adds loose inline HTML as bullets, one per paragraph break (a blank line).
	 *
	 * @param string   $inline Inline HTML.
	 * @param string[] $items  Bullets, appended to.
	 * @return void
	 */
	private static function add_inline( string $inline, array &$items ): void {
		foreach ( preg_split( '/\n\s*\n/', $inline ) as $part ) {
			$items[] = (string) $part;
		}
	}

	/**
	 * HTML of a node's children.
	 *
	 * @param \DOMNode $node Node.
	 * @param string[] $skip Child element names left out.
	 * @return string
	 */
	private static function inner_html( \DOMNode $node, array $skip = [] ): string {
		$html = '';
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties.
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), $skip, true ) ) {
				continue;
			}
			$html .= (string) $node->ownerDocument->saveHTML( $child );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $html;
	}

	/**
	 * Whether HTML holds text (not only tags, spaces or non-breaking spaces).
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	public static function has_text( string $html ): bool {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return '' !== trim( (string) preg_replace( '/[\s\x{00a0}]+/u', '', $text ) );
	}

	/**
	 * The Context of a live.
	 *
	 * @param int $live_id Live ID.
	 * @return string Sanitized HTML.
	 */
	public static function get( int $live_id ): string {
		return self::sanitize( get_post_meta( $live_id, self::META, true ) );
	}

	/**
	 * The Context card's content, ready to print: a bulleted list, typography, the allowlist.
	 *
	 * @param int $live_id Live ID.
	 * @return string HTML, '' when empty.
	 */
	public static function render( int $live_id ): string {
		$html = self::get( $live_id );
		return '' === $html ? '' : trim( wp_kses( wptexturize( $html ), self::ALLOWED_HTML ) );
	}

	/**
	 * A Context change on a published live bumps the revision, so open pages replace the card on
	 * their next poll.
	 *
	 * @param int|int[] $meta_ids  Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @param mixed     $value     Meta value.
	 * @return void
	 */
	public static function on_meta_change( $meta_ids, $object_id, $meta_key, $value = '' ): void {
		// A new empty row shows nothing: no change for readers.
		$empty_row = 'added_post_meta' === current_action() && '' === self::sanitize( $value );
		if ( self::META !== $meta_key || $empty_row || 'publish' !== get_post_status( (int) $object_id ) || ! Live::is_live( (int) $object_id ) ) {
			return;
		}
		Live::bump_context( (int) $object_id );
	}

	/**
	 * Adds the "Context" box below the block editor, for users who can edit live settings. It stays
	 * hidden until live coverage is on (the editor panel shows it).
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      Post.
	 * @return void
	 */
	public static function add_meta_box( $post_type, $post = null ): void {
		if ( ! self::has_box( $post ) ) {
			return;
		}
		add_meta_box(
			self::BOX,
			__( 'Context', 'newspack-live' ),
			[ __CLASS__, 'render_meta_box' ],
			$post_type,
			'normal',
			'high',
			[ '__back_compat_meta_box' => false ]
		);
		add_filter( "postbox_classes_{$post_type}_" . self::BOX, [ __CLASS__, 'meta_box_classes' ] );
	}

	/**
	 * Whether the "Context" box is shown for a post: a live-capable post type in the block editor, and
	 * a user who can edit live settings (the meta's auth callback checks the post too).
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function has_box( $post ): bool {
		return $post instanceof WP_Post && in_array( $post->post_type, Live::post_types(), true ) && current_user_can( 'newspack_live_manage' ) && use_block_editor_for_post( $post );
	}

	/**
	 * Starts the box hidden when the post isn't a live yet (the editor panel reveals it).
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function meta_box_classes( $classes ) {
		global $post;
		if ( ! Live::is_live( $post ) ) {
			$classes[] = 'newspack-live-context--off';
		}
		return $classes;
	}

	/**
	 * Box content: the editor panel mounts the Context editor here.
	 *
	 * @return void
	 */
	public static function render_meta_box(): void {
		echo '<div id="newspack-live-context-root" class="newspack-live-context-box"></div>';
	}
}
