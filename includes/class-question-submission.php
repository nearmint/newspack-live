<?php
/**
 * Reader question submission pipeline.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and stores a reader question, step by step in a fixed order; stops at the first failure.
 */
final class Question_Submission {

	/**
	 * Minimum seconds between page load (signed timestamp) and submission.
	 */
	public const MIN_SECONDS = 3;

	/**
	 * Transient prefix of the rate limits.
	 */
	public const RATE_LIMIT_PREFIX = 'newspack_live_rl_';

	/**
	 * Seconds between two questions from the same IP or user.
	 *
	 * @return int
	 */
	public static function rate_limit_seconds(): int {
		/**
		 * Filters how many seconds a reader must wait between two questions.
		 *
		 * @since 1.2.0
		 *
		 * @param int $seconds Seconds. Default 60.
		 */
		return max( 0, (int) apply_filters( 'newspack_live_question_rate_limit', 60 ) );
	}

	/**
	 * Hashed client IP (never stored or logged in clear).
	 *
	 * @return string
	 */
	public static function get_ip_hash(): string {
		$ip = self::get_ip();
		return '' !== $ip ? wp_hash( $ip ) : '';
	}

	/**
	 * Client IP address.
	 *
	 * @return string
	 */
	private static function get_ip(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- POST-only, uncached route; the address is only hashed.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Transient keys of the rate limits for the current reader.
	 *
	 * @param string $ip_hash IP hash.
	 * @param int    $user_id User ID.
	 * @return string[]
	 */
	private static function rate_limit_keys( string $ip_hash, int $user_id ): array {
		$generation = Questions::get_rate_limit_generation();
		$keys       = [];
		if ( '' !== $ip_hash ) {
			$keys[] = self::RATE_LIMIT_PREFIX . $generation . '_i' . substr( md5( $ip_hash ), 0, 20 );
		}
		if ( $user_id > 0 ) {
			$keys[] = self::RATE_LIMIT_PREFIX . $generation . '_u' . $user_id;
		}
		return $keys;
	}

	/**
	 * Forgets a user's rate-limit slots (privacy eraser).
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function forget_user( int $user_id ): void {
		foreach ( self::rate_limit_keys( '', $user_id ) as $key ) {
			delete_transient( $key );
			wp_cache_delete( $key, 'newspack_live_rl' );
		}
	}

	/**
	 * Runs the pipeline.
	 *
	 * @param int             $live_id Live ID.
	 * @param WP_REST_Request $request Request (form-encoded fields).
	 * @return int|true|WP_Error Question ID, true when silently dropped (honeypot), or an error.
	 */
	public static function submit( int $live_id, WP_REST_Request $request ) {
		// 1. Open for questions.
		$state    = Live::get_state( $live_id );
		$settings = Live::get_settings( $live_id );
		if ( ! in_array( $state, [ Live::STATE_UPCOMING, Live::STATE_LIVE ], true ) || 'off' === $settings['questions_mode'] || ! $settings['questions_open'] ) {
			return new WP_Error( 'newspack_live_questions_closed', __( 'Questions are closed.', 'newspack-live' ), [ 'status' => 403 ] );
		}

		// 2. Registered-only mode (cookie auth + nonce).
		$user_id = get_current_user_id();
		if ( 'registered' === $settings['questions_mode'] && ! $user_id ) {
			return new WP_Error( 'newspack_live_login_required', __( 'Please sign in to ask a question.', 'newspack-live' ), [ 'status' => 401 ] );
		}

		// 3. Honeypot: pretend success, store nothing.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return true;
		}

		// 4. Signed timestamp, at least 3 seconds old.
		$timestamp = Questions::verify_timestamp( $live_id, (string) $request->get_param( 'ts' ) );
		if ( ! $timestamp || time() - $timestamp < self::MIN_SECONDS ) {
			return new WP_Error( 'newspack_live_too_fast', __( 'Please take a moment before sending your question.', 'newspack-live' ), [ 'status' => 400 ] );
		}

		// 5. Lengths.
		$text = trim( sanitize_textarea_field( (string) $request->get_param( 'question' ) ) );
		$name = trim( sanitize_text_field( (string) $request->get_param( 'display_name' ) ) );
		if ( '' === $text || mb_strlen( $text ) > Questions::MAX_LENGTH || '' === $name || mb_strlen( $name ) > Questions::MAX_NAME_LENGTH ) {
			return new WP_Error(
				'newspack_live_invalid_length',
				__( 'Your question must be between 1 and 500 characters, and your name between 1 and 50.', 'newspack-live' ),
				[ 'status' => 400 ]
			);
		}

		// 6. Rate limit per IP hash and per user.
		$ip_hash = self::get_ip_hash();
		$keys    = self::rate_limit_keys( $ip_hash, $user_id );
		foreach ( $keys as $key ) {
			if ( false !== get_transient( $key ) ) {
				return new WP_Error( 'newspack_live_rate_limited', __( 'You just sent a question. Please wait a minute before sending another one.', 'newspack-live' ), [ 'status' => 429 ] );
			}
		}

		// 7. reCAPTCHA, when Newspack has it configured.
		$captcha = Integrations\Anti_Spam::verify_recaptcha();
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		// 8. Akismet, when available: spam is stored as such and answered with success.
		$status = Integrations\Anti_Spam::is_spam(
			[
				'text'       => $text,
				'name'       => $name,
				'ip'         => self::get_ip(),
				'user_agent' => (string) $request->get_header( 'user_agent' ),
				'referrer'   => (string) $request->get_header( 'referer' ),
				'permalink'  => (string) get_permalink( $live_id ),
				'user_id'    => $user_id,
			]
		) ? 'nl_spam' : 'nl_new';

		// Claim the rate-limit slots before storing. With a persistent object cache, wp_cache_add()
		// is atomic, so parallel submissions can't all pass step 6.
		$seconds = self::rate_limit_seconds();
		if ( $seconds > 0 ) {
			foreach ( $keys as $key ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- rate-limit window from the filter (default 60 s).
				if ( wp_using_ext_object_cache() && ! wp_cache_add( $key, 1, 'newspack_live_rl', $seconds ) ) {
					return new WP_Error( 'newspack_live_rate_limited', __( 'You just sent a question. Please wait a minute before sending another one.', 'newspack-live' ), [ 'status' => 429 ] );
				}
				set_transient( $key, 1, $seconds );
			}
		}

		// 9. Store as plain text.
		return Questions::create( $live_id, $text, $name, $user_id, $status, $ip_hash );
	}
}
