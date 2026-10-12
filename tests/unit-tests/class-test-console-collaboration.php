<?php
/**
 * Who's online and console paging.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Presence;
use Newspack_Live\Questions;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Presence through the Heartbeat filter, its expiry and scoping; cursor paging of the four
 * console lists.
 *
 * @group console
 */
class Test_Console_Collaboration extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Runs the heartbeat filter as the current user.
	 *
	 * @param int $live_id  Live ID.
	 * @param int $interval Interval.
	 * @return array Heartbeat response.
	 */
	private function beat( int $live_id, int $interval = 15 ): array {
		$data = [
			Presence::KEY => [
				'live'     => $live_id,
				'interval' => $interval,
			],
		];
		return (array) apply_filters( 'heartbeat_received', [], $data, 'admin_page_newspack-live-console' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
	}

	/**
	 * Names in a presence list.
	 *
	 * @param array $response Heartbeat response.
	 * @return string[]
	 */
	private static function names( array $response ): array {
		return array_column( $response[ Presence::KEY ]['users'] ?? [], 'name' );
	}

	/**
	 * Two team members see each other (the viewer first); users who can't post to the live are
	 * never recorded and get no list; a user taken off the team disappears.
	 */
	public function test_presence_scoping(): void {
		$alice    = self::factory()->user->create(
			[
				'role'         => 'author',
				'display_name' => 'Alice',
			]
		);
		$bob      = self::factory()->user->create(
			[
				'role'         => 'author',
				'display_name' => 'Bob',
			]
		);
		$outsider = self::factory()->user->create(
			[
				'role'         => 'author',
				'display_name' => 'Outsider',
			]
		);
		$reader   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$live     = $this->create_live( [], [ Live::META_TEAM => [ $alice, $bob ] ] );

		wp_set_current_user( $alice );
		$this->assertSame( [ 'Alice' ], self::names( $this->beat( $live ) ) );
		wp_set_current_user( $bob );
		$response = $this->beat( $live );
		$this->assertSame( [ 'Bob', 'Alice' ], self::names( $response ), 'The viewer comes first.' );
		$this->assertTrue( $response[ Presence::KEY ]['users'][0]['self'] );
		$this->assertStringStartsWith( 'http', $response[ Presence::KEY ]['users'][1]['avatar'] );

		foreach ( [ $outsider, $reader ] as $user ) {
			wp_set_current_user( $user );
			$this->assertArrayNotHasKey( Presence::KEY, $this->beat( $live ), 'No list for users who cannot post to the live.' );
		}
		$this->assertSame( [ 'Alice', 'Bob' ], array_column( Presence::users( $live ), 'name' ), 'They were never recorded.' );

		// Off the team: no longer listed, even with a fresh tick on record.
		update_post_meta( $live, Live::META_TEAM, [ $alice ] );
		\Newspack_Live\Live::flush_team();
		$this->assertSame( [ 'Alice' ], array_column( Presence::users( $live ), 'name' ) );

		// Another live is separate; a non-live post is ignored.
		wp_set_current_user( $alice );
		$other = $this->create_live( [], [ Live::META_TEAM => [ $alice ] ] );
		$this->assertSame( [ 'Alice' ], self::names( $this->beat( $other ) ) );
		$this->assertArrayNotHasKey( Presence::KEY, $this->beat( self::factory()->post->create() ) );
	}

	/**
	 * Expiry: a user drops off 60 s after their last tick, at once on leave; the index is pruned
	 * and refreshed before it expires; nothing is written to meta.
	 */
	public function test_presence_expiry_and_leave(): void {
		$editor = $this->create_user( 'editor' );
		$other  = $this->create_user( 'editor' );
		$live   = $this->create_live();
		Presence::tick( $live, $editor );
		Presence::tick( $live, $other );
		$this->assertCount( 2, Presence::users( $live ) );

		// Simulate an expired transient for $other (core's transient API with a past timeout).
		update_option( '_transient_timeout_newspack_live_presence_' . $live . '_' . $other, time() - 1, false );
		wp_cache_delete( 'newspack_live_presence_' . $live . '_' . $other, 'transient' );
		$this->assertSame( [ $editor ], array_column( Presence::users( $live ), 'id' ) );
		$this->assertSame( [ $editor ], get_transient( 'newspack_live_presence_' . $live )['ids'], 'Pruned from the index.' );

		Presence::leave( $live, $editor );
		$this->assertSame( [], Presence::users( $live ) );

		// 60 s without a tick.
		Presence::tick( $live, $editor );
		$this->assertEqualsWithDelta( time() + 60, (int) get_option( '_transient_timeout_newspack_live_presence_' . $live . '_' . $editor ), 2 );

		// An index close to expiring is rewritten on the next tick (members never vanish at once).
		set_transient(
			'newspack_live_presence_' . $live,
			[
				'ids' => [ $editor, 0 ],
				'at'  => time() - 400,
			],
			600
		);
		Presence::tick( $live, $editor );
		$index = get_transient( 'newspack_live_presence_' . $live );
		$this->assertSame( [ $editor ], $index['ids'], 'Rewritten, without the zero.' );
		$this->assertEqualsWithDelta( time(), $index['at'], 2 );
		Presence::leave( $live, $editor );

		$this->assertSame( [], array_filter( array_keys( get_post_meta( $live ) ), static fn( $key ) => str_contains( $key, 'presence' ) ), 'No post meta.' );
		$this->assertSame( [], array_filter( array_keys( get_user_meta( $editor ) ), static fn( $key ) => str_contains( $key, 'presence' ) ), 'No user meta.' );

		// The leave route and the privacy eraser hook.
		wp_set_current_user( $editor );
		$response = rest_do_request( new WP_REST_Request( 'POST', '/newspack-live/v1/lives/' . $live . '/presence/leave' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], Presence::users( $live ) );
		Presence::tick( $live, $editor );
		Presence::forget_user( $editor );
		$this->assertSame( [], Presence::users( $live ) );
	}

	/**
	 * The console page beats every 15 s; other admin pages keep core's settings.
	 */
	public function test_heartbeat_settings(): void {
		set_current_screen( 'dashboard' );
		$_GET['page'] = 'newspack-live-console';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$this->assertSame( 15, apply_filters( 'heartbeat_settings', [] )['interval'] ?? null );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$this->assertSame( 'disable', apply_filters( 'heartbeat_settings', [] )['suspension'] ?? null, 'No 10-minute suspension on the console.' );
		foreach ( [ 'edit.php', 'post.php', 'newspack-live-other' ] as $page ) {
			$_GET['page'] = $page;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			$settings = apply_filters( 'heartbeat_settings', [] );
			$this->assertArrayNotHasKey( 'interval', $settings, $page );
			$this->assertArrayNotHasKey( 'suspension', $settings, "Core's suspension elsewhere ($page)." );
		}
		unset( $_GET['page'] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$settings = apply_filters( 'heartbeat_settings', [] );
		$this->assertArrayNotHasKey( 'suspension', $settings );
		// Front end (no admin screen): untouched even with the page parameter.
		set_current_screen( 'front' );
		$_GET['page'] = 'newspack-live-console';
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$this->assertArrayNotHasKey( 'suspension', apply_filters( 'heartbeat_settings', [] ) );
		unset( $_GET['page'] );
	}

	/**
	 * Feed and Drafts: 50 items, then "Load more" through every item exactly once, newest first.
	 */
	public function test_feed_and_drafts_paging(): void {
		$editor = $this->create_user( 'editor' );
		wp_set_current_user( $editor );
		$live  = $this->create_live();
		$feed  = [];
		$draft = [];
		for ( $i = 1; $i <= 150; $i++ ) {
			$feed[] = Entries::save(
				[
					'live_id' => $live,
					'title'   => "Update $i",
					'author'  => $editor,
					// 30 entries share each second (exact keyset).
					'time'    => time() - 1000 + intdiv( $i, 30 ),
				]
			);
		}
		for ( $i = 1; $i <= 60; $i++ ) {
			$draft[] = Entries::save(
				[
					'live_id' => $live,
					'title'   => "Draft $i",
					'author'  => $editor,
					'status'  => 'draft',
				]
			);
		}
		$console = rest_do_request( new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/console' ) )->get_data();
		$this->assertCount( 50, $console['entries'] );
		$this->assertTrue( $console['has_more'] );
		$this->assertCount( 50, $console['drafts'] );
		$this->assertTrue( $console['drafts_more'] );
		$this->assertSame( 60, $console['drafts_total'] );

		foreach ( [
			'feed'   => [ $console['entries'], $feed ],
			'drafts' => [ $console['drafts'], $draft ],
		] as $list => [ $first, $all ] ) {
			$seen = array_column( $first, 'id' );
			$more = true;
			while ( $more ) {
				$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/console/entries' );
				$request->set_query_params(
					[
						'list'   => $list,
						'before' => end( $seen ),
					]
				);
				$data = rest_do_request( $request )->get_data();
				$this->assertLessThanOrEqual( 50, count( $data['items'] ) );
				$seen = array_merge( $seen, array_column( $data['items'], 'id' ) );
				$more = $data['has_more'];
			}
			$this->assertSame( array_reverse( $all ), $seen, "$list: every item once, newest first" );
		}

		// A refresh keeps the drafts loaded so far (down to the oldest one loaded).
		$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/console' );
		$request->set_query_params(
			[
				'since'        => Live::get_rev( $live ),
				'drafts_until' => $draft[0],
			]
		);
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( array_reverse( $draft ), array_column( $data['drafts'], 'id' ) );
		$this->assertFalse( $data['drafts_more'] );
	}

	/**
	 * Questions: 50 items, then "Load more" with the `before` cursor; `until` refreshes everything
	 * loaded so far.
	 */
	public function test_questions_paging(): void {
		$editor = $this->create_user( 'editor' );
		wp_set_current_user( $editor );
		$live = $this->create_live();
		foreach ( [ 'question' ] as $kind ) {
			$ids = [];
			for ( $i = 1; $i <= 120; $i++ ) {
				// Distinct words: no similar-question groups.
				$ids[] = Questions::create( $live, "$kind number $i " . wp_generate_password( 12, false ), "Reader $i" );
			}
			$seen   = [];
			$before = 0;
			do {
				$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/questions' );
				$request->set_query_params(
					[
						'status' => 'nl_new',
						'before' => $before,
					]
				);
				$data = rest_do_request( $request )->get_data();
				$this->assertLessThanOrEqual( 50, count( $data['items'] ) );
				$seen   = array_merge( $seen, array_column( $data['items'], 'id' ) );
				$before = (int) end( $seen );
			} while ( $data['has_more'] );
			$this->assertSame( array_reverse( $ids ), $seen, "$kind: every item once, newest first" );

			$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/questions' );
			$request->set_query_params(
				[
					'status' => 'nl_new',
					'until'  => $ids[20],
				]
			);
			$data = rest_do_request( $request )->get_data();
			$this->assertSame( array_reverse( array_slice( $ids, 20 ) ), array_column( $data['items'], 'id' ) );
			$this->assertTrue( $data['has_more'] );
		}
	}

	/**
	 * Drafts: "Load more" only when a next draft exists, and the order doesn't change when a
	 * draft's time is set (ID keyset).
	 */
	public function test_drafts_has_more_is_exact(): void {
		$editor = $this->create_user( 'editor' );
		wp_set_current_user( $editor );
		$live   = $this->create_live();
		$drafts = [];
		for ( $i = 1; $i <= 50; $i++ ) {
			$drafts[] = Entries::save(
				[
					'live_id' => $live,
					'title'   => "Draft $i",
					'author'  => $editor,
					'status'  => 'draft',
				]
			);
		}
		$console = rest_do_request( new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/console' ) )->get_data();
		$this->assertCount( 50, $console['drafts'] );
		$this->assertFalse( $console['drafts_more'], 'No older draft: no "Load more".' );
		$this->assertSame( 50, $console['drafts_total'] );

		// Refresh down to the oldest draft: still nothing more.
		$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/console' );
		$request->set_query_params(
			[
				'since'        => Live::get_rev( $live ),
				'drafts_until' => $drafts[0],
			]
		);
		$data = rest_do_request( $request )->get_data();
		$this->assertCount( 50, $data['drafts'] );
		$this->assertFalse( $data['drafts_more'] );

		// Setting a draft's time doesn't move it: newest first by ID.
		Entries::save( [ 'time' => time() - DAY_IN_SECONDS ], $drafts[49] );
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( array_reverse( $drafts ), array_column( $data['drafts'], 'id' ) );
	}
}
