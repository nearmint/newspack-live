<?php
/**
 * Template selection for single lives.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Picks how a single live renders:
 * - Newspack classic themes: `single-live.php` from the theme, else the plugin's template;
 * - block themes: the plugin block template `newspack-live//single-live`;
 * - other classic themes: the live layout replaces `the_content`.
 */
final class Templates {

	/**
	 * Registered query vars.
	 */
	public const QUERY_VARS = [ 'live-entry', 'live-before' ];

	/**
	 * Block template name.
	 */
	public const BLOCK_TEMPLATE = 'newspack-live//single-live';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'init', [ __CLASS__, 'register_block_template' ], 20 );
		add_filter( 'single_template_hierarchy', [ __CLASS__, 'template_hierarchy' ] );
		add_filter( 'template_include', [ __CLASS__, 'template_include' ], 20 );
		add_filter( 'the_content', [ __CLASS__, 'replace_content' ], 1 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
	}

	/**
	 * Registers the front-end query vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function add_query_vars( $vars ) {
		return array_merge( (array) $vars, self::QUERY_VARS );
	}

	/**
	 * The live being viewed, or 0.
	 *
	 * @return int
	 */
	public static function get_current_live_id(): int {
		// oEmbed views of a live keep core's embed template.
		if ( is_embed() || ! is_singular( Live::post_types() ) ) {
			return 0;
		}
		$post_id = (int) get_queried_object_id();
		return Live::is_live( $post_id ) ? $post_id : 0;
	}

	/**
	 * Whether the active theme is the Newspack classic theme or one of its children.
	 *
	 * @return bool
	 */
	public static function is_newspack_classic_theme(): bool {
		return ! wp_is_block_theme() && 'newspack-theme' === get_template();
	}

	/**
	 * Registers the block template used by block themes.
	 *
	 * @return void
	 */
	public static function register_block_template(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		register_block_template(
			self::BLOCK_TEMPLATE,
			[
				'title'       => __( 'Single live', 'newspack-live' ),
				'description' => __( 'Displays a post with live coverage enabled.', 'newspack-live' ),
				'post_types'  => Live::post_types(),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:group {"tagName":"main","align":"full","layout":{"type":"constrained"}} --><main class="wp-block-group alignfull">'
					. '<!-- wp:newspack-live/live {"align":"wide"} /-->'
					. '</main><!-- /wp:group -->'
					. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			]
		);
	}

	/**
	 * Prepends `single-live` to the hierarchy of lives (theme `single-live.php` or the block template).
	 *
	 * @param string[] $templates Template candidates.
	 * @return string[]
	 */
	public static function template_hierarchy( $templates ) {
		if ( ! self::get_current_live_id() || ( ! wp_is_block_theme() && ! self::is_newspack_classic_theme() ) ) {
			return $templates;
		}
		return array_merge( [ 'single-live.php' ], (array) $templates );
	}

	/**
	 * Uses the plugin's classic template on Newspack classic themes that don't provide one.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function template_include( $template ) {
		if ( ! self::get_current_live_id() || ! self::is_newspack_classic_theme() ) {
			return $template;
		}
		if ( 'single-live.php' === basename( (string) $template ) ) {
			return $template;
		}
		return NEWSPACK_LIVE_DIR . 'templates/single-live.php';
	}

	/**
	 * Other classic themes: replaces the live's content with the live layout. The theme already
	 * shows the title and featured image, so the plugin header omits them.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function replace_content( $content ) {
		$live_id = self::get_current_live_id();
		if ( ! $live_id || wp_is_block_theme() || self::is_newspack_classic_theme() || ! in_the_loop() || ! is_main_query() || get_the_ID() !== $live_id ) {
			return $content;
		}
		// Nested `the_content` calls made while the layout is pending (e.g. excerpts) keep their content.
		if ( self::$stash && self::content_depth() > self::$stash_depth ) {
			return $content;
		}
		// Runs early, so later the_content filters see block-free HTML; the live renders its own content.
		remove_filter( 'the_content', [ __CLASS__, 'replace_content' ], 1 );
		$html = Renderer::render_live( $live_id, [ 'show_title_media' => false ] );
		add_filter( 'the_content', [ __CLASS__, 'replace_content' ], 1 );
		return self::protect_from_content_filters( $html );
	}

	/**
	 * Protects the live layout from the remaining `the_content` filters (wpautop, content inserters).
	 *
	 * The layout is stashed under a unique placeholder and restored on the last `the_content`
	 * priority, only in content that contains the placeholder (nested `the_content` calls are safe).
	 *
	 * @param string $html Live layout.
	 * @return string Placeholder.
	 */
	private static function protect_from_content_filters( string $html ): string {
		self::$stash_depth     = self::content_depth();
		$token                 = 'newspack-live-layout-' . wp_generate_uuid4();
		self::$stash[ $token ] = $html;
		if ( ! has_filter( 'the_content', [ __CLASS__, 'restore_content' ] ) ) {
			add_filter( 'the_content', [ __CLASS__, 'restore_content' ], PHP_INT_MAX );
		}
		return '<!-- ' . $token . ' -->';
	}

	/**
	 * `the_content` nesting depth at which the pending layout was stashed.
	 *
	 * @var int
	 */
	private static int $stash_depth = 0;

	/**
	 * Current nesting depth of `the_content`.
	 *
	 * @return int
	 */
	private static function content_depth(): int {
		global $wp_current_filter;
		return count( array_keys( (array) $wp_current_filter, 'the_content', true ) );
	}

	/**
	 * Stashed live layouts, keyed by placeholder token.
	 *
	 * @var array<string, string>
	 */
	private static array $stash = [];

	/**
	 * Restores stashed live layouts in place of their placeholders.
	 *
	 * @param string $content Filtered content.
	 * @return string
	 */
	public static function restore_content( $content ) {
		foreach ( self::$stash as $token => $html ) {
			$placeholder = '<!-- ' . $token . ' -->';
			if ( str_contains( (string) $content, $placeholder ) ) {
				$layout  = '<div class="newspack-live-content">' . $html . '</div>';
				$content = str_replace( [ '<p>' . $placeholder . '</p>', $placeholder ], $layout, (string) $content );
				unset( self::$stash[ $token ] );
			}
		}
		if ( ! self::$stash ) {
			remove_filter( 'the_content', [ __CLASS__, 'restore_content' ], PHP_INT_MAX );
		}
		return $content;
	}

	/**
	 * Enqueues the live styles in the head of live pages (avoids a flash of unstyled content on classic themes).
	 *
	 * @return void
	 */
	public static function enqueue_styles(): void {
		if ( ! self::get_current_live_id() ) {
			return;
		}
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-live/live' );
		if ( $block && ! empty( $block->style_handles ) ) {
			foreach ( $block->style_handles as $handle ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/**
	 * Adds a body class to live pages.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::get_current_live_id() ) {
			$classes[] = 'newspack-live-page';
		}
		return $classes;
	}
}
