<?php
/**
 * Plugin Name:       Newspack Live
 * Description:       Live coverage of developing stories for Newspack sites.
 * Version:           1.2.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Requires Plugins:  newspack-plugin
 * Author:            Nicolas Rapp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       newspack-live
 * Domain Path:       /languages
 *
 * @package Newspack_Live
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Kept in sync with the plugin header and package.json.
 *
 * @var string
 */
define( 'NEWSPACK_LIVE_VERSION', '1.2.0' );

/**
 * Absolute path to the main plugin file.
 *
 * @var string
 */
define( 'NEWSPACK_LIVE_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with a trailing slash.
 *
 * @var string
 */
define( 'NEWSPACK_LIVE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL of the plugin directory, with a trailing slash.
 *
 * @var string
 */
define( 'NEWSPACK_LIVE_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( NEWSPACK_LIVE_DIR . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'newspack_live_missing_autoloader_notice' );
	return;
}

require_once NEWSPACK_LIVE_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ '\Newspack_Live\Setup', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\Newspack_Live\Setup', 'deactivate' ] );

add_action( 'plugins_loaded', [ '\Newspack_Live\Plugin', 'init' ] );

/**
 * Shows an admin notice when the Composer autoloader is missing (unbuilt checkout).
 *
 * @return void
 */
function newspack_live_missing_autoloader_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Newspack Live is not built: run "composer install" in the plugin folder.', 'newspack-live' )
	);
}
