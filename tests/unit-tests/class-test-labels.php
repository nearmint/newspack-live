<?php
/**
 * Entry labels.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Tests;

use Newspack_Live\Entries;
use Newspack_Live\Labels;
use Newspack_Live\Live;
use Newspack_Live\Questions;
use Newspack_Live\Renderer;
use Newspack_Live\Uninstall;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Meta registration, validation, custom labels, the "Your questions" lock, routes and rendering.
 *
 * @group labels
 */
class Test_Labels extends WP_UnitTestCase {
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
	 * Registers the meta again (the test library unregisters every meta key after each test) and
	 * creates a live.
	 */
	public function set_up(): void {
		parent::set_up();
		Labels::register();
		$this->editor  = $this->create_user( 'editor' );
		$this->live_id = $this->create_live( [ 'post_author' => $this->editor ] );
		wp_set_current_user( $this->editor );
	}

	/**
	 * Resets the current user and the translation filter.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'gettext_with_context' );
		parent::tear_down();
	}

	/**
	 * Dispatches a private route.
	 *
	 * @param string $method Method.
	 * @param string $route  Route relative to the namespace.
	 * @param array  $params Body parameters.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/newspack-live/v1' . $route );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Adds an entry.
	 *
	 * @param array $data Fields.
	 * @return int|\WP_Error
	 */
	private function add( array $data = [] ) {
		return Entries::save(
			array_merge(
				[
					'live_id' => $this->live_id,
					'title'   => 'Entry',
					'author'  => $this->editor,
				],
				$data
			)
		);
	}

	/**
	 * Adds a question to the live.
	 *
	 * @return int
	 */
	private function question(): int {
		return (int) Questions::create( $this->live_id, 'When do polls close?', 'Ana' );
	}

	/**
	 * Both meta keys are registered with a type, sanitize and auth callbacks, and stay out of core REST.
	 */
	public function test_registration(): void {
		$entry_meta = get_registered_meta_keys( 'post', \Newspack_Live\Post_Types::ENTRY );
		$this->assertArrayHasKey( Labels::ENTRY_META, $entry_meta );
		$this->assertSame( 'string', $entry_meta[ Labels::ENTRY_META ]['type'] );
		$this->assertSame( [ Labels::class, 'sanitize_value' ], $entry_meta[ Labels::ENTRY_META ]['sanitize_callback'] );
		$this->assertFalse( $entry_meta[ Labels::ENTRY_META ]['show_in_rest'] );

		$live_meta = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( Labels::LIVE_META, $live_meta );
		$this->assertSame( 'array', $live_meta[ Labels::LIVE_META ]['type'] );
		$this->assertSame( [ Labels::class, 'sanitize_custom_list' ], $live_meta[ Labels::LIVE_META ]['sanitize_callback'] );
		$this->assertFalse( $live_meta[ Labels::LIVE_META ]['show_in_rest'] );

		// Auth: the live's custom labels need the publishing right on that live.
		$this->assertTrue( current_user_can( 'edit_post_meta', $this->live_id, Labels::LIVE_META ) );
		wp_set_current_user( $this->create_user( 'author' ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $this->live_id, Labels::LIVE_META ), 'An author off the team cannot.' );

		// Both keys are removed on uninstall.
		$this->assertContains( Labels::ENTRY_META, Uninstall::POST_META_KEYS );
		$this->assertContains( Labels::LIVE_META, Uninstall::POST_META_KEYS );
	}

	/**
	 * Sanitize callbacks keep presets and well-formed custom values only; "Your questions" is never stored.
	 */
	public function test_sanitize(): void {
		$this->assertSame( 'breaking', Labels::sanitize_value( 'breaking' ) );
		$this->assertSame( 'key-point', Labels::sanitize_value( ' key-point ' ) );
		$this->assertSame( 'custom:ab12cd34', Labels::sanitize_value( 'custom:ab12cd34' ) );
		$this->assertSame( '', Labels::sanitize_value( 'your-questions' ) );
		$this->assertSame( '', Labels::sanitize_value( 'custom:AB12CD34' ) );
		$this->assertSame( '', Labels::sanitize_value( 'custom:<script>' ) );
		$this->assertSame( '', Labels::sanitize_value( [ 'breaking' ] ) );

		$this->assertSame(
			[
				[
					'id'   => 'ab12cd34',
					'text' => 'Turnout',
				],
			],
			Labels::sanitize_custom_list(
				[
					[
						'id'   => 'ab12cd34',
						'text' => '  <b>Turnout</b> ',
					],
					[
						'id'   => 'ab12cd34',
						'text' => 'Duplicate ID',
					],
					[
						'id'   => 'bad id!!',
						'text' => 'Bad ID',
					],
					[
						'id'   => 'zz99zz99',
						'text' => str_repeat( 'x', 25 ),
					],
					'not an array',
				]
			)
		);
	}

	/**
	 * Custom labels: trimmed, 1–24 characters, refused when empty.
	 */
	public function test_custom_label_length(): void {
		$label = Labels::create( $this->live_id, '   ' . str_repeat( 'é', 24 ) . '   ' );
		$this->assertIsArray( $label );
		$this->assertSame( str_repeat( 'é', 24 ), $label['text'], '24 multibyte characters fit.' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{8}$/', $label['id'] );

		$this->assertSame( 'newspack_live_label_too_long', Labels::create( $this->live_id, str_repeat( 'a', 25 ) )->get_error_code() );
		$this->assertSame( 'newspack_live_label_empty', Labels::create( $this->live_id, "  \n " )->get_error_code() );
		$this->assertSame( 'newspack_live_label_empty', Labels::create( $this->live_id, '<b></b>' )->get_error_code() );
		$this->assertCount( 1, Labels::get_custom( $this->live_id ) );
	}

	/**
	 * Duplicates are refused ignoring case: presets in English and in the site language, and the live's
	 * custom labels. Another live can reuse the text.
	 */
	public function test_custom_label_duplicates(): void {
		add_filter(
			'gettext_with_context',
			static fn( $translation, $text, $context ) => 'entry label' === $context && 'Breaking' === $text ? 'Dernière minute' : $translation,
			10,
			3
		);
		$this->assertSame( 'newspack_live_label_exists', Labels::create( $this->live_id, 'BREAKING' )->get_error_code() );
		$this->assertSame( 'newspack_live_label_exists', Labels::create( $this->live_id, 'dernière MINUTE' )->get_error_code(), 'The translated preset name is taken.' );
		$this->assertSame( 'newspack_live_label_exists', Labels::create( $this->live_id, 'your questions' )->get_error_code() );
		$this->assertSame( 'newspack_live_label_exists', Labels::create( $this->live_id, 'Key Point' )->get_error_code() );

		$this->assertIsArray( Labels::create( $this->live_id, 'Turnout' ) );
		$this->assertSame( 'newspack_live_label_exists', Labels::create( $this->live_id, 'TURNOUT' )->get_error_code() );

		$other = $this->create_live( [ 'post_author' => $this->editor ] );
		$this->assertIsArray( Labels::create( $other, 'Turnout' ), 'Custom labels are scoped to their live.' );
		$this->assertCount( 1, Labels::get_custom( $this->live_id ) );
	}

	/**
	 * At most 50 custom labels per live.
	 */
	public function test_custom_label_limit(): void {
		$list = [];
		for ( $i = 0; $i < Labels::MAX_CUSTOM; $i++ ) {
			$list[] = [
				'id'   => sprintf( 'id%06d', $i ),
				'text' => 'Label ' . $i,
			];
		}
		update_post_meta( $this->live_id, Labels::LIVE_META, $list );
		$this->assertSame( 'newspack_live_label_limit', Labels::create( $this->live_id, 'One more' )->get_error_code() );
	}

	/**
	 * The label routes need the publishing right on the live (authors and contributors off the team,
	 * contributors on it: 403) and a signed-in user (401).
	 */
	public function test_route_capabilities(): void {
		$route = '/lives/' . $this->live_id . '/labels';
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', $route, [ 'text' => 'Turnout' ] )->get_status() );

		$contributor = $this->create_user( 'contributor' );
		update_post_meta( $this->live_id, Live::META_TEAM, [ $contributor ] );
		Live::flush_team();
		wp_set_current_user( $contributor );
		$this->assertSame( 403, $this->request( 'POST', $route, [ 'text' => 'Turnout' ] )->get_status(), 'Contributors propose entries but cannot create labels.' );

		wp_set_current_user( $this->create_user( 'author' ) );
		$this->assertSame( 403, $this->request( 'POST', $route, [ 'text' => 'Turnout' ] )->get_status(), 'Authors off the team cannot.' );

		$author = $this->create_user( 'author' );
		update_post_meta( $this->live_id, Live::META_TEAM, [ $author ] );
		Live::flush_team();
		wp_set_current_user( $author );
		$response = $this->request( 'POST', $route, [ 'text' => ' Turnout ' ] );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Turnout', $data['label']['text'] );
		$this->assertSame( 'custom:' . $data['label']['id'], $data['label']['value'] );
		$this->assertFalse( $data['label']['used'] );
		$this->assertCount( 1, $data['labels'] );

		$this->assertSame( 409, $this->request( 'POST', $route, [ 'text' => 'turnout' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', $route, [ 'text' => '' ] )->get_status() );

		// A user authorized on this live gets 403 on another live.
		$other = $this->create_live( [ 'post_author' => $this->editor ] );
		$this->assertSame( 403, $this->request( 'POST', '/lives/' . $other . '/labels', [ 'text' => 'Turnout' ] )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/lives/' . $other . '/labels/' . $data['label']['id'] )->get_status() );
	}

	/**
	 * A custom label can be deleted only while no entry of the live uses it, trashed entries included.
	 */
	public function test_delete_only_unused(): void {
		$label = Labels::create( $this->live_id, 'Turnout' );
		$value = Labels::CUSTOM_PREFIX . $label['id'];
		$route = '/lives/' . $this->live_id . '/labels/' . $label['id'];

		$entry = $this->add( [ 'label' => $value ] );
		$this->assertSame( $value, get_post_meta( $entry, Labels::ENTRY_META, true ) );
		$this->assertTrue( Labels::payload( $this->live_id )[0]['used'] );
		$response = $this->request( 'DELETE', $route );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'newspack_live_label_in_use', $response->get_data()['code'] );

		wp_trash_post( $entry );
		$this->assertSame( 409, $this->request( 'DELETE', $route )->get_status(), 'A trashed entry keeps the label in use.' );

		wp_untrash_post( $entry );
		Entries::save( [ 'label' => '' ], $entry );
		$this->assertSame( '', get_post_meta( $entry, Labels::ENTRY_META, true ) );
		$this->assertFalse( Labels::payload( $this->live_id )[0]['used'], 'The cached usage follows label writes.' );
		$response = $this->request( 'DELETE', $route );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['labels'] );
		$this->assertSame( 404, $this->request( 'DELETE', $route )->get_status() );
	}

	/**
	 * Entry writes accept presets, custom labels of the live and '' only.
	 */
	public function test_entry_label_validation(): void {
		$entry = $this->add( [ 'label' => 'breaking' ] );
		$this->assertSame( 'breaking', Labels::get_value( $entry ) );

		$this->assertSame( 'newspack_live_invalid_label', $this->add( [ 'label' => 'urgent' ] )->get_error_code() );
		$this->assertSame( 'newspack_live_invalid_label', $this->add( [ 'label' => 'your-questions' ] )->get_error_code(), 'Your questions is not selectable.' );
		$this->assertSame( 'newspack_live_invalid_label', $this->add( [ 'label' => 'custom:ab12cd34' ] )->get_error_code(), 'Unknown custom label.' );

		$other = $this->create_live( [ 'post_author' => $this->editor ] );
		$label = Labels::create( $other, 'Turnout' );
		$this->assertSame( 'newspack_live_invalid_label', $this->add( [ 'label' => 'custom:' . $label['id'] ] )->get_error_code(), "Another live's custom label." );

		// A label change after publication is an entry edit: the live revision moves.
		$rev = Live::get_rev( $this->live_id );
		$this->assertSame( $entry, Entries::save( [ 'label' => 'key-point' ], $entry ) );
		$this->assertSame( 'key-point', Labels::get_value( $entry ) );
		$this->assertSame( $rev + 1, Live::get_rev( $this->live_id ) );
		$this->assertSame( $rev + 1, Entries::get_rev( $entry ) );

		// The console route carries the field.
		$response = $this->request( 'PUT', '/entries/' . $entry, [ 'label' => 'video' ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'video', $response->get_data()['entry']['label'] );
		$this->assertSame( 400, $this->request( 'PUT', '/entries/' . $entry, [ 'label' => 'urgent' ] )->get_status() );
		$this->assertSame( 'video', Labels::get_value( $entry ) );
	}

	/**
	 * A dangling custom label never blocks a save: an unchanged value is kept (and renders nothing),
	 * and a label deleted while a draft was being saved is refused under the lock.
	 */
	public function test_dangling_label_and_delete_race(): void {
		$label = Labels::create( $this->live_id, 'Turnout' );
		$value = Labels::CUSTOM_PREFIX . $label['id'];

		// Deleted although used (an older race, or data from elsewhere): edits still save.
		$entry = $this->add( [ 'label' => $value ] );
		update_post_meta( $this->live_id, Labels::LIVE_META, [] );
		$this->assertSame(
			$entry,
			Entries::save(
				[
					'title' => 'Edited',
					'label' => $value,
				],
				$entry 
			) 
		);
		$this->assertStringNotContainsString( 'newspack-live-entry__label', Renderer::render_entry( $entry ) );
		$this->assertSame( 'newspack_live_invalid_label', $this->add( [ 'label' => $value ] )->get_error_code(), 'A new entry cannot take it.' );

		// The race: normalize() sees the label (cached meta), the deletion lands before the write.
		$label = Labels::create( $this->live_id, 'Recount' );
		$value = Labels::CUSTOM_PREFIX . $label['id'];
		Labels::get_custom( $this->live_id );
		global $wpdb;
		// Another request deletes the label behind this request's meta cache.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery -- test only: a write that bypasses the meta cache.
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => maybe_serialize( [] ) ],
			[
				'post_id'  => $this->live_id,
				'meta_key' => Labels::LIVE_META,
			]
		);
		// phpcs:enable
		$draft = $this->add(
			[
				'label'  => $value,
				'status' => 'draft',
			]
		);
		$this->assertWPError( $draft );
		$this->assertSame( 409, $draft->get_error_data()['status'] );
	}

	/**
	 * Answers carry "Your questions" only: derived, never stored, locked on every write path.
	 */
	public function test_your_questions_lock(): void {
		$question = $this->question();
		$this->assertSame(
			'newspack_live_label_locked',
			$this->add(
				[
					'question_ids' => [ $question ],
					'label'        => 'breaking',
				] 
			)->get_error_code(),
			'An answer cannot carry another label.' 
		);

		$answer = $this->add( [ 'question_ids' => [ $question ] ] );
		$this->assertIsInt( $answer );
		$this->assertSame( 'your-questions', Labels::get_value( $answer ) );
		$this->assertFalse( metadata_exists( 'post', $answer, Labels::ENTRY_META ), 'Never stored.' );

		$this->assertSame( 'newspack_live_label_locked', Entries::save( [ 'label' => '' ], $answer )->get_error_code(), 'It cannot be removed.' );
		$this->assertSame( 'newspack_live_label_locked', Entries::save( [ 'label' => 'photos' ], $answer )->get_error_code(), 'It cannot be changed.' );
		$this->assertSame( $answer, Entries::save( [ 'label' => 'your-questions' ], $answer ), 'Sending it unchanged is a no-op.' );
		$this->assertSame( 400, $this->request( 'PUT', '/entries/' . $answer, [ 'label' => 'breaking' ] )->get_status() );
		$this->assertSame( 'your-questions', Labels::get_value( $answer ) );

		// A labeled entry that becomes an answer loses its stored label.
		$entry = $this->add( [ 'label' => 'photos' ] );
		Entries::save( [ 'question_ids' => [ $this->question() ] ], $entry );
		$this->assertFalse( metadata_exists( 'post', $entry, Labels::ENTRY_META ) );
		$this->assertSame( 'your-questions', Labels::get_value( $entry ) );

		// Existing answers (no stored label, no migration) get it at render time.
		$this->assertStringContainsString( 'newspack-live-entry__label--your-questions', Renderer::render_entry( $answer ) );
	}

	/**
	 * Rendering: text after the time, separator for screen readers, accent class on red labels, the
	 * Key point card class, nothing for unknown or dangling values.
	 */
	public function test_render(): void {
		$label  = Labels::create( $this->live_id, 'Turn<i>out</i> & co' );
		$custom = Labels::CUSTOM_PREFIX . $label['id'];
		$cases  = [
			'breaking'  => [ 'Breaking', true ],
			'photos'    => [ 'Photos', false ],
			'video'     => [ 'Video', false ],
			'key-point' => [ 'Key point', true ],
		];
		foreach ( $cases as $slug => [ $text, $accent ] ) {
			$html = Renderer::render_entry( $this->add( [ 'label' => $slug ] ) );
			$this->assertMatchesRegularExpression(
				'#</time><span class="newspack-live-entry__label newspack-live-entry__label--' . $slug . ( $accent ? ' newspack-live-entry__label--accent' : '' ) . '"><span class="newspack-live__sr-only">, </span>' . $text . '</span>#',
				$html,
				$slug
			);
			$this->assertSame( 'key-point' === $slug, str_contains( $html, 'newspack-live-entry--key-point' ), $slug );
		}

		$html = Renderer::render_entry( $this->add( [ 'label' => $custom ] ) );
		$this->assertStringContainsString( '<span class="newspack-live-entry__label newspack-live-entry__label--custom"><span class="newspack-live__sr-only">, </span>Turnout &amp; co</span>', $html );

		// Pinned Key point: both classes.
		$html = Renderer::render_entry(
			$this->add(
				[
					'label'  => 'key-point',
					'pinned' => true,
				] 
			) 
		);
		$this->assertStringContainsString( 'newspack-live-entry--pinned newspack-live-entry--key-point', $html );

		// No label, unknown stored value, dangling custom ID: no label markup, the entry still renders.
		$plain = $this->add();
		$this->assertStringNotContainsString( 'newspack-live-entry__label', Renderer::render_entry( $plain ) );
		$unknown = $this->add();
		// Stored without the sanitize callback, as an older or foreign write could have left it.
		unregister_post_meta( \Newspack_Live\Post_Types::ENTRY, Labels::ENTRY_META );
		add_post_meta( $unknown, Labels::ENTRY_META, 'urgent' );
		Labels::register();
		$this->assertSame( 'urgent', get_post_meta( $unknown, Labels::ENTRY_META, true ) );
		$this->assertStringNotContainsString( 'newspack-live-entry__label', Renderer::render_entry( $unknown ) );
		$dangling = $this->add();
		add_post_meta( $dangling, Labels::ENTRY_META, 'custom:zz99zz99' );
		$html = Renderer::render_entry( $dangling );
		$this->assertStringNotContainsString( 'newspack-live-entry__label', $html );
		$this->assertStringContainsString( 'newspack-live-entry__time', $html );
	}

	/**
	 * The console payloads carry the entry label and the live's custom labels with their flags.
	 */
	public function test_console_payload(): void {
		$label = Labels::create( $this->live_id, 'Turnout' );
		$this->add( [ 'label' => 'breaking' ] );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/newspack-live/v1/lives/' . $this->live_id . '/console' ) );
		$data     = $response->get_data();
		$this->assertSame( 'breaking', $data['entries'][0]['label'] );
		$this->assertSame(
			[
				[
					'id'    => $label['id'],
					'value' => 'custom:' . $label['id'],
					'text'  => 'Turnout',
					'used'  => false,
				],
			],
			$data['live']['labels']
		);
		$config = Labels::console_config();
		$this->assertSame( [ 'breaking', 'photos', 'video', 'key-point' ], array_column( $config['presets'], 'value' ), 'Your questions is not offered.' );
		$this->assertContains( 'your questions', $config['taken'] );
	}
}
