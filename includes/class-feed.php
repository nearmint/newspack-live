<?php
/**
 * Feed queries: pages, cursors, pinned entry.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Entry queries of one live, always scoped by post type + parent + status.
 *
 * The feed contains published `update` entries ordered by `post_date_gmt` then ID. The pinned
 * entry is rendered in its own container.
 */
final class Feed {

	/**
	 * Default page size: the first render and each "Load older updates" page.
	 */
	public const PER_PAGE = 15;

	/**
	 * Maximum page size of REST requests.
	 */
	public const MAX_PER_PAGE = 50;

	/**
	 * Most items a console refresh returns for a list paged down to a cursor.
	 */
	public const THROUGH_LIMIT = 500;

	/**
	 * Query var holding an exact feed keyset bound: `[ post_date_gmt, ID, 'before'|'after' ]`.
	 */
	private const KEYSET_VAR = 'newspack_live_keyset';

	/**
	 * Query var marking plugin queries ordered by `post_date_gmt`.
	 */
	private const ORDER_VAR = 'newspack_live_gmt_order';

	/**
	 * Request-local memo of per-live lookups, keyed by live ID and revision.
	 *
	 * @var array<string, mixed>
	 */
	private static array $memo = [];

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'posts_orderby', [ __CLASS__, 'order_by_gmt' ], 10, 2 );
		add_filter( 'posts_where', [ __CLASS__, 'keyset_where' ], 10, 2 );
	}

	/**
	 * Restricts plugin entry queries to one side of a `(post_date_gmt, ID)` key, exactly: entries
	 * sharing the bound's second are split by ID, however many there are (no fetch slack). A bound
	 * without a date compares the ID alone (console drafts).
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public static function keyset_where( $where, $query ) {
		$bound = $query instanceof \WP_Query ? $query->get( self::KEYSET_VAR ) : null;
		if ( ! is_array( $bound ) || 3 !== count( $bound ) ) {
			return $where;
		}
		global $wpdb;
		[ $gmt, $id, $side ] = $bound;
		$operator            = 'after' === $side ? '>' : '<';
		if ( null === $gmt ) {
			// ID-only keyset (console drafts): IDs never change between refreshes.
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID {$operator} %d", (int) $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a fixed operator.
		}
		return $where . $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_date_gmt {$operator} %s OR ( {$wpdb->posts}.post_date_gmt = %s AND {$wpdb->posts}.ID {$operator} %d ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a fixed operator.
			(string) $gmt,
			(string) $gmt,
			(int) $id
		);
	}

	/**
	 * Orders plugin entry queries by `post_date_gmt` then ID, matching the cursor boundaries
	 * (ordering by the local `post_date` would misplace entries around DST changes), or by ID alone
	 * (`ID ASC` / `ID DESC`, console drafts).
	 *
	 * @param string    $orderby ORDER BY clause.
	 * @param \WP_Query $query   Query.
	 * @return string
	 */
	public static function order_by_gmt( $orderby, $query ) {
		$order = $query instanceof \WP_Query ? $query->get( self::ORDER_VAR ) : '';
		global $wpdb;
		if ( 'ID ASC' === $order || 'ID DESC' === $order ) {
			return "{$wpdb->posts}.{$order}";
		}
		if ( 'ASC' !== $order && 'DESC' !== $order ) {
			return $orderby;
		}
		return "{$wpdb->posts}.post_date_gmt {$order}, {$wpdb->posts}.ID {$order}";
	}

	/**
	 * Clears the request-local memo (tests, long-running processes).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = [];
	}

	/**
	 * Memoizes a per-live lookup for the current revision.
	 *
	 * @param string   $name     Lookup name.
	 * @param int      $live_id  Live ID.
	 * @param callable $callback Lookup.
	 * @return mixed
	 */
	private static function memo( string $name, int $live_id, callable $callback ) {
		$key = get_current_blog_id() . ':' . $name . ':' . $live_id . ':' . Live::get_rev( $live_id );
		if ( ! array_key_exists( $key, self::$memo ) ) {
			self::$memo[ $key ] = $callback();
		}
		return self::$memo[ $key ];
	}

	/**
	 * Page size, filterable.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		/**
		 * Filters how many entries the live page shows at first render and per "Load older updates"
		 * page (the pinned entry doesn't count).
		 *
		 * @since 1.2.0
		 *
		 * @param int $per_page Entries per page. Default 15.
		 */
		$per_page = (int) apply_filters( 'newspack_live_per_page', self::PER_PAGE );
		return max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}

	/**
	 * ID of the published pinned entry of a live, or 0.
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	public static function get_pinned_id( int $live_id ): int {
		return (int) self::memo( 'pinned', $live_id, static fn() => self::find_pinned_id( $live_id ) );
	}

	/**
	 * Finds the published pinned update of a live.
	 *
	 * @param int $live_id Live ID.
	 * @return int
	 */
	private static function find_pinned_id( int $live_id ): int {
		foreach ( Entries::get_pinned_ids( $live_id ) as $entry_id ) {
			if ( 'publish' === get_post_status( $entry_id ) ) {
				return $entry_id;
			}
		}
		return 0;
	}

	/**
	 * Number of published updates before an entry in feed order.
	 *
	 * @param int $live_id  Live ID.
	 * @param int $entry_id Entry ID.
	 * @return int
	 */
	public static function count_older( int $live_id, int $entry_id ): int {
		$entry = get_post( $entry_id );
		if ( ! $entry ) {
			return 0;
		}
		$older = new \WP_Query(
			[
				'post_type'              => Post_Types::ENTRY,
				'post_parent'            => $live_id,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				self::KEYSET_VAR         => [ $entry->post_date_gmt, (int) $entry->ID, 'before' ],
			]
		);
		return (int) $older->found_posts;
	}

	/**
	 * Entry IDs excluded from the feed list: the pinned entry.
	 *
	 * @param int $live_id Live ID.
	 * @return int[]
	 */
	public static function get_excluded_ids( int $live_id ): array {
		return array_values( array_filter( [ self::get_pinned_id( $live_id ) ] ) );
	}

	/**
	 * A page of the feed.
	 *
	 * @param int    $live_id  Live ID.
	 * @param string $order    `desc` (newest first) or `asc`.
	 * @param int    $cursor   Entry ID to continue after (in the given order), 0 for the first page.
	 * @param int    $per_page Page size.
	 * @param bool   $exclude_pinned Whether the pinned entry is left out (it has its own container on newest-first views).
	 * @return array{ids: int[], has_more: bool}
	 */
	public static function get_page( int $live_id, string $order = 'desc', int $cursor = 0, int $per_page = 0, bool $exclude_pinned = true ): array {
		$per_page = $per_page > 0 ? min( $per_page, self::MAX_PER_PAGE ) : self::per_page();
		$order    = 'asc' === $order ? 'asc' : 'desc';
		$excluded = $exclude_pinned ? self::get_excluded_ids( $live_id ) : [];
		// The pinned entry is filtered in PHP: one extra row.
		$args = [
			'posts_per_page' => $per_page + 1 + count( $excluded ),
			'order'          => strtoupper( $order ),
		];

		$cursor_post = $cursor > 0 ? get_post( $cursor ) : null;
		if ( $cursor_post instanceof WP_Post && Entries::is_entry( $cursor_post ) && (int) $cursor_post->post_parent === $live_id ) {
			$args[ self::KEYSET_VAR ] = [ $cursor_post->post_date_gmt, (int) $cursor_post->ID, 'desc' === $order ? 'before' : 'after' ];
			$ids                      = self::query_ids( $live_id, $args );
		} elseif ( $cursor > 0 ) {
			return [
				'ids'      => [],
				'has_more' => false,
			];
		} else {
			$ids = self::query_ids( $live_id, $args );
		}

		$ids = array_values( array_diff( $ids, $excluded ) );
		return [
			'ids'      => array_slice( $ids, 0, $per_page ),
			'has_more' => count( $ids ) > $per_page,
		];
	}

	/**
	 * Query arguments that order by `(post_date_gmt, ID)` and, with a cursor, keep one side of it
	 * (the reader feed's keyset, reused by the console lists). Works for any post type.
	 *
	 * @param \WP_Post|null $cursor    Cursor post, null for the first page.
	 * @param string        $side      `before` (older, for newest-first lists) or `after`.
	 * @param bool          $inclusive Whether the cursor itself is included.
	 * @param string        $order     `DESC` or `ASC` (default: newest first before the cursor, oldest first after it).
	 * @param bool          $by_id     Whether the key is the ID alone (lists whose dates can change, such as drafts).
	 * @return array
	 */
	public static function keyset_args( ?\WP_Post $cursor, string $side = 'before', bool $inclusive = false, string $order = '', bool $by_id = false ): array {
		$order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : ( 'before' === $side ? 'DESC' : 'ASC' );
		$args  = [
			'orderby'       => $by_id ? 'ID' : 'date',
			'order'         => $order,
			self::ORDER_VAR => $by_id ? 'ID ' . $order : $order,
		];
		if ( $cursor ) {
			$id = (int) $cursor->ID;
			if ( $inclusive ) {
				$id += 'after' === $side ? -1 : 1;
			}
			$args[ self::KEYSET_VAR ] = [ $by_id ? null : $cursor->post_date_gmt, $id, 'after' === $side ? 'after' : 'before' ];
		}
		return $args;
	}

	/**
	 * Every post of a query from the newest down to a cursor (included), newest first, read in
	 * chunks of 100 on the keyset (`posts_per_page` ≤ 100), at most `$limit` posts.
	 * `has_more` is true only when another post exists after the last one returned (older than the cursor, or past the limit:
	 * the caller's "Load more" then continues from the last post returned, so nothing is skipped and
	 * the button never leads to an empty page).
	 *
	 * @param array    $args    WP_Query arguments (type, parent, statuses, filters).
	 * @param \WP_Post $cursor  Cursor post.
	 * @param int      $limit   Most posts returned.
	 * @param bool     $by_id   Whether the key is the ID alone (see keyset_args()).
	 * @return array{posts: \WP_Post[], has_more: bool}
	 */
	public static function posts_down_to( array $args, \WP_Post $cursor, int $limit = self::THROUGH_LIMIT, bool $by_id = false ): array {
		$key   = static fn( \WP_Post $post ): array => $by_id ? [ (int) $post->ID ] : [ (string) $post->post_date_gmt, (int) $post->ID ];
		$bound = $key( $cursor );
		$posts = [];
		$last  = null;
		$full  = false;
		while ( true ) {
			$query = new \WP_Query(
				array_merge(
					$args,
					[
						'posts_per_page' => 100,
						'fields'         => '',
					],
					self::keyset_args( $last, 'before', false, '', $by_id ) 
				) 
			);
			foreach ( $query->posts as $post ) {
				// Past the cursor or the limit: one more post exists after the last one returned.
				if ( $full || $key( $post ) < $bound ) {
					return [
						'posts'    => $posts,
						'has_more' => true,
					];
				}
				$posts[] = $post;
				$full    = count( $posts ) >= $limit || $key( $post ) === $bound;
			}
			if ( count( $query->posts ) < 100 ) {
				return [
					'posts'    => $posts,
					'has_more' => false,
				];
			}
			$last = end( $query->posts );
		}
	}

	/**
	 * A page of a live's entries in some statuses (console drafts), newest first by ID (a draft's
	 * date can change between refreshes when its time is set, its ID can't): older than the
	 * cursor, or (`$through`) everything from the newest down to the cursor included, to refresh
	 * pages already loaded.
	 *
	 * @param int      $live_id  Live ID.
	 * @param string[] $statuses Post statuses.
	 * @param int      $author   Only this author's entries (0: everyone).
	 * @param int      $cursor   Cursor entry ID (0: first page).
	 * @param bool     $through  Whether to return everything down to the cursor (refresh).
	 * @param int      $per_page Page size (pages after the cursor).
	 * @return array{ids: int[], has_more: bool}
	 */
	public static function get_status_page( int $live_id, array $statuses, int $author = 0, int $cursor = 0, bool $through = false, int $per_page = self::MAX_PER_PAGE ): array {
		$cursor_post = $cursor > 0 ? get_post( $cursor ) : null;
		if ( ! $cursor_post instanceof WP_Post || ! Entries::is_entry( $cursor_post ) || (int) $cursor_post->post_parent !== $live_id ) {
			if ( $cursor > 0 && ! $through ) {
				return [
					'ids'      => [],
					'has_more' => false,
				];
			}
			$cursor_post = null;
			$through     = false;
		}
		$base = [
			'post_type'              => Post_Types::ENTRY,
			'post_parent'            => $live_id,
			'post_status'            => $statuses,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		];
		if ( $author > 0 ) {
			$base['author'] = $author;
		}
		if ( $through && $cursor_post ) {
			$found = self::posts_down_to( $base, $cursor_post, self::THROUGH_LIMIT, true );
			return [
				'ids'      => array_map( static fn( WP_Post $post ): int => (int) $post->ID, $found['posts'] ),
				'has_more' => $found['has_more'],
			];
		}
		// One more than a page: `has_more` only when that item exists.
		$query = new \WP_Query( array_merge( $base, [ 'posts_per_page' => $per_page + 1 ], self::keyset_args( $cursor_post, 'before', false, '', true ) ) );
		$ids   = array_map( 'intval', $query->posts );
		return [
			'ids'      => array_slice( $ids, 0, $per_page ),
			'has_more' => count( $ids ) > $per_page,
		];
	}

	/**
	 * The window around an entry: up to 10 newer entries, the entry, up to 9 older ones.
	 *
	 * @param int $live_id  Live ID.
	 * @param int $entry_id Target entry.
	 * @return array{ids: int[], has_more: bool, has_newer: bool}|null Null when the entry isn't in the feed.
	 */
	public static function get_window( int $live_id, int $entry_id ): ?array {
		$entry = get_post( $entry_id );
		if ( ! Entries::is_entry( $entry ) || (int) $entry->post_parent !== $live_id || 'publish' !== $entry->post_status || in_array( $entry_id, self::get_excluded_ids( $live_id ), true ) ) {
			return null;
		}
		$newer = self::get_page( $live_id, 'asc', $entry_id, 10 );
		$older = self::get_page( $live_id, 'desc', $entry_id, 9 );
		return [
			'ids'       => array_merge( array_reverse( $newer['ids'] ), [ $entry_id ], $older['ids'] ),
			'has_more'  => $older['has_more'],
			'has_newer' => [] !== $newer['ids'],
		];
	}

	/**
	 * Runs a bounded ID query on the published entries of a live.
	 *
	 * @param int   $live_id Live ID.
	 * @param array $args    Extra WP_Query arguments.
	 * @return int[]
	 */
	private static function query_ids( int $live_id, array $args ): array {
		$order = isset( $args['order'] ) && 'ASC' === $args['order'] ? 'ASC' : 'DESC';
		unset( $args['order'] );
		$query = new \WP_Query(
			array_merge(
				[
					'post_type'              => Post_Types::ENTRY,
					'post_parent'            => $live_id,
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
					'orderby'                => 'date',
					'order'                  => $order,
					self::ORDER_VAR          => $order,
				],
				$args
			)
		);
		return array_map( 'intval', $query->posts );
	}
}
