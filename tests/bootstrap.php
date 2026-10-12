<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads the PHPUnit polyfills, the WordPress test library (wp-phpunit) and the plugin.
 * Newspack plugins are not loaded: the classes the plugin integrates with are stubbed in tests/mocks/.
 *
 * @package Newspack_Live
 */

$newspack_live_root = dirname( __DIR__ );

require_once $newspack_live_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$newspack_live_config = __DIR__ . '/wp-tests-config.php';
if ( ! file_exists( $newspack_live_config ) ) {
	fwrite( STDERR, "Missing tests/wp-tests-config.php: copy tests/wp-tests-config.example.php.\n" );
	exit( 1 );
}
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $newspack_live_config );

$newspack_live_tests_dir = $newspack_live_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $newspack_live_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $newspack_live_root ) {
		require_once __DIR__ . '/mocks/newspack.php';
		require_once __DIR__ . '/mocks/newspack-collections.php';
		require_once __DIR__ . '/mocks/co-authors-plus.php';
		require_once $newspack_live_root . '/newspack-live.php';
	}
);

require $newspack_live_tests_dir . '/includes/bootstrap.php';

// Role capabilities and default labels, outside the per-test transactions.
\Newspack_Live\Setup::run();

require_once __DIR__ . '/unit-tests/trait-live-factory.php';
