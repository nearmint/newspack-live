<?php
/**
 * Entry model: writes, flags, transitions and queries.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Every plugin write on an entry goes through save(), set_flags() or transition(), which bump
 * the live revision when the change is reader-visible.
 */
final class Entries {

	public const META_TYPE         = '_newspack_live_type';
	public const META_PINNED       = '_newspack_live_pinned';
	public const META_QUESTION_IDS = '_newspack_live_question_ids';
	public const META_REV          = '_newspack_live_rev';

	/**
	 * Set when a re-stamp (touch) couldn't take the lock, with the attempt count; and the one-off
	 * cron event retrying it (args: entry ID).
	 */
	public const META_TOUCH_PENDING = '_newspack_live_touch_pending';
	public const TOUCH_EVENT        = 'newspack_live_entry_touch';
	public const META_PUBLISHED_AT = '_newspack_live_published_at';
	public const META_COMPOSE_MS   = '_newspack_live_compose_ms';

	public const TYPE_UPDATE = 'update';

	/**
	 * Statuses an entry can be saved with.
	 */
	public const STATUSES = [ 'publish', 'draft', 'pending' ];

	/**
	 * Allowed clock skew for entry times, in seconds.
	 */
	public const FUTURE_TOLERANCE = 60;

	/**
	 * Maximum number of questions answered by one entry.
	 */
	public const MAX_QUESTIONS = 50;

	/**
	 * Request-local guard: true while Entries::* performs a write, so the core hooks skip it.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_after_insert_post', [ __CLASS__, 'on_after_insert_post' ], 10, 4 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete_post' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'filter_untrusted_markup' ], 9, 2 );
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'normalize_post_data_headings' ], 10, 2 );
		add_action( self::TOUCH_EVENT, [ __CLASS__, 'retry_touch' ] );
		add_filter( 'post_type_link', [ __CLASS__, 'entry_link' ], 10, 2 );
	}

	/**
	 * An entry's public URL is its permalink on the live (`?live-entry=`): entries aren't publicly
	 * queryable.
	 *
	 * @param string  $url  URL.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function entry_link( $url, $post ) {
		if ( $post instanceof WP_Post && Post_Types::ENTRY === $post->post_type && $post->post_parent > 0 ) {
			return Renderer::entry_permalink( (int) $post->post_parent, (int) $post->ID );
		}
		return $url;
	}

	/**
	 * Whether a post is an entry.
	 *
	 * @param mixed $post Post or ID.
	 * @return bool
	 */
	public static function is_entry( $post ): bool {
		$post = get_post( $post );
		return $post instanceof WP_Post && Post_Types::ENTRY === $post->post_type;
	}

	/**
	 * Whether a body has visible content: text, or media/embed/card blocks.
	 *
	 * @param string $content Block markup.
	 * @return bool
	 */
	public static function has_body( string $content ): bool {
		if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
			return true;
		}
		return (bool) preg_match( '/<(img|iframe|video|audio|table)\b|<!--\s+wp:(embed|newspack-live\/article-card|image|gallery|video)\b/i', $content );
	}

	/**
	 * Creates or updates an entry.
	 *
	 * @param array $data     Fields: live_id (create only), title, content, time (UTC timestamp, null = now),
	 *                        pinned, author, status, question_ids, label (preset slug, `custom:<id>`
	 *                        or '' for none), compose_ms, type (`update`).
	 * @param int   $entry_id Entry to update, 0 to create.
	 * @return int|WP_Error Entry ID.
	 */
	public static function save( array $data, int $entry_id = 0 ) {
		$existing = null;
		if ( $entry_id > 0 ) {
			$existing = get_post( $entry_id );
			if ( ! self::is_entry( $existing ) ) {
				return new WP_Error( 'newspack_live_entry_not_found', __( 'Entry not found.', 'newspack-live' ), [ 'status' => 404 ] );
			}
			$live_id = (int) $existing->post_parent;
		} else {
			$live_id = isset( $data['live_id'] ) ? (int) $data['live_id'] : 0;
		}
		if ( ! Live::is_live( $live_id ) ) {
			return new WP_Error( 'newspack_live_not_live', __( 'This post is not a live.', 'newspack-live' ), [ 'status' => 404 ] );
		}

		$fields = self::normalize( $data, $existing, $live_id );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$was_published = $existing && 'publish' === $existing->post_status;
		$visible       = $was_published || 'publish' === $fields['status'] || true === $fields['pinned'];
		$write         = static fn( int $rev ) => self::persist( $live_id, $fields, $existing, $rev );

		if ( $visible ) {
			return Live::write( $live_id, $write );
		}
		// A new custom label is checked again under the live's lock, which its deletion holds too:
		// a draft can't keep a label deleted while it was being saved.
		return Labels::is_new_custom( $fields['label'], $fields['label_was'] ) ? Live::with_lock( $live_id, static fn() => $write( 0 ) ) : $write( 0 );
	}

	/**
	 * Clamps heading levels to h3–h4: the live title is h1, section titles h2 and entry
	 * titles h3. Heading blocks get an explicit level 3 or 4; raw headings (classic content, HTML
	 * and freeform blocks) map h1–h2 → h3 and h5–h6 → h4. Other blocks are left alone. Content without such headings is returned
	 * unchanged (no re-serialization).
	 *
	 * @param string $content Entry content.
	 * @return string
	 */
	public static function normalize_headings( string $content ): string {
		$clamp_tags = static fn( string $html, ?int $level = null ): string => (string) preg_replace_callback(
			'#<(/?)h([1-6])(?=[\s>/])#i',
			static fn( array $match ): string => '<' . $match[1] . 'h' . ( $level ?? min( 4, max( 3, (int) $match[2] ) ) ),
			$html
		);
		if ( ! has_blocks( $content ) ) {
			return preg_match( '#<h[1256][\s>/]#i', $content ) ? $clamp_tags( $content ) : $content;
		}
		$changed = false;
		$walk    = static function ( array $blocks ) use ( &$walk, &$changed, $clamp_tags ): array {
			foreach ( $blocks as &$block ) {
				if ( 'core/heading' === $block['blockName'] ) {
					$level = (int) ( $block['attrs']['level'] ?? 2 );
					$fixed = min( 4, max( 3, $level ) );
					if ( $fixed !== $level || ! isset( $block['attrs']['level'] ) ) {
						$changed                 = true;
						$block['attrs']['level'] = $fixed;
						$block['innerHTML']      = $clamp_tags( $block['innerHTML'], $fixed );
						$block['innerContent']   = array_map( static fn( $part ) => is_string( $part ) ? $clamp_tags( $part, $fixed ) : $part, $block['innerContent'] );
					}
				} elseif ( in_array( $block['blockName'], [ null, 'core/html', 'core/freeform' ], true ) && preg_match( '#<h[1256][\s>/]#i', $block['innerHTML'] ) ) {
					// Raw headings only: other static blocks own their markup (rewriting it would make them invalid in the editor).
					$changed               = true;
					$block['innerHTML']    = $clamp_tags( $block['innerHTML'] );
					$block['innerContent'] = array_map( static fn( $part ) => is_string( $part ) ? $clamp_tags( $part ) : $part, $block['innerContent'] );
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$block['innerBlocks'] = $walk( $block['innerBlocks'] );
				}
			}
			return $blocks;
		};
		$blocks = $walk( parse_blocks( $content ) );
		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/**
	 * Entries saved by a user without `unfiltered_html` keep no script, event handler or
	 * `javascript:` URL, on every path. Core's KSES filters already do it when they are
	 * active; this check doesn't depend on them (other code may remove them). Users with
	 * `unfiltered_html` keep core's behavior.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function filter_untrusted_markup( $data, $postarr ) {
		unset( $postarr );
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$type = (string) ( $data['post_type'] ?? '' );
		// Entries, and their revisions and autosaves.
		$entry = Post_Types::ENTRY === $type || ( 'revision' === $type && Post_Types::ENTRY === get_post_type( (int) ( $data['post_parent'] ?? 0 ) ) );
		if ( ! $entry || current_user_can( 'unfiltered_html' ) ) {
			return $data;
		}
		if ( isset( $data['post_content'] ) ) {
			$data['post_content'] = wp_slash( wp_kses_post( wp_unslash( (string) $data['post_content'] ) ) );
		}
		if ( isset( $data['post_title'] ) ) {
			$data['post_title'] = wp_slash( wp_kses_data( wp_unslash( (string) $data['post_title'] ) ) );
		}
		return $data;
	}

	/**
	 * Applies the heading levels to entries saved through any path (core REST, full editor).
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function normalize_post_data_headings( $data, $postarr ) {
		if ( is_array( $data ) && Post_Types::ENTRY === ( $data['post_type'] ?? '' ) && isset( $data['post_content'] ) ) {
			$data['post_content'] = wp_slash( self::normalize_headings( wp_unslash( (string) $data['post_content'] ) ) );
		}
		return $data;
	}

	/**
	 * Validates and normalizes entry fields, merging them with the existing entry.
	 *
	 * @param array        $data     Raw fields.
	 * @param WP_Post|null $existing Existing entry.
	 * @param int          $live_id  Live ID.
	 * @return array|WP_Error
	 */
	private static function normalize( array $data, ?WP_Post $existing, int $live_id ) {
		$get = static function ( string $key, $fallback ) use ( $data ) {
			return array_key_exists( $key, $data ) ? $data[ $key ] : $fallback;
		};

		$fields = [
			'title'        => sanitize_text_field( (string) $get( 'title', $existing ? $existing->post_title : '' ) ),
			'content'      => self::normalize_headings( (string) $get( 'content', $existing ? $existing->post_content : '' ) ),
			'status'       => (string) $get( 'status', $existing ? $existing->post_status : 'publish' ),
			'author'       => (int) $get( 'author', $existing ? (int) $existing->post_author : get_current_user_id() ),
			'type'         => (string) $get( 'type', self::TYPE_UPDATE ),
			'time'         => $get( 'time', null ),
			'pinned'       => array_key_exists( 'pinned', $data ) ? (bool) $data['pinned'] : null,
			'question_ids' => array_key_exists( 'question_ids', $data ) ? Live_Meta::sanitize_id_list( $data['question_ids'] ) : null,
			'compose_ms'   => array_key_exists( 'compose_ms', $data ) ? absint( $data['compose_ms'] ) : null,
			'label'        => array_key_exists( 'label', $data ) ? trim( (string) $data['label'] ) : null,
		];

		if ( ! in_array( $fields['status'], self::STATUSES, true ) ) {
			return new WP_Error( 'newspack_live_invalid_status', __( 'Invalid entry status.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( self::TYPE_UPDATE !== $fields['type'] ) {
			return new WP_Error( 'newspack_live_invalid_type', __( 'Invalid entry type.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( '' === $fields['title'] && ! self::has_body( $fields['content'] ) ) {
			return new WP_Error( 'newspack_live_validation_empty', __( 'Add a title or some content before publishing.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( $fields['author'] <= 0 || ! get_userdata( $fields['author'] ) ) {
			return new WP_Error( 'newspack_live_invalid_author', __( 'Invalid entry author.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		if ( null !== $fields['time'] ) {
			$fields['time'] = (int) $fields['time'];
			if ( $fields['time'] <= 0 ) {
				return new WP_Error( 'newspack_live_invalid_time', __( 'Invalid entry time.', 'newspack-live' ), [ 'status' => 400 ] );
			}
			if ( $fields['time'] > time() + self::FUTURE_TOLERANCE ) {
				return new WP_Error( 'newspack_live_validation_future', __( "The time can't be in the future.", 'newspack-live' ), [ 'status' => 400 ] );
			}
			// Tolerated skew: never store a future date (core would schedule the post).
			$fields['time'] = min( $fields['time'], time() );
		}
		if ( null !== $fields['question_ids'] && count( $fields['question_ids'] ) > self::MAX_QUESTIONS ) {
			return new WP_Error( 'newspack_live_too_many_questions', __( 'Answer at most 50 questions at once.', 'newspack-live' ), [ 'status' => 400 ] );
		}
		foreach ( (array) $fields['question_ids'] as $question_id ) {
			if ( ! Questions::is_question( $question_id ) || $live_id !== (int) get_post_field( 'post_parent', $question_id ) ) {
				return new WP_Error( 'newspack_live_invalid_question', __( 'A selected question does not belong to this live.', 'newspack-live' ), [ 'status' => 400 ] );
			}
		}
		// Answers carry "Your questions" only: derived, never stored, never replaced.
		$fields['is_answer'] = null !== $fields['question_ids'] ? [] !== $fields['question_ids'] : ( $existing && [] !== self::get_question_ids( $existing->ID ) );
		$fields['label_was'] = $existing ? Labels::sanitize_value( get_post_meta( $existing->ID, Labels::ENTRY_META, true ) ) : '';
		$label_check         = Labels::validate_for_entry( $fields['label'], $live_id, $fields['is_answer'], $fields['label_was'] );
		if ( is_wp_error( $label_check ) ) {
			return $label_check;
		}
		return $fields;
	}

	/**
	 * Writes an entry. Runs inside Live::write() when reader-visible ($rev > 0).
	 *
	 * @param int          $live_id  Live ID.
	 * @param array        $fields   Normalized fields.
	 * @param WP_Post|null $existing Existing entry.
	 * @param int          $rev      New live revision, 0 when not reader-visible.
	 * @return int|WP_Error Entry ID.
	 */
	private static function persist( int $live_id, array $fields, ?WP_Post $existing, int $rev ) {
		// Under the lock: the custom label may have been deleted since normalize() checked it.
		if ( Labels::is_new_custom( $fields['label'], $fields['label_was'] ) ) {
			wp_cache_delete( $live_id, 'post_meta' );
			if ( null === Labels::custom_text( $live_id, (string) $fields['label'] ) ) {
				return new WP_Error( 'newspack_live_invalid_label', __( 'This label was just deleted. Pick another one.', 'newspack-live' ), [ 'status' => 409 ] );
			}
		}
		$postarr = [
			'post_type'    => Post_Types::ENTRY,
			'post_parent'  => $live_id,
			'post_status'  => $fields['status'],
			'post_title'   => $fields['title'],
			'post_content' => $fields['content'],
			'post_author'  => $fields['author'],
		];
		if ( null !== $fields['time'] ) {
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $fields['time'] );
			$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
			$postarr['edit_date']     = true;
		} elseif ( $existing && 'publish' !== $existing->post_status && 'publish' === $fields['status'] && '0000-00-00 00:00:00' !== $existing->post_date_gmt && ! self::has_fixed_time( $existing ) ) {
			// A draft published without an explicit time is published "now".
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
			$postarr['edit_date']     = true;
		}

		self::$writing = true;
		try {
			if ( $existing ) {
				$postarr['ID'] = $existing->ID;
				$entry_id      = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$entry_id = wp_insert_post( wp_slash( $postarr ), true );
			}
			if ( is_wp_error( $entry_id ) ) {
				return $entry_id;
			}
			$entry_id = (int) $entry_id;

			if ( null !== $fields['time'] ) {
				update_post_meta( $entry_id, '_newspack_live_fixed_time', 1 );
			}
			update_post_meta( $entry_id, self::META_TYPE, $fields['type'] );
			if ( null !== $fields['question_ids'] ) {
				update_post_meta( $entry_id, self::META_QUESTION_IDS, $fields['question_ids'] );
			}
			if ( null !== $fields['compose_ms'] ) {
				update_post_meta( $entry_id, self::META_COMPOSE_MS, $fields['compose_ms'] );
			}
			Labels::store( $entry_id, $fields['label'], $fields['is_answer'] );
			if ( null !== $fields['pinned'] ) {
				self::apply_pin( $live_id, $entry_id, $fields['pinned'], $rev );
			}
			if ( 'publish' === $fields['status'] && ! get_post_meta( $entry_id, self::META_PUBLISHED_AT, true ) ) {
				update_post_meta( $entry_id, self::META_PUBLISHED_AT, time() );
			}
			if ( $rev > 0 ) {
				update_post_meta( $entry_id, self::META_REV, $rev );
				if ( $existing && 'publish' === $existing->post_status && 'publish' !== $fields['status'] ) {
					Live::log_removal( $live_id, $entry_id, $rev );
				}
			}
			return $entry_id;
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Whether an entry's time was set explicitly (backdated).
	 *
	 * @param WP_Post $entry Entry.
	 * @return bool
	 */
	private static function has_fixed_time( WP_Post $entry ): bool {
		return (bool) get_post_meta( $entry->ID, '_newspack_live_fixed_time', true );
	}

	/**
	 * Sets or clears the pin; pinning unpins the previously pinned entry of the live.
	 *
	 * @param int  $live_id  Live ID.
	 * @param int  $entry_id Entry ID.
	 * @param bool $pinned   Pinned.
	 * @param int  $rev      Revision stamped on every entry that changes (0 = none).
	 * @return void
	 */
	private static function apply_pin( int $live_id, int $entry_id, bool $pinned, int $rev ): void {
		if ( $pinned ) {
			foreach ( self::get_pinned_ids( $live_id ) as $other_id ) {
				if ( $other_id === $entry_id ) {
					continue;
				}
				update_post_meta( $other_id, self::META_PINNED, 0 );
				if ( $rev > 0 ) {
					update_post_meta( $other_id, self::META_REV, $rev );
				}
			}
		}
		update_post_meta( $entry_id, self::META_PINNED, $pinned ? 1 : 0 );
	}

	/**
	 * Changes the pinned flag of an entry.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $flags    `pinned` boolean.
	 * @return int|WP_Error Entry ID.
	 */
	public static function set_flags( int $entry_id, array $flags ) {
		$entry = get_post( $entry_id );
		if ( ! self::is_entry( $entry ) ) {
			return new WP_Error( 'newspack_live_entry_not_found', __( 'Entry not found.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		$live_id = (int) $entry->post_parent;
		$write   = static function ( int $rev ) use ( $live_id, $entry_id, $flags ) {
			if ( array_key_exists( 'pinned', $flags ) ) {
				self::apply_pin( $live_id, $entry_id, (bool) $flags['pinned'], $rev );
			}
			if ( $rev > 0 ) {
				update_post_meta( $entry_id, self::META_REV, $rev );
			}
			return $entry_id;
		};
		$visible = 'publish' === $entry->post_status || ! empty( $flags['pinned'] );
		return $visible ? Live::write( $live_id, $write ) : $write( 0 );
	}

	/**
	 * Changes the status of an entry: publish, draft, pending or trash.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $status   New status.
	 * @return int|WP_Error Entry ID.
	 */
	public static function transition( int $entry_id, string $status ) {
		$entry = get_post( $entry_id );
		if ( ! self::is_entry( $entry ) ) {
			return new WP_Error( 'newspack_live_entry_not_found', __( 'Entry not found.', 'newspack-live' ), [ 'status' => 404 ] );
		}
		if ( 'trash' !== $status ) {
			return self::save( [ 'status' => $status ], $entry_id );
		}
		$live_id = (int) $entry->post_parent;
		$write   = static function ( int $rev ) use ( $live_id, $entry_id ) {
			self::$writing = true;
			try {
				$trashed = wp_trash_post( $entry_id );
			} finally {
				self::$writing = false;
			}
			if ( ! $trashed ) {
				return new WP_Error( 'newspack_live_trash_failed', __( 'The entry could not be moved to the trash.', 'newspack-live' ), [ 'status' => 500 ] );
			}
			if ( $rev > 0 ) {
				// With the trash disabled, the entry is already deleted: no rev to stamp.
				if ( get_post( $entry_id ) ) {
					update_post_meta( $entry_id, self::META_REV, $rev );
				}
				Live::log_removal( $live_id, $entry_id, $rev );
			}
			return $entry_id;
		};
		return 'publish' === $entry->post_status ? Live::write( $live_id, $write ) : $write( 0 );
	}

	/**
	 * Re-stamps a published entry with a new revision so readers and caches re-render it
	 * (e.g. after a quoted question is erased). Skipped inside a write in
	 * progress (hooks fired by a write): that write stamps the entry itself.
	 *
	 * @param int  $entry_id Entry ID.
	 * @param bool $retry    Whether a busy lock schedules a retry (one-off cron event, bounded).
	 * @return void
	 */
	public static function touch( int $entry_id, bool $retry = false ): void {
		$entry = get_post( $entry_id );
		if ( ! self::is_entry( $entry ) || 'publish' !== $entry->post_status || Live::is_writing() ) {
			delete_post_meta( $entry_id, self::META_TOUCH_PENDING );
			return;
		}
		$result = Live::write(
			(int) $entry->post_parent,
			static function ( int $rev ) use ( $entry_id ) {
				update_post_meta( $entry_id, self::META_REV, $rev );
				return true;
			}
		);
		if ( ! is_wp_error( $result ) ) {
			delete_post_meta( $entry_id, self::META_TOUCH_PENDING );
			return;
		}
		if ( ! $retry ) {
			return;
		}
		$tries = (int) get_post_meta( $entry_id, self::META_TOUCH_PENDING, true );
		update_post_meta( $entry_id, self::META_TOUCH_PENDING, $tries + 1 );
		if ( $tries < 10 && ! wp_next_scheduled( self::TOUCH_EVENT, [ $entry_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::TOUCH_EVENT, [ $entry_id ] );
		}
	}

	/**
	 * Cron: retries a touch that couldn't take the lock.
	 *
	 * @param int $entry_id Entry ID.
	 * @return void
	 */
	public static function retry_touch( $entry_id ): void {
		$entry_id = (int) $entry_id;
		if ( $entry_id > 0 && get_post_meta( $entry_id, self::META_TOUCH_PENDING, true ) ) {
			self::touch( $entry_id, true );
		}
	}

	/**
	 * Catches entry edits made outside Entries::* (full editor, core REST) after meta and terms are saved.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post after the change.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Post before the change.
	 * @return void
	 */
	public static function on_after_insert_post( $post_id, $post, $update, $post_before ): void {
		if ( self::$writing || ! $post instanceof WP_Post || Post_Types::ENTRY !== $post->post_type ) {
			return;
		}
		$was_published = $post_before instanceof WP_Post && 'publish' === $post_before->post_status;
		$is_published  = 'publish' === $post->post_status;
		$live_id       = (int) $post->post_parent;
		if ( ( ! $was_published && ! $is_published ) || ! Live::is_live( $live_id ) ) {
			return;
		}
		$result = Live::write(
			$live_id,
			static function ( int $rev ) use ( $post, $live_id, $was_published, $is_published ) {
				update_post_meta( $post->ID, self::META_REV, $rev );
				if ( $is_published && ! get_post_meta( $post->ID, self::META_PUBLISHED_AT, true ) ) {
					update_post_meta( $post->ID, self::META_PUBLISHED_AT, time() );
				}
				if ( $was_published && ! $is_published ) {
					Live::log_removal( $live_id, $post->ID, $rev );
				}
				return true;
			}
		);
		self::log_failed_write( $result, $live_id );
	}

	/**
	 * Logs a revision write that failed in a core hook (the edit itself is already saved).
	 *
	 * @param mixed $result  Result of Live::write().
	 * @param int   $live_id Live ID.
	 * @return void
	 */
	private static function log_failed_write( $result, int $live_id ): void {
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		$message = sprintf( 'Revision bump failed on live %d: %s', $live_id, $result->get_error_code() );
		if ( class_exists( '\Newspack\Logger' ) ) {
			\Newspack\Logger::log( $message, 'NEWSPACK-LIVE' );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only fallback when Newspack's logger is absent.
		}
	}

	/**
	 * Logs the removal of a published entry deleted permanently.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being deleted.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id, $post ): void {
		// Deletions triggered by Entries::* (trash disabled) are already logged by the caller.
		if ( self::$writing || ! $post instanceof WP_Post || Post_Types::ENTRY !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		$live_id = (int) $post->post_parent;
		if ( ! Live::is_live( $live_id ) ) {
			return;
		}
		$result = Live::write(
			$live_id,
			static function ( int $rev ) use ( $live_id, $post ) {
				Live::log_removal( $live_id, $post->ID, $rev );
				return true;
			}
		);
		self::log_failed_write( $result, $live_id );
	}

	/**
	 * Whether an entry is pinned.
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool
	 */
	public static function is_pinned( int $entry_id ): bool {
		return (bool) get_post_meta( $entry_id, self::META_PINNED, true );
	}

	/**
	 * Revision of the last reader-visible change of an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return int
	 */
	public static function get_rev( int $entry_id ): int {
		return (int) get_post_meta( $entry_id, self::META_REV, true );
	}

	/**
	 * IDs of the questions answered by an entry.
	 *
	 * @param int $entry_id Entry ID.
	 * @return int[]
	 */
	public static function get_question_ids( int $entry_id ): array {
		return Live_Meta::sanitize_id_list( get_post_meta( $entry_id, self::META_QUESTION_IDS, true ) );
	}

	/**
	 * IDs of the published, pinned update entries of a live (normally one).
	 *
	 * @param int $live_id Live ID.
	 * @return int[]
	 */
	public static function get_pinned_ids( int $live_id ): array {
		$ids = get_posts(
			[
				'post_type'              => Post_Types::ENTRY,
				'post_parent'            => $live_id,
				'post_status'            => [ 'publish', 'draft', 'pending' ],
				'posts_per_page'         => 20,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped to the entries of one live (parent + type index).
				'meta_query'             => [
					[
						'key'   => self::META_PINNED,
						'value' => '1',
					],
				],
			]
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Counts entries of a live by status and flags (diagnostics).
	 *
	 * @param int $live_id Live ID.
	 * @return array<string, int>
	 */
	public static function count( int $live_id ): array {
		$counts = [
			'publish' => 0,
			'draft'   => 0,
			'pending' => 0,
			'pinned'  => 0,
			'max_rev' => 0,
		];
		$page   = 1;
		do {
			$ids = get_posts(
				[
					'post_type'           => Post_Types::ENTRY,
					'post_parent'         => $live_id,
					'post_status'         => [ 'publish', 'draft', 'pending' ],
					'posts_per_page'      => 100,
					'paged'               => $page,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'orderby'             => 'ID',
					'order'               => 'ASC',
				]
			);
			if ( $ids ) {
				update_meta_cache( 'post', $ids );
			}
			foreach ( $ids as $id ) {
				$id     = (int) $id;
				$status = get_post_status( $id );
				if ( isset( $counts[ $status ] ) ) {
					++$counts[ $status ];
				}
				if ( 'publish' === $status && self::is_pinned( $id ) ) {
					++$counts['pinned'];
				}
				$counts['max_rev'] = max( $counts['max_rev'], self::get_rev( $id ) );
			}
			++$page;
			$full = 100 === count( $ids );
		} while ( $full && $page <= 100 );
		return $counts;
	}
}
