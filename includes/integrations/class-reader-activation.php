<?php
/**
 * Newspack Reader Activation integration.
 *
 * @package Newspack_Live
 */

namespace Newspack_Live\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Registered-only questions use Newspack's sign-in modal when Reader Activation is on;
 * otherwise the sign-in link goes to wp-login.php and back to the form.
 */
final class Reader_Activation {

	/**
	 * Whether Newspack Reader Activation is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return class_exists( '\\Newspack\\Reader_Activation' )
			&& method_exists( '\\Newspack\\Reader_Activation', 'is_enabled' )
			&& (bool) \Newspack\Reader_Activation::is_enabled();
	}
}
