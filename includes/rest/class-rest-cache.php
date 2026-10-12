<?php
/**
 * Cache headers of the plugin's REST routes.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\REST;

use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Sets Cache-Control on `newspack-live/v1` responses:
 * anonymous GETs are publicly cacheable, authenticated requests and writes never are.
 */
final class REST_Cache {

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'set_headers' ], 10, 3 );
	}

	/**
	 * Cache-Control value for a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $status  HTTP status.
	 * @return string|null Null for routes that aren't ours.
	 */
	public static function get_cache_control( WP_REST_Request $request, int $status ): ?string {
		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/' . REST_Public::NAMESPACE . '/' ) ) {
			return null;
		}
		$gated = preg_match( '#/lives/(\d+)/entries$#', $route, $match ) && \Newspack_Live\Renderer::is_gated( (int) $match[1] );
		if ( 'GET' !== $request->get_method() || $status >= 400 || is_user_logged_in() || $gated ) {
			return 'no-store' === self::private_value( $request ) ? 'no-store' : 'private, no-store';
		}
		if ( preg_match( '#/lives/\d+/status$#', $route ) ) {
			return 'public, max-age=60, s-maxage=60';
		}
		if ( preg_match( '#/lives/\d+/entries$#', $route ) ) {
			if ( null !== $request['since'] || null !== $request['until'] ) {
				return 'public, max-age=300, s-maxage=300';
			}
			return 'public, max-age=60, s-maxage=60';
		}
		return 'no-store';
	}

	/**
	 * Value for requests that are never cached: private routes get `no-store`, public ones `private, no-store`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function private_value( WP_REST_Request $request ): string {
		$route  = $request->get_route();
		$public = preg_match( '#/lives/\d+/(status|entries)$#', $route ) || ( preg_match( '#/lives/\d+/questions$#', $route ) && 'POST' === $request->get_method() );
		return $public ? 'private, no-store' : 'no-store';
	}

	/**
	 * Adds the header to our responses.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function set_headers( $response, $server, $request ) {
		if ( ! $response instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$value = self::get_cache_control( $request, (int) $response->get_status() );
		if ( null !== $value ) {
			$response->header( 'Cache-Control', $value );
		}
		return $response;
	}
}
