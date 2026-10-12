<?php
/**
 * Question model and retention tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Cron;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use WP_UnitTestCase;

/**
 * Storage, statuses, retention.
 *
 * @group questions
 */
class Test_Questions_Model extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Questions are stored as plain text with their display name, as anonymous by default.
	 */
	public function test_create(): void {
		$live_id     = $this->create_live();
		$question_id = Questions::create( $live_id, '<b>Why</b> <script>x</script>?', 'Ana', 0, 'nl_new', 'hash' );
		$this->assertIsInt( $question_id );
		$post = get_post( $question_id );
		$this->assertSame( 'nl_new', $post->post_status );
		$this->assertSame( '0', (string) $post->post_author );
		$this->assertStringNotContainsString( '<', $post->post_content );
		$this->assertSame( 'Ana', Questions::get_display_name( $question_id ) );
		$this->assertSame( 1, Questions::count( $live_id )['nl_new'] );
	}

	/**
	 * Length limits.
	 */
	public function test_lengths(): void {
		$live_id = $this->create_live();
		$this->assertWPError( Questions::create( $live_id, str_repeat( 'a', 501 ), 'Ana' ) );
		$this->assertWPError( Questions::create( $live_id, 'Why?', str_repeat( 'n', 51 ) ) );
		$this->assertWPError( Questions::create( $live_id, '   ', 'Ana' ) );
		$this->assertIsInt( Questions::create( $live_id, str_repeat( 'é', 500 ), str_repeat( 'é', 50 ) ) );
	}

	/**
	 * Answering links the entry.
	 */
	public function test_set_status(): void {
		$live_id     = $this->create_live();
		$question_id = Questions::create( $live_id, 'Why?', 'Ana' );
		$this->assertTrue( Questions::set_status( $question_id, 'nl_answered', 123 ) );
		$this->assertSame( 'nl_answered', get_post_status( $question_id ) );
		$this->assertSame( '123', get_post_meta( $question_id, Questions::META_ANSWER_ENTRY, true ) );
		$this->assertWPError( Questions::set_status( $question_id, 'publish' ) );
	}

	/**
	 * Unanswered questions of lives ended more than 30 days ago are deleted; answered ones are kept.
	 */
	public function test_retention(): void {
		$old_live    = $this->create_live( [], [ Live::META_END => time() - 31 * DAY_IN_SECONDS ] );
		$recent_live = $this->create_live( [], [ Live::META_END => time() - DAY_IN_SECONDS ] );
		$expired     = Questions::create( $old_live, 'Old new', 'Ana' );
		$answered    = Questions::create( $old_live, 'Old answered', 'Ana', 0, 'nl_answered' );
		$recent      = Questions::create( $recent_live, 'Recent', 'Ana' );

		$result = Cron::run_daily();
		$this->assertSame( 1, $result['deleted'] );
		$this->assertNull( get_post( $expired ) );
		$this->assertNotNull( get_post( $answered ) );
		$this->assertNotNull( get_post( $recent ) );
	}

	/**
	 * IP hashes older than 30 days are cleared.
	 */
	public function test_ip_hash_purge(): void {
		$live_id  = $this->create_live();
		$old      = Questions::create( $live_id, 'Old', 'Ana', 0, 'nl_new', 'old-hash' );
		$recent   = Questions::create( $live_id, 'Recent', 'Ana', 0, 'nl_new', 'recent-hash' );
		$old_date = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
		wp_update_post(
			[
				'ID'            => $old,
				'post_date_gmt' => $old_date,
				'post_date'     => get_date_from_gmt( $old_date ),
				'edit_date'     => true,
			]
		);
		$this->assertSame( 1, Questions::clear_old_ip_hashes() );
		$this->assertSame( '', get_post_meta( $old, Questions::META_IP_HASH, true ) );
		$this->assertSame( 'recent-hash', get_post_meta( $recent, Questions::META_IP_HASH, true ) );
	}

	/**
	 * Resetting rate limits bumps the generation.
	 */
	public function test_reset_rate_limits(): void {
		$before = Questions::get_rate_limit_generation();
		Questions::reset_rate_limits();
		$this->assertSame( $before + 1, Questions::get_rate_limit_generation() );
	}

	/**
	 * Retention advances a cursor, so lives beyond the first batches are reached on later runs.
	 */
	public function test_retention_cursor(): void {
		$lives = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$lives[ $i ] = $this->create_live( [], [ Live::META_END => time() - ( 40 - $i ) * DAY_IN_SECONDS ] );
			Questions::create( $lives[ $i ], 'Q1', 'Ana' );
			Questions::create( $lives[ $i ], 'Q2', 'Ana' );
		}
		$this->assertSame( 3, Questions::delete_expired( 3 ) );
		$this->assertSame( Live::get_end( $lives[0] ), (int) get_option( Questions::OPTION_RETENTION_CURSOR ) );
		$this->assertSame( 3, Questions::delete_expired( 3 ) );
		$this->assertSame( Live::get_end( $lives[2] ), (int) get_option( Questions::OPTION_RETENTION_CURSOR ) );
		$this->assertSame( 0, Questions::delete_expired( 3 ) );
	}

	/**
	 * Q&A entries only accept questions of their live.
	 */
	public function test_questions_scoped_to_live(): void {
		$live_a   = $this->create_live();
		$live_b   = $this->create_live();
		$question = Questions::create( $live_b, 'Other live', 'Ana' );
		$result   = \Newspack_Live\Entries::save(
			[
				'live_id'      => $live_a,
				'title'        => 'Answer',
				'author'       => self::factory()->user->create( [ 'role' => 'editor' ] ),
				'question_ids' => [ $question ],
			]
		);
		$this->assertWPError( $result );
		$this->assertSame( 'newspack_live_invalid_question', $result->get_error_code() );
	}

	/**
	 * More than a page of lives without questions doesn't hide the live after them.
	 */
	public function test_retention_beyond_first_page(): void {
		$base = time() - 60 * DAY_IN_SECONDS;
		for ( $i = 0; $i < 105; $i++ ) {
			$this->create_live( [], [ Live::META_END => $base + $i ] );
		}
		$last     = $this->create_live( [], [ Live::META_END => $base + 200 ] );
		$question = Questions::create( $last, 'Late', 'Ana' );
		$this->assertSame( 1, Questions::delete_expired( 10 ) );
		$this->assertNull( get_post( $question ) );
	}

	/**
	 * Moving a question back to an unanswered status rewinds the retention cursor.
	 */
	public function test_status_change_rewinds_cursor(): void {
		$live_id  = $this->create_live( [], [ Live::META_END => time() - 40 * DAY_IN_SECONDS ] );
		$question = Questions::create( $live_id, 'Kept?', 'Ana', 0, 'nl_answered' );
		update_option( Questions::OPTION_RETENTION_CURSOR, time() - 35 * DAY_IN_SECONDS );
		Questions::set_status( $question, 'nl_rejected' );
		$this->assertSame( Live::get_end( $live_id ), (int) get_option( Questions::OPTION_RETENTION_CURSOR ) );
		$this->assertSame( 1, Questions::delete_expired( 10 ) );
	}
}
