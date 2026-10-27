<?php
/**
 * Main plugin orchestrator: owns the settings and boots everything else.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Text_Us_Widget_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'text_us_widget_settings';

	/**
	 * Devices the widget can show on: key => short code the browser script
	 * reports. Apple devices get the iMessage icon, Android ones Messages.
	 */
	const DEVICES = array(
		'mac'            => 'mac',
		'iphone'         => 'iphone',
		'ipad'           => 'ipad',
		'android'        => 'android',
		'android_tablet' => 'atab',
		'windows'        => 'win',
		'other'          => 'other',
	);

	/**
	 * Wording settings, which fall back to translated defaults when empty.
	 */
	const LABELS = array( 'title', 'subtitle', 'button', 'heading', 'text', 'send' );

	/**
	 * Single shared instance.
	 *
	 * @var Text_Us_Widget_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings, read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Text_Us_Widget_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Text_Us_Widget_Stats::maybe_upgrade();

		new Text_Us_Widget_Api( $this );
		new Text_Us_Widget_Frontend( $this );

		if ( is_admin() ) {
			new Text_Us_Widget_Admin( $this );
		}

		add_action( Text_Us_Widget_Stats::CRON, array( $this, 'daily' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'settings_changed' ) );
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'         => 1,
			'number'          => '',
			'number_display'  => '',
			'message'         => '',
			'show_number'     => 0,
			'title'           => '',
			'subtitle'        => '',
			'button'          => '',
			'heading'         => '',
			'text'            => '',
			'send'            => '',
			'devices'         => array( 'mac', 'iphone', 'ipad' ),
			'icon'            => 'app',
			'corner'          => 'right',
			'phone_layout'    => 'bar',
			'theme'           => 'dark',
			'accent'          => '#0a84ff',
			'offset_x'        => 20,
			'offset_y'        => 20,
			'delay'           => 15,
			'hide_days'       => 7,
			'on_interaction'  => 1,
			'exclude'         => '',
			'turnstile'       => 0,
			'site_key'        => '',
			'secret_key'      => '',
			'direct'          => 0,
			'exclude_editors' => 1,
			'ga4'             => 0,
			'retention'       => 12,
		);
	}

	/**
	 * The default wording, translated.
	 *
	 * @param string $key One of {@see LABELS}.
	 * @return string
	 */
	public static function default_label( $key ) {
		switch ( $key ) {
			case 'title':
				return __( 'Have a question?', 'text-us-widget' );
			case 'subtitle':
				return __( 'Text us and we\'ll get right back to you.', 'text-us-widget' );
			case 'button':
				return __( 'Text Us', 'text-us-widget' );
			case 'heading':
				/* translators: %s: site title. */
				return sprintf( __( 'Text %s', 'text-us-widget' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			case 'text':
				return __( 'Send us a text message and we\'ll reply as soon as we can.', 'text-us-widget' );
			case 'send':
				return __( 'Send us a text', 'text-us-widget' );
		}

		return '';
	}

	/**
	 * The wording for a label: the saved text, or the default.
	 *
	 * @param string $key One of {@see LABELS}.
	 * @return string
	 */
	public function label( $key ) {
		$saved = trim( (string) $this->get_setting( $key ) );

		return '' !== $saved ? $saved : self::default_label( $key );
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings (after a save).
	 *
	 * @return void
	 */
	public function settings_changed() {
		$this->settings = null;
		self::purge_page_caches();
	}

	/**
	 * Devices the widget is switched on for.
	 *
	 * @return string[] Keys of {@see DEVICES}.
	 */
	public function devices() {
		$devices = $this->get_setting( 'devices' );

		return is_array( $devices ) ? array_values( array_intersect( array_keys( self::DEVICES ), $devices ) ) : array();
	}

	/**
	 * Whether Turnstile protection is switched on (keys or not).
	 *
	 * @return bool
	 */
	public function wants_turnstile() {
		return (bool) $this->get_setting( 'turnstile' );
	}

	/**
	 * Why the widget isn't showing, or '' when it is.
	 *
	 * @return string Machine code: off, number, devices, keys.
	 */
	public function why_off() {
		if ( defined( 'TEXT_US_WIDGET_DISABLED' ) && TEXT_US_WIDGET_DISABLED ) {
			return 'constant';
		}

		if ( ! $this->get_setting( 'enabled' ) ) {
			return 'off';
		}

		if ( '' === (string) $this->get_setting( 'number' ) ) {
			return 'number';
		}

		if ( ! $this->devices() ) {
			return 'devices';
		}

		// Fail closed: with protection on but no keys, the number would
		// otherwise end up in the page for anyone to read.
		if ( $this->wants_turnstile() && ! Text_Us_Widget_Turnstile::ready( $this ) ) {
			return 'keys';
		}

		return '';
	}

	/**
	 * Whether the widget is showing on the site.
	 *
	 * @return bool
	 */
	public function is_live() {
		return '' === $this->why_off();
	}

	/**
	 * Clean up a phone number for an sms: link.
	 *
	 * Keeps a leading + and the digits. Letters (vanity numbers, "ext.")
	 * aren't accepted, because they'd be dropped silently.
	 *
	 * @param string $raw Number as typed.
	 * @return string "+15551234567" or "5551234567", or '' when invalid.
	 */
	public static function normalize_number( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw || strlen( $raw ) > 40 || ! preg_match( '/^\+?[0-9\s().\-\/]+$/', $raw ) ) {
			return '';
		}

		$plus   = 0 === strpos( $raw, '+' ) ? '+' : '';
		$digits = (string) preg_replace( '/\D+/', '', $raw );

		// E.164 numbers have at most 15 digits; short codes have 3 or more.
		if ( strlen( $digits ) < 3 || strlen( $digits ) > 15 ) {
			return '';
		}

		return $plus . $digits;
	}

	/**
	 * Daily task: delete clicks older than the retention period.
	 *
	 * @return void
	 */
	public function daily() {
		Text_Us_Widget_Stats::prune( (int) $this->get_setting( 'retention' ) );
		Text_Us_Widget_Request::forget_old_salt();
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy).
	 *
	 * @return void
	 */
	public function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text  = '<p>' . esc_html__( 'Our "Text us" button counts how often it is opened and used. Each count records the date, the type of device (for example iPhone or Mac) and the page it happened on. It doesn\'t record your IP address, name or phone number: to stop repeat taps and abuse, your connection is identified by a code that changes every day and can\'t be turned back into an IP address.', 'text-us-widget' ) . '</p>';
		$text .= '<p>' . esc_html__( 'The button stores two small cookies in your browser: tuw_seen, so it can appear straight away on your next page view, and tuw_hide, if you close it, so it stays hidden for a while. Neither is used for tracking or shared with anyone.', 'text-us-widget' ) . '</p>';

		if ( $this->wants_turnstile() ) {
			$text .= '<p>' . esc_html__( 'Before our phone number is shown, Cloudflare Turnstile checks that you\'re a person and not a bot. Cloudflare processes information about your browser and connection to do this; see Cloudflare\'s privacy policy.', 'text-us-widget' ) . '</p>';
		}

		wp_add_privacy_policy_content( __( 'Text Us Widget', 'text-us-widget' ), wp_kses_post( $text ) );
	}

	/**
	 * Clear page caches so visitors see changed settings straight away.
	 * Covers the common caching plugins; others can hook the action below.
	 *
	 * @return void
	 */
	public static function purge_page_caches() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		do_action( 'litespeed_purge_all' );

		/**
		 * Fires after the plugin asks caching plugins to clear their pages,
		 * for caches it doesn't know about.
		 */
		do_action( 'text_us_widget_purge_cache' );
	}
}
