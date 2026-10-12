<?php
/**
 * Feed and team consistency: exact feed keyset, team memo, people cache salt.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use Newspack_Live\Renderer;
use WP_UnitTestCase;

/**
 * Hardening I.
 */
class Test_Feed_Consistency extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Editor.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Live.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Creates a live.
	 */
	public function set_up(): void {
		parent::set_up();
		Feed::flush();
		$this->editor  = $this->create_user( 'editor' );
		$this->live_id = $this->create_live( [ 'post_author' => $this->editor ] );
	}

	/**
	 * 60 entries sharing one second paginate fully, once each, in both orders.
	 */
	public function test_same_second_pagination(): void {
		$time = time() - 120;
		$ids  = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$ids[] = Entries::save(
				[
					'live_id' => $this->live_id,
					'title'   => "Tie $i",
					'author'  => $this->editor,
					'time'    => $time,
				]
			);
		}
		foreach ( [ 'desc', 'asc' ] as $order ) {
			Feed::flush();
			$seen   = [];
			$cursor = 0;
			do {
				$page   = Feed::get_page( $this->live_id, $order, $cursor, 7 );
				$seen   = array_merge( $seen, $page['ids'] );
				$cursor = $page['ids'] ? (int) end( $page['ids'] ) : 0;
			} while ( $page['has_more'] && $cursor );
			$expected = $ids;
			'desc' === $order ? rsort( $expected ) : sort( $expected );
			$this->assertSame( $expected, $seen, $order );
		}
		$this->assertSame( 30, Feed::count_older( $this->live_id, $ids[30] ) );
	}

	/**
	 * The team memo follows co-author and team changes.
	 */
	public function test_team_memo(): void {
		$member = $this->create_user( 'author' );
		$this->assertSame( [ $this->editor ], Live::get_team( $this->live_id ) );
		update_post_meta( $this->live_id, Live::META_TEAM, [ $member ] );
		$this->assertSame( [ $this->editor, $member ], Live::get_team( $this->live_id ) );
		delete_post_meta( $this->live_id, Live::META_TEAM );
		$this->assertSame( [ $this->editor ], Live::get_team( $this->live_id ) );

		$other = $this->create_user( 'author' );
		wp_update_post(
			[
				'ID'          => $this->live_id,
				'post_author' => $other,
			]
		);
		$this->assertSame( [ $other ], Live::get_team( $this->live_id ) );

		// Co-authors change through their taxonomy (Co-Authors Plus).
		$cohost       = get_userdata( $member );
		$cohost->type = 'wpuser';
		$GLOBALS['newspack_live_test_coauthors'][ $this->live_id ] = [ get_userdata( $other ), $cohost ];
		do_action( 'set_object_terms', $this->live_id, [], [], 'author', false, [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core action.
		$this->assertSame( [ $other, $member ], Live::get_team( $this->live_id ) );
		unset( $GLOBALS['newspack_live_test_coauthors'][ $this->live_id ] );
		Live::flush_team();
	}

	/**
	 * Author and question display-name changes re-render entries.
	 */
	public function test_people_changes_invalidate_entry_cache(): void {
		update_post_meta( $this->live_id, '_newspack_live_show_entry_author', 1 );
		wp_update_user(
			[
				'ID'           => $this->editor,
				'display_name' => 'Ann Before',
			]
		);
		$entry = Entries::save(
			[
				'live_id' => $this->live_id,
				'title'   => 'Bylined',
				'author'  => $this->editor,
			]
		);
		$this->assertStringContainsString( 'Ann Before', Renderer::render_entry( $entry, [ 'show_author' => true ] ) );
		wp_update_user(
			[
				'ID'           => $this->editor,
				'display_name' => 'Ann After',
			]
		);
		$this->assertStringContainsString( 'Ann After', Renderer::render_entry( $entry, [ 'show_author' => true ] ) );

		$question = Questions::create( $this->live_id, 'When?', 'Reader One', 0, 'nl_shortlisted' );
		$answer   = Entries::save(
			[
				'live_id'      => $this->live_id,
				'content'      => '<!-- wp:paragraph --><p>Now.</p><!-- /wp:paragraph -->',
				'author'       => $this->editor,
				'question_ids' => [ $question ],
			]
		);
		$this->assertStringContainsString( 'Reader One', Renderer::render_entry( $answer ) );
		update_post_meta( $question, Questions::META_DISPLAY_NAME, 'Reader Two' );
		$this->assertStringContainsString( 'Reader Two', Renderer::render_entry( $answer ) );
	}

	/**
	 * Readers can't invalidate the entry cache: new questions and unchanged profiles keep the salt.
	 */
	public function test_people_salt_bumps_only_on_name_changes(): void {
		$salt = wp_cache_get_last_changed( \Newspack_Live\Post_Types::PEOPLE_CACHE );
		Questions::create( $this->live_id, 'Anyone?', 'Anonymous', 0, 'nl_new' );
		$reader = $this->create_user( 'subscriber' );
		wp_update_user(
			[
				'ID'          => $reader,
				'description' => 'Bio only',
			]
		);
		$this->assertSame( $salt, wp_cache_get_last_changed( \Newspack_Live\Post_Types::PEOPLE_CACHE ) );

		wp_update_user(
			[
				'ID'           => $reader,
				'display_name' => 'Renamed',
			]
		);
		$renamed = wp_cache_get_last_changed( \Newspack_Live\Post_Types::PEOPLE_CACHE );
		$this->assertNotSame( $salt, $renamed );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $reader, $this->editor );
		$this->assertNotSame( $renamed, wp_cache_get_last_changed( \Newspack_Live\Post_Types::PEOPLE_CACHE ) );
	}
}
