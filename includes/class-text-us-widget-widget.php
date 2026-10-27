<?php
/**
 * The widget's markup and browser settings, shared by the front end and
 * the settings page preview so the two can't drift apart.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Widget renderer.
 */
final class Text_Us_Widget_Widget {

	/**
	 * The widget's HTML.
	 *
	 * Icons are empty placeholders ([data-tuw-icon]) filled in by script
	 * with the right one for the visitor's device. The Turnstile section is
	 * only there when protection is on (always, hidden, in the preview).
	 *
	 * @param Text_Us_Widget_Plugin $plugin  Plugin.
	 * @param bool                  $preview Markup for the settings page preview.
	 * @return string
	 */
	public static function markup( Text_Us_Widget_Plugin $plugin, $preview = false ) {
		$check = $preview || $plugin->wants_turnstile();
		$x     = '<svg width="%1$d" height="%1$d" viewBox="0 0 10 10" aria-hidden="true" focusable="false"><path d="M1 1l8 8M9 1L1 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

		ob_start();
		?>
<div class="<?php echo esc_attr( self::classes( $plugin ) ); ?>" id="tuw" style="<?php echo esc_attr( self::style_vars( $plugin ) ); ?>">
	<div class="tuw-bar">
		<button type="button" class="tuw-bar-main" data-tuw-open aria-haspopup="dialog" aria-controls="tuw-sheet" aria-expanded="false">
			<span class="tuw-icon tuw-bar-icon" data-tuw-icon></span>
			<span class="tuw-bar-text">
				<span class="tuw-bar-title" data-tuw-text="title"><?php echo esc_html( $plugin->label( 'title' ) ); ?></span>
				<span class="tuw-bar-sub" data-tuw-text="subtitle"><?php echo esc_html( $plugin->label( 'subtitle' ) ); ?></span>
			</span>
			<span class="tuw-bar-btn" data-tuw-text="button"><?php echo esc_html( $plugin->label( 'button' ) ); ?></span>
		</button>
		<button type="button" class="tuw-bar-close" data-tuw-dismiss aria-label="<?php esc_attr_e( 'Hide the Text Us button', 'text-us-widget' ); ?>"><?php printf( $x, 9 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></button>
	</div>
	<div class="tuw-backdrop" data-tuw-close></div>
	<div class="tuw-sheet" id="tuw-sheet" role="dialog" aria-modal="true" aria-labelledby="tuw-title" aria-hidden="true" tabindex="-1">
		<span class="tuw-grabber" aria-hidden="true"></span>
		<button type="button" class="tuw-close" data-tuw-close aria-label="<?php esc_attr_e( 'Close', 'text-us-widget' ); ?>"><?php printf( $x, 11 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></button>
		<span class="tuw-icon tuw-sheet-icon" data-tuw-icon></span>
		<div class="tuw-title" id="tuw-title" data-tuw-text="heading"><?php echo esc_html( $plugin->label( 'heading' ) ); ?></div>
		<p class="tuw-lead" data-tuw-text="text"><?php echo esc_html( $plugin->label( 'text' ) ); ?></p>
		<?php if ( $check ) : ?>
		<div class="tuw-check">
			<p class="tuw-note"><?php esc_html_e( 'Quick human check first', 'text-us-widget' ); ?></p>
			<div class="tuw-turnstile"><?php echo $preview ? '<span class="tuw-preview-turnstile"><span class="tuw-preview-tick"></span>' . esc_html__( 'Verify you are human', 'text-us-widget' ) . '<b>Cloudflare</b></span>' : ''; ?></div>
			<p class="tuw-status" role="status" aria-live="polite"></p>
		</div>
		<?php endif; ?>
		<div class="tuw-ready">
			<a class="tuw-cta" href="#" data-tuw-text="send"><?php echo esc_html( $plugin->label( 'send' ) ); ?></a>
			<p class="tuw-number"<?php echo $preview && '' !== (string) $plugin->get_setting( 'number_display' ) && $plugin->get_setting( 'show_number' ) ? '' : ' hidden'; ?>><?php echo $preview ? esc_html( (string) $plugin->get_setting( 'number_display' ) ) : ''; ?></p>
		</div>
	</div>
</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Classes on the widget's root.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin.
	 * @return string
	 */
	public static function classes( Text_Us_Widget_Plugin $plugin ) {
		$theme  = in_array( $plugin->get_setting( 'theme' ), array( 'dark', 'light', 'auto' ), true ) ? $plugin->get_setting( 'theme' ) : 'dark';
		$corner = 'left' === $plugin->get_setting( 'corner' ) ? 'left' : 'right';
		$layout = 'button' === $plugin->get_setting( 'phone_layout' ) ? 'button' : 'bar';

		return 'tuw tuw-' . $theme . ' tuw-corner-' . $corner . ' tuw-pl-' . $layout;
	}

	/**
	 * Inline CSS variables: the accent color, the text color on it and the
	 * distance from the screen edges.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin.
	 * @return string
	 */
	public static function style_vars( Text_Us_Widget_Plugin $plugin ) {
		$accent = sanitize_hex_color( (string) $plugin->get_setting( 'accent' ) );
		$accent = $accent ? $accent : '#0a84ff';

		return sprintf(
			'--tuw-accent:%1$s;--tuw-on-accent:%2$s;--tuw-x:%3$dpx;--tuw-y:%4$dpx',
			$accent,
			self::on_accent( $accent ),
			max( 0, min( 200, (int) $plugin->get_setting( 'offset_x' ) ) ),
			max( 0, min( 200, (int) $plugin->get_setting( 'offset_y' ) ) )
		);
	}

	/**
	 * White or black text, whichever reads better on the accent color.
	 * White unless the color is very light: iOS blue (#0a84ff) has white
	 * text on iPhones, even though black scores higher on paper.
	 *
	 * @param string $hex Color, #rrggbb or #rgb.
	 * @return string
	 */
	public static function on_accent( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return '#fff';
		}

		$channel = function ( $part ) {
			$c = hexdec( $part ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};

		$luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );

		return $luminance > 0.55 ? '#000' : '#fff';
	}

	/**
	 * Icon URLs: a = Apple devices, g = Android, o = everything else.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin.
	 * @return array{a:string,g:string,o:string}
	 */
	public static function icons( Text_Us_Widget_Plugin $plugin ) {
		$icons = self::app_icons();

		if ( 'site' === $plugin->get_setting( 'icon' ) ) {
			$site = get_site_icon_url( 128 );
			if ( $site ) {
				$icons = array(
					'a' => $site,
					'g' => $site,
					'o' => $site,
				);
			}
		}

		/**
		 * Filters the widget's icon URLs.
		 *
		 * @param array $icons a = iPhone, iPad and Mac; g = Android; o = other devices.
		 */
		return (array) apply_filters( 'text_us_widget_icons', $icons );
	}

	/**
	 * The bundled Messages app icons: a = iMessage (Apple devices),
	 * g = Messages (Android), o = a plain one for everything else.
	 *
	 * @return array{a:string,g:string,o:string}
	 */
	public static function app_icons() {
		$base = TEXT_US_WIDGET_URL . 'assets/';
		$ver  = '?ver=' . rawurlencode( TEXT_US_WIDGET_VERSION );

		return array(
			'a' => $base . 'imessage.svg' . $ver,
			'g' => $base . 'android-messages.webp' . $ver,
			'o' => $base . 'message.svg' . $ver,
		);
	}

	/**
	 * Settings for widget.js (see the key list at the top of it).
	 *
	 * @param Text_Us_Widget_Plugin $plugin  Plugin.
	 * @param int                   $post_id Page being viewed, or 0.
	 * @return array<string,mixed>
	 */
	public static function config( Text_Us_Widget_Plugin $plugin, $post_id ) {
		$devices = array();
		foreach ( $plugin->devices() as $key ) {
			$devices[] = Text_Us_Widget_Plugin::DEVICES[ $key ];
		}

		$theme  = (string) $plugin->get_setting( 'theme' );
		$config = array(
			'u'  => self::rest_path( 'hit' ),
			'dv' => $devices,
			'i'  => self::icons( $plugin ),
			'p'  => (int) $post_id,
			'w'  => max( 0, min( 600, (int) $plugin->get_setting( 'delay' ) ) ),
			'h'  => max( 0, min( 365, (int) $plugin->get_setting( 'hide_days' ) ) ),
			'ia' => $plugin->get_setting( 'on_interaction' ) ? 1 : 0,
			'th' => in_array( $theme, array( 'dark', 'light', 'auto' ), true ) ? $theme : 'dark',
			'm'  => (string) $plugin->get_setting( 'message' ),
			'l'  => array(
				'loading'   => __( 'Loading…', 'text-us-widget' ),
				'verifying' => __( 'Verifying…', 'text-us-widget' ),
				'failed'    => __( 'We couldn\'t verify you. Please try again.', 'text-us-widget' ),
				'error'     => __( 'Something went wrong. Please try again.', 'text-us-widget' ),
				'snag'      => __( 'The check hit a snag. Retrying…', 'text-us-widget' ),
				'expired'   => __( 'The check expired. Please try again.', 'text-us-widget' ),
				'timeout'   => __( 'The check timed out. Please try again.', 'text-us-widget' ),
				'noload'    => __( 'Couldn\'t load the human check. Please turn off content blockers or try again.', 'text-us-widget' ),
				'blocked'   => __( 'We couldn\'t verify you right now. Please try again later.', 'text-us-widget' ),
				'code'      => __( 'Code:', 'text-us-widget' ),
			),
		);

		if ( $plugin->wants_turnstile() ) {
			// The number stays on the server until Cloudflare confirms the check.
			$config['r'] = self::rest_path( 'reveal' );
			$config['k'] = Text_Us_Widget_Turnstile::site_key( $plugin );
		} else {
			$config['n'] = (string) $plugin->get_setting( 'number' );
			$config['x'] = $plugin->get_setting( 'direct' ) ? 1 : 0;

			if ( $plugin->get_setting( 'show_number' ) ) {
				$config['s'] = (string) $plugin->get_setting( 'number_display' );
			}
		}

		if ( $plugin->get_setting( 'ga4' ) ) {
			$config['ga'] = 1;
		}

		/**
		 * Filters the widget's browser settings for the current page.
		 *
		 * @param array $config Settings (see assets/widget.js for the keys).
		 */
		return (array) apply_filters( 'text_us_widget_config', $config );
	}

	/**
	 * Site-relative URL of one of the plugin's endpoints.
	 *
	 * @param string $route "hit" or "reveal".
	 * @return string
	 */
	public static function rest_path( $route ) {
		return wp_make_link_relative( rest_url( Text_Us_Widget_Api::NS . '/' . $route ) );
	}
}
