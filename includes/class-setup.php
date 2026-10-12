<?php
/**
 * Setup routine: capabilities and cron events.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on activation and whenever `newspack_live_db_version` differs from the plugin version.
 * The version check only runs on admin_init, cli_init and the hourly cron, never
 * during front-end or REST requests.
 */
final class Setup {

	public const OPTION_VERSION = 'newspack_live_db_version';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_run' ] );
		add_action( 'cli_init', [ __CLASS__, 'maybe_run' ] );
	}

	/**
	 * Activation hook. Activation runs after `init`, without the plugin's post types: resetting the
	 * version makes the next admin_init or cli_init run the full routine.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Capabilities::add_role_caps();
		Cron::schedule();
		delete_option( self::OPTION_VERSION );
	}

	/**
	 * Deactivation hook: unschedules cron events, nothing else.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Runs the routine when the stored version differs from the plugin version.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( NEWSPACK_LIVE_VERSION === get_option( self::OPTION_VERSION ) ) {
			return;
		}
		if ( ! post_type_exists( Post_Types::ENTRY ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Idempotent setup: capabilities, cron events, version.
	 *
	 * @return void
	 */
	public static function run(): void {
		Capabilities::add_role_caps();
		Cron::schedule();
		update_option( self::OPTION_VERSION, NEWSPACK_LIVE_VERSION, true );
	}
}
