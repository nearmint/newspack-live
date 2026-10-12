<?php
/**
 * Structured data, canonical URLs, entry Open Graph and modified dates.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * SEO for live pages.
 */
final class SEO {

	/**
	 * Updates listed in `liveBlogUpdate`.
	 */
	public const UPDATES = 20;

	/**
	 * Seconds between two bumps of the live's `post_modified`.
	 */
	public const MODIFIED_THROTTLE = 60;

	/**
	 * Throttle meta of the modified date bump.
	 */
	public const META_BUMPED_AT = '_newspack_live_modified_bumped_at';

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'newspack_live_updated', [ __CLASS__, 'bump_modified' ] );
		add_action( 'wp_head', [ __CLASS__, 'print_head' ], 5 );
		add_filter( 'get_canonical_url', [ __CLASS__, 'canonical_url' ], 10, 2 );
		add_filter( 'wpseo_canonical', [ __CLASS__, 'yoast_canonical' ] );
		add_filter( 'wpseo_schema_article_type', [ __CLASS__, 'yoast_article_type' ], 10, 2 );
		add_filter( 'wpseo_schema_article', [ __CLASS__, 'yoast_article' ] );
		add_filter( 'wpseo_opengraph_title', [ __CLASS__, 'yoast_entry_title' ] );
		add_filter( 'wpseo_twitter_title', [ __CLASS__, 'yoast_entry_title' ] );
		add_filter( 'wpseo_opengraph_desc', [ __CLASS__, 'yoast_entry_description' ] );
		add_filter( 'wpseo_twitter_description', [ __CLASS__, 'yoast_entry_description' ] );
		add_filter( 'wpseo_opengraph_url', [ __CLASS__, 'yoast_entry_url' ] );
	}

	/**
	 * Whether SEO output is enabled (filter, default true).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether Newspack Live prints SEO output (JSON-LD, canonical and Open Graph tweaks).
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'newspack_live_seo_enabled', true );
	}

	/**
	 * Whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function has_yoast(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Whether another plugin prints Open Graph tags (Yoast, Jetpack).
	 *
	 * @return bool
	 */
	public static function has_open_graph_plugin(): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Jetpack's filter.
		return self::has_yoast() || ( class_exists( '\Jetpack' ) && (bool) apply_filters( 'jetpack_enable_open_graph', false ) );
	}

	/**
	 * Current live of a singular request, or 0.
	 *
	 * @return int
	 */
	private static function current_live(): int {
		return self::enabled() ? Templates::get_current_live_id() : 0;
	}

	/**
	 * Prints JSON-LD (unless Yoast handles it) and the entry Open Graph tags.
	 *
	 * @return void
	 */
	public static function print_head(): void {
		$live_id = self::current_live();
		if ( ! $live_id || post_password_required( $live_id ) ) {
			return;
		}
		if ( ! self::has_yoast() ) {
			printf(
				'<script type="application/ld+json" class="newspack-live-schema">%s</script>' . "\n",
				wp_json_encode( self::schema( $live_id ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with JSON_HEX_TAG (no "</script>" possible).
			);
		}
		self::print_entry_open_graph( $live_id );
	}

	/**
	 * ISO 8601 date of a timestamp.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	private static function iso( int $timestamp ): string {
		return gmdate( 'c', $timestamp );
	}

	/**
	 * Hosts as schema.org persons.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function persons( int $post_id ): array {
		$persons = [];
		foreach ( Integrations\Co_Authors_Plus::get_hosts( $post_id ) as $host ) {
			$persons[] = array_filter(
				[
					'@type' => 'Person',
					'name'  => Content::plain_text( (string) $host['name'] ),
					'url'   => $host['url'],
				]
			);
		}
		return $persons;
	}

	/**
	 * The `LiveBlogPosting` object of a live.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	public static function schema( int $live_id ): array {
		$post    = get_post( $live_id );
		$image   = get_the_post_thumbnail_url( $live_id, 'full' );
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$schema  = [
			'@context'      => 'https://schema.org',
			'@type'         => 'LiveBlogPosting',
			'headline'      => Content::plain_text( (string) get_post_field( 'post_title', $live_id ) ),
			'description'   => Renderer::get_standfirst( $live_id ),
			'url'           => (string) get_permalink( $live_id ),
			'datePublished' => self::iso( (int) strtotime( $post->post_date_gmt . ' UTC' ) ),
			'dateModified'  => self::iso( Live::get_last_update( $live_id ) ),
			'author'        => self::persons( $live_id ),
			'publisher'     => array_filter(
				[
					'@type' => 'Organization',
					'name'  => get_bloginfo( 'name' ),
					'logo'  => $logo_id ? [
						'@type' => 'ImageObject',
						'url'   => (string) wp_get_attachment_image_url( $logo_id, 'full' ),
					] : null,
				]
			),
		] + self::coverage( $live_id );
		if ( '' === $schema['description'] ) {
			unset( $schema['description'] );
		}
		if ( $image ) {
			$schema['image'] = [ $image ];
		}
		// Gated content isn't listed for readers who can't read it.
		if ( ! self::hides_updates( $live_id ) ) {
			$schema['liveBlogUpdate'] = self::updates( $live_id );
		}
		return $schema;
	}

	/**
	 * Coverage times: start, and end once the live has ended.
	 *
	 * @param int $live_id Live ID.
	 * @return array{coverageStartTime: string, coverageEndTime?: string}
	 */
	private static function coverage( int $live_id ): array {
		$coverage = [ 'coverageStartTime' => self::iso( Live::get_start( $live_id ) ) ];
		if ( Live::STATE_ENDED === Live::get_state( $live_id ) ) {
			$end                         = Live::get_end( $live_id );
			$coverage['coverageEndTime'] = self::iso( $end ? $end : Live::get_auto_end_at( $live_id ) );
		}
		return $coverage;
	}

	/**
	 * The latest published updates as `BlogPosting` objects.
	 *
	 * @param int $live_id Live ID.
	 * @return array
	 */
	private static function updates( int $live_id ): array {
		$page = Feed::get_page( $live_id, 'desc', 0, self::UPDATES, false );
		Renderer::prime_entries( $page['ids'] );
		$updates     = [];
		$show_author = (bool) Live::get_settings( $live_id )['show_entry_author'];
		foreach ( $page['ids'] as $entry_id ) {
			$entry     = get_post( $entry_id );
			$text      = Content::plain_text( $entry->post_content );
			$author    = $show_author ? get_userdata( (int) $entry->post_author ) : false;
			$modified  = (int) strtotime( $entry->post_modified_gmt . ' UTC' );
			$updates[] = array_filter(
				[
					'@type'         => 'BlogPosting',
					'headline'      => '' !== $entry->post_title ? Content::plain_text( $entry->post_title ) : Content::plain_text( $entry->post_content, 110 ),
					'datePublished' => self::iso( Renderer::entry_time( $entry ) ),
					'dateModified'  => self::iso( $modified ),
					'articleBody'   => mb_substr( $text, 0, 500 ),
					'url'           => Renderer::entry_permalink( $live_id, $entry_id ),
					'author'        => $author ? [
						'@type' => 'Person',
						'name'  => Content::plain_text( $author->display_name ),
					] : null,
				]
			);
		}
		return $updates;
	}

	/**
	 * Open Graph and Twitter tags for an entry permalink, when no SEO plugin prints them.
	 *
	 * @param int $live_id Live ID.
	 * @return void
	 */
	private static function print_entry_open_graph( int $live_id ): void {
		$entry = self::entry_open_graph( $live_id );
		if ( ! $entry || self::has_open_graph_plugin() ) {
			return;
		}
		foreach (
			[
				'og:title'            => $entry['title'],
				'og:description'      => $entry['description'],
				'og:url'              => $entry['url'],
				'twitter:title'       => $entry['title'],
				'twitter:description' => $entry['description'],
			] as $property => $content
		) {
			$attribute = str_starts_with( $property, 'og:' ) ? 'property' : 'name';
			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $attribute ), esc_attr( $property ), esc_attr( $content ) );
		}
	}

	/**
	 * Whether update content must stay out of SEO output: gated (Content Gate) or password-protected
	 * lives, for readers who can't read them.
	 *
	 * @param int $live_id Live ID.
	 * @return bool
	 */
	public static function hides_updates( int $live_id ): bool {
		return Renderer::is_restricted( $live_id ) || post_password_required( $live_id );
	}

	/**
	 * Title, description and URL of the entry an entry permalink (`?live-entry=`) shares, or null
	 * (not an entry page, unpublished entry, gated live).
	 *
	 * @param int $live_id Live ID.
	 * @return array{title: string, description: string, url: string}|null
	 */
	public static function entry_open_graph( int $live_id ): ?array {
		$entry_id = absint( get_query_var( 'live-entry' ) );
		$entry    = $entry_id ? get_post( $entry_id ) : null;
		if ( ! $entry instanceof WP_Post || ! Entries::is_entry( $entry ) || (int) $entry->post_parent !== $live_id || 'publish' !== $entry->post_status || self::hides_updates( $live_id ) ) {
			return null;
		}
		return [
			'title'       => '' !== $entry->post_title ? Content::plain_text( $entry->post_title ) : Content::plain_text( $entry->post_content, 110 ),
			'description' => Content::plain_text( $entry->post_content, 200 ),
			'url'         => Renderer::entry_permalink( $live_id, $entry_id ),
		];
	}

	/**
	 * Yoast: the entry's title in Open Graph and Twitter tags of an entry permalink.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function yoast_entry_title( $title ) {
		$entry = self::current_live() ? self::entry_open_graph( self::current_live() ) : null;
		return $entry ? $entry['title'] : $title;
	}

	/**
	 * Yoast: the entry's text as Open Graph and Twitter description of an entry permalink.
	 *
	 * @param string $description Description.
	 * @return string
	 */
	public static function yoast_entry_description( $description ) {
		$entry = self::current_live() ? self::entry_open_graph( self::current_live() ) : null;
		return $entry && '' !== $entry['description'] ? $entry['description'] : $description;
	}

	/**
	 * Yoast: the entry permalink as `og:url` of an entry permalink.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function yoast_entry_url( $url ) {
		$entry = self::current_live() ? self::entry_open_graph( self::current_live() ) : null;
		return $entry ? $entry['url'] : $url;
	}

	/**
	 * Canonical URL: cursor pages point to themselves; entry permalinks keep the live URL.
	 *
	 * @param string  $url  Canonical URL.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function canonical_url( $url, $post ) {
		if ( ! $post instanceof WP_Post || $post->ID !== self::current_live() ) {
			return $url;
		}
		$view = Renderer::get_view( $post->ID );
		if ( 'before' === $view['type'] ) {
			return add_query_arg( 'live-before', $view['cursor'], (string) get_permalink( $post ) );
		}
		return $url;
	}

	/**
	 * Same canonical rule for Yoast.
	 *
	 * @param string $url Canonical URL.
	 * @return string
	 */
	public static function yoast_canonical( $url ) {
		$live_id = self::current_live();
		return $live_id ? self::canonical_url( $url, get_post( $live_id ) ) : $url;
	}

	/**
	 * Yoast: live pages are `LiveBlogPosting` articles (untested in the sandbox).
	 *
	 * @param string|string[] $type      Article type.
	 * @param mixed           $indexable Yoast indexable (object with `object_id`) or post ID.
	 * @return string|string[]
	 */
	public static function yoast_article_type( $type, $indexable = null ) {
		$post_id = is_object( $indexable ) && isset( $indexable->object_id ) ? (int) $indexable->object_id : ( is_numeric( $indexable ) ? (int) $indexable : self::current_live() );
		return $post_id && Live::is_live( $post_id ) && self::enabled() ? 'LiveBlogPosting' : $type;
	}

	/**
	 * Yoast: adds the coverage times and the latest updates (`liveBlogUpdate`, unless the live is
	 * gated) to the article piece, which Yoast types `LiveBlogPosting`.
	 *
	 * @param array $data Article piece.
	 * @return array
	 */
	public static function yoast_article( $data ) {
		$live_id = self::current_live();
		if ( ! $live_id || ! is_array( $data ) ) {
			return $data;
		}
		$data = array_merge( $data, self::coverage( $live_id ) );
		if ( ! self::hides_updates( $live_id ) ) {
			$data['liveBlogUpdate'] = self::updates( $live_id );
		}
		return $data;
	}

	/**
	 * Moves the live's modified date to now after an update, at most once a minute, without
	 * creating a revision or firing save hooks.
	 *
	 * @param int $live_id Live ID.
	 * @return void
	 */
	public static function bump_modified( $live_id ): void {
		global $wpdb;
		$live_id = (int) $live_id;
		$last = (int) get_post_meta( $live_id, self::META_BUMPED_AT, true );
		if ( time() - $last < self::MODIFIED_THROTTLE ) {
			return;
		}
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- throttled modified-date bump without revisions or hooks, cache cleaned below.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			],
			[ 'ID' => $live_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		clean_post_cache( $live_id );
		update_post_meta( $live_id, self::META_BUMPED_AT, time() );
	}
}
