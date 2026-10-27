<?php
/**
 * Cloudflare Turnstile: the keys, and checking a visitor's token with
 * Cloudflare before the number is handed out.
 *
 * Keys can also live in wp-config.php, which wins over the settings page:
 *
 *     define( 'TEXT_US_WIDGET_TURNSTILE_SITE_KEY', '0x4AAAA...' );
 *     define( 'TEXT_US_WIDGET_TURNSTILE_SECRET_KEY', '0x4AAAA...' );
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turnstile keys and verification.
 */
final class Text_Us_Widget_Turnstile {

	/**
	 * Cloudflare's verification endpoint.
	 */
	const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Action name the widget is rendered with, checked on the way back.
	 */
	const ACTION = 'text_us_widget';

	/**
	 * Shape of a site or secret key (real keys start with 0x, Cloudflare's
	 * test keys with 1x, 2x or 3x).
	 */
	const KEY_PATTERN = '/^[0-9]x[A-Za-z0-9_\-]{10,120}$/';

	/**
	 * The site key: from wp-config.php, else the settings.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin.
	 * @return string
	 */
	public static function site_key( Text_Us_Widget_Plugin $plugin ) {
		if ( self::site_key_constant() ) {
			return (string) TEXT_US_WIDGET_TURNSTILE_SITE_KEY;
		}

		return (string) $plugin->get_setting( 'site_key' );
	}

	/**
	 * The secret key: from wp-config.php, else the settings.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin.
	 * @return string
	 */
	public static function secret_key( Text_Us_Widget_Plugin $plugin ) {
		if ( self::secret_key_constant() ) {
			return (string) TEXT_US_WIDGET_TURNSTILE_SECRET_KEY;
		}

		return (string) $plugin->get_setting( 'secret_key' );
	}

	/**
	 * Whether wp-config.php sets the site key.
	 *
	 * @return bool
	 */
	public static function site_key_constant() {
		return defined( 'TEXT_US_WIDGET_TURNSTILE_SITE_KEY' ) && '' !== (string) TEXT_US_WIDGET_TURNSTILE_SITE_KEY;
	}

	/**
	 * Whether wp-config.php sets the secret key.
	 *
	 * @return bool
	 */
	public static function secret_key_constant() {
		return defined( 'TEXT_US_WIDGET_TURNSTILE_SECRET_KEY' ) && '' !== (string) TEXT_US_WIDGET_TURNSTILE_SECRET_KEY;
	}

	/**
	 * Whether a key looks like a Turnstile key.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function valid_key( $key ) {
		return is_string( $key ) && (bool) preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Whether both keys are set and well formed.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin.
	 * @return bool
	 */
	public static function ready( Text_Us_Widget_Plugin $plugin ) {
		return self::valid_key( self::site_key( $plugin ) ) && self::valid_key( self::secret_key( $plugin ) );
	}

	/**
	 * Whether a key is one of Cloudflare's test keys, which pass (or fail)
	 * every visitor and work on any domain.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_test_key( $key ) {
		return (bool) preg_match( '/^[123]x0{10,}/', (string) $key );
	}

	/**
	 * Ask Cloudflare whether a visitor's token is good.
	 *
	 * @param string $secret Secret key.
	 * @param string $token  Token from the visitor's browser.
	 * @param string $ip     Visitor IP ('' to leave it out).
	 * @return array{ok:bool,error:string,codes:string[]} error is '' on success,
	 *         else "unavailable" (Cloudflare couldn't be reached) or "failed".
	 */
	public static function verify( $secret, $token, $ip = '' ) {
		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);

		if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_safe_remote_post(
			self::VERIFY_URL,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( 'unavailable', array( 'http-request-failed' ) );
		}

		$result = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $result ) ) {
			return self::result( 'unavailable', array( 'bad-response-' . (int) wp_remote_retrieve_response_code( $response ) ) );
		}

		// Cloudflare's reason codes (invalid-input-secret, timeout-or-duplicate
		// and so on) say why; they hold nothing secret.
		$codes = isset( $result['error-codes'] ) && is_array( $result['error-codes'] ) ? array_slice( array_map( 'sanitize_key', array_filter( $result['error-codes'], 'is_string' ) ), 0, 5 ) : array();

		if ( true !== ( isset( $result['success'] ) ? $result['success'] : false ) ) {
			return self::result( 'failed', $codes ? $codes : array( 'not-verified' ) );
		}

		// A token made for another widget on the same key isn't ours.
		if ( ! empty( $result['action'] ) && self::ACTION !== $result['action'] ) {
			return self::result( 'failed', array( 'action-mismatch' ) );
		}

		// Real keys report the site the check was solved on (test keys say
		// example.com). Turnstile's own hostname list already limits where a
		// key works; this catches a key shared between several sites.
		if ( ! self::is_test_key( $secret ) && ! empty( $result['hostname'] ) && ! self::own_host( (string) $result['hostname'] ) ) {
			return self::result( 'failed', array( 'hostname-mismatch' ) );
		}

		return self::result( '', array() );
	}

	/**
	 * Whether a hostname is this site's.
	 *
	 * @param string $hostname Hostname Cloudflare reported.
	 * @return bool
	 */
	private static function own_host( $hostname ) {
		$hosts = array( strtolower( (string) preg_replace( '/:\d+$/', '', Text_Us_Widget_Request::server( 'HTTP_HOST' ) ) ) );

		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) ) {
				$hosts[] = strtolower( $host );
			}
		}

		/**
		 * Hostnames a Turnstile check may have been solved on.
		 *
		 * @param string[] $hosts Hostnames, lowercase, no port.
		 */
		$hosts = (array) apply_filters( 'text_us_widget_turnstile_hosts', array_unique( $hosts ) );

		return in_array( strtolower( $hostname ), $hosts, true );
	}

	/**
	 * Build a result array.
	 *
	 * @param string   $error '' on success.
	 * @param string[] $codes Reason codes.
	 * @return array{ok:bool,error:string,codes:string[]}
	 */
	private static function result( $error, array $codes ) {
		return array(
			'ok'    => '' === $error,
			'error' => $error,
			'codes' => array_values( $codes ),
		);
	}
}
