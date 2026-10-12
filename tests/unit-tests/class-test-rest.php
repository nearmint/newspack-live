<?php
/**
 * REST API tests.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Feed;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Public and private routes, permissions, schemas, headers.
 *
 * @group rest
 */
class Test_REST extends WP_UnitTestCase {
	use Live_Factory;

	/**
	 * Editor.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Live ID.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * Creates a live.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->editor  = $this->create_user( 'editor' );
		$this->live_id = $this->create_live( [ 'post_author' => $this->editor ] );
		Feed::flush();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route relative to the namespace.
	 * @param array  $params Parameters.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/newspack-live/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		$response = rest_do_request( $request );
		// rest_do_request() skips rest_post_dispatch, which serve_request() applies (cache headers).
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
	}

	/**
	 * Adds a published entry as the editor.
	 *
	 * @param array $data Fields.
	 * @return int
	 */
	private function add( array $data = [] ): int {
		wp_set_current_user( $this->editor );
		$id = Entries::save(
			array_merge(
				[
					'live_id' => $this->live_id,
					'title'   => 'Entry',
				],
				$data
			)
		);
		wp_set_current_user( 0 );
		return $id;
	}

	/**
	 * Status payload, schema and public cache headers for anonymous readers.
	 */
	public function test_status(): void {
		$this->add();
		$response = $this->request( 'GET', '/lives/' . $this->live_id . '/status' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'live', $data['state'] );
		$this->assertSame( 1, $data['rev'] );
		$this->assertSame( 30, $data['poll_interval'] );
		$this->assertTrue( $data['questions_open'] );
		$this->assertSame( 'public, max-age=60, s-maxage=60', $response->get_headers()['Cache-Control'] );

		foreach ( ( new \Newspack_Live\REST\REST_Public() )->get_status_schema()['properties'] as $key => $schema ) {
			if ( in_array( $key, [ 'auto_end', 'hosts' ], true ) ) {
				// Manager-only fields, absent for anonymous readers.
				$this->assertArrayNotHasKey( $key, $data );
				continue;
			}
			$this->assertArrayHasKey( $key, $data );
			$this->assertTrue( rest_validate_value_from_schema( $data[ $key ], $schema, $key ) );
		}
	}

	/**
	 * The poll interval filter is clamped to 30–120 seconds.
	 */
	public function test_poll_interval_clamped(): void {
		add_filter( 'newspack_live_poll_interval', static fn() => 5 );
		$this->assertSame( 30, $this->request( 'GET', '/lives/' . $this->live_id . '/status' )->get_data()['poll_interval'] );
		remove_all_filters( 'newspack_live_poll_interval' );
		add_filter( 'newspack_live_poll_interval', static fn() => 500 );
		$this->assertSame( 120, $this->request( 'GET', '/lives/' . $this->live_id . '/status' )->get_data()['poll_interval'] );
		remove_all_filters( 'newspack_live_poll_interval' );
	}

	/**
	 * Authenticated status requests are never cached.
	 */
	public function test_status_authenticated_no_store(): void {
		wp_set_current_user( $this->editor );
		$this->assertSame( 'private, no-store', $this->request( 'GET', '/lives/' . $this->live_id . '/status' )->get_headers()['Cache-Control'] );
	}

	/**
	 * Unknown, non-live and draft lives are not readable anonymously.
	 */
	public function test_unreadable_lives(): void {
		$this->assertSame( 404, $this->request( 'GET', '/lives/999999/status' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/lives/' . self::factory()->post->create() . '/status' )->get_status() );
		$draft = $this->create_live( [ 'post_status' => 'draft' ] );
		$this->assertSame( 404, $this->request( 'GET', '/lives/' . $draft . '/entries' )->get_status() );
	}

	/**
	 * Deltas: new, edited and removed entries, pinned ID, `until` bound.
	 */
	public function test_delta(): void {
		$first  = $this->add();
		$second = $this->add();
		$delta  = $this->request(
			'GET',
			'/lives/' . $this->live_id . '/entries',
			[
				'since' => 0,
				'until' => 2,
			]
		);
		$this->assertSame( 'public, max-age=300, s-maxage=300', $delta->get_headers()['Cache-Control'] );
		$data = $delta->get_data();
		$this->assertSame( [ $first, $second ], array_column( $data['entries'], 'id' ) );
		$this->assertStringContainsString( 'id="live-entry-' . $first . '"', $data['entries'][0]['html'] );
		$this->assertArrayNotHasKey( 'key', $data['entries'][0], 'Key entries removed.' );
		$this->assertArrayNotHasKey( 'label', $data['entries'][0] );
		$this->assertArrayNotHasKey( 'key_moments', $data );

		wp_set_current_user( $this->editor );
		Entries::transition( $first, 'draft' );
		Entries::set_flags( $second, [ 'pinned' => true ] );
		wp_set_current_user( 0 );
		$data = $this->request(
			'GET',
			'/lives/' . $this->live_id . '/entries',
			[
				'since' => 2,
				'until' => 4,
			]
		)->get_data();
		$this->assertSame( [ $first ], $data['removed'] );
		$this->assertSame( [ $second ], array_column( $data['entries'], 'id' ) );
		$this->assertSame( $second, $data['pinned_id'] );

		$this->assertSame(
			400,
			$this->request(
				'GET',
				'/lives/' . $this->live_id . '/entries',
				[
					'since' => 0,
					'until' => 99,
				]
			)->get_status()
		);
	}

	/**
	 * Deltas carry the Context card when the live's Context changed.
	 */
	public function test_delta_context(): void {
		$this->add( [ 'title' => 'An update' ] );
		$data = $this->request(
			'GET',
			'/lives/' . $this->live_id . '/entries',
			[
				'since' => 0,
				'until' => 1,
			]
		)->get_data();
		$this->assertCount( 1, $data['entries'] );
		$this->assertArrayNotHasKey( 'context', $data );

		$rev = Live::get_rev( $this->live_id );
		update_post_meta( $this->live_id, \Newspack_Live\Context::META, '<p>Background of the race.</p>' );
		$this->assertSame( $rev + 1, Live::get_rev( $this->live_id ), 'A Context change bumps the revision.' );
		$data = $this->request(
			'GET',
			'/lives/' . $this->live_id . '/entries',
			[
				'since' => $rev,
				'until' => $rev + 1,
			]
		)->get_data();
		$this->assertStringContainsString( 'Background of the race.', $data['context']['html'] );
		$this->assertStringContainsString( 'id="newspack-live-context"', $data['context']['html'] );
	}

	/**
	 * Older pages with cursor.
	 */
	public function test_pages(): void {
		$ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->add( [ 'time' => time() - 600 + $i ] );
		}
		$page = $this->request(
			'GET',
			'/lives/' . $this->live_id . '/entries',
			[
				'before'   => $ids[3],
				'per_page' => 2,
			]
		);
		$this->assertSame( 'public, max-age=60, s-maxage=60', $page->get_headers()['Cache-Control'] );
		$data = $page->get_data();
		$this->assertSame( [ $ids[2], $ids[1] ], array_column( $data['entries'], 'id' ) );
		$this->assertTrue( $data['has_more'] );
		$this->assertSame( $ids[1], $data['next_before'] );
		$this->assertArrayNotHasKey( 'ads', $data );
	}

	/**
	 * Restricted lives: /entries is 403 and never cached; /status stays available.
	 */
	public function test_restricted(): void {
		$this->add();
		add_filter( 'newspack_is_post_restricted', '__return_true' );
		$entries = $this->request( 'GET', '/lives/' . $this->live_id . '/entries' );
		$status  = $this->request( 'GET', '/lives/' . $this->live_id . '/status' );
		remove_filter( 'newspack_is_post_restricted', '__return_true' );
		$this->assertSame( 403, $entries->get_status() );
		$this->assertSame( 'private, no-store', $entries->get_headers()['Cache-Control'] );
		$this->assertSame( 200, $status->get_status() );
	}

	/**
	 * Submits a question with a valid, old-enough timestamp.
	 *
	 * @param array $overrides Fields.
	 * @return \WP_REST_Response
	 */
	private function ask( array $overrides = [] ): \WP_REST_Response {
		return $this->request(
			'POST',
			'/lives/' . $this->live_id . '/questions',
			array_merge(
				[
					'question'     => 'When?',
					'display_name' => 'Ana',
					'website'      => '',
					'ts'           => Questions::sign_timestamp( $this->live_id, time() - 10 ),
				],
				$overrides
			)
		);
	}

	/**
	 * Question pipeline, every step in order with its status.
	 */
	public function test_question_pipeline(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- simulates a client address.
		Questions::reset_rate_limits();

		// 1. Closed.
		update_post_meta( $this->live_id, Live::META_QUESTIONS_OPEN, 0 );
		$this->assertSame( 'newspack_live_questions_closed', $this->ask()->get_data()['code'] );
		$this->assertSame( 403, $this->ask()->get_status() );
		update_post_meta( $this->live_id, Live::META_QUESTIONS_OPEN, 1 );

		// 2. Registered-only.
		update_post_meta( $this->live_id, '_newspack_live_questions_mode', 'registered' );
		$this->assertSame( 401, $this->ask()->get_status() );
		update_post_meta( $this->live_id, '_newspack_live_questions_mode', 'anyone' );

		// 3. Honeypot: success, nothing stored.
		$response = $this->ask( [ 'website' => 'http://spam.example' ] );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 0, array_sum( Questions::count( $this->live_id ) ) );

		// 4. Too fast / invalid timestamp.
		$this->assertSame( 'newspack_live_too_fast', $this->ask( [ 'ts' => Questions::sign_timestamp( $this->live_id, time() ) ] )->get_data()['code'] );
		$this->assertSame( 400, $this->ask( [ 'ts' => '' ] )->get_status() );

		// 5. Lengths.
		$this->assertSame( 'newspack_live_invalid_length', $this->ask( [ 'question' => str_repeat( 'a', 501 ) ] )->get_data()['code'] );
		$this->assertSame( 400, $this->ask( [ 'display_name' => '' ] )->get_status() );

		// 9. Stored as new, plain text.
		$this->assertSame( 201, $this->ask( [ 'question' => '<b>Why</b>?' ] )->get_status() );
		$counts = Questions::count( $this->live_id );
		$this->assertSame( 1, $counts['nl_new'] );

		// 6. Rate limit.
		$limited = $this->ask();
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'newspack_live_rate_limited', $limited->get_data()['code'] );
		Questions::reset_rate_limits();
		$this->assertSame( 201, $this->ask()->get_status() );
	}

	/**
	 * A registered reader's question records the user.
	 */
	public function test_registered_question(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- simulates a client address.
		Questions::reset_rate_limits();
		update_post_meta( $this->live_id, '_newspack_live_questions_mode', 'registered' );
		$reader = $this->create_user( 'subscriber' );
		wp_set_current_user( $reader );
		$this->assertSame( 201, $this->ask()->get_status() );
		$ids = get_posts(
			[
				'post_type'   => 'newspack_live_q',
				'post_status' => 'nl_new',
				'post_parent' => $this->live_id,
				'fields'      => 'ids',
			]
		);
		$this->assertSame( (string) $reader, get_post_field( 'post_author', $ids[0] ) );
	}

	/**
	 * Private routes: anonymous 401, and an author on live A gets 403 on every write route of live B.
	 */
	public function test_private_scope(): void {
		$author = $this->create_user( 'author' );
		$live_a = $this->create_live( [], [ Live::META_TEAM => [ $author ] ] );
		$live_b = $this->live_id;
		$entry_b = $this->add();
		$question_b = Questions::create( $live_b, 'Q?', 'Ana' );

		$routes = [
			[ 'GET', '/lives/%d/console', [] ],
			[ 'POST', '/lives/%d/entries', [ 'title' => 'X' ] ],
			[ 'POST', '/lives/%d/state', [ 'action' => 'end' ] ],
			[ 'POST', '/lives/%d/settings', [ 'questions_open' => false ] ],
			[ 'GET', '/lives/%d/questions', [] ],
			[
				'POST',
				'/lives/%d/questions/bulk',
				[
					'ids'    => [ $question_b ],
					'status' => 'nl_spam',
				],
			],
		];

		wp_set_current_user( 0 );
		foreach ( $routes as [ $method, $route, $params ] ) {
			$this->assertSame( 401, $this->request( $method, sprintf( $route, $live_b ), $params )->get_status(), "anonymous $route" );
		}
		$this->assertSame( 401, $this->request( 'PUT', '/entries/' . $entry_b, [ 'title' => 'X' ] )->get_status() );

		wp_set_current_user( $author );
		foreach ( $routes as [ $method, $route, $params ] ) {
			$this->assertSame( 403, $this->request( $method, sprintf( $route, $live_b ), $params )->get_status(), "author on A, $route on B" );
		}
		foreach ( [ [ 'PUT', '', [ 'title' => 'X' ] ], [ 'POST', '/flags', [ 'pinned' => true ] ], [ 'POST', '/status', [ 'status' => 'trash' ] ] ] as [ $method, $suffix, $params ] ) {
			$this->assertSame( 403, $this->request( $method, '/entries/' . $entry_b . $suffix, $params )->get_status(), "entry$suffix" );
		}
		// On their own live, the same author succeeds.
		$this->assertSame( 201, $this->request( 'POST', '/lives/' . $live_a . '/entries', [ 'title' => 'Mine' ] )->get_status() );
	}

	/**
	 * Console create, update, flags, status, state and questions toggle.
	 */
	public function test_console_writes(): void {
		wp_set_current_user( $this->editor );
		$created = $this->request(
			'POST',
			'/lives/' . $this->live_id . '/entries',
			[
				'title'      => 'Hello',
				'content'    => '<!-- wp:paragraph --><p>World</p><!-- /wp:paragraph -->',
				'compose_ms' => 4200,
			]
		);
		$this->assertSame( 201, $created->get_status() );
		$entry_id = $created->get_data()['entry']['id'];
		$this->assertSame( '4200', get_post_meta( $entry_id, Entries::META_COMPOSE_MS, true ) );

		$this->assertSame( 200, $this->request( 'PUT', '/entries/' . $entry_id, [ 'title' => 'Edited' ] )->get_status() );
		$this->assertSame( 'Edited', get_the_title( $entry_id ) );
		$this->request( 'POST', '/entries/' . $entry_id . '/status', [ 'status' => 'draft' ] );
		$this->assertSame( 'draft', get_post_status( $entry_id ) );

		$this->assertSame( 'ended', $this->request( 'POST', '/lives/' . $this->live_id . '/state', [ 'action' => 'end' ] )->get_data()['live']['state'] );
		$this->assertSame( 409, $this->request( 'POST', '/lives/' . $this->live_id . '/state', [ 'action' => 'end' ] )->get_status() );
		$this->request( 'POST', '/lives/' . $this->live_id . '/settings', [ 'questions_open' => false ] );
		$this->assertFalse( Live::are_questions_open( $this->live_id ) );
	}

	/**
	 * Answering questions marks them answered; contributors submit for review only.
	 */
	public function test_answers_and_contributors(): void {
		$question = Questions::create( $this->live_id, 'Why?', 'Ana' );
		wp_set_current_user( $this->editor );
		$response = $this->request(
			'POST',
			'/lives/' . $this->live_id . '/entries',
			[
				'content'      => '<!-- wp:paragraph --><p>Hi Ana,</p><!-- /wp:paragraph -->',
				'question_ids' => [ $question ],
			]
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'nl_answered', get_post_status( $question ) );

		$contributor = $this->create_user( 'contributor' );
		update_post_meta( $this->live_id, Live::META_TEAM, [ $contributor ] );
		wp_set_current_user( $contributor );
		$this->assertSame( 403, $this->request( 'POST', '/lives/' . $this->live_id . '/entries', [ 'title' => 'Mine' ] )->get_status(), 'Default status publish is refused.' );
		$pending = $this->request(
			'POST',
			'/lives/' . $this->live_id . '/entries',
			[
				'title'  => 'Mine',
				'status' => 'pending',
			]
		);
		$this->assertSame( 201, $pending->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/entries/' . $pending->get_data()['entry']['id'] . '/status', [ 'status' => 'publish' ] )->get_status() );
	}

	/**
	 * Console bootstrap and refresh; question listing escapes nothing server side (plain text) and paginates.
	 */
	public function test_console_and_questions(): void {
		$this->add();
		Questions::create( $this->live_id, '<b>Bold</b> <script>x</script> & why?', 'Ana' );
		wp_set_current_user( $this->editor );
		$boot = $this->request( 'GET', '/lives/' . $this->live_id . '/console' );
		$this->assertSame( 200, $boot->get_status() );
		$this->assertSame( 'no-store', $boot->get_headers()['Cache-Control'] );
		$data = $boot->get_data();
		$this->assertCount( 1, $data['entries'] );
		$this->assertArrayHasKey( 'team', $data );
		$this->assertArrayNotHasKey( 'labels', $data, 'Labels removed.' );
		$this->assertSame( 1, $data['question_count']['nl_new'] );

		$refresh = $this->request( 'GET', '/lives/' . $this->live_id . '/console', [ 'since' => $data['rev'] ] )->get_data();
		$this->assertSame( [], $refresh['entries'] );
		$this->assertArrayNotHasKey( 'team', $refresh );

		$questions = $this->request( 'GET', '/lives/' . $this->live_id . '/questions' )->get_data();
		$this->assertCount( 1, $questions['items'] );
		$this->assertFalse( $questions['has_more'] );
		$this->assertStringNotContainsString( '<', $questions['items'][0]['text'], 'Stored as plain text.' );
	}

	/**
	 * Team candidates require newspack_live_manage and only list users who can post entries.
	 */
	public function test_team_candidates(): void {
		$subscriber = $this->create_user( 'subscriber' );
		$author     = $this->create_user( 'author' );
		wp_set_current_user( $subscriber );
		$this->assertSame( 403, $this->request( 'GET', '/team-candidates' )->get_status() );
		wp_set_current_user( $this->editor );
		$ids = array_column( $this->request( 'GET', '/team-candidates' )->get_data(), 'id' );
		$this->assertContains( $author, $ids );
		$this->assertNotContains( $subscriber, $ids );
	}

	/**
	 * Akismet present: spam is stored as `nl_spam` and answered with 201.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_akismet_spam(): void {
		require_once dirname( __DIR__ ) . '/mocks/akismet.php';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- simulates a client address.
		Questions::reset_rate_limits();
		$this->assertSame( 201, $this->ask( [ 'question' => 'Cheap viagra here' ] )->get_status() );
		$this->assertSame( 1, Questions::count( $this->live_id )['nl_spam'] );
		$this->assertSame( 0, Questions::count( $this->live_id )['nl_new'] );
	}

	/**
	 * Akismet absent (sandbox): questions are stored as new.
	 */
	public function test_akismet_absent(): void {
		$this->assertFalse( \Newspack_Live\Integrations\Anti_Spam::can_use_akismet() );
		$this->assertFalse( \Newspack_Live\Integrations\Anti_Spam::is_spam( [ 'text' => 'viagra' ] ) );
		$this->assertTrue( \Newspack_Live\Integrations\Anti_Spam::verify_recaptcha() );
	}

	/**
	 * A client older than the pruned removal log must reload.
	 */
	public function test_reset_after_pruning(): void {
		$entry = $this->add();
		wp_set_current_user( $this->editor );
		Entries::transition( $entry, 'draft' );
		$removed         = Live::get_removed( $this->live_id );
		$removed[0]['t'] = time() - 2 * DAY_IN_SECONDS;
		update_post_meta( $this->live_id, Live::META_REMOVED, $removed );
		Live::prune_removed( $this->live_id );
		$this->assertSame( 2, Live::get_removed_floor( $this->live_id ) );
		wp_set_current_user( 0 );
		$data = $this->request(
			'GET',
			'/lives/' . $this->live_id . '/entries',
			[
				'since' => 1,
				'until' => 2,
			]
		)->get_data();
		$this->assertTrue( $data['reset'] );
	}

	/**
	 * Deltas list the assets entries need, also when the entry comes from the render cache.
	 */
	public function test_delta_assets(): void {
		$story = self::factory()->post->create( [ 'post_title' => 'Story' ] );
		$this->add( [ 'content' => '<!-- wp:newspack-live/article-card {"postId":' . $story . '} /-->' ] );
		$params = [
			'since' => 0,
			'until' => 1,
		];
		foreach ( [ 'fresh', 'cached' ] as $pass ) {
			wp_styles()->queue = [];
			$data              = $this->request( 'GET', '/lives/' . $this->live_id . '/entries', $params )->get_data();
			$handles           = array_column( $data['assets'], 'handle' );
			$this->assertContains( 'newspack-live-article-card-style', $handles, $pass );
			foreach ( $data['assets'] as $asset ) {
				$this->assertTrue( 1 === preg_match( '#^https?://#', $asset['src'] ) || isset( $asset['after'] ) || isset( $asset['before'] ) || isset( $asset['data'] ), $asset['handle'] );
			}
		}
		remove_filter( 'should_load_block_assets_on_demand', '__return_true' );
	}

	/**
	 * Contributors can't pin (pinning displaces a published pin), on create or through flags.
	 */
	public function test_contributor_cannot_pin(): void {
		$pinned      = $this->add( [ 'pinned' => true ] );
		$contributor = $this->create_user( 'contributor' );
		update_post_meta( $this->live_id, Live::META_TEAM, [ $contributor ] );
		wp_set_current_user( $contributor );
		$create = $this->request(
			'POST',
			'/lives/' . $this->live_id . '/entries',
			[
				'title'  => 'Mine',
				'status' => 'pending',
				'pinned' => true,
			]
		);
		$this->assertSame( 403, $create->get_status() );
		$draft = $this->request(
			'POST',
			'/lives/' . $this->live_id . '/entries',
			[
				'title'  => 'Mine',
				'status' => 'pending',
			]
		)->get_data()['entry']['id'];
		$this->assertSame( 403, $this->request( 'POST', '/entries/' . $draft . '/flags', [ 'pinned' => true ] )->get_status() );
		$this->assertTrue( Entries::is_pinned( $pinned ) );
		// Changing the author requires edit-others rights.
		$this->assertSame( 403, $this->request( 'PUT', '/entries/' . $draft, [ 'author' => $this->editor ] )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/entries/' . $draft, [ 'status' => 'publish' ] )->get_status() );
	}

	/**
	 * Pipeline order: the first failing step decides, even when later ones would fail too.
	 */
	public function test_question_pipeline_order(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10'; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- simulates a client address.
		Questions::reset_rate_limits();
		$too_fast = Questions::sign_timestamp( $this->live_id, time() );
		// Honeypot (3) before timing (4) and lengths (5): silent success.
		$this->assertSame(
			201,
			$this->ask(
				[
					'website'  => 'x',
					'ts'       => $too_fast,
					'question' => str_repeat( 'a', 6000 ),
				]
			)->get_status()
		);
		// Closed (1) before honeypot (3).
		update_post_meta( $this->live_id, Live::META_QUESTIONS_OPEN, 0 );
		$this->assertSame( 403, $this->ask( [ 'website' => 'x' ] )->get_status() );
		update_post_meta( $this->live_id, Live::META_QUESTIONS_OPEN, 1 );
		// Timing (4) before lengths (5).
		$this->assertSame(
			'newspack_live_too_fast',
			$this->ask(
				[
					'ts'       => $too_fast,
					'question' => '',
				] 
			)->get_data()['code'] 
		);
		$this->assertSame( 0, array_sum( Questions::count( $this->live_id ) ) );
	}

	/**
	 * Gated lives are never publicly cached, even for readers who pass the gate.
	 */
	public function test_gated_never_public(): void {
		$this->add();
		add_filter( 'newspack_post_has_restrictions', '__return_true' );
		$response = $this->request( 'GET', '/lives/' . $this->live_id . '/entries' );
		$status   = $this->request( 'GET', '/lives/' . $this->live_id . '/status' )->get_data();
		remove_filter( 'newspack_post_has_restrictions', '__return_true' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		$this->assertTrue( $status['gated'] );
	}

	/**
	 * The moderation inbox is a private route: `no-store`.
	 */
	public function test_inbox_headers(): void {
		wp_set_current_user( $this->editor );
		$this->assertSame( 'no-store', $this->request( 'GET', '/lives/' . $this->live_id . '/questions' )->get_headers()['Cache-Control'] );
	}

	/**
	 * Team candidates resolve IDs and don't search login names.
	 */
	public function test_team_candidates_include(): void {
		$author = self::factory()->user->create(
			[
				'role'         => 'author',
				'user_login'   => 'secretlogin',
				'display_name' => 'Visible Name',
			]
		);
		wp_set_current_user( $this->editor );
		$this->assertSame( [], $this->request( 'GET', '/team-candidates', [ 'search' => 'secretlogin' ] )->get_data() );
		$this->assertSame( [ $author ], array_column( $this->request( 'GET', '/team-candidates', [ 'search' => 'Visible' ] )->get_data(), 'id' ) );
		$this->assertSame( [ $author ], array_column( $this->request( 'GET', '/team-candidates', [ 'include' => [ $author ] ] )->get_data(), 'id' ) );
	}

	/**
	 * Publishers pin through PUT; a contributor's PUT can't pin.
	 */
	public function test_pin_through_put(): void {
		$entry = $this->add();
		wp_set_current_user( $this->editor );
		$this->assertSame( 200, $this->request( 'PUT', '/entries/' . $entry, [ 'pinned' => true ] )->get_status() );
		$this->assertTrue( Entries::is_pinned( $entry ) );

		$contributor = $this->create_user( 'contributor' );
		update_post_meta( $this->live_id, Live::META_TEAM, [ $contributor ] );
		wp_set_current_user( $contributor );
		$draft = $this->request(
			'POST',
			'/lives/' . $this->live_id . '/entries',
			[
				'title'  => 'Mine',
				'status' => 'pending',
			]
		)->get_data()['entry']['id'];
		$this->assertSame( 403, $this->request( 'PUT', '/entries/' . $draft, [ 'pinned' => true ] )->get_status() );
		$this->assertTrue( Entries::is_pinned( $entry ) );
	}
}
