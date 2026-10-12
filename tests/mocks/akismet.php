<?php
/**
 * Stub of Akismet: flags questions containing "viagra" as spam.
 *
 * @package Newspack_Live
 */

if ( ! class_exists( 'Akismet' ) ) {
	/**
	 * Akismet stub.
	 */
	class Akismet {

		/**
		 * API key.
		 *
		 * @return string
		 */
		public static function get_api_key() {
			return 'test-key';
		}

		/**
		 * Comment check.
		 *
		 * @param string $request Query string.
		 * @param string $path    API path.
		 * @return array Headers and body.
		 */
		public static function http_post( $request, $path ) {
			parse_str( $request, $data );
			$spam = 'comment-check' === $path && str_contains( (string) ( $data['comment_content'] ?? '' ), 'viagra' ) && 'newspack-live-question' === ( $data['comment_type'] ?? '' );
			return [ [], $spam ? 'true' : 'false' ];
		}
	}
}
