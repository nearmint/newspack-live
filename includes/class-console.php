<?php
/**
 * Live console admin page.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * The live console: a React app on `admin.php?page=newspack-live-console&live={id}`.
 */
final class Console {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'newspack-live-console';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'load-admin_page_' . Admin::CONSOLE_PAGE, [ __CLASS__, 'check_access' ] );
	}

	/**
	 * Refuses access before any output, so the response carries the right status (404/403).
	 *
	 * @return void
	 */
	public static function check_access(): void {
		$live_id = self::get_requested_live_id();
		if ( ! Live::is_live( $live_id ) ) {
			wp_die( esc_html__( 'This post is not a live.', 'newspack-live' ), '', [ 'response' => 404 ] );
		}
		if ( ! current_user_can( 'newspack_live_post_to_live', $live_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to post to this live.', 'newspack-live' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Registers the hidden console page (under options.php, so it has no menu entry).
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'options.php',
			__( 'Live console', 'newspack-live' ),
			__( 'Live console', 'newspack-live' ),
			'newspack_live_post_entries',
			Admin::CONSOLE_PAGE,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Live ID requested by the console screen, or 0.
	 *
	 * @return int
	 */
	public static function get_requested_live_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen parameter, capability checked.
		return isset( $_GET['live'] ) ? absint( wp_unslash( $_GET['live'] ) ) : 0;
	}

	/**
	 * Whether the current admin request is the console page (before the screen is set up, e.g. when
	 * the Heartbeat settings are printed).
	 *
	 * @return bool
	 */
	public static function is_console_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen parameter.
		return is_admin() && isset( $_GET['page'] ) && Admin::CONSOLE_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Whether the current screen is the console.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return bool
	 */
	private static function is_console_screen( string $hook_suffix ): bool {
		return 'admin_page_' . Admin::CONSOLE_PAGE === $hook_suffix;
	}

	/**
	 * Allowed composer blocks.
	 *
	 * @return string[]
	 */
	public static function allowed_blocks(): array {
		$blocks = [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/pullquote',
			'core/image',
			'core/gallery',
			'core/video',
			'core/embed',
			'core/table',
			'core/separator',
			'core/buttons',
			'core/button',
			'newspack-live/article-card',
		];

		/**
		 * Filters the blocks available in the live console composer.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $blocks Block names.
		 */
		$blocks = apply_filters( 'newspack_live_allowed_blocks', $blocks );
		return is_array( $blocks ) ? array_values( array_filter( $blocks, 'is_string' ) ) : [];
	}

	/**
	 * Enqueues the console app and the block editor it embeds.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( ! self::is_console_screen( (string) $hook_suffix ) ) {
			return;
		}
		$live_id = self::get_requested_live_id();
		if ( ! Live::is_live( $live_id ) || ! current_user_can( 'newspack_live_post_to_live', $live_id ) ) {
			return;
		}
		$asset = Admin::asset( 'console' );
		if ( ! $asset ) {
			return;
		}

		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media( [ 'post' => $live_id ] );
		}

		// Server-side block definitions and categories, as the post editor prints them.
		wp_add_inline_script(
			'wp-blocks',
			sprintf( 'wp.blocks.unstable__bootstrapServerSideBlockDefinitions( %s );', wp_json_encode( get_block_editor_server_block_settings() ) ),
			'after'
		);
		wp_add_inline_script(
			'wp-blocks',
			sprintf( 'wp.blocks.setCategories( %s );', wp_json_encode( get_block_categories( get_post( $live_id ) ) ) ),
			'after'
		);

		$dependencies = array_merge( $asset['dependencies'], [ 'wp-block-library', 'wp-format-library', 'heartbeat' ] );
		$card         = \WP_Block_Type_Registry::get_instance()->get_registered( 'newspack-live/article-card' );
		if ( $card && ! empty( $card->editor_script_handles ) ) {
			$dependencies = array_merge( $dependencies, $card->editor_script_handles );
		}
		wp_enqueue_script( self::HANDLE, NEWSPACK_LIVE_URL . 'build/app/console.js', array_unique( $dependencies ), $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'newspack-live', NEWSPACK_LIVE_DIR . 'languages' );

		foreach ( [ 'wp-components', 'wp-block-editor', 'wp-block-library', 'wp-edit-blocks', 'wp-format-library' ] as $style ) {
			wp_enqueue_style( $style );
		}
		if ( file_exists( NEWSPACK_LIVE_DIR . 'build/app/console.css' ) ) {
			wp_enqueue_style( self::HANDLE, NEWSPACK_LIVE_URL . 'build/app/console.css', [ 'wp-components' ], $asset['version'] );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}

		$context  = new \WP_Block_Editor_Context( [ 'name' => 'newspack-live/console' ] );
		$settings = get_block_editor_settings(
			[
				'allowedBlockTypes' => self::allowed_blocks(),
				'hasFixedToolbar'   => true,
			],
			$context
		);
		// The composer has no full-site context: drop heavy or irrelevant settings.
		unset( $settings['__experimentalBlockPatterns'], $settings['__experimentalBlockPatternCategories'], $settings['blockInspectorAnimation'] );

		$user = wp_get_current_user();
		wp_add_inline_script(
			self::HANDLE,
			'window.newspackLiveConsole = ' . wp_json_encode(
				[
					'liveId'         => $live_id,
					'userId'         => $user->ID,
					'allowedBlocks'  => self::allowed_blocks(),
					'labels'         => Labels::console_config(),
					'editorSettings' => $settings,
					'pollInterval'   => 15,
					'fullEditorBase' => admin_url( 'post.php?action=edit&post=' ),
					// Who's online: closing the console leaves at once (sendBeacon can't set headers).
					'presenceLeave'  => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( REST\REST_Public::NAMESPACE . '/lives/' . $live_id . '/presence/leave' ) ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the console root.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		// Access is checked on the page's load hook (check_access()).
		$live_id = self::get_requested_live_id();
		?>
		<div class="wrap newspack-live-console-wrap">
			<h1 class="screen-reader-text">
				<?php
				/* translators: %s: live title. */
				echo esc_html( sprintf( __( 'Live console: %s', 'newspack-live' ), get_the_title( $live_id ) ) );
				?>
			</h1>
			<div id="newspack-live-console" data-live-id="<?php echo esc_attr( (string) $live_id ); ?>">
				<p><?php esc_html_e( 'Loading the live console…', 'newspack-live' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'The live console needs JavaScript.', 'newspack-live' ); ?></p></noscript>
		</div>
		<?php
	}
}
