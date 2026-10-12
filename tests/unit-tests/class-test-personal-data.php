<?php
/**
 * Personal data exporters and erasers through core's privacy hooks.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use WP_UnitTestCase;

/**
 * Runs the plugin's exporters and erasers as registered on core's filters, page by page, as core's
 * privacy tools do, and checks that a reader's questions, display names, email settings and
 * every stored list holding their user ID are exported, then erased.
 *
 * @group privacy
 */
class Test_Personal_Data extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Runs all registered exporters for an email address (every page).
	 *
	 * @param string $email Email address.
	 * @return array Exported items, keyed by exporter.
	 */
	private function export_all( string $email ): array {
		$items = [];
		$this->assertCount( 2, array_filter( array_keys( apply_filters( 'wp_privacy_personal_data_exporters', [] ) ), static fn( $key ) => str_starts_with( (string) $key, 'newspack-live' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		foreach ( apply_filters( 'wp_privacy_personal_data_exporters', [] ) as $key => $exporter ) {
			if ( ! str_starts_with( (string) $key, 'newspack-live' ) ) {
				continue;
			}
			$page = 1;
			do {
				$result = call_user_func( $exporter['callback'], $email, $page );
				$this->assertIsArray( $result['data'], $key );
				$items[ $key ] = array_merge( $items[ $key ] ?? [], $result['data'] );
				++$page;
			} while ( empty( $result['done'] ) && $page < 50 );
		}
		return $items;
	}

	/**
	 * Runs all registered erasers for an email address (every page).
	 *
	 * @param string $email Email address.
	 * @return array Results, keyed by eraser.
	 */
	private function erase_all( string $email ): array {
		$results = [];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		foreach ( apply_filters( 'wp_privacy_personal_data_erasers', [] ) as $key => $eraser ) {
			if ( ! str_starts_with( (string) $key, 'newspack-live' ) ) {
				continue;
			}
			$page = 1;
			do {
				$result = call_user_func( $eraser['callback'], $email, $page );
				foreach ( [ 'items_removed', 'items_retained', 'messages', 'done' ] as $field ) {
					$this->assertArrayHasKey( $field, $result, "$key / $field" );
				}
				$results[ $key ][] = $result;
				++$page;
			} while ( empty( $result['done'] ) && $page < 50 );
		}
		return $results;
	}

	/**
	 * Every value of the exported items as one string.
	 *
	 * @param array $items Items by exporter.
	 * @return string
	 */
	private static function flatten( array $items ): string {
		$values = [];
		foreach ( $items as $list ) {
			foreach ( $list as $item ) {
				foreach ( $item['data'] as $pair ) {
					$values[] = $pair['name'] . ': ' . $pair['value'];
				}
			}
		}
		return implode( "\n", $values );
	}

	/**
	 * A reader with a question, a rate-limit slot and a team membership; another reader on the same
	 * lists stays untouched.
	 */
	public function test_export_then_erase_everything(): void {
		$editor = $this->create_user( 'editor' );
		$reader = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.test',
			]
		);
		$other  = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'other@example.test',
			]
		);
		$live   = $this->create_live( [ 'post_title' => 'Storm night' ], [ Live::META_TEAM => [ $editor, $reader ] ] );

		$question = Questions::create( $live, 'Will the bridge close tonight?', 'Rita R.', $reader, 'nl_shortlisted', 'hash' );
		$theirs   = Questions::create( $live, 'Is school open tomorrow?', 'Omar O.', $other, 'nl_shortlisted', 'hash2' );
		$entry    = Entries::save(
			[
				'live_id'      => $live,
				'title'        => 'Bridge update',
				'content'      => '<!-- wp:paragraph --><p>It stays open.</p><!-- /wp:paragraph -->',
				'author'       => $editor,
				'question_ids' => [ $question, $theirs ],
			]
		);
		$this->assertIsInt( $entry );
		set_transient( 'newspack_live_rl_' . Questions::get_rate_limit_generation() . '_u' . $reader, 1, 60 );

		// Export: questions, display names and every list.
		$exported = self::flatten( $this->export_all( 'reader@example.test' ) );
		foreach ( [ 'Will the bridge close tonight?', 'Rita R.', 'Live team', 'Storm night' ] as $expected ) {
			$this->assertStringContainsString( $expected, $exported, $expected );
		}
		$this->assertStringNotContainsString( 'Omar O.', $exported, 'Another reader is never exported.' );
		$this->assertStringNotContainsString( 'Is school open tomorrow?', $exported );

		// Erase.
		$results = $this->erase_all( 'reader@example.test' );
		$this->assertNotEmpty( array_filter( array_merge( ...array_values( $results ) ), static fn( $r ) => $r['items_removed'] ) );
		$this->assertNull( get_post( $question ) );
		$this->assertFalse( get_transient( 'newspack_live_rl_' . Questions::get_rate_limit_generation() . '_u' . $reader ) );
		$this->assertSame( [ $editor ], array_map( 'intval', (array) get_post_meta( $live, Live::META_TEAM, true ) ) );

		// Nothing left to export; the other reader is untouched.
		$this->assertSame( '', self::flatten( $this->export_all( 'reader@example.test' ) ) );
		$this->assertNotNull( get_post( $theirs ) );
		$this->assertStringContainsString( 'Omar O.', self::flatten( $this->export_all( 'other@example.test' ) ) );

		// A second erasure finds nothing and is done.
		foreach ( $this->erase_all( 'reader@example.test' ) as $key => $runs ) {
			$this->assertFalse( end( $runs )['items_removed'], $key );
			$this->assertTrue( end( $runs )['done'], $key );
		}
	}

	/**
	 * Long lists are paged: 120 lives with the reader on their team are all exported and erased.
	 */
	public function test_paging(): void {
		$reader = self::factory()->user->create( [ 'user_email' => 'pages@example.test' ] );
		$ids    = [];
		for ( $i = 1; $i <= 120; $i++ ) {
			$ids[] = $this->create_live( [ 'post_title' => "Live $i" ], [ Live::META_TEAM => [ $reader ] ] );
		}
		$this->assertSame( 120, substr_count( self::flatten( $this->export_all( 'pages@example.test' ) ), 'Live team member' ) );
		$this->erase_all( 'pages@example.test' );
		foreach ( $ids as $id ) {
			$this->assertSame( [], (array) get_post_meta( $id, Live::META_TEAM, true ), "Live $id" );
		}
	}

	/**
	 * Unknown email addresses: nothing exported, nothing erased, done.
	 */
	public function test_unknown_email(): void {
		$this->assertSame( '', self::flatten( $this->export_all( 'nobody@example.test' ) ) );
		foreach ( $this->erase_all( 'nobody@example.test' ) as $runs ) {
			$this->assertFalse( $runs[0]['items_removed'] );
			$this->assertTrue( $runs[0]['done'] );
		}
	}

	/**
	 * Lists where the reader's ID only appears as an array key (lists longer than the ID) are not
	 * matches: the eraser never stalls on them and reaches every real match.
	 */
	public function test_no_false_positives(): void {
		$reader = self::factory()->user->create( [ 'user_email' => 'keys@example.test' ] );
		$others = array_map( fn() => self::factory()->user->create(), range( 1, 3 ) );
		// 60 lists long enough to hold the reader's ID as a key, without the reader.
		$long = array_merge( array_fill( 0, $reader + 2, $others[0] ), [ $others[1] ] );
		// Raw lists: the registered sanitize callback would drop the duplicates (hooks are restored after the test).
		remove_all_filters( 'sanitize_post_meta_' . Live::META_TEAM . '_for_post' );
		for ( $i = 0; $i < 60; $i++ ) {
			update_post_meta( $this->create_live(), Live::META_TEAM, $long );
		}
		$theirs = [ $this->create_live( [], [ Live::META_TEAM => [ $others[2], $reader ] ] ), $this->create_live( [], [ Live::META_TEAM => [ $reader ] ] ) ];
		$this->assertSame( 2, substr_count( self::flatten( $this->export_all( 'keys@example.test' ) ), 'Live team member' ) );
		$this->erase_all( 'keys@example.test' );
		$this->assertSame( [ $others[2] ], array_map( 'intval', (array) get_post_meta( $theirs[0], Live::META_TEAM, true ) ) );
		$this->assertSame( [], (array) get_post_meta( $theirs[1], Live::META_TEAM, true ) );
	}
}
