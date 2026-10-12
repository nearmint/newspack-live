<?php
/**
 * Revision counter and removal log tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Cron;
use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Post_Types;
use WP_UnitTestCase;

/**
 * Monotonicity, stamping, removals and pruning.
 *
 * @group rev
 */
class Test_Revisions extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Live ID.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Author ID.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * Creates a live.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->author  = $this->create_user( 'editor' );
		$this->live_id = $this->create_live( [ 'post_author' => $this->author ] );
		wp_set_current_user( $this->author );
	}

	/**
	 * Adds an entry.
	 *
	 * @param array $data Fields.
	 * @return int
	 */
	private function add( array $data = [] ): int {
		$entry_id = Entries::save(
			array_merge(
				[
					'live_id' => $this->live_id,
					'title'   => 'Entry',
				],
				$data
			)
		);
		$this->assertIsInt( $entry_id );
		return $entry_id;
	}

	/**
	 * Every reader-visible write bumps the revision and stamps the entry; the counter is monotonic.
	 */
	public function test_publish_bumps_and_stamps(): void {
		$updated = [];
		add_action(
			'newspack_live_updated',
			static function ( $live_id, $rev ) use ( &$updated ) {
				$updated[] = $rev;
			},
			10,
			2
		);
		$first  = $this->add();
		$second = $this->add();
		$this->assertSame( 1, Entries::get_rev( $first ) );
		$this->assertSame( 2, Entries::get_rev( $second ) );
		$this->assertSame( 2, Live::get_rev( $this->live_id ) );
		$this->assertSame( [ 1, 2 ], $updated );
		$this->assertGreaterThan( 0, (int) get_post_meta( $this->live_id, Live::META_LAST_UPDATE, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $first, Entries::META_PUBLISHED_AT, true ) );

		Entries::save( [ 'title' => 'Edited' ], $first );
		$this->assertSame( 3, Entries::get_rev( $first ) );
		$this->assertSame( 3, Live::get_rev( $this->live_id ) );
	}

	/**
	 * Drafts and pending entries don't bump the revision.
	 */
	public function test_drafts_do_not_bump(): void {
		$draft = $this->add( [ 'status' => 'draft' ] );
		$this->add( [ 'status' => 'pending' ] );
		Entries::save( [ 'title' => 'Still a draft' ], $draft );
		$this->assertSame( 0, Live::get_rev( $this->live_id ) );
		$this->assertSame( 0, Entries::get_rev( $draft ) );

		Entries::transition( $draft, 'publish' );
		$this->assertSame( 1, Live::get_rev( $this->live_id ) );
		$this->assertSame( 1, Entries::get_rev( $draft ) );
	}

	/**
	 * Flags bump the revision; pinning unpins the previous entry and stamps it too.
	 */
	public function test_flags_and_single_pin(): void {
		$first  = $this->add( [ 'pinned' => true ] );
		$second = $this->add();
		Entries::set_flags( $second, [ 'pinned' => true ] );
		$rev = Live::get_rev( $this->live_id );
		$this->assertFalse( Entries::is_pinned( $first ) );
		$this->assertTrue( Entries::is_pinned( $second ) );
		$this->assertSame( $rev, Entries::get_rev( $first ) );
		$this->assertSame( $rev, Entries::get_rev( $second ) );

		Entries::set_flags( $first, [ 'pinned' => true ] );
		$this->assertTrue( Entries::is_pinned( $first ) );
		$this->assertSame( $rev + 1, Live::get_rev( $this->live_id ) );
	}

	/**
	 * Unpublishing, trashing and deleting published entries log removals.
	 */
	public function test_removals(): void {
		$unpublished = $this->add();
		$trashed     = $this->add();
		$deleted     = $this->add();

		Entries::transition( $unpublished, 'draft' );
		Entries::transition( $trashed, 'trash' );
		wp_delete_post( $deleted, true );

		$removed = Live::get_removed( $this->live_id );
		$this->assertSame( [ $unpublished, $trashed, $deleted ], array_column( $removed, 'id' ) );
		$this->assertSame( [ 4, 5, 6 ], array_column( $removed, 'rev' ) );
		$this->assertSame( 6, Live::get_rev( $this->live_id ) );
		$this->assertSame( 'trash', get_post_status( $trashed ) );
	}

	/**
	 * Removing a draft logs nothing.
	 */
	public function test_draft_removal_not_logged(): void {
		$draft = $this->add( [ 'status' => 'draft' ] );
		Entries::transition( $draft, 'trash' );
		$this->assertSame( [], Live::get_removed( $this->live_id ) );
		$this->assertSame( 0, Live::get_rev( $this->live_id ) );
	}

	/**
	 * Items older than 24 hours are pruned.
	 */
	public function test_prune(): void {
		update_post_meta(
			$this->live_id,
			Live::META_REMOVED,
			[
				[
					'id'  => 1,
					'rev' => 1,
					't'   => time() - DAY_IN_SECONDS - 10,
				],
				[
					'id'  => 2,
					'rev' => 2,
					't'   => time() - 10,
				],
			]
		);
		$this->assertSame( 1, Live::prune_removed( $this->live_id ) );
		$this->assertSame( [ 2 ], array_column( Live::get_removed( $this->live_id ), 'id' ) );
	}

	/**
	 * Full editor edits (core functions) are caught once, after meta and terms are saved.
	 */
	public function test_external_edits_counted_once(): void {
		$entry_id = $this->add();
		$this->assertSame( 1, Live::get_rev( $this->live_id ) );

		wp_update_post(
			[
				'ID'         => $entry_id,
				'post_title' => 'Edited in the full editor',
			]
		);
		$this->assertSame( 2, Live::get_rev( $this->live_id ) );
		$this->assertSame( 2, Entries::get_rev( $entry_id ) );

		$new_id = wp_insert_post(
			[
				'post_type'   => Post_Types::ENTRY,
				'post_parent' => $this->live_id,
				'post_status' => 'publish',
				'post_title'  => 'Created in the full editor',
			]
		);
		$this->assertSame( 3, Entries::get_rev( $new_id ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $new_id, Entries::META_PUBLISHED_AT, true ) );

		wp_trash_post( $new_id );
		$this->assertSame( 4, Live::get_rev( $this->live_id ) );
		$this->assertSame( [ $new_id ], array_column( Live::get_removed( $this->live_id ), 'id' ) );
	}

	/**
	 * Entry validation.
	 */
	public function test_validation(): void {
		$this->assertWPError( Entries::save( [ 'live_id' => $this->live_id ] ) );
		$this->assertWPError(
			Entries::save(
				[
					'live_id' => $this->live_id,
					'title'   => 'Future',
					'time'    => time() + 120,
				]
			)
		);
		$this->assertWPError(
			Entries::save(
				[
					'live_id' => self::factory()->post->create(),
					'title'   => 'Not a live',
				]
			)
		);
		$this->assertWPError(
			Entries::save(
				[
					'live_id' => $this->live_id,
					'title'   => 'Bad status',
					'status'  => 'future',
				]
			)
		);
		// An image-only body is valid; slight clock skew is clamped to now.
		$image = $this->add(
			[
				'title'   => '',
				'content' => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt=""/></figure><!-- /wp:image -->',
				'time'    => time() + 30,
			]
		);
		$this->assertSame( 'publish', get_post_status( $image ) );
		$this->assertLessThanOrEqual( time(), strtotime( get_post_field( 'post_date_gmt', $image ) . ' UTC' ) );
	}

	/**
	 * Backdating stores the given time.
	 */
	public function test_backdating(): void {
		$time     = time() - 30 * MINUTE_IN_SECONDS;
		$entry_id = $this->add( [ 'time' => $time ] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $time ), get_post_field( 'post_date_gmt', $entry_id ) );
	}

	/**
	 * A draft without a time is published "now".
	 */
	public function test_draft_published_now(): void {
		$draft = $this->add( [ 'status' => 'draft' ] );
		Entries::transition( $draft, 'publish' );
		$this->assertEqualsWithDelta( time(), strtotime( get_post_field( 'post_date_gmt', $draft ) . ' UTC' ), 5 );
	}

	/**
	 * The hourly cron persists virtual auto-ends and prunes removal logs.
	 */
	public function test_hourly_cron(): void {
		$now = time();
		update_post_meta( $this->live_id, Live::META_START, $now - 10 * HOUR_IN_SECONDS );
		update_post_meta( $this->live_id, Live::META_ACTIVITY_AT, $now - 7 * HOUR_IN_SECONDS );
		update_post_meta( $this->live_id, Live::META_AUTO_END_HOURS, 6 );
		update_post_meta(
			$this->live_id,
			Live::META_REMOVED,
			[
				[
					'id'  => 1,
					'rev' => 1,
					't'   => $now - 2 * DAY_IN_SECONDS,
				],
			]
		);
		$result = Cron::run_hourly();
		$this->assertSame( 1, $result['ended'] );
		$this->assertSame( 1, $result['pruned'] );
		$this->assertSame( $now - HOUR_IN_SECONDS, Live::get_end( $this->live_id ) );
	}

	/**
	 * The lock returns the callback result, and a 503 `try_again` error while another connection holds it.
	 */
	public function test_lock_contention(): void {
		$this->assertSame( 'inside', Live::with_lock( $this->live_id, static fn() => 'inside' ) );

		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK( %s, 0 )', 'newspack_live_' . $this->live_id ) );
		try {
			$result = Live::with_lock( $this->live_id, static fn() => 'inside', 0 );
			$this->assertWPError( $result );
			$this->assertSame( 'newspack_live_try_again', $result->get_error_code() );
			$this->assertSame( 503, $result->get_error_data()['status'] );
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK( %s )', 'newspack_live_' . $this->live_id ) );
			$other->close();
		}
	}

	/**
	 * A removal logged by another request while this one waited on the lock is not lost (stale meta cache).
	 */
	public function test_removal_log_survives_stale_cache(): void {
		$first  = $this->add();
		$second = $this->add();
		Live::get_removed( $this->live_id );
		$stale = wp_cache_get( $this->live_id, 'post_meta' );
		Entries::transition( $first, 'draft' );
		wp_cache_set( $this->live_id, $stale, 'post_meta' );
		Entries::transition( $second, 'draft' );
		$this->assertSame( [ $first, $second ], array_column( Live::get_removed( $this->live_id ), 'id' ) );
	}

	/**
	 * Pinning a draft unpins the published pinned entry as a reader-visible change.
	 */
	public function test_pinning_draft_bumps_when_unpinning_published(): void {
		$published = $this->add( [ 'pinned' => true ] );
		$draft     = $this->add( [ 'status' => 'draft' ] );
		$rev       = Live::get_rev( $this->live_id );
		Entries::set_flags( $draft, [ 'pinned' => true ] );
		$this->assertFalse( Entries::is_pinned( $published ) );
		$this->assertSame( $rev + 1, Live::get_rev( $this->live_id ) );
		$this->assertSame( $rev + 1, Entries::get_rev( $published ) );

		$new_draft = $this->add(
			[
				'status' => 'draft',
				'pinned' => true,
			]
		);
		// Pins are always written under the lock (race-free); an extra empty revision is harmless.
		$this->assertSame( $rev + 2, Live::get_rev( $this->live_id ) );
		$this->assertTrue( Entries::is_pinned( $new_draft ) );
	}

	/**
	 * With the trash disabled, trashing logs the removal once.
	 */
	public function test_trash_disabled_logs_once(): void {
		$entry_id = $this->add();
		add_filter( 'pre_trash_post', [ $this, 'force_delete' ], 10, 2 );
		Entries::transition( $entry_id, 'trash' );
		remove_filter( 'pre_trash_post', [ $this, 'force_delete' ], 10 );
		$this->assertCount( 1, Live::get_removed( $this->live_id ) );
	}

	/**
	 * Simulates EMPTY_TRASH_DAYS = 0: deletes instead of trashing.
	 *
	 * @param mixed    $trash Short-circuit value.
	 * @param \WP_Post $post  Post.
	 * @return mixed
	 */
	public function force_delete( $trash, $post ) {
		return wp_delete_post( $post->ID, true );
	}
}
