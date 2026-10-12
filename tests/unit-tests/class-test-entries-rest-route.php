<?php
/**
 * Core REST route of the entry post type.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Live;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `/wp/v2/newspack-live-entries` never leaks and is scoped to the target live.
 *
 * @group rest
 */
class Test_Entries_REST_Route extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Anonymous users get 401 on reads.
	 */
	public function test_anonymous_gets_401(): void {
		$live_id  = $this->create_live();
		$entry_id = Entries::save(
			[
				'live_id' => $live_id,
				'title'   => 'Entry',
				'author'  => self::factory()->user->create( [ 'role' => 'editor' ] ),
			]
		);
		wp_set_current_user( 0 );
		$list = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/newspack-live-entries' ) );
		$this->assertSame( 401, $list->get_status() );
		$item = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/newspack-live-entries/' . $entry_id ) );
		$this->assertSame( 401, $item->get_status() );
	}

	/**
	 * Subscribers get 403.
	 */
	public function test_subscriber_gets_403(): void {
		wp_set_current_user( $this->create_user( 'subscriber' ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/newspack-live-entries' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An author on live A can create entries on A only, and never move one.
	 */
	public function test_create_scoped_to_team(): void {
		$author = $this->create_user( 'author' );
		$live_a = $this->create_live( [], [ Live::META_TEAM => [ $author ] ] );
		$live_b = $this->create_live();
		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/wp/v2/newspack-live-entries' );
		$request->set_body_params(
			[
				'title'  => 'On B',
				'parent' => $live_b,
				'status' => 'publish',
			]
		);
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		$request->set_param( 'parent', $live_a );
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );
		$entry_id = $response->get_data()['id'];
		$this->assertSame( $live_a, (int) get_post_field( 'post_parent', $entry_id ) );
		$this->assertSame( $live_a, $response->get_data()['parent'] );
		$this->assertSame( 1, Entries::get_rev( $entry_id ), 'Entries created through core REST bump the revision.' );

		$move = new WP_REST_Request( 'POST', '/wp/v2/newspack-live-entries/' . $entry_id );
		$move->set_body_params( [ 'parent' => $live_b ] );
		$this->assertSame( 400, rest_do_request( $move )->get_status() );
	}

	/**
	 * Creating an entry outside a live is refused.
	 */
	public function test_parent_must_be_live(): void {
		wp_set_current_user( $this->create_user( 'editor' ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/newspack-live-entries' );
		$request->set_body_params(
			[
				'title'  => 'Orphan',
				'parent' => self::factory()->post->create(),
			]
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}
}
