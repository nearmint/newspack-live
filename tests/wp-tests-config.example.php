<?php
/**
 * WordPress test suite configuration (example).
 *
 * Copy to tests/wp-tests-config.php (gitignored) and adjust the socket path.
 * The database MUST NOT be the site database: the test suite empties its tables.
 *
 * @package Newspack_Live
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', 'wp_tests_newspack_live' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
// Local by Flywheel only listens on a per-site socket.
define( 'DB_HOST', 'localhost:' . getenv( 'HOME' ) . '/Library/Application Support/Local/run/your-local-site-id/mysql/mysqld.sock' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

// The installer runs in a subprocess: use Local's PHP 8.2 through the wrapper (its path has no spaces).
define( 'WP_PHP_BINARY', dirname( __DIR__ ) . '/bin/php.local.sh' );

define( 'WPLANG', '' );
