<?php
/**
 * State computation tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Live;
use WP_UnitTestCase;

/**
 * Every row of the state table, including edge cases.
 *
 * @group state
 */
class Test_State extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * A post without live coverage is not a live.
	 */
	public function test_not_enabled_is_none(): void {
		$post_id = self::factory()->post->create();
		$this->assertSame( Live::STATE_NONE, Live::get_state( $post_id ) );
		update_post_meta( $post_id, Live::META_ENABLED, 0 );
		$this->assertSame( Live::STATE_NONE, Live::get_state( $post_id ) );
	}

	/**
	 * Pages are not live-capable by default.
	 */
	public function test_other_post_types_are_none(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $page_id, Live::META_ENABLED, 1 );
		$this->assertSame( Live::STATE_NONE, Live::get_state( $page_id ) );
	}

	/**
	 * An unpublished live is a draft.
	 */
	public function test_unpublished_is_draft(): void {
		$live_id = $this->create_live( [ 'post_status' => 'draft' ] );
		$this->assertSame( Live::STATE_DRAFT, Live::get_state( $live_id ) );
	}

	/**
	 * Without start meta, the publish time is the start.
	 */
	public function test_publish_time_is_start(): void {
		$live_id = $this->create_live();
		$this->assertSame( Live::STATE_LIVE, Live::get_state( $live_id ) );
		$this->assertSame( (int) strtotime( get_post_field( 'post_date_gmt', $live_id ) . ' UTC' ), Live::get_start( $live_id ) );
	}

	/**
	 * A future scheduled start is upcoming, and becomes live once passed, without any action.
	 */
	public function test_scheduled_start(): void {
		$now     = time();
		$live_id = $this->create_live( [], [ Live::META_SCHEDULED_START => $now + 3600 ] );
		$this->assertSame( Live::STATE_UPCOMING, Live::get_state( $live_id, $now ) );
		$this->assertSame( Live::STATE_LIVE, Live::get_state( $live_id, $now + 3601 ) );
	}

	/**
	 * `_start` wins over the scheduled start.
	 */
	public function test_start_overrides_scheduled_start(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_SCHEDULED_START => $now + 3600,
				Live::META_START           => $now - 60,
			]
		);
		$this->assertSame( Live::STATE_LIVE, Live::get_state( $live_id, $now ) );
	}

	/**
	 * A past `_end` means ended; a future `_end` doesn't.
	 */
	public function test_end(): void {
		$now     = time();
		$live_id = $this->create_live( [], [ Live::META_END => $now - 1 ] );
		$this->assertSame( Live::STATE_ENDED, Live::get_state( $live_id, $now ) );
		update_post_meta( $live_id, Live::META_END, $now + 60 );
		$this->assertSame( Live::STATE_LIVE, Live::get_state( $live_id, $now ) );
	}

	/**
	 * Ended wins over a future start.
	 */
	public function test_end_before_upcoming(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_SCHEDULED_START => $now + 3600,
				Live::META_END             => $now - 10,
			]
		);
		$this->assertSame( Live::STATE_ENDED, Live::get_state( $live_id, $now ) );
	}

	/**
	 * Inactivity beyond the auto-end delay reads as ended (virtually).
	 */
	public function test_auto_end(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_START          => $now - 10 * HOUR_IN_SECONDS,
				Live::META_ACTIVITY_AT    => $now - 7 * HOUR_IN_SECONDS,
				Live::META_AUTO_END_HOURS => 6,
			]
		);
		$this->assertSame( Live::STATE_ENDED, Live::get_state( $live_id, $now ) );
		$this->assertTrue( Live::is_virtually_ended( $live_id, $now ) );
		$this->assertSame( $now - HOUR_IN_SECONDS, Live::get_auto_end_at( $live_id ) );
	}

	/**
	 * Auto-end 0 never ends.
	 */
	public function test_auto_end_never(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_START          => $now - 100 * HOUR_IN_SECONDS,
				Live::META_AUTO_END_HOURS => 0,
			]
		);
		$this->assertSame( Live::STATE_LIVE, Live::get_state( $live_id, $now ) );
		$this->assertSame( 0, Live::get_auto_end_at( $live_id ) );
	}

	/**
	 * Activity older than the start: the start is the inactivity reference.
	 */
	public function test_activity_before_start(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_START          => $now - 2 * HOUR_IN_SECONDS,
				Live::META_ACTIVITY_AT    => $now - 30 * HOUR_IN_SECONDS,
				Live::META_AUTO_END_HOURS => 6,
			]
		);
		$this->assertSame( Live::STATE_LIVE, Live::get_state( $live_id, $now ) );
		$this->assertSame( $now + 4 * HOUR_IN_SECONDS, Live::get_auto_end_at( $live_id ) );
	}

	/**
	 * The auto-end warning shows in the last hour only.
	 */
	public function test_auto_end_warning(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_START          => $now - 10 * HOUR_IN_SECONDS,
				Live::META_ACTIVITY_AT    => $now - 5.5 * HOUR_IN_SECONDS,
				Live::META_AUTO_END_HOURS => 6,
			]
		);
		$this->assertTrue( Live::get_auto_end_warning( $live_id, $now )['show'] );
		update_post_meta( $live_id, Live::META_ACTIVITY_AT, $now - 4 * HOUR_IN_SECONDS );
		$this->assertFalse( Live::get_auto_end_warning( $live_id, $now )['show'] );
	}

	/**
	 * State actions are allowed only in the states they apply to.
	 */
	public function test_actions(): void {
		$now     = time();
		$live_id = $this->create_live( [], [ Live::META_SCHEDULED_START => $now + DAY_IN_SECONDS ] );

		$this->assertWPError( Live::apply_action( $live_id, 'end' ) );
		$this->assertSame( Live::STATE_LIVE, Live::apply_action( $live_id, 'go_live' ) );
		$this->assertGreaterThanOrEqual( $now, (int) get_post_meta( $live_id, Live::META_START, true ) );
		$this->assertSame( 0, (int) get_post_meta( $live_id, Live::META_END, true ) );
		$this->assertGreaterThanOrEqual( $now, (int) get_post_meta( $live_id, Live::META_ACTIVITY_AT, true ) );

		$this->assertWPError( Live::apply_action( $live_id, 'reopen' ) );
		update_post_meta( $live_id, Live::META_ACTIVITY_AT, $now - 100 );
		$this->assertSame( Live::STATE_LIVE, Live::apply_action( $live_id, 'keep_live' ) );
		$this->assertGreaterThanOrEqual( $now, (int) get_post_meta( $live_id, Live::META_ACTIVITY_AT, true ) );

		$this->assertSame( Live::STATE_ENDED, Live::apply_action( $live_id, 'end' ) );
		$this->assertGreaterThanOrEqual( $now, Live::get_end( $live_id ) );
		$this->assertWPError( Live::apply_action( $live_id, 'keep_live' ) );

		$this->assertSame( Live::STATE_LIVE, Live::apply_action( $live_id, 'reopen' ) );
		$this->assertSame( 0, Live::get_end( $live_id ) );
		$this->assertWPError( Live::apply_action( $live_id, 'dance' ) );
	}

	/**
	 * A draft live can't go live.
	 */
	public function test_draft_cannot_go_live(): void {
		$live_id = $this->create_live( [ 'post_status' => 'draft' ] );
		$result  = Live::apply_action( $live_id, 'go_live' );
		$this->assertWPError( $result );
		$this->assertSame( 'Publish the post to go live.', $result->get_error_message() );
	}

	/**
	 * Virtual ends are persisted by the next write, not by reads.
	 */
	public function test_virtual_end_persisted_by_write_only(): void {
		$now     = time();
		$live_id = $this->create_live(
			[],
			[
				Live::META_START          => $now - 10 * HOUR_IN_SECONDS,
				Live::META_ACTIVITY_AT    => $now - 7 * HOUR_IN_SECONDS,
				Live::META_AUTO_END_HOURS => 6,
			]
		);
		Live::get_state( $live_id );
		$this->assertSame( 0, Live::get_end( $live_id ) );
		Live::set_questions_open( $live_id, false );
		$this->assertSame( $now - HOUR_IN_SECONDS, Live::get_end( $live_id ) );
		$this->assertSame( Live::STATE_ENDED, Live::get_state( $live_id ) );
	}

	/**
	 * Settings fall back to their defaults and are sanitized.
	 */
	public function test_settings_defaults(): void {
		$live_id  = $this->create_live();
		$settings = Live::get_settings( $live_id );
		$this->assertSame( 24, $settings['auto_end_hours'] );
		$this->assertSame( 'anyone', $settings['questions_mode'] );
		$this->assertArrayNotHasKey( 'ads_every', $settings, 'No feed ads.' );
		$this->assertArrayNotHasKey( 'prompts', $settings );
		$this->assertTrue( $settings['show_entry_author'] );
		$this->assertTrue( $settings['questions_open'] );
		$this->assertSame( 'primary-category', $settings['related_source'] );
		update_post_meta( $live_id, '_newspack_live_auto_end_hours', 7 );
		$settings = Live::get_settings( $live_id );
		$this->assertSame( 24, $settings['auto_end_hours'] );
	}

	/**
	 * Enabling live coverage creates the revision counter.
	 */
	public function test_enabling_creates_rev(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( metadata_exists( 'post', $post_id, Live::META_REV ) );
		update_post_meta( $post_id, Live::META_ENABLED, true );
		$this->assertTrue( metadata_exists( 'post', $post_id, Live::META_REV ) );
		$this->assertSame( 0, Live::get_rev( $post_id ) );
	}
}
