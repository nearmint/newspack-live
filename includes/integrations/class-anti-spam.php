<?php
/**
 * Anti-spam integrations: reCAPTCHA (Newspack) and Akismet.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Integrations;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Guarded wrappers around Newspack's reCAPTCHA and Akismet.
 * Both are absent in the sandbox: every branch degrades silently.
 */
final class Anti_Spam {

	/**
	 * Whether Newspack's reCAPTCHA is configured.
	 *
	 * @return bool
	 */
	public static function can_use_recaptcha(): bool {
		return class_exists( '\Newspack\Recaptcha' )
			&& method_exists( '\Newspack\Recaptcha', 'can_use_captcha' )
			&& method_exists( '\Newspack\Recaptcha', 'verify_captcha' )
			&& \Newspack\Recaptcha::can_use_captcha();
	}

	/**
	 * Verifies the reCAPTCHA token posted with the form (Newspack reads `g-recaptcha-response` from `$_POST`).
	 *
	 * @return true|WP_Error
	 */
	public static function verify_recaptcha() {
		if ( ! self::can_use_recaptcha() ) {
			return true;
		}
		$result = \Newspack\Recaptcha::verify_captcha();
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'newspack_live_captcha_failed',
				__( "We couldn't verify your request. Please try again.", 'newspack-live' ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}

	/**
	 * Whether Akismet is available.
	 *
	 * @return bool
	 */
	public static function can_use_akismet(): bool {
		return class_exists( '\Akismet' ) && method_exists( '\Akismet', 'http_post' ) && method_exists( '\Akismet', 'get_api_key' ) && (bool) \Akismet::get_api_key();
	}

	/**
	 * Asks Akismet whether a question is spam.
	 *
	 * @param array $question `text`, `name`, `ip`, `user_agent`, `referrer`, `permalink`, `user_id`.
	 * @return bool True when Akismet flags it as spam; false when absent or on error.
	 */
	public static function is_spam( array $question ): bool {
		if ( ! self::can_use_akismet() ) {
			return false;
		}
		$query = [
			'blog'                      => home_url(),
			'blog_lang'                 => get_locale(),
			'blog_charset'              => get_option( 'blog_charset' ),
			'user_ip'                   => $question['ip'] ?? '',
			'user_agent'                => $question['user_agent'] ?? '',
			'referrer'                  => $question['referrer'] ?? '',
			'permalink'                 => $question['permalink'] ?? '',
			'comment_type'              => 'newspack-live-question',
			'comment_author'            => $question['name'] ?? '',
			'comment_content'           => $question['text'] ?? '',
			'user_role'                 => ! empty( $question['user_id'] ) ? 'registered' : '',
			'comment_date_gmt'          => gmdate( 'c' ),
			'comment_post_modified_gmt' => '',
		];
		$response = \Akismet::http_post( build_query( $query ), 'comment-check' );
		return is_array( $response ) && isset( $response[1] ) && 'true' === trim( (string) $response[1] );
	}
}
