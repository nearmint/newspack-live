<?php
/**
 * Setup and uninstall tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Capabilities;
use Newspack_Live\Cron;
use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Post_Types;
use Newspack_Live\Setup;
use Newspack_Live\Uninstall;
use WP_UnitTestCase;

/**
 * Setup routine (idempotent) and uninstall with and without data deletion.
 *
 * @group setup
 */
class Test_Setup extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Restores the capabilities and cron events removed by the uninstall tests.
	 */
	public function tear_down(): void {
		Capabilities::add_role_caps();
		parent::tear_down();
	}

	/**
	 * The routine schedules cron and stores the version, idempotently.
	 */
	public function test_setup_is_idempotent(): void {
		delete_option( Setup::OPTION_VERSION );
		Setup::maybe_run();
		Setup::run();
		$this->assertSame( NEWSPACK_LIVE_VERSION, get_option( Setup::OPTION_VERSION ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::HOURLY ) );
		$this->assertNotFalse( wp_next_scheduled( Cron::DAILY ) );
	}

	/**
	 * Deactivation only unschedules events.
	 */
	public function test_deactivate(): void {
		Cron::schedule();
		Setup::deactivate();
		$this->assertFalse( wp_next_scheduled( Cron::HOURLY ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'newspack_live_manage' ) );
	}

	/**
	 * Uninstall keeps content by default.
	 */
	public function test_uninstall_keeps_data(): void {
		$live_id  = $this->create_live();
		$entry_id = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Kept',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		Uninstall::run( false );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'newspack_live_manage' ) );
		$this->assertFalse( get_option( Setup::OPTION_VERSION ) );
		$this->assertNotNull( get_post( $entry_id ) );
		$this->assertSame( '1', get_post_meta( $live_id, Live::META_ENABLED, true ) );
	}

	/**
	 * Uninstall with the data constant deletes entries, questions and live meta.
	 */
	public function test_uninstall_deletes_data(): void {
		$live_id  = $this->create_live();
		$entry_id = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Deleted',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		update_post_meta( $live_id, Live::META_CONTEXT_REV, 3 );
		update_post_meta( $live_id, Live::META_CONTEXT_PENDING, 1 );
		Uninstall::run( true );
		$this->assertNull( get_post( $entry_id ) );
		$this->assertSame( '', get_post_meta( $live_id, Live::META_CONTEXT_REV, true ) );
		$this->assertSame( '', get_post_meta( $live_id, Live::META_CONTEXT_PENDING, true ) );
		$this->assertNotNull( get_post( $live_id ), 'The live post itself is a regular post and is kept.' );
		$this->assertSame( '', get_post_meta( $live_id, Live::META_ENABLED, true ) );
	}

	/**
	 * Uninstall deletes questions even when the plugin types aren't registered (uninstall.php context).
	 */
	public function test_uninstall_registers_types(): void {
		$live_id     = $this->create_live();
		$question_id = \Newspack_Live\Questions::create( $live_id, 'Why?', 'Ana' );
		unregister_post_type( Post_Types::ENTRY );
		unregister_post_type( Post_Types::QUESTION );
		global $wp_post_statuses;
		foreach ( Post_Types::QUESTION_STATUSES as $status ) {
			unset( $wp_post_statuses[ $status ] );
		}
		Uninstall::run( true );
		$this->assertNull( get_post( $question_id ) );
	}

	/**
	 * Every `_newspack_live_*` meta key the plugin and its tooling write is deleted by uninstall,
	 * checked against the code itself.
	 */
	public function test_uninstall_covers_every_meta_key(): void {
		$root  = dirname( __DIR__, 2 );
		$found = [];
		$files = [ $root . '/uninstall.php', $root . '/bin/seed-demo-content.php', $root . '/tests/e2e/utils/live.ts', $root . '/tests/e2e/utils/wp-cli.ts' ];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		foreach ( $files as $file ) {
			preg_match_all( '/(?<![A-Za-z0-9_])_newspack_live_[a-z0-9_]+/', (string) file_get_contents( $file ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local source file.
			$found = array_merge( $found, $matches[0] );
		}
		$covered = array_merge( array_keys( \Newspack_Live\Live_Meta::definitions() ), Uninstall::POST_META_KEYS, Uninstall::USER_META_KEYS );
		// Prefixes of dynamic names, not keys.
		$found = array_filter( array_unique( $found ), static fn( $key ) => ! in_array( $key, [ '_newspack_live_' ], true ) && ! str_ends_with( $key, '_' ) );
		$this->assertSame( [], array_values( array_diff( $found, $covered ) ), 'Keys missing from the uninstall routine.' );
	}

	/**
	 * Uninstall with data deletion leaves no plugin meta, transient or option, and keeps the rest.
	 */
	public function test_uninstall_leaves_nothing(): void {
		global $wpdb;
		$live_id = $this->create_live();
		$other   = self::factory()->post->create( [ 'post_title' => 'Unrelated' ] );
		update_post_meta( $other, 'unrelated_meta', 'kept' );
		update_post_meta( $live_id, '_newspack_live_demo', 1 );
		update_post_meta( $live_id, '_newspack_live_e2e', 1 );
		$user = $this->create_user( 'subscriber' );
		update_user_meta( $user, '_newspack_live_e2e', 1 );
		\Newspack_Live\Presence::tick( $live_id, $user );
		set_transient( 'newspack_live_rl_1_u' . $user, 1, 60 );
		set_transient( 'unrelated_transient', 1, 60 );
		Uninstall::run( true );
		$like = $wpdb->esc_like( '_newspack_live_' ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertions on the tables.
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'newspack_live' ) . '%' ) ) );
		// phpcs:enable
		$this->assertSame( 'kept', get_post_meta( $other, 'unrelated_meta', true ) );
		$this->assertSame( 1, (int) get_transient( 'unrelated_transient' ) );
		$this->assertNotNull( get_post( $live_id ) );
	}
}
