<?php
/**
 * Participation: similar-question grouping, cached question counts, retention.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Live;
use Newspack_Live\Privacy;
use Newspack_Live\Question_Groups;
use Newspack_Live\Questions;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Participation.
 */
class Test_Question_Groups extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Clears the rate limits.
	 */
	public function set_up(): void {
		parent::set_up();
		Questions::reset_rate_limits();
	}

	/**
	 * Restores the locale.
	 */
	public function tear_down(): void {
		remove_all_filters( 'locale' );
		parent::tear_down();
	}

	/**
	 * Tokens ignore stop words of the site language (bundled en, es, fr, pt, de; English fallback).
	 */
	public function test_tokens_and_languages(): void {
		$this->assertSame( [ 'close', 'polls' ], Question_Groups::tokens( 'When do the polls close?', 'en' ) );
		$this->assertSame( [ 'bureaux', 'ferment', 'vote' ], Question_Groups::tokens( 'Quand est-ce que les bureaux de vote ferment ?', 'fr' ) );
		$this->assertSame( [ 'cierran', 'colegios', 'electorales' ], Question_Groups::tokens( '¿Cuándo cierran los colegios electorales?', 'es' ) );
		$this->assertSame( [ 'fecham', 'secoes' ], Question_Groups::tokens( 'Quando fecham as seções?', 'pt' ) );
		$this->assertSame( [ 'schliessen', 'wahllokale' ], Question_Groups::tokens( 'Wann schliessen die Wahllokale?', 'de' ) );
		$this->assertSame( Question_Groups::tokens( 'When do the polls close?', 'en' ), Question_Groups::tokens( 'When do the polls close?', 'xx' ), 'English fallback.' );
		add_filter( 'locale', static fn() => 'fr_FR' );
		$this->assertSame( [ 'bureaux', 'ferment', 'vote' ], Question_Groups::tokens( 'Quand est-ce que les bureaux de vote ferment ?' ), 'Site language.' );
		$this->assertSame( 1.0, Question_Groups::similarity( [ 'a', 'b' ], [ 'b', 'a' ] ) );
		$this->assertSame( 0.5, Question_Groups::similarity( [ 'a', 'b' ], [ 'a', 'c', 'b', 'd' ] ) );
	}

	/**
	 * Similar questions are grouped deterministically, follow the group's status,
	 * move together in bulk, and can be ungrouped.
	 */
	public function test_grouping(): void {
		$live  = $this->create_live();
		$first = Questions::create( $live, 'When do the polls close tonight?', 'Ana' );
		Questions::set_status( $first, 'nl_shortlisted' );
		$second = Questions::create( $live, 'When will the polls close tonight?', 'Ben' );
		$third  = Questions::create( $live, 'Polls close tonight, when?', 'Cleo' );
		$other  = Questions::create( $live, 'Who is running for mayor?', 'Dan' );

		$this->assertSame( (string) $first, get_post_meta( $second, Question_Groups::META_GROUP, true ) );
		$this->assertSame( (string) $first, get_post_meta( $third, Question_Groups::META_GROUP, true ) );
		$this->assertSame( '', get_post_meta( $first, Question_Groups::META_GROUP, true ), 'The first question heads the group.' );
		$this->assertSame( '', get_post_meta( $other, Question_Groups::META_GROUP, true ) );
		$this->assertSame( 'nl_shortlisted', get_post_status( $second ), 'A member takes the group status.' );

		// Inbox: members listed under the first question; bulk actions move the whole group.
		wp_set_current_user( $this->create_user( 'editor' ) );
		$request = new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $live . '/questions' );
		$request->set_query_params( [ 'status' => 'nl_shortlisted' ] );
		$items = rest_do_request( $request )->get_data()['items'];
		$this->assertSame( [ $first ], array_column( $items, 'id' ) );
		$this->assertSame( [ $second, $third ], array_column( $items[0]['similar'], 'id' ) );

		$bulk = new WP_REST_Request( 'POST', '/newspack-live/v1/lives/' . $live . '/questions/bulk' );
		$bulk->set_body_params(
			[
				'ids'    => [ $first ],
				'status' => 'nl_rejected',
			]
		);
		rest_do_request( $bulk );
		$this->assertSame( 'nl_rejected', get_post_status( $third ) );

		// Ungroup a member, then the head: its oldest member takes over.
		$ungroup = new WP_REST_Request( 'POST', '/newspack-live/v1/lives/' . $live . '/questions/' . $third . '/ungroup' );
		$this->assertTrue( rest_do_request( $ungroup )->get_data()['ungrouped'] );
		$this->assertSame( '', get_post_meta( $third, Question_Groups::META_GROUP, true ) );
		$fourth = Questions::create( $live, 'When will polls close tonight?', 'Fay' );
		Questions::set_status( $fourth, 'nl_rejected' );
		update_post_meta( $fourth, Question_Groups::META_GROUP, $first );
		$this->assertTrue( Question_Groups::ungroup( $first ) );
		$this->assertSame( '', get_post_meta( $second, Question_Groups::META_GROUP, true ), 'The oldest member heads the group.' );
		$this->assertSame( (string) $second, get_post_meta( $fourth, Question_Groups::META_GROUP, true ) );
		$this->assertSame( 0, Question_Groups::assign( $third ), 'An ungrouped question is never grouped again.' );
	}

	/**
	 * Question counts are cached and invalidated on every question write.
	 */
	public function test_counts_cached(): void {
		$live = $this->create_live();
		Questions::create( $live, 'First?', 'Ana' );
		$this->assertSame( 1, Questions::count( $live )['nl_new'] );
		$GLOBALS['wpdb']->num_queries = 0;
		Questions::count( $live );
		$this->assertSame( 0, $GLOBALS['wpdb']->num_queries, 'Served from the cache.' );
		$second = Questions::create( $live, 'Second?', 'Ben' );
		$this->assertSame( 2, Questions::count( $live )['nl_new'] );
		Questions::set_status( $second, 'nl_spam' );
		$counts = Questions::count( $live );
		$this->assertSame( 1, $counts['nl_new'] );
		$this->assertSame( 1, $counts['nl_spam'] );
		wp_delete_post( $second, true );
		$this->assertSame( 0, Questions::count( $live )['nl_spam'] );
	}

	/**
	 * The export lists the questions; unanswered questions follow the retention.
	 */
	public function test_privacy_and_retention(): void {
		$live   = $this->create_live( [], [ Live::META_END => time() - 40 * DAY_IN_SECONDS ] );
		$reader = $this->create_user( 'subscriber' );
		$asked  = Questions::create( $live, 'My question', 'Ana', $reader );

		$export = Privacy::export( get_userdata( $reader )->user_email );
		$values = wp_json_encode( $export['data'] );
		$this->assertStringContainsString( 'My question', $values );

		update_option( Questions::OPTION_RETENTION_CURSOR, 0 );
		Questions::delete_expired();
		$this->assertNull( get_post( $asked ) );
	}

	/**
	 * Review fixes: a question joining while the head is answered shows again on its own; a deleted
	 * head hands over its group; an answer needs text.
	 */
	public function test_group_edges_and_answer_text(): void {
		$live   = $this->create_live();
		$editor = $this->create_user( 'editor' );
		$head   = Questions::create( $live, 'When will the final results be certified?', 'Ana' );
		$member = Questions::create( $live, 'When are the final results certified?', 'Ben' );
		$late   = Questions::create( $live, 'When will final results be certified exactly?', 'Cleo' );
		$this->assertSame( (string) $head, get_post_meta( $late, Question_Groups::META_GROUP, true ) );

		// Answered with the head and one member only: the late joiner leaves the group.
		Questions::set_status( $member, 'nl_answered' );
		Questions::set_status( $head, 'nl_answered' );
		$this->assertSame( '', get_post_meta( $late, Question_Groups::META_GROUP, true ) );
		$this->assertSame( (string) $head, get_post_meta( $member, Question_Groups::META_GROUP, true ), 'Answered members stay grouped.' );
		$newer = Questions::create( $live, 'When will the final results be certified?', 'Dan' );
		$this->assertSame( (string) $late, get_post_meta( $newer, Question_Groups::META_GROUP, true ), 'Never joins an answered group.' );

		// Deleting a head promotes its oldest member.
		$third = Questions::create( $live, 'When will the final results be certified, please?', 'Eve' );
		wp_delete_post( $late, true );
		$this->assertSame( '', get_post_meta( $newer, Question_Groups::META_GROUP, true ) );
		$this->assertSame( (string) $newer, get_post_meta( $third, Question_Groups::META_GROUP, true ) );

		// An answer needs a title or text.
		$empty = Entries::save(
			[
				'live_id'      => $live,
				'author'       => $editor,
				'question_ids' => [ $third ],
			]
		);
		$this->assertSame( 'newspack_live_validation_empty', $empty->get_error_code() );
	}
}
