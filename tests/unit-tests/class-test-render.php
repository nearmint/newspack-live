<?php
/**
 * Rendering tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Content;
use Newspack_Live\Dates;
use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use Newspack_Live\Renderer;
use WP_UnitTestCase;

/**
 * Content pipeline, entry frame, feed, states and form.
 *
 * @group render
 */
class Test_Render extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Live ID.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Editor ID.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Creates a live.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->editor  = $this->create_user( 'editor' );
		$this->live_id = $this->create_live( [ 'post_author' => $this->editor ] );
		wp_set_current_user( $this->editor );
		// The test library deletes all terms between test classes.
	}

	/**
	 * Resets query vars.
	 */
	public function tear_down(): void {
		foreach ( [ 'live-entry', 'live-before' ] as $var ) {
			set_query_var( $var, '' );
		}
		parent::tear_down();
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
	 * The pipeline renders blocks and embeds but never applies `the_content`.
	 */
	public function test_content_pipeline(): void {
		add_filter( 'the_content', [ $this, 'inject_marker' ] );
		add_filter( 'newspack_live_content', [ $this, 'inject_private_marker' ] );
		$html = Content::render( "<!-- wp:paragraph -->\n<p>Hello \"world\"</p>\n<!-- /wp:paragraph -->" );
		remove_filter( 'the_content', [ $this, 'inject_marker' ] );
		remove_filter( 'newspack_live_content', [ $this, 'inject_private_marker' ] );

		$this->assertStringContainsString( 'Hello &#8220;world&#8221;</p>', $html );
		$this->assertStringNotContainsString( 'INSERTED', $html );
		$this->assertStringContainsString( 'PRIVATE', $html );
		$this->assertStringNotContainsString( '<!-- wp:', $html );
	}

	/**
	 * Simulates a content inserter.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function inject_marker( $content ) {
		return $content . 'INSERTED';
	}

	/**
	 * Private filter marker.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function inject_private_marker( $content ) {
		return $content . 'PRIVATE';
	}

	/**
	 * Image blocks never get the lightbox inside entries; classic content gets paragraphs.
	 */
	public function test_pipeline_lightbox_and_classic(): void {
		$html = Content::render( '<!-- wp:image {"lightbox":{"enabled":true}} --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt=""/></figure><!-- /wp:image -->' );
		$this->assertStringNotContainsString( 'data-wp-interactive', $html );
		$this->assertStringContainsString( '<p>Line</p>', Content::render( 'Line' ) );
		$this->assertSame( '', Content::render( '   ' ) );
	}

	/**
	 * Entry frame with the share button.
	 */
	public function test_entry_frame(): void {
		$entry_id = $this->add(
			[
				'title'   => 'Polls close',
				'content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			]
		);
		$html = Renderer::render_entry( $entry_id, [ 'show_author' => true ] );
		$this->assertStringContainsString( 'class="newspack-live-entry"', $html );
		$this->assertStringContainsString( 'id="live-entry-' . $entry_id . '"', $html );
		$this->assertStringContainsString( 'data-entry-id="' . $entry_id . '"', $html );
		$this->assertStringContainsString( 'data-rev="' . Entries::get_rev( $entry_id ) . '"', $html );
		$this->assertMatchesRegularExpression( '/data-time="\d+"/', $html );
		$this->assertStringContainsString( 'data-action="share"', $html );
		$this->assertStringContainsString( '<h3 class="newspack-live-entry__title">Polls close</h3>', $html );
		$this->assertStringContainsString( 'newspack-live-entry__author', $html );
		$this->assertStringNotContainsString( 'data-wp-', $html, 'No directives inside entries.' );
		$this->assertStringNotContainsString( 'newspack-live-entry__author', Renderer::render_entry( $entry_id, [ 'show_author' => false ] ) );
	}

	/**
	 * "Updated {time}" shows only when edited more than 5 minutes after first publish.
	 */
	public function test_entry_updated_label(): void {
		$entry_id = $this->add();
		$this->assertStringNotContainsString( 'newspack-live-entry__updated', Renderer::render_entry( $entry_id ) );
		update_post_meta( $entry_id, Entries::META_PUBLISHED_AT, time() - 10 * MINUTE_IN_SECONDS );
		Entries::save( [ 'title' => 'Edited' ], $entry_id );
		$this->assertStringContainsString( 'newspack-live-entry__updated', Renderer::render_entry( $entry_id ) );
	}

	/**
	 * Q&A entries escape reader text and always sign the answer.
	 */
	public function test_qa_entry_escapes_questions(): void {
		$question = Questions::create( $this->live_id, '<script>alert(1)</script> & <b>why</b>?', 'Ana <i>' );
		$entry_id = $this->add(
			[
				'title'        => '',
				'content'      => '<!-- wp:paragraph --><p>Hi Ana,</p><!-- /wp:paragraph -->',
				'question_ids' => [ $question ],
			]
		);
		$html = Renderer::render_entry( $entry_id, [ 'show_author' => false ] );
		$this->assertStringContainsString( 'newspack-live-entry--qa', $html );
		$this->assertStringContainsString( '<span class="newspack-live__sr-only">, </span>Your questions</span>', $html, 'Answers carry the Your questions label.' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>why</b>', $html );
		$this->assertStringContainsString( 'Question from', $html );
		$this->assertStringContainsString( 'newspack-live-entry__author', $html );
	}

	/**
	 * Feed: newest first, pinned entry out of the list, backdated entries in place.
	 */
	public function test_feed_order(): void {
		$old     = $this->add( [ 'time' => time() - HOUR_IN_SECONDS ] );
		$pinned  = $this->add(
			[
				'time'   => time() - 2 * HOUR_IN_SECONDS,
				'pinned' => true,
			]
		);
		$new     = $this->add( [ 'time' => time() - 60 ] );
		$back    = $this->add( [ 'time' => time() - 30 * MINUTE_IN_SECONDS ] );
		Feed::flush();
		$this->assertSame( [ $new, $back, $old ], Feed::get_page( $this->live_id )['ids'] );
		$this->assertSame( $pinned, Feed::get_pinned_id( $this->live_id ) );
		$this->assertSame( [ $new, $back, $old, $pinned ], Feed::get_page( $this->live_id, 'desc', 0, 0, false )['ids'] );

		$html = Renderer::render_live( $this->live_id );
		$this->assertLessThan( strpos( $html, 'id="live-entry-' . $new . '"' ), strpos( $html, 'id="live-entry-' . $pinned . '"' ), 'Pinned entry first.' );
		$this->assertStringContainsString( 'newspack-live-entry__pinned', $html );
	}

	/**
	 * Cursor pages are stable and handle entries sharing a second.
	 */
	public function test_cursor_pages(): void {
		$time = time() - HOUR_IN_SECONDS;
		$ids  = [];
		for ( $i = 0; $i < 25; $i++ ) {
			// Pairs of entries share the same second.
			$ids[] = $this->add( [ 'time' => $time + intdiv( $i, 2 ) ] );
		}
		$newest_first = array_reverse( $ids );
		$first        = Feed::get_page( $this->live_id );
		$this->assertSame( 15, Feed::per_page(), '15 per page.' );
		$this->assertSame( array_slice( $newest_first, 0, 15 ), $first['ids'] );
		$this->assertTrue( $first['has_more'] );
		$second = Feed::get_page( $this->live_id, 'desc', end( $first['ids'] ) );
		$this->assertSame( array_slice( $newest_first, 15 ), $second['ids'] );
		$this->assertFalse( $second['has_more'] );

		$this->add();
		$this->assertSame( $second['ids'], Feed::get_page( $this->live_id, 'desc', end( $first['ids'] ) )['ids'], 'Cursor pages are stable.' );

		$asc = Feed::get_page( $this->live_id, 'asc', 0, 0, false );
		$this->assertSame( array_slice( $ids, 0, 15 ), $asc['ids'] );
	}

	/**
	 * Entry window: 10 newer, the target, 9 older.
	 */
	public function test_window(): void {
		$ids = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$ids[] = $this->add( [ 'time' => time() - 3600 + $i * 10 ] );
		}
		$newest_first = array_reverse( $ids );
		$target       = $newest_first[15];
		$window       = Feed::get_window( $this->live_id, $target );
		$this->assertSame( array_slice( $newest_first, 5, 20 ), $window['ids'] );
		$this->assertTrue( $window['has_newer'] );
		$this->assertTrue( $window['has_more'] );
		$this->assertNull( Feed::get_window( $this->live_id, 999999 ) );
	}

	/**
	 * Views from the query vars.
	 */
	public function test_views(): void {
		$this->assertSame(
			[
				'type'   => 'latest',
				'cursor' => 0,
			],
			Renderer::get_view( $this->live_id )
		);
		set_query_var( 'live-entry', '12' );
		$this->assertSame( 'entry', Renderer::get_view( $this->live_id )['type'] );
	}

	/**
	 * State badges and empty-state copy.
	 */
	public function test_states(): void {
		$html = Renderer::render_live( $this->live_id );
		$this->assertStringContainsString( 'newspack-live-badge--live', $html );
		$this->assertStringContainsString( 'First updates coming soon.', $html );

		$upcoming = $this->create_live( [], [ Live::META_SCHEDULED_START => time() + DAY_IN_SECONDS ] );
		$html     = Renderer::render_live( $upcoming );
		$this->assertStringContainsString( 'Upcoming · Starts', $html );
		$this->assertStringContainsString( 'Coverage starts', $html );
		$this->assertStringContainsString( 'id="newspack-live-ask"', $html, 'Early questions are allowed while upcoming.' );

		Live::apply_action( $this->live_id, 'end' );
		$html = Renderer::render_live( $this->live_id );
		$this->assertStringContainsString( 'newspack-live-badge--ended', $html );
		$this->assertStringContainsString( 'Questions are closed.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * Ask form per mode and toggle; registered-only mode asks anonymous readers to sign in.
	 */
	public function test_ask_form_modes(): void {
		$this->assertStringContainsString( 'name="website"', Renderer::render_live( $this->live_id ) );

		update_post_meta( $this->live_id, '_newspack_live_questions_mode', 'registered' );
		wp_set_current_user( 0 );
		$html = Renderer::render_live( $this->live_id );
		$this->assertStringContainsString( 'Sign in to ask a question', $html );
		$this->assertStringContainsString( 'name="question"', $html, 'Anonymous readers can still type.' );

		update_post_meta( $this->live_id, '_newspack_live_questions_mode', 'off' );
		$this->assertStringNotContainsString( 'newspack-live__ask', Renderer::render_live( $this->live_id ) );

		update_post_meta( $this->live_id, '_newspack_live_questions_mode', 'anyone' );
		update_post_meta( $this->live_id, Live::META_QUESTIONS_OPEN, 0 );
		$this->assertStringContainsString( 'Questions are closed.', Renderer::render_live( $this->live_id ) );
	}

	/**
	 * The signed timestamp verifies and rejects tampering.
	 */
	public function test_signed_timestamp(): void {
		$now    = time() - 10;
		$signed = Questions::sign_timestamp( $this->live_id, $now );
		$this->assertSame( $now, Questions::verify_timestamp( $this->live_id, $signed ) );
		$this->assertSame( 0, Questions::verify_timestamp( $this->live_id, ( $now + 1 ) . '.' . explode( '.', $signed )[1] ) );
		$this->assertSame( 0, Questions::verify_timestamp( $this->live_id + 1, $signed ) );
		$old = time() - DAY_IN_SECONDS - 60;
		$this->assertSame( 0, Questions::verify_timestamp( $this->live_id, Questions::sign_timestamp( $this->live_id, $old ) ), 'Expired after a day.' );
		$this->assertSame( 0, Questions::verify_timestamp( $this->live_id, 'garbage' ) );
	}

	/**
	 * Byline names join with commas and "and".
	 */
	public function test_join_names(): void {
		$this->assertSame( 'A', Renderer::join_names( [ 'A' ] ) );
		$this->assertSame( 'A and B', Renderer::join_names( [ 'A', 'B' ] ) );
		$this->assertSame( 'A, B and C', Renderer::join_names( [ 'A', 'B', 'C' ] ) );
	}

	/**
	 * Date labels.
	 */
	public function test_date_labels(): void {
		$now = time();
		$this->assertSame( 'Updated ' . Dates::time( $now ), Dates::updated_label( $now, $now ), 'No "today".' );
		$this->assertStringStartsWith( 'Updated yesterday at ', Dates::updated_label( $now - DAY_IN_SECONDS, $now ) );
		$this->assertStringNotContainsString( 'today', Dates::updated_label( $now - 3 * DAY_IN_SECONDS, $now ) );
		$this->assertSame( Dates::time( $now ), Dates::entry_label( $now, $now ) );
		$this->assertStringContainsString( ', ', Dates::entry_label( $now - 3 * DAY_IN_SECONDS, $now ) );
	}

	/**
	 * Article card: published posts only, with title, CTA and reading time.
	 */
	public function test_article_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Council vote',
				'post_content' => str_repeat( 'word ', 600 ),
			]
		);
		$html    = Renderer::render_article_card( $post_id );
		$this->assertStringContainsString( '<h4 class="newspack-live-card__title">', $html );
		$this->assertStringContainsString( 'Read the story', $html );
		$this->assertStringContainsString( '2 min read', $html );
		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( '', Renderer::render_article_card( $draft ) );
	}
}
