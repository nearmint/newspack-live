<?php
/**
 * Asset recording for HTML inserted after load.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Records the style and script handles enqueued while rendering entries for a REST response,
 * so the view module can load what inserted HTML needs.
 */
final class Assets {

	/**
	 * Snapshot of the style and script queues.
	 *
	 * @return array{styles: string[], scripts: string[]}
	 */
	public static function snapshot(): array {
		return [
			'styles'  => (array) wp_styles()->queue,
			'scripts' => (array) wp_scripts()->queue,
		];
	}

	/**
	 * Handles enqueued since a snapshot.
	 *
	 * @param array $before Snapshot from snapshot().
	 * @return array{styles: string[], scripts: string[]}
	 */
	public static function diff( array $before ): array {
		$after = self::snapshot();
		return [
			'styles'  => array_values( array_diff( $after['styles'], $before['styles'] ) ),
			'scripts' => array_values( array_diff( $after['scripts'], $before['scripts'] ) ),
		];
	}

	/**
	 * Enqueues recorded handles again (used when an entry comes from the cache).
	 *
	 * @param array $handles `styles` and `scripts` handles.
	 * @return void
	 */
	public static function enqueue( array $handles ): void {
		foreach ( $handles['styles'] ?? [] as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( $handles['scripts'] ?? [] as $handle ) {
			wp_enqueue_script( $handle );
		}
	}

	/**
	 * Resolves handles and their dependencies to `{handle, type, src}` items, in load order, with
	 * their inline code when they have some: `data` (`wp_localize_script()`), `before` and `after`
	 * (`wp_add_inline_script()`, `wp_add_inline_style()`). Inline-only handles (no `src`) are listed
	 * for their inline code.
	 *
	 * @param array $handles `styles` and `scripts` handles.
	 * @return array<int, array{handle: string, type: string, src: string, data?: string, before?: string, after?: string}>
	 */
	public static function describe( array $handles ): array {
		$items = [];
		foreach ( [
			'style'  => wp_styles(),
			'script' => wp_scripts(),
		] as $type => $registry ) {
			$list = $handles[ 'style' === $type ? 'styles' : 'scripts' ] ?? [];
			if ( ! $list ) {
				continue;
			}
			// Resolve dependencies in load order without printing anything.
			$resolver = clone $registry;

			$resolver->to_do = [];
			$resolver->done  = [];
			$resolver->all_deps( $list );
			foreach ( $resolver->to_do as $handle ) {
				$handle = (string) $handle;
				$item   = [
					'handle' => $handle,
					'type'   => $type,
					'src'    => self::src( $registry, $handle ),
				] + self::inline( $registry, $type, $handle );
				if ( '' !== $item['src'] || count( $item ) > 3 ) {
					$items[] = $item;
				}
			}
		}
		return $items;
	}

	/**
	 * Inline code attached to a handle, as WordPress prints it around the tag.
	 *
	 * @param \WP_Dependencies $registry Styles or scripts registry.
	 * @param string           $type     `style` or `script`.
	 * @param string           $handle   Handle.
	 * @return array{data?: string, translations?: string, before?: string, after?: string}
	 */
	private static function inline( \WP_Dependencies $registry, string $type, string $handle ): array {
		$parts = [];
		if ( 'script' === $type ) {
			$data = $registry->get_data( $handle, 'data' );
			if ( is_string( $data ) && '' !== trim( $data ) ) {
				$parts['data'] = $data;
			}
			if ( $registry instanceof \WP_Scripts ) {
				$translations = $registry->print_translations( $handle, false );
				if ( is_string( $translations ) && '' !== trim( $translations ) ) {
					$parts['translations'] = $translations;
				}
			}
		}
		foreach ( 'script' === $type ? [ 'before', 'after' ] : [ 'after' ] as $position ) {
			$code = $registry->get_data( $handle, $position );
			$code = is_array( $code ) ? implode( "\n", array_filter( $code, 'is_string' ) ) : '';
			if ( '' !== trim( $code ) ) {
				$parts[ $position ] = $code;
			}
		}
		return $parts;
	}

	/**
	 * Whether recorded asset lists may be cached: `wp_enqueue_scripts` has run (page renders after
	 * `wp_head`, REST after register_front_assets()), so they are complete.
	 *
	 * @return bool
	 */
	public static function can_cache_recordings(): bool {
		return did_action( 'wp_enqueue_scripts' ) > 0 || doing_action( 'wp_enqueue_scripts' );
	}

	/**
	 * Called by the REST routes before they render entries: fires `wp_enqueue_scripts` once, where
	 * themes and plugins usually register their front-end assets. A handle enqueued before it is
	 * registered isn't queued (WordPress keeps it aside until registration), so it would be missing
	 * from the recorded assets. Only the registrations are kept: the queues are restored, so what the
	 * action enqueues (never printed by a REST response) doesn't hide what entries enqueue. Never
	 * called during page renders: WordPress fires the action itself there (block themes render the
	 * template before `wp_head`, see Renderer::render_entry()).
	 *
	 * @return void
	 */
	public static function register_front_assets(): void {
		if ( did_action( 'wp_enqueue_scripts' ) || doing_action( 'wp_enqueue_scripts' ) ) {
			return;
		}
		$styles  = wp_styles()->queue;
		$scripts = wp_scripts()->queue;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core action.
		do_action( 'wp_enqueue_scripts' );
		wp_styles()->queue  = $styles;
		wp_scripts()->queue = $scripts;
	}

	/**
	 * Absolute, versioned URL of a registered asset.
	 *
	 * @param \WP_Dependencies $registry Styles or scripts registry.
	 * @param string           $handle   Handle.
	 * @return string Empty for inline-only or unknown handles.
	 */
	private static function src( \WP_Dependencies $registry, string $handle ): string {
		$dependency = $registry->registered[ $handle ] ?? null;
		if ( ! $dependency || ! is_string( $dependency->src ) || '' === $dependency->src ) {
			return '';
		}
		$src = $dependency->src;
		if ( str_starts_with( $src, '//' ) ) {
			$src = set_url_scheme( $src );
		} elseif ( ! preg_match( '#^https?://#', $src ) ) {
			$src = $registry->base_url . $src;
		}
		$version = null === $dependency->ver ? '' : ( false === $dependency->ver ? $registry->default_version : (string) $dependency->ver );
		if ( '' !== $version ) {
			$src = add_query_arg( 'ver', rawurlencode( $version ), $src );
		}
		return esc_url_raw( $src );
	}
}
