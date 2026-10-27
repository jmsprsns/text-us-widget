<?php
/**
 * The widget's two public endpoints:
 *
 * POST /wp-json/text-us-widget/v1/hit     counts a tap (opened, or Send)
 * POST /wp-json/text-us-widget/v1/reveal  checks a Turnstile token with
 *                                         Cloudflare, then returns the number
 *
 * They're public by necessity (visitors aren't logged in, and cached pages
 * can't carry a nonce), so they trust nothing they're sent:
 *
 * - Real POST requests only, with a small JSON body. WordPress's REST API
 *   would otherwise run them for a GET with ?_method=POST, which an <img>
 *   in a post could send from every visitor's browser.
 * - Browsers that say where a request came from (Origin, Sec-Fetch-Site)
 *   must be on this site, so another website can't make its visitors post.
 * - Every field is checked against a short list: the event, a device the
 *   widget is switched on for, and a page that's really published.
 * - Bots, prefetches and (by default) the site's own editors aren't
 *   counted, double taps are merged, and each connection can add at most
 *   30 taps an hour (2,000 an hour for the whole site).
 * - /reveal answers only when Turnstile is on, and each connection gets 10
 *   tries per 10 minutes (300 for the whole site), so it can't be used to
 *   make this server call Cloudflare endlessly. The number is only in the
 *   answer to a token Cloudflare has just confirmed (tokens work once).
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public REST endpoints.
 */
final class Text_Us_Widget_Api {

	/**
	 * REST namespace.
	 */
	const NS = 'text-us-widget/v1';

	/**
	 * Largest request body accepted, in bytes (a Turnstile token is at most
	 * 2,048 characters).
	 */
	const MAX_BODY = 4096;

	/**
	 * Plugin instance.
	 *
	 * @var Text_Us_Widget_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin instance.
	 */
	public function __construct( Text_Us_Widget_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'hit' ),
				// Public on purpose: see the class comment for what guards it.
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);

		register_rest_route(
			self::NS,
			'/reveal',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reveal' ),
				// Public on purpose: see the class comment for what guards it.
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Count a tap. Accepted taps get an empty 204, whether they were
	 * counted, merged or ignored, so the answer says nothing about the
	 * filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function hit( WP_REST_Request $request ) {
		$data = $this->read( $request );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$event = isset( $data['e'] ) && is_string( $data['e'] ) ? $data['e'] : '';

		// o = opened, t = tapped Send, d = tapped straight through to
		// Messages (no panel), which is both.
		if ( ! in_array( $event, array( 'o', 't', 'd' ), true ) ) {
			return new WP_Error( 'text_us_widget_invalid', 'Invalid.', array( 'status' => 400 ) );
		}

		$events = 'd' === $event ? array( 'o', 't' ) : array( $event );

		foreach ( $events as $code ) {
			$this->count( $code, $data );
		}

		return self::response( null, 204 );
	}

	/**
	 * Check a Turnstile token and, if Cloudflare confirms it, return the
	 * number.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reveal( WP_REST_Request $request ) {
		$plugin = $this->plugin;
		$data   = $this->read( $request );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! $plugin->is_live() || ! $plugin->wants_turnstile() ) {
			return new WP_Error( 'text_us_widget_off', 'Not available.', array( 'status' => 404 ) );
		}

		$token = isset( $data['token'] ) && is_string( $data['token'] ) ? $data['token'] : '';

		// Turnstile tokens are at most 2,048 URL-safe characters. Anything
		// else is refused here, without a call to Cloudflare.
		if ( ! preg_match( '/^[A-Za-z0-9._\-+\/=]{10,2048}$/', $token ) ) {
			return self::response( array( 'code' => 'verify_failed' ), 400 );
		}

		if ( ! $this->allow_attempt() ) {
			return self::response( array( 'code' => 'too_many' ), 429 );
		}

		$result = Text_Us_Widget_Turnstile::verify(
			Text_Us_Widget_Turnstile::secret_key( $plugin ),
			$token,
			Text_Us_Widget_Request::client_ip()
		);

		if ( ! $result['ok'] ) {
			return self::response(
				array(
					'code'  => 'unavailable' === $result['error'] ? 'verify_unavailable' : 'verify_failed',
					'codes' => $result['codes'],
				),
				'unavailable' === $result['error'] ? 502 : 403
			);
		}

		$this->count( 'v', $data );

		$out = array( 'n' => (string) $plugin->get_setting( 'number' ) );

		if ( $plugin->get_setting( 'show_number' ) ) {
			$out['s'] = (string) $plugin->get_setting( 'number_display' );
		}

		return self::response( $out, 200 );
	}

	/**
	 * Check the request and decode its JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function read( WP_REST_Request $request ) {
		if ( ! Text_Us_Widget_Request::is_post() ) {
			return new WP_Error( 'text_us_widget_method', 'POST only.', array( 'status' => 405 ) );
		}

		if ( ! Text_Us_Widget_Request::same_origin() ) {
			return new WP_Error( 'text_us_widget_origin', 'Cross-site requests are not accepted.', array( 'status' => 403 ) );
		}

		$body = (string) $request->get_body();

		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'text_us_widget_size', 'Too large.', array( 'status' => 413 ) );
		}

		$data = json_decode( $body, true, 4 );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'text_us_widget_invalid', 'Invalid.', array( 'status' => 400 ) );
		}

		return $data;
	}

	/**
	 * Save one event, unless it shouldn't count.
	 *
	 * @param string              $event Event code.
	 * @param array<string,mixed> $data  Decoded request.
	 * @return void
	 */
	private function count( $event, array $data ) {
		$plugin = $this->plugin;

		if ( ! $plugin->is_live() || Text_Us_Widget_Request::is_bot() ) {
			return;
		}

		// Only devices the widget is switched on for can have tapped it.
		$device  = isset( $data['d'] ) && is_string( $data['d'] ) ? $data['d'] : '';
		$enabled = array();
		foreach ( $plugin->devices() as $key ) {
			$enabled[] = Text_Us_Widget_Plugin::DEVICES[ $key ];
		}

		if ( ! in_array( $device, $enabled, true ) ) {
			return;
		}

		if ( $plugin->get_setting( 'exclude_editors' ) ) {
			$user_id = Text_Us_Widget_Request::user_id();
			if ( $user_id && user_can( $user_id, 'edit_posts' ) ) {
				return;
			}
		}

		$client = Text_Us_Widget_Request::client_hash();

		// A double tap, or the same tap sent twice.
		if ( Text_Us_Widget_Stats::count_recent( 5, $client, $event, $device ) > 0 ) {
			return;
		}

		/**
		 * Most taps one connection can add in an hour.
		 *
		 * @param int $limit Taps.
		 */
		if ( Text_Us_Widget_Stats::count_recent( HOUR_IN_SECONDS, $client ) >= (int) apply_filters( 'text_us_widget_connection_limit', 30 ) ) {
			return;
		}

		/**
		 * Most taps the whole site can add in an hour.
		 *
		 * @param int $limit Taps.
		 */
		if ( Text_Us_Widget_Stats::count_recent( HOUR_IN_SECONDS ) >= (int) apply_filters( 'text_us_widget_site_limit', 2000 ) ) {
			return;
		}

		Text_Us_Widget_Stats::add( $event, $device, self::page_id( isset( $data['p'] ) ? $data['p'] : 0 ), $client );
	}

	/**
	 * The page a tap came from, if it's a real published page anyone can
	 * see. Anything else is counted as "other pages" (0).
	 *
	 * @param mixed $value Post ID sent by the widget.
	 * @return int
	 */
	private static function page_id( $value ) {
		$id = is_numeric( $value ) ? (int) $value : 0;

		if ( $id <= 0 ) {
			return 0;
		}

		$post = get_post( $id );

		if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) || ! is_post_publicly_viewable( $post ) ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Rate limit for /reveal: 10 tries per connection and 300 for the
	 * whole site every 10 minutes.
	 *
	 * @return bool Whether this try is allowed.
	 */
	private function allow_attempt() {
		$window = (int) floor( time() / ( 10 * MINUTE_IN_SECONDS ) );
		$keys   = array(
			/**
			 * Turnstile checks one connection can ask for every 10 minutes.
			 *
			 * @param int $limit Checks.
			 */
			'text_us_widget_rv_' . Text_Us_Widget_Request::client_hash( 'r' ) . '_' . $window => (int) apply_filters( 'text_us_widget_reveal_limit', 10 ),
			/**
			 * Turnstile checks the whole site can ask for every 10 minutes.
			 *
			 * @param int $limit Checks.
			 */
			'text_us_widget_rv_all_' . $window => (int) apply_filters( 'text_us_widget_reveal_site_limit', 300 ),
		);

		foreach ( $keys as $key => $limit ) {
			$used = (int) get_transient( $key );

			if ( $used >= $limit ) {
				return false;
			}

			set_transient( $key, $used + 1, 10 * MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * A response that's never cached or indexed.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function response( $data, $status ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}
}
