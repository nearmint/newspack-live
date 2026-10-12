<?php
/**
 * Uninstall: removes capabilities, options and cron events. Content (entries, questions, labels)
 * is kept unless NEWSPACK_LIVE_UNINSTALL_DELETE_DATA is true.
 *
 * @package Newspack_Live
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'NEWSPACK_LIVE_VERSION' ) ) {
	/**
	 * Plugin version (the main file isn't loaded during uninstall).
	 *
	 * @var string
	 */
	define( 'NEWSPACK_LIVE_VERSION', '1.2.0' );
}

/*
 * NEWSPACK_LIVE_UNINSTALL_DELETE_DATA (bool, default false): when true, uninstalling also
 * deletes every entry, question and label, every `_newspack_live_*` post and user meta, and the
 * plugin's transients. On a network, every site is cleaned.
 */
\Newspack_Live\Uninstall::run_everywhere( defined( 'NEWSPACK_LIVE_UNINSTALL_DELETE_DATA' ) && NEWSPACK_LIVE_UNINSTALL_DELETE_DATA );
