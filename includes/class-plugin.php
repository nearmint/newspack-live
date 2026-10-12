<?php
/**
 * Plugin bootstrap.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps Newspack Live: checks requirements and initializes every class in order.
 */
final class Plugin {

	/**
	 * Initializes the plugin once all plugins are loaded.
	 *
	 * Classes are initialized in this order: data model first (post types, meta,
	 * capabilities), then services that depend on it (REST, rendering, admin),
	 * then integrations.
	 *
	 * @return void
	 */
	public static function init(): void {
		load_plugin_textdomain( 'newspack-live', false, dirname( plugin_basename( NEWSPACK_LIVE_FILE ) ) . '/languages' );

		if ( ! self::is_newspack_active() ) {
			add_action( 'admin_notices', [ __CLASS__, 'render_missing_newspack_notice' ] );
			return;
		}

		// Data model first, then services, then integrations.
		Post_Types::init();
		Live_Meta::init();
		Live::init();
		Context::init();
		Labels::init();
		Capabilities::init();
		Entries::init();
		Setup::init();
		Cron::init();
		Questions::init();
		Question_Groups::init();
		Feed::init();
		Blocks::init();
		Templates::init();
		REST\REST_Public::init();
		REST\REST_Private::init();
		REST\REST_Cache::init();
		Admin::init();
		Console::init();
		Privacy::init();
		Presence::init();
		SEO::init();
		E2E::init();
		Integrations\Co_Authors_Plus::init();
		Integrations\Newspack_Ads::init();
		Listings::init();
		Integrations\Homepage_Posts::init();
		CLI\Command::init();

		/**
		 * Fires once Newspack Live has been initialized.
		 *
		 * @since 1.2.0
		 */
		do_action( 'newspack_live_loaded' );
	}

	/**
	 * Whether the Newspack main plugin is active.
	 *
	 * @return bool
	 */
	public static function is_newspack_active(): bool {
		return defined( 'NEWSPACK_PLUGIN_FILE' ) || class_exists( '\Newspack\Newspack' );
	}

	/**
	 * Renders an admin notice when the Newspack plugin is not active.
	 *
	 * @return void
	 */
	public static function render_missing_newspack_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Newspack Live requires the Newspack plugin to be installed and active.', 'newspack-live' )
		);
	}
}
