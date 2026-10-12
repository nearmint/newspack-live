<?php
/**
 * Reader trust: Context bump without another write, "yesterday" mid-sentence.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use mysqli;
use Newspack_Live\Dates;
use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Renderer;
use WP_UnitTestCase;

/**
 * Reader trust.
 */
class Test_Context_Bump extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Second database connection holding a live's lock.
	 *
	 * @var mysqli|null
	 */
	private ?mysqli $other = null;

	/**
	 * Releases the lock held by the second connection.
	 */
	public function tear_down(): void {
		if ( $this->other ) {
			$this->other->close();
			$this->other = null;
		}
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Takes a live's named lock from another database session, as a concurrent request would.
	 *
	 * @param int $live_id Live ID.
	 */
	private function hold_lock( int $live_id ): void {
		[ $host, $socket ] = array_pad( explode( ':', DB_HOST, 2 ), 2, null );
		$this->other       = new mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, 0, $socket );
		$result            = $this->other->query( "SELECT GET_LOCK( 'newspack_live_{$live_id}', 0 )" );
		$this->assertSame( '1', (string) $result->fetch_row()[0], 'The other session holds the lock.' );
	}

	/**
	 * A1: a Context change on a busy live is retried, then bumped by a one-off event without any
	 * other write of the live.
	 */
	public function test_context_bump_busy_lock(): void {
		$live = $this->create_live( [], [ \Newspack_Live\Context::META => '<p>First background.</p>' ] );
		$rev  = Live::get_rev( $live );
		$this->hold_lock( $live );

		update_post_meta( $live, \Newspack_Live\Context::META, '<p>Second background.</p>' );
		$this->assertSame( $rev, Live::get_rev( $live ), 'Busy: no bump yet.' );
		$this->assertSame( '1', get_post_meta( $live, Live::META_CONTEXT_PENDING, true ) );
		$next = wp_next_scheduled( Live::CONTEXT_EVENT, [ $live ] );
		$this->assertNotFalse( $next, 'A one-off retry is scheduled.' );
		$this->assertLessThanOrEqual( time() + 10, $next, 'A few seconds later.' );

		// Still busy when the event runs: it reschedules itself.
		wp_unschedule_event( $next, Live::CONTEXT_EVENT, [ $live ] );
		Live::bump_context( $live, 0 );
		$this->assertSame( '2', get_post_meta( $live, Live::META_CONTEXT_PENDING, true ) );
		$this->assertNotFalse( wp_next_scheduled( Live::CONTEXT_EVENT, [ $live ] ) );

		// The lock is free when the event runs: bumped, no other write needed.
		$this->other->close();
		$this->other = null;
		$this->assertNotFalse( has_action( Live::CONTEXT_EVENT, [ Live::class, 'retry_context_bump' ] ), 'The event runs the retry.' );
		Live::retry_context_bump( $live );
		$this->assertSame( $rev + 1, Live::get_rev( $live ) );
		$this->assertSame( $rev + 1, Live::get_context_rev( $live ) );
		$this->assertSame( '', get_post_meta( $live, Live::META_CONTEXT_PENDING, true ) );

		// A late event with nothing pending does nothing.
		Live::retry_context_bump( $live );
		$this->assertSame( $rev + 1, Live::get_rev( $live ) );
	}

	/**
	 * A1: a free lock bumps right away, without scheduling anything.
	 */
	public function test_context_bump_free_lock(): void {
		$live = $this->create_live();
		$rev  = Live::get_rev( $live );
		update_post_meta( $live, \Newspack_Live\Context::META, '<p>Background.</p>' );
		$this->assertSame( $rev + 1, Live::get_context_rev( $live ) );
		$this->assertFalse( wp_next_scheduled( Live::CONTEXT_EVENT, [ $live ] ) );

		// The description (post content) changes on page load only.
		wp_update_post(
			[
				'ID'           => $live,
				'post_content' => 'A new description.',
			]
		);
		$this->assertSame( $rev + 1, Live::get_rev( $live ) );
	}

	/**
	 * A2: "Yesterday" starts entry labels; "yesterday" is lowercase after "Updated".
	 */
	public function test_yesterday_capitalization(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$now       = time();
		$yesterday = (int) ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->modify( '-1 day' )->setTime( 10, 48 )->getTimestamp();
		$this->assertStringStartsWith( 'Yesterday, ', Dates::entry_label( $yesterday, $now ) );
		$this->assertStringStartsWith( 'yesterday, ', Dates::entry_label( $yesterday, $now, true ) );
		$element = Dates::time_element( $yesterday, '', true );
		$this->assertStringContainsString( 'data-mid="1"', $element );
		$this->assertStringContainsString( '>yesterday, ', $element );

		$live  = $this->create_live();
		$entry = Entries::save(
			[
				'live_id' => $live,
				'title'   => 'Edited later',
				'author'  => $this->create_user( 'editor' ),
			]
		);
		update_post_meta( $entry, Entries::META_PUBLISHED_AT, $yesterday - HOUR_IN_SECONDS );
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $yesterday ) ], [ 'ID' => $entry ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		clean_post_cache( $entry );
		$html    = Renderer::render_entry( $entry );
		$updated = substr( $html, (int) strpos( $html, 'newspack-live-entry__updated' ) );
		$this->assertStringContainsString( 'Updated <time', $updated );
		$this->assertStringContainsString( '>yesterday, ', $updated );
		$this->assertStringNotContainsString( '>Yesterday, ', $updated );
	}
}
