<?php
/**
 * Front end: prints the widget at the end of the page.
 *
 * The page gets two things, and nothing else (no extra files, no requests
 * until someone taps it):
 *
 * - <template id="tuw-template"> with the markup. A template is inert: the
 *   browser parses it but doesn't draw it, load its images or apply it.
 * - One inline script (assets/widget.min.js) that checks the device and,
 *   if the widget is on for it, adds the styles and the markup (straight
 *   away, or at the first interaction).
 *
 * Both are the same for every visitor, so the page can be cached. Script
 * optimizers (WP Rocket, LiteSpeed, Cloudflare Rocket Loader, Perfmatters,
 * Autoptimize, Jetpack Boost, SiteGround and "delay until interaction"
 * plugins) are told to leave the script alone: it does its own waiting.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget output.
 */
final class Text_Us_Widget_Frontend {

	/**
	 * Query args page builders add to their editing previews.
	 */
	const BUILDER_ARGS = array( 'elementor-preview', 'fl_builder', 'et_fb', 'ct_builder', 'bricks', 'brizy-edit-iframe', 'vcv-editable', 'tve', 'uxb_iframe', 'breakdance_iframe', 'givewp-route' );

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

		add_action( 'wp_footer', array( $this, 'print_widget' ), 50 );

		add_filter( 'rocket_delay_js_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'rocket_excluded_inline_js_content', array( $this, 'exclude_scripts' ) );
		add_filter( 'rocket_defer_inline_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'perfmatters_delay_js_exclusions', array( $this, 'exclude_scripts' ) );
		add_filter( 'sgo_javascript_combine_excluded_inline_content', array( $this, 'exclude_scripts' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( $this, 'exclude_scripts_csv' ) );
	}

	/**
	 * Whether the current page gets the widget.
	 *
	 * @return bool
	 */
	public function should_show() {
		$show = $this->plugin->is_live()
			&& ! is_admin()
			&& ! is_feed()
			&& ! is_embed()
			&& ! is_robots()
			&& ! is_trackback()
			&& ! wp_doing_ajax()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! is_customize_preview()
			&& ! ( function_exists( 'amp_is_request' ) && amp_is_request() )
			&& ! $this->is_excluded( self::request_path() );

		// Only for people who can edit: anyone can add these to a URL, and a
		// page cache that ignores query strings would then keep a copy
		// without the widget.
		if ( $show && current_user_can( 'edit_posts' ) ) {
			foreach ( self::BUILDER_ARGS as $arg ) {
				if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check.
					$show = false;
					break;
				}
			}
		}

		/**
		 * Filters whether the current page gets the widget.
		 *
		 * @param bool $show Whether to print it.
		 */
		return (bool) apply_filters( 'text_us_widget_show', $show );
	}

	/**
	 * The path of the current request, lowercase, without the query string.
	 *
	 * @return string
	 */
	public static function request_path() {
		$path = (string) wp_parse_url( Text_Us_Widget_Request::server( 'REQUEST_URI' ), PHP_URL_PATH );

		return strtolower( '' !== $path ? rawurldecode( $path ) : '/' );
	}

	/**
	 * The "Hide on these pages" list, cleaned: one path per line, starting
	 * with "/", optionally ending in "*".
	 *
	 * @param string $text Saved list.
	 * @return string[]
	 */
	public static function exclude_rules( $text ) {
		$rules = array();

		foreach ( preg_split( '/[\r\n]+/', (string) $text ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			// A full URL works too: keep its path.
			if ( preg_match( '#^https?://#i', $line ) ) {
				$path = wp_parse_url( $line, PHP_URL_PATH );
				$line = is_string( $path ) ? $path : '/';
			}

			$line = '/' . ltrim( strtolower( (string) preg_replace( '/[\s<>"\']+/', '', $line ) ), '/' );
			$rules[] = substr( $line, 0, 200 );

			if ( count( $rules ) >= 100 ) {
				break;
			}
		}

		return array_values( array_unique( $rules ) );
	}

	/**
	 * Whether a path is on the "Hide on these pages" list. "/contact" also
	 * matches "/contact/", and "/shop/*" matches everything under /shop/.
	 *
	 * @param string $path Request path.
	 * @return bool
	 */
	public function is_excluded( $path ) {
		$path = untrailingslashit( $path );

		foreach ( self::exclude_rules( (string) $this->plugin->get_setting( 'exclude' ) ) as $rule ) {
			if ( '*' === substr( $rule, -1 ) ) {
				if ( 0 === strpos( $path . '/', substr( $rule, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( untrailingslashit( $rule ) === $path ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Print the template and the script.
	 *
	 * @return void
	 */
	public function print_widget() {
		if ( ! $this->should_show() ) {
			return;
		}

		$code = self::asset( 'widget.min.js' );
		$css  = self::asset( 'widget.min.css' );

		// JSON_HEX_TAG writes < and > as Unicode escapes, so nothing in the
		// settings (or the CSS) can close the <script> element early.
		$flags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$config = wp_json_encode( Text_Us_Widget_Widget::config( $this->plugin, is_singular() ? (int) get_queried_object_id() : 0 ), $flags );
		$styles = wp_json_encode( $css, $flags );

		if ( '' === $code || '' === $css || ! $config || ! $styles ) {
			return;
		}

		echo "\n<template id=\"tuw-template\">" . Text_Us_Widget_Widget::markup( $this->plugin ) . "</template>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in markup().

		wp_print_inline_script_tag(
			str_replace( array( '__TUW_CONFIG__', '__TUW_CSS__' ), array( $config, $styles ), $code ),
			array(
				'id'                 => 'text-us-widget-js',
				// Opt out of Cloudflare Rocket Loader, LiteSpeed, WP Rocket,
				// Jetpack Boost and "delay until interaction" plugins.
				'data-cfasync'       => 'false',
				'data-no-optimize'   => '1',
				'data-no-defer'      => '1',
				'data-no-delay'      => '1',
				'data-no-minify'     => '1',
				'data-pagespeed-no-defer' => '1',
				'data-jetpack-boost' => 'ignore',
				'nowprocket'         => true,
			)
		);
	}

	/**
	 * Contents of a bundled asset.
	 *
	 * @param string $file File in assets/.
	 * @return string Empty if it can't be read.
	 */
	public static function asset( $file ) {
		static $cache = array();

		if ( ! isset( $cache[ $file ] ) ) {
			$path           = TEXT_US_WIDGET_DIR . 'assets/' . basename( $file );
			$cache[ $file ] = is_readable( $path ) ? trim( (string) file_get_contents( $path ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $cache[ $file ];
	}

	/**
	 * Keep script optimizers from delaying, deferring or combining the widget.
	 *
	 * @param mixed $patterns Excluded patterns.
	 * @return array
	 */
	public function exclude_scripts( $patterns ) {
		$patterns   = is_array( $patterns ) ? $patterns : array();
		$patterns[] = 'text-us-widget';
		$patterns[] = 'tuw-template';

		return $patterns;
	}

	/**
	 * Autoptimize takes its exclusions as a comma-separated string.
	 *
	 * @param mixed $list Excluded patterns.
	 * @return string
	 */
	public function exclude_scripts_csv( $list ) {
		$list = is_string( $list ) ? $list : '';

		return ( '' !== trim( $list ) ? rtrim( $list, ', ' ) . ', ' : '' ) . 'text-us-widget, tuw-template';
	}
}
