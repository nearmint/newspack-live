<?php
/**
 * Who's online in the console, through the WordPress Heartbeat API.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

defined( 'ABSPATH' ) || exit;

/**
 * Presence is kept in short-TTL transients (the object cache when there is one), never in post or
 * user meta: each tick refreshes the user's own transient (no contention between editors), and a
 * per-live index of user IDs is written only when someone joins or leaves (or the index is about
 * to expire). A user drops off when their transient expires (60 s without a tick) or at once when
 * the console page is closed (a `sendBeacon` to the leave route). The console ticks every 15 s while
 * focused and every 30 s while visible but unfocused; a hidden tab stops ticking (away).
 */
final class Presence {

	/**
	 * Heartbeat payload key.
	 */
	public const KEY = 'newspack_live_presence';

	/**
	 * Heartbeat interval of the console, in seconds (core's fastest).
	 */
	public const INTERVAL = 15;

	/**
	 * Seconds without a tick after which a user drops off the list (about 60 s).
	 */
	public const TTL = 60;

	/**
	 * Lifetime of the per-live and per-user indexes, in seconds (rewritten at half-life).
	 */
	private const INDEX_TTL = 600;

	/**
	 * Hooks the class into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'heartbeat_received', [ __CLASS__, 'heartbeat_received' ], 10, 2 );
		add_filter( 'heartbeat_settings', [ __CLASS__, 'heartbeat_settings' ] );
	}

	/**
	 * The console page beats every 15 s (core keeps 120 s while the window is in the background)
	 * and isn't suspended after 10 idle minutes in an unfocused window (core still suspends after an
	 * hour), so a console watched on a second screen stays listed. Other admin pages keep core's
	 * settings.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public static function heartbeat_settings( $settings ) {
		if ( Console::is_console_request() ) {
			$settings               = (array) $settings;
			$settings['interval']   = self::INTERVAL;
			$settings['suspension'] = 'disable';
		}
		return $settings;
	}

	/**
	 * Records the user on the live and returns who is there. Only users who can post to the live
	 * are recorded and get the list.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data     Heartbeat data.
	 * @return array
	 */
	public static function heartbeat_received( $response, $data ) {
		$payload = is_array( $data ) && isset( $data[ self::KEY ] ) && is_array( $data[ self::KEY ] ) ? $data[ self::KEY ] : null;
		if ( null === $payload ) {
			return $response;
		}
		$live_id = absint( $payload['live'] ?? 0 );
		$user_id = get_current_user_id();
		if ( ! $user_id || ! Live::is_live( $live_id ) || ! current_user_can( 'newspack_live_post_to_live', $live_id ) ) {
			return $response;
		}
		self::tick( $live_id, $user_id );
		$response              = (array) $response;
		$response[ self::KEY ] = [
			'live'  => $live_id,
			'users' => self::users( $live_id, $user_id ),
		];
		return $response;
	}

	/**
	 * Transient key of a user's presence on a live.
	 *
	 * @param int $live_id Live ID.
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function user_key( int $live_id, int $user_id ): string {
		return 'newspack_live_presence_' . $live_id . '_' . $user_id;
	}

	/**
	 * Transient key of the user IDs seen on a live.
	 *
	 * @param int $live_id Live ID.
	 * @return string
	 */
	private static function live_key( int $live_id ): string {
		return 'newspack_live_presence_' . $live_id;
	}

	/**
	 * Transient key of the lives a user was seen on (privacy eraser).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function lives_key( int $user_id ): string {
		return 'newspack_live_presence_u' . $user_id;
	}

	/**
	 * Reads an index transient (user or live IDs).
	 *
	 * @param string $key Transient key.
	 * @return array{ids: int[], at: int}
	 */
	private static function read_index( string $key ): array {
		$index = get_transient( $key );
		return [
			'ids' => is_array( $index ) ? array_values( array_filter( array_map( 'intval', (array) ( $index['ids'] ?? [] ) ) ) ) : [],
			'at'  => is_array( $index ) ? (int) ( $index['at'] ?? 0 ) : 0,
		];
	}

	/**
	 * Writes an index transient.
	 *
	 * @param string $key Transient key.
	 * @param int[]  $ids IDs.
	 * @return void
	 */
	private static function write_index( string $key, array $ids ): void {
		set_transient(
			$key,
			[
				'ids' => array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ),
				'at'  => time(),
			],
			self::INDEX_TTL
		);
	}

	/**
	 * Adds an ID to an index when it is missing, or refreshes the index before it expires.
	 *
	 * @param string $key Transient key.
	 * @param int    $id  ID.
	 * @return void
	 */
	private static function keep_in_index( string $key, int $id ): void {
		$index = self::read_index( $key );
		if ( ! in_array( $id, $index['ids'], true ) || $index['at'] < time() - self::INDEX_TTL / 2 ) {
			$index['ids'][] = $id;
			self::write_index( $key, $index['ids'] );
		}
	}

	/**
	 * Records a tick.
	 *
	 * @param int $live_id Live ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function tick( int $live_id, int $user_id ): void {
		set_transient( self::user_key( $live_id, $user_id ), time(), self::TTL );
		self::keep_in_index( self::live_key( $live_id ), $user_id );
		self::keep_in_index( self::lives_key( $user_id ), $live_id );
	}

	/**
	 * Removes a user from a live (the console page was closed).
	 *
	 * @param int $live_id Live ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function leave( int $live_id, int $user_id ): void {
		delete_transient( self::user_key( $live_id, $user_id ) );
		$index = self::read_index( self::live_key( $live_id ) );
		if ( in_array( $user_id, $index['ids'], true ) ) {
			self::write_index( self::live_key( $live_id ), array_diff( $index['ids'], [ $user_id ] ) );
		}
	}

	/**
	 * Removes a user from every live they were seen on (privacy eraser).
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function forget_user( int $user_id ): void {
		foreach ( self::read_index( self::lives_key( $user_id ) )['ids'] as $live_id ) {
			self::leave( $live_id, $user_id );
		}
		delete_transient( self::lives_key( $user_id ) );
	}

	/**
	 * Users currently on a live who can still post to it: the viewer first, then by name.
	 *
	 * @param int $live_id Live ID.
	 * @param int $viewer  Current user ID.
	 * @return array<int, array{id: int, name: string, avatar: string, self: bool}>
	 */
	public static function users( int $live_id, int $viewer = 0 ): array {
		$index = self::read_index( self::live_key( $live_id ) )['ids'];
		$users = [];
		$alive = [];
		foreach ( $index as $user_id ) {
			if ( false === get_transient( self::user_key( $live_id, $user_id ) ) ) {
				continue;
			}
			$alive[] = $user_id;
			$user    = get_userdata( $user_id );
			if ( ! $user || ! user_can( $user, 'newspack_live_post_to_live', $live_id ) ) {
				continue;
			}
			$users[] = [
				'id'     => $user_id,
				'name'   => $user->display_name,
				'avatar' => (string) get_avatar_url( $user_id, [ 'size' => 48 ] ),
				'self'   => $user_id === $viewer,
			];
		}
		// Prune expired users from the index (a write only when someone left without saying so).
		if ( count( $alive ) !== count( $index ) ) {
			self::write_index( self::live_key( $live_id ), $alive );
		}
		usort( $users, static fn( $a, $b ) => [ ! $a['self'], $a['name'] ] <=> [ ! $b['self'], $b['name'] ] );
		return $users;
	}
}
