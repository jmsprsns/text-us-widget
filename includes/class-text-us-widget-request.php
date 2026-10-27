<?php
/**
 * Facts about the current visitor request, for the public endpoints: who
 * is asking (as an anonymous hash), whether it came from this site, and
 * whether it's a bot.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request helpers.
 */
final class Text_Us_Widget_Request {

	/**
	 * Option holding today's salt for connection hashes.
	 */
	const SALT_OPTION = 'text_us_widget_salt';

	/**
	 * User agents that are never counted.
	 */
	const BOTS = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|phantomjs|puppeteer|playwright|selenium|webdriver|python|curl\/|wget|httpclient|okhttp|axios|node-fetch|go-http|java\/|libwww|scrapy|facebookexternalhit|embedly|preview|validator|prerender|semrush|ahrefs|mj12|petalbot|bytespider|yandex|baiduspider|duckduckbot|applebot|amazonbot|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-user|perplexitybot|ccbot|diffbot|dataforseo/i';

	/**
	 * Whether the request was sent by a page on this site.
	 *
	 * Browsers say where a request came from (Sec-Fetch-Site, Origin), and
	 * pages can't change that, so another website can't make its visitors
	 * post here. Requests without the headers (older browsers, privacy
	 * tools) are allowed: the endpoints' other checks still apply.
	 *
	 * @return bool
	 */
	public static function same_origin() {
		$fetch_site = strtolower( self::server( 'HTTP_SEC_FETCH_SITE' ) );

		if ( '' !== $fetch_site && 'same-origin' !== $fetch_site ) {
			return false;
		}

		$origin = self::server( 'HTTP_ORIGIN' );

		if ( '' === $origin ) {
			return true;
		}

		$parts = wp_parse_url( $origin );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host    = strtolower( $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		$allowed = array( strtolower( self::server( 'HTTP_HOST' ) ) );

		foreach ( array( home_url(), site_url() ) as $url ) {
			$own = wp_parse_url( $url );

			if ( is_array( $own ) && ! empty( $own['host'] ) ) {
				$allowed[] = strtolower( $own['host'] . ( isset( $own['port'] ) ? ':' . $own['port'] : '' ) );
			}
		}

		return in_array( $host, $allowed, true );
	}

	/**
	 * Whether the request is a real POST. WordPress's REST API also runs
	 * POST routes for a GET with ?_method=POST, which an <img> in a post
	 * could send from every visitor's browser.
	 *
	 * @return bool
	 */
	public static function is_post() {
		return 'POST' === strtoupper( self::server( 'REQUEST_METHOD' ) );
	}

	/**
	 * Whether the request is from a bot, a headless browser or a prefetch.
	 *
	 * @return bool
	 */
	public static function is_bot() {
		$agent = self::server( 'HTTP_USER_AGENT' );

		if ( strlen( $agent ) < 20 || preg_match( self::BOTS, $agent ) ) {
			return true;
		}

		$purpose = strtolower( self::server( 'HTTP_SEC_PURPOSE' ) . ' ' . self::server( 'HTTP_PURPOSE' ) . ' ' . self::server( 'HTTP_X_MOZ' ) );

		return false !== strpos( $purpose, 'prefetch' );
	}

	/**
	 * The signed-in user, from the login cookie. The REST API ignores that
	 * cookie without a nonce (which cached pages can't have), so it's
	 * checked here directly.
	 *
	 * @return int User ID, or 0.
	 */
	public static function user_id() {
		$id = wp_validate_auth_cookie( '', 'logged_in' );

		return $id ? (int) $id : 0;
	}

	/**
	 * An anonymous ID for the visitor's connection: a hash of the IP (IPv6
	 * by its /64 network, which one household usually gets) with a salt
	 * that changes every day. Used for rate limits and to merge double taps;
	 * it can't be turned back into the IP, or followed to the next day.
	 *
	 * @param string $purpose Keeps hashes for different uses apart.
	 * @return string 16 hex characters.
	 */
	public static function client_hash( $purpose = 'c' ) {
		$ip = self::client_ip();

		if ( false !== strpos( $ip, ':' ) ) {
			$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validated already; false just means "use as is".
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				$ip = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
			}
		}

		return substr( hash_hmac( 'sha256', $purpose . '|' . $ip, self::daily_salt() ), 0, 16 );
	}

	/**
	 * The visitor's IP.
	 *
	 * REMOTE_ADDR, unless it's a private address (a proxy or load balancer
	 * on the same network): then the last public address in
	 * X-Forwarded-For, which is the one the proxy itself saw. Cloudflare's
	 * CF-Connecting-IP is only believed when the request really came from
	 * Cloudflare's network.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );
		$hop    = $remote;

		if ( '' !== $remote && ! self::is_public( $remote ) ) {
			$forwarded = array_reverse( explode( ',', self::server( 'HTTP_X_FORWARDED_FOR' ) ) );

			foreach ( $forwarded as $candidate ) {
				$candidate = self::valid_ip( $candidate );

				if ( '' !== $candidate && self::is_public( $candidate ) ) {
					$hop = $candidate;
					break;
				}
			}

			if ( $hop === $remote ) {
				$real = self::valid_ip( self::server( 'HTTP_X_REAL_IP' ) );
				$hop  = '' !== $real && self::is_public( $real ) ? $real : $hop;
			}
		}

		$cloudflare = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
		$ip         = '' !== $cloudflare && self::is_cloudflare( $hop ) ? $cloudflare : $hop;

		/**
		 * The visitor IP used for rate limits and Turnstile checks. Change it
		 * for an unusual proxy setup.
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'text_us_widget_client_ip', $ip );
	}

	/**
	 * A trimmed, valid IP address, or ''.
	 *
	 * @param mixed $ip Candidate.
	 * @return string
	 */
	private static function valid_ip( $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';

		return '' !== $ip && strlen( $ip ) <= 45 && filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP is publicly routable.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public( $ip ) {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Whether an IP is one of Cloudflare's (https://www.cloudflare.com/ips/).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_cloudflare( $ip ) {
		$ranges = array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);

		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP is inside a CIDR range.
	 *
	 * @param string $ip    IP address.
	 * @param string $range CIDR range.
	 * @return bool
	 */
	private static function in_range( $ip, $range ) {
		$parts  = explode( '/', $range, 2 );
		$ip_bin = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input just means "no match".
		$sn_bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $ip_bin || false === $sn_bin || strlen( $ip_bin ) !== strlen( $sn_bin ) ) {
			return false;
		}

		$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $ip_bin ) * 8;
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $sn_bin, 0, $bytes ) ) {
			return false;
		}

		if ( $rest > 0 ) {
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;

			if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $sn_bin[ $bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Today's salt for connection hashes. It changes every day, so a hash
	 * can't be followed from one day to the next.
	 *
	 * @return string
	 */
	private static function daily_salt() {
		$today = gmdate( 'Y-m-d' );
		$saved = get_option( self::SALT_OPTION );

		if ( is_array( $saved ) && isset( $saved['day'], $saved['salt'] ) && $today === $saved['day'] && is_string( $saved['salt'] ) ) {
			return $saved['salt'];
		}

		$salt = wp_generate_password( 40, true, true );
		update_option(
			self::SALT_OPTION,
			array(
				'day'  => $today,
				'salt' => $salt,
			),
			false
		);

		return $salt;
	}

	/**
	 * Replace yesterday's salt (the daily task), so even an idle site
	 * doesn't keep it.
	 *
	 * @return void
	 */
	public static function forget_old_salt() {
		self::daily_salt();
	}

	/**
	 * A $_SERVER value as a plain string.
	 *
	 * @param string $key $_SERVER key.
	 * @return string
	 */
	public static function server( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		return trim( substr( wp_unslash( $_SERVER[ $key ] ), 0, 1000 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched, hashed or validated by the callers.
	}
}
