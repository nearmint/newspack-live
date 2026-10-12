<?php
/**
 * Newsroom admin: the "Live" menu, Lives list, New live, editor panel assets.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens: the Live menu, the Lives list and the New live link.
 */
final class Admin {

	/**
	 * Query var filtering the posts list to lives.
	 */
	public const LIST_VAR = 'newspack_live';

	/**
	 * Nonce action of the "New live" link.
	 */
	public const NEW_NONCE = 'newspack_live_new';

	/**
	 * Admin page slug of the live console.
	 */
	public const CONSOLE_PAGE = 'newspack-live-console';


	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
		add_action( 'wp_insert_post', [ __CLASS__, 'enable_new_live' ], 10, 3 );
		add_filter( 'parent_file', [ __CLASS__, 'highlight_menu' ] );
		add_action( 'admin_footer-edit.php', [ __CLASS__, 'print_add_new_link' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_lives_list' ] );
		add_filter( 'views_edit-post', [ __CLASS__, 'add_lives_view' ] );
		add_filter( 'submenu_file', [ __CLASS__, 'highlight_submenu' ] );
		add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_panel' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_styles' ] );
		foreach ( Live::post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ __CLASS__, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ __CLASS__, 'render_column' ], 10, 2 );
		}
	}

	/**
	 * URL of the live console.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	public static function console_url( int $live_id ): string {
		return add_query_arg(
			[
				'page' => self::CONSOLE_PAGE,
				'live' => $live_id,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Slug (and link) of the "Lives" list, also the "Live" menu.
	 *
	 * @return string
	 */
	public static function lives_slug(): string {
		return 'edit.php?' . self::LIST_VAR . '=1';
	}

	/**
	 * Link of "New live": core's new post screen, flagged and nonce-protected.
	 *
	 * @return string
	 */
	public static function new_live_slug(): string {
		return 'post-new.php?' . self::LIST_VAR . '=1&_wpnonce=' . wp_create_nonce( self::NEW_NONCE );
	}

	/**
	 * Whether the current user can create a live from "New live".
	 *
	 * @return bool
	 */
	public static function can_create_live(): bool {
		return current_user_can( 'newspack_live_manage' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the top-level "Live" menu right after Posts: "Lives" and "New live", each
	 * shown only to users who can use it (the menu disappears when none is accessible).
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		add_menu_page(
			__( 'Live', 'newspack-live' ),
			__( 'Live', 'newspack-live' ),
			'newspack_live_post_entries',
			self::lives_slug(),
			'',
			'dashicons-format-status',
			// Between Posts (5) and the next item: Newspack Collections also uses 6, where core would
			// push this menu below it.
			'5.1'
		);
		add_submenu_page( self::lives_slug(), __( 'Lives', 'newspack-live' ), __( 'Lives', 'newspack-live' ), 'newspack_live_post_entries', self::lives_slug() );
		if ( self::can_create_live() ) {
			add_submenu_page( self::lives_slug(), __( 'New live', 'newspack-live' ), __( 'New live', 'newspack-live' ), 'newspack_live_manage', self::new_live_slug() );
		}
	}

	/**
	 * Whether the current request is core's new post screen opened from "New live", by a user who can
	 * create lives (others get a regular post, highlighted as such).
	 *
	 * @return bool
	 */
	public static function is_new_live_screen(): bool {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only; enable_new_live() verifies the nonce.
		return 'post-new.php' === $pagenow && isset( $_GET[ self::LIST_VAR ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::LIST_VAR ] ) ) && self::can_create_live();
	}

	/**
	 * Turns the auto-draft core creates on "New live" into a live (default settings, empty Context):
	 * only with a valid nonce and the capabilities to create a live.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public static function enable_new_live( $post_id, $post, $update ): void {
		if ( $update || ! $post instanceof WP_Post || 'auto-draft' !== $post->post_status || ! in_array( $post->post_type, Live::post_types(), true ) || ! self::is_new_live_screen() ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NEW_NONCE ) || ! self::can_create_live() ) {
			return;
		}
		update_post_meta( (int) $post_id, Live::META_ENABLED, 1 );
	}

	/**
	 * Whether the current screen edits a live in the post editor.
	 *
	 * @return bool
	 */
	public static function is_live_editor(): bool {
		global $pagenow, $post;
		return 'post.php' === $pagenow && $post instanceof WP_Post && Live::is_live( $post );
	}

	/**
	 * Opens the "Live" menu on its screens, the console and the editor of a live.
	 *
	 * @param string $parent_file Parent menu file.
	 * @return string
	 */
	public static function highlight_menu( $parent_file ) {
		global $plugin_page;
		$console = self::CONSOLE_PAGE === (string) $plugin_page;
		if ( self::is_lives_list() || self::is_new_live_screen() || $console || self::is_live_editor() ) {
			return self::lives_slug();
		}
		return $parent_file;
	}

	/**
	 * Points the list's "Add New" button to "New live" on the Lives list.
	 *
	 * @return void
	 */
	public static function print_add_new_link(): void {
		if ( ! self::is_lives_list() || ! self::can_create_live() ) {
			return;
		}
		wp_print_inline_script_tag(
			sprintf(
				'( function () { var link = document.querySelector( \'.wrap .page-title-action\' ); if ( link ) { link.href = %s; } } )();',
				wp_json_encode( admin_url( self::new_live_slug() ) )
			)
		);
	}

	/**
	 * Filters the posts list to lives on `edit.php?newspack_live=1`.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function filter_lives_list( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || ! self::is_lives_list() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list filter, paginated.
		$query->set(
			'meta_query',
			[
				[
					'key'   => Live::META_ENABLED,
					'value' => '1',
				],
			]
		);
	}

	/**
	 * Whether the current admin request is the Lives list.
	 *
	 * @return bool
	 */
	public static function is_lives_list(): bool {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen parameter, capability checked by core.
		return 'edit.php' === $pagenow && isset( $_GET[ self::LIST_VAR ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::LIST_VAR ] ) );
	}

	/**
	 * Adds "Lives" to the posts list views, with a "New live" link on the Lives list.
	 *
	 * @param string[] $views Views.
	 * @return string[]
	 */
	public static function add_lives_view( $views ) {
		if ( self::is_lives_list() ) {
			// Keep the Lives filter on the status links (All, Drafts, Published, …).
			foreach ( $views as $key => $link ) {
				$views[ $key ] = (string) preg_replace_callback(
					'/href=(["\'])([^"\']+)\1/',
					[ __CLASS__, 'add_list_var_to_href' ],
					(string) $link
				);
			}
		}
		$views['newspack_live'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( admin_url( 'edit.php?' . self::LIST_VAR . '=1' ) ),
			self::is_lives_list() ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Lives', 'newspack-live' )
		);
		if ( self::is_lives_list() && self::can_create_live() ) {
			$views['newspack_live_new'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( self::new_live_slug() ) ),
				esc_html__( 'New live', 'newspack-live' )
			);
		}
		return $views;
	}

	/**
	 * Adds `newspack_live=1` to a view link (callback of add_lives_view()).
	 *
	 * @param string[] $matches Regex matches: quote and URL.
	 * @return string
	 */
	public static function add_list_var_to_href( array $matches ): string {
		$url = add_query_arg( self::LIST_VAR, '1', html_entity_decode( $matches[2] ) );
		return 'href=' . $matches[1] . esc_url( $url ) . $matches[1];
	}

	/**
	 * Highlights the right "Live" submenu.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @return string|null
	 */
	public static function highlight_submenu( $submenu_file ) {
		global $plugin_page;
		if ( self::is_new_live_screen() ) {
			return self::new_live_slug();
		}
		if ( self::is_lives_list() || self::is_live_editor() || self::CONSOLE_PAGE === (string) $plugin_page ) {
			return self::lives_slug();
		}
		return $submenu_file;
	}

	/**
	 * Adds the "Live" column.
	 *
	 * @param string[] $columns Columns.
	 * @return string[]
	 */
	public static function add_column( $columns ) {
		$columns['newspack_live'] = __( 'Live', 'newspack-live' );
		return $columns;
	}

	/**
	 * State labels for the admin.
	 *
	 * @return array<string, string>
	 */
	public static function state_labels(): array {
		return [
			Live::STATE_DRAFT    => _x( 'Draft', 'live state', 'newspack-live' ),
			Live::STATE_UPCOMING => _x( 'Upcoming', 'live state', 'newspack-live' ),
			Live::STATE_LIVE     => _x( 'Live', 'live state', 'newspack-live' ),
			Live::STATE_ENDED    => _x( 'Ended', 'live state', 'newspack-live' ),
		];
	}

	/**
	 * Renders the state badge in the "Live" column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ): void {
		if ( 'newspack_live' !== $column ) {
			return;
		}
		$state = Live::get_state( (int) $post_id );
		if ( Live::STATE_NONE === $state ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		printf(
			'<span class="newspack-live-admin-badge newspack-live-admin-badge--%1$s">%2$s</span>',
			esc_attr( $state ),
			esc_html( self::state_labels()[ $state ] ?? $state )
		);
	}

	/**
	 * Adds "Open console" to the row actions of lives.
	 *
	 * @param string[] $actions Actions.
	 * @param WP_Post  $post    Post.
	 * @return string[]
	 */
	public static function row_actions( $actions, $post ) {
		if ( $post instanceof WP_Post && Live::is_live( $post ) && current_user_can( 'newspack_live_post_to_live', $post->ID ) ) {
			$actions['newspack_live_console'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::console_url( $post->ID ) ),
				esc_html__( 'Open console', 'newspack-live' )
			);
		}
		return $actions;
	}

	/**
	 * Loads a built admin bundle, or returns false (with a notice) on an unbuilt checkout.
	 *
	 * @param string $name Bundle name under build/app/.
	 * @return array{dependencies: string[], version: string}|false
	 */
	public static function asset( string $name ) {
		$file = NEWSPACK_LIVE_DIR . 'build/app/' . $name . '.asset.php';
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$asset = require $file;
		return is_array( $asset ) ? $asset : false;
	}

	/**
	 * Enqueues the editor panel on live-capable post types.
	 *
	 * @return void
	 */
	public static function enqueue_editor_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, Live::post_types(), true ) ) {
			return;
		}
		$asset = self::asset( 'editor-panel' );
		if ( ! $asset ) {
			return;
		}
		wp_enqueue_script( 'newspack-live-editor-panel', NEWSPACK_LIVE_URL . 'build/app/editor-panel.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'newspack-live-editor-panel', 'newspack-live', NEWSPACK_LIVE_DIR . 'languages' );
		if ( file_exists( NEWSPACK_LIVE_DIR . 'build/app/editor-panel.css' ) ) {
			wp_enqueue_style( 'newspack-live-editor-panel', NEWSPACK_LIVE_URL . 'build/app/editor-panel.css', [ 'wp-components' ], $asset['version'] );
			wp_style_add_data( 'newspack-live-editor-panel', 'rtl', 'replace' );
		}
		// The live's saved Collection is always offered, even when older or no longer published.
		$post_id     = (int) get_the_ID();
		$collections = Integrations\Collections::choices( $post_id ? (int) get_post_meta( $post_id, '_newspack_live_related_collection', true ) : 0 );
		wp_add_inline_script(
			'newspack-live-editor-panel',
			'window.newspackLiveEditor = ' . wp_json_encode(
				[
					'consoleUrl'  => admin_url( 'admin.php?page=' . self::CONSOLE_PAGE . '&live=' ),
					'canManage'   => current_user_can( 'newspack_live_manage' ),
					'hasAds'      => class_exists( '\Newspack_Ads\Placements' ),
					// Newspack Collections for the related source; empty while the module is inactive.
					'collections' => array_map(
						static fn( $id, $title ) => [
							'id'    => $id,
							'title' => $title,
						],
						array_keys( $collections ),
						$collections
					),
					'postTypes'   => Live::post_types(),
					// The "Context" box below the editor.
					'contextBox'  => Context::has_box( get_post() ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Styles for the Lives list.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_styles( $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}
		wp_register_style( 'newspack-live-admin', false, [], NEWSPACK_LIVE_VERSION );
		wp_enqueue_style( 'newspack-live-admin' );
		wp_add_inline_style(
			'newspack-live-admin',
			'.newspack-live-admin-badge{border-radius:999px;display:inline-block;font-size:12px;font-weight:600;line-height:1.6;padding:0 8px;background:#f0f0f1;color:#1d2327}'
			. '.newspack-live-admin-badge--live{background:#c8102e;color:#fff}.newspack-live-admin-badge--ended{background:#4b5563;color:#fff}'
		);
	}
}
