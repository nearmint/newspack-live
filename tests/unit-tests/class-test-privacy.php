<?php
/**
 * Privacy exporter and eraser tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Privacy;
use Newspack_Live\Questions;
use WP_UnitTestCase;

/**
 * Registered readers' questions are exported and erased by email.
 *
 * @group questions
 */
class Test_Privacy extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * The exporter and eraser are registered.
	 */
	public function test_registered(): void {
		$this->assertArrayHasKey( 'newspack-live', apply_filters( 'wp_privacy_personal_data_exporters', [] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$this->assertArrayHasKey( 'newspack-live', apply_filters( 'wp_privacy_personal_data_erasers', [] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
	}

	/**
	 * Export lists the reader's questions only; unknown emails export nothing.
	 */
	public function test_export(): void {
		$reader  = self::factory()->user->create( [ 'user_email' => 'reader@example.org' ] );
		$live_id = $this->create_live( [ 'post_title' => 'Election night' ] );
		Questions::create( $live_id, 'Mine?', 'Reader', $reader );
		Questions::create( $live_id, 'Someone else', 'Other' );
		$export = Privacy::export( 'reader@example.org', 1 );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$values = array_column( $export['data'][0]['data'], 'value' );
		$this->assertContains( 'Mine?', $values );
		$this->assertContains( 'Election night', $values );
		$this->assertSame( [], Privacy::export( 'nobody@example.org', 1 )['data'] );
	}

	/**
	 * Erasure deletes the questions and re-renders the answers that quoted them.
	 */
	public function test_erase(): void {
		$editor   = $this->create_user( 'editor' );
		$reader   = self::factory()->user->create( [ 'user_email' => 'erase@example.org' ] );
		$live_id  = $this->create_live( [ 'post_author' => $editor ] );
		$question = Questions::create( $live_id, 'Delete me?', 'Reader', $reader );
		$entry_id = Entries::save(
			[
				'live_id'      => $live_id,
				'content'      => '<!-- wp:paragraph --><p>Hi Reader,</p><!-- /wp:paragraph -->',
				'author'       => $editor,
				'question_ids' => [ $question ],
			]
		);
		Questions::set_status( $question, 'nl_answered', $entry_id );
		$rev = Entries::get_rev( $entry_id );

		$result = Privacy::erase( 'erase@example.org', 1 );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertNull( get_post( $question ) );
		$this->assertGreaterThan( $rev, Entries::get_rev( $entry_id ), 'The answer is re-rendered without the question.' );
		$this->assertStringNotContainsString( 'Delete me?', \Newspack_Live\Renderer::render_entry( $entry_id ) );
	}

	/**
	 * /status carries a fresh signed timestamp for the ask form.
	 */
	public function test_status_question_ts(): void {
		$live_id = $this->create_live();
		$data    = rest_do_request( '/newspack-live/v1/lives/' . $live_id . '/status' )->get_data();
		$this->assertEqualsWithDelta( time(), Questions::verify_timestamp( $live_id, $data['question_ts'] ), 5 );
	}
}
