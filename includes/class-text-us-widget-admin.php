<?php
/**
 * Admin side: the Content Powered > Text Us Widget page and the
 * Plugins-screen link.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Text_Us_Widget_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'text-us-widget';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'message-circle-more';

	/**
	 * Settings tabs: id => Lucide icon.
	 */
	const TABS = array(
		'overview'   => 'layout-dashboard',
		'widget'     => 'message-square-text',
		'display'    => 'monitor-smartphone',
		'protection' => 'shield-check',
		'advanced'   => 'sliders-horizontal',
	);

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'text_us_widget_admin';

	/**
	 * Report periods offered on the Overview tab, in days.
	 */
	const RANGES = array( 7, 30, 90 );

	/**
	 * Plugin instance.
	 *
	 * @var Text_Us_Widget_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Text_Us_Widget_Plugin $plugin Plugin instance.
	 */
	public function __construct( Text_Us_Widget_Plugin $plugin ) {
		$this->plugin = $plugin;

		Text_Us_Widget_Menu::init();
		Text_Us_Widget_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_text_us_widget_test_turnstile', array( $this, 'ajax_test_turnstile' ) );
		add_action( 'wp_ajax_text_us_widget_reset_stats', array( $this, 'ajax_reset_stats' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( TEXT_US_WIDGET_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'text-us-widget' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Text_Us_Widget_Icons::get( self::ICON, 17, 'text-us-widget-menu-icon' );
		$menu_title = $icon . '<span class="text-us-widget-menu-label">' . esc_html__( 'Text Us Widget', 'text-us-widget' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Text_Us_Widget_Menu::SLUG,
			__( 'Text Us Widget', 'text-us-widget' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' tuw-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one
	 * form saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Text_Us_Widget_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Text_Us_Widget_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = Text_Us_Widget_Plugin::defaults();
		$saved    = $this->plugin->get_settings();
		$flag     = function ( $key ) use ( $in ) {
			return empty( $in[ $key ] ) ? 0 : 1;
		};
		$number   = function ( $key, $min, $max ) use ( $in, $defaults ) {
			$value = isset( $in[ $key ] ) && is_numeric( $in[ $key ] ) ? (int) $in[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};
		// options.php has already unslashed the values.
		$text   = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
		};
		$choice = function ( $key, array $allowed ) use ( $text, $defaults ) {
			return in_array( $text( $key ), $allowed, true ) ? $text( $key ) : $defaults[ $key ];
		};

		$devices = isset( $in['devices'] ) && is_array( $in['devices'] ) ? array_filter( $in['devices'], 'is_string' ) : array();
		$accent  = sanitize_hex_color( trim( $text( 'accent' ) ) );

		$out = array(
			'enabled'         => $flag( 'enabled' ),
			'number'          => $saved['number'],
			'number_display'  => $saved['number_display'],
			'message'         => self::clip( sanitize_text_field( $text( 'message' ) ), 300 ),
			'show_number'     => $flag( 'show_number' ),
			'devices'         => array_values( array_intersect( array_keys( Text_Us_Widget_Plugin::DEVICES ), $devices ) ),
			'icon'            => $choice( 'icon', array( 'app', 'site' ) ),
			'corner'          => $choice( 'corner', array( 'right', 'left' ) ),
			'phone_layout'    => $choice( 'phone_layout', array( 'bar', 'button' ) ),
			'theme'           => $choice( 'theme', array( 'dark', 'light', 'auto' ) ),
			'accent'          => $accent ? strtolower( $accent ) : $defaults['accent'],
			'offset_x'        => $number( 'offset_x', 0, 200 ),
			'offset_y'        => $number( 'offset_y', 0, 200 ),
			'delay'           => $number( 'delay', 0, 600 ),
			'hide_days'       => $number( 'hide_days', 0, 365 ),
			'on_interaction'  => $flag( 'on_interaction' ),
			'exclude'         => implode( "\n", Text_Us_Widget_Frontend::exclude_rules( $text( 'exclude' ) ) ),
			'turnstile'       => $flag( 'turnstile' ),
			'site_key'        => $saved['site_key'],
			'secret_key'      => $saved['secret_key'],
			'direct'          => $flag( 'direct' ),
			'exclude_editors' => $flag( 'exclude_editors' ),
			'ga4'             => $flag( 'ga4' ),
			'retention'       => $number( 'retention', 0, 120 ),
		);

		// Wording: the default text is stored as '' so it follows translations.
		foreach ( Text_Us_Widget_Plugin::LABELS as $key ) {
			$value       = self::clip( sanitize_text_field( $text( $key ) ), 'text' === $key ? 300 : 120 );
			$out[ $key ] = Text_Us_Widget_Plugin::default_label( $key ) === $value ? '' : $value;
		}

		// The number: kept as typed for showing, digits (and +) for the link.
		$typed = trim( sanitize_text_field( $text( 'number_display' ) ) );
		if ( '' === $typed ) {
			$out['number']         = '';
			$out['number_display'] = '';
		} elseif ( '' !== Text_Us_Widget_Plugin::normalize_number( $typed ) ) {
			$out['number']         = Text_Us_Widget_Plugin::normalize_number( $typed );
			$out['number_display'] = self::clip( $typed, 40 );
		} else {
			add_settings_error( self::PAGE, 'tuw-number', __( 'That phone number doesn\'t look right, so it wasn\'t saved. Use digits, spaces, dashes and brackets, with + and the country code in front (for example +1 555 123 4567).', 'text-us-widget' ), 'error' );
		}

		// Turnstile keys. A blank secret keeps the saved one, so it never has
		// to be printed back into the page.
		$site_key = trim( sanitize_text_field( $text( 'site_key' ) ) );
		if ( '' === $site_key || Text_Us_Widget_Turnstile::valid_key( $site_key ) ) {
			$out['site_key'] = $site_key;
		} else {
			add_settings_error( self::PAGE, 'tuw-site-key', __( 'That Turnstile site key doesn\'t look right (it should start with 0x), so the old one was kept.', 'text-us-widget' ), 'error' );
		}

		$secret = trim( sanitize_text_field( $text( 'secret_key' ) ) );
		if ( ! empty( $in['secret_clear'] ) ) {
			$out['secret_key'] = '';
		} elseif ( Text_Us_Widget_Turnstile::valid_key( $secret ) ) {
			$out['secret_key'] = $secret;
		} elseif ( '' !== $secret ) {
			add_settings_error( self::PAGE, 'tuw-secret-key', __( 'That Turnstile secret key doesn\'t look right (it should start with 0x), so the old one was kept.', 'text-us-widget' ), 'error' );
		}

		if ( $out['enabled'] && ! $out['devices'] ) {
			add_settings_error( self::PAGE, 'tuw-devices', __( 'No devices are ticked, so the widget won\'t show anywhere. Pick at least one on the Display tab.', 'text-us-widget' ), 'warning' );
		}

		$keys_ready = Text_Us_Widget_Turnstile::valid_key( Text_Us_Widget_Turnstile::site_key_constant() ? (string) TEXT_US_WIDGET_TURNSTILE_SITE_KEY : $out['site_key'] )
			&& Text_Us_Widget_Turnstile::valid_key( Text_Us_Widget_Turnstile::secret_key_constant() ? (string) TEXT_US_WIDGET_TURNSTILE_SECRET_KEY : $out['secret_key'] );

		if ( $out['enabled'] && $out['turnstile'] && ! $keys_ready ) {
			add_settings_error( self::PAGE, 'tuw-keys', __( 'Turnstile is on but both keys aren\'t set yet, so the widget is hidden to keep your number private. Add them on the Spam Protection tab.', 'text-us-widget' ), 'warning' );
		}

		return $out;
	}

	/**
	 * Cut a string to a number of characters.
	 *
	 * @param string $value Text.
	 * @param int    $chars Most characters.
	 * @return string
	 */
	private static function clip( $value, $chars ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $chars ) : substr( $value, 0, $chars );
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="text-us-widget-menu-icon-style">'
			. '#adminmenu a:has(>.text-us-widget-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .text-us-widget-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .text-us-widget-menu-icon,#adminmenu a:hover .text-us-widget-menu-icon,#adminmenu a:focus .text-us-widget-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'text-us-widget-preview', TEXT_US_WIDGET_URL . 'assets/widget.min.css', array(), TEXT_US_WIDGET_VERSION );
		wp_enqueue_style( 'text-us-widget-admin', TEXT_US_WIDGET_URL . 'assets/admin.css', array( 'text-us-widget-preview' ), TEXT_US_WIDGET_VERSION );
		wp_enqueue_script( 'text-us-widget-admin', TEXT_US_WIDGET_URL . 'assets/admin.js', array( 'wp-color-picker' ), TEXT_US_WIDGET_VERSION, true );

		$defaults = array();
		foreach ( Text_Us_Widget_Plugin::LABELS as $key ) {
			$defaults[ $key ] = Text_Us_Widget_Plugin::default_label( $key );
		}

		$site_icon = get_site_icon_url( 128 );

		wp_localize_script(
			'text-us-widget-admin',
			'text_us_widget_admin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'defaults' => $defaults,
				'icons'    => array(
					'app'  => Text_Us_Widget_Widget::app_icons(),
					'site' => $site_icon ? $site_icon : '',
				),
				'i18n'     => array(
					'testing'     => __( 'Loading the check…', 'text-us-widget' ),
					'verifying'   => __( 'Checking the token with Cloudflare…', 'text-us-widget' ),
					'noSiteKey'   => __( 'Enter a site key first.', 'text-us-widget' ),
					'badSiteKey'  => __( 'Cloudflare says this site key isn\'t valid. Copy it again from the Turnstile dashboard.', 'text-us-widget' ),
					'badDomain'   => __( 'Cloudflare refused to run the check on this domain. Add it under Hostnames in the widget\'s Turnstile settings.', 'text-us-widget' ),
					'clientError' => __( 'The check couldn\'t run (Cloudflare error %s).', 'text-us-widget' ),
					'noLoad'      => __( 'Couldn\'t load Turnstile from challenges.cloudflare.com. Is a content blocker or security policy stopping it?', 'text-us-widget' ),
					'failed'      => __( 'Something went wrong. Please try again.', 'text-us-widget' ),
					'confirm'     => __( 'Delete every recorded tap? This can\'t be undone.', 'text-us-widget' ),
					'reset'       => __( 'All taps deleted.', 'text-us-widget' ),
					'badNumber'   => __( 'Use digits, spaces, dashes and brackets, with + and the country code in front.', 'text-us-widget' ),
					'noPlus'      => __( 'Add + and the country code (for example +1 for the US) so the link works for visitors abroad too.', 'text-us-widget' ),
					'linkPreview' => __( 'Link: %s', 'text-us-widget' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Text_Us_Widget_Icons::get( $name, $size );
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'activity'   => array(
				'panel'  => 'overview',
				'icon'   => 'chart-column',
				'title'  => __( 'Activity', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_activity',
			),
			'by_device'  => array(
				'panel'  => 'overview',
				'icon'   => 'smartphone',
				'title'  => __( 'By device', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_by_device',
			),
			'top_pages'  => array(
				'panel'  => 'overview',
				'icon'   => 'file-text',
				'title'  => __( 'Top pages', 'text-us-widget' ),
				'intro'  => __( 'The pages people opened the widget on most. Home, archive and search pages are grouped as "Other pages".', 'text-us-widget' ),
				'render' => 'render_top_pages',
			),
			'number'     => array(
				'panel'  => 'widget',
				'icon'   => 'phone',
				'title'  => __( 'Your number', 'text-us-widget' ),
				'intro'  => __( 'The number texts go to: a mobile, or a business line that can receive texts (most VoIP and "text-enabled landline" services can).', 'text-us-widget' ),
				'render' => 'render_number',
			),
			'wording'    => array(
				'panel'  => 'widget',
				'icon'   => 'type',
				'title'  => __( 'Wording', 'text-us-widget' ),
				'intro'  => __( 'Keep the button short. The preview updates as you type.', 'text-us-widget' ),
				'render' => 'render_wording',
			),
			'devices'    => array(
				'panel'  => 'display',
				'icon'   => 'monitor-smartphone',
				'title'  => __( 'Show on these devices', 'text-us-widget' ),
				'intro'  => __( 'Text links open the Messages app on iPhone, iPad and Mac, which all have it built in, and the default texting app on Android phones. Most other computers can\'t open them, so the widget stays hidden there unless you tick them.', 'text-us-widget' ),
				'render' => 'render_devices',
			),
			'look'       => array(
				'panel'  => 'display',
				'icon'   => 'palette',
				'title'  => __( 'Position and style', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_look',
			),
			'timing'     => array(
				'panel'  => 'display',
				'icon'   => 'timer',
				'title'  => __( 'Loading and timing', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_timing',
			),
			'pages'      => array(
				'panel'  => 'display',
				'icon'   => 'eye-off',
				'title'  => __( 'Hide on these pages', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_pages',
			),
			'turnstile'  => array(
				'panel'  => 'protection',
				'icon'   => 'shield-check',
				'title'  => __( 'Cloudflare Turnstile', 'text-us-widget' ),
				'intro'  => __( 'Bots scrape phone numbers from web pages and sell them to spam lists. With Turnstile on, your number is never in the page: it\'s only sent after Cloudflare confirms a real person opened the widget. Turnstile is free, usually needs no clicks, and works without moving your site to Cloudflare.', 'text-us-widget' ),
				'render' => 'render_turnstile',
			),
			'no_check'   => array(
				'panel'  => 'protection',
				'icon'   => 'mouse-pointer-click',
				'title'  => __( 'Without Turnstile', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_no_check',
			),
			'tracking'   => array(
				'panel'  => 'advanced',
				'icon'   => 'chart-line',
				'title'  => __( 'Tap counting', 'text-us-widget' ),
				'intro'  => __( 'Each tap is saved with the date, the type of device and the page. No IP address, name or number is stored.', 'text-us-widget' ),
				'render' => 'render_tracking',
			),
			'devnotes'   => array(
				'panel'  => 'advanced',
				'icon'   => 'code',
				'title'  => __( 'For developers', 'text-us-widget' ),
				'intro'  => '',
				'render' => 'render_developer',
			),
		);
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'overview'   => __( 'Overview', 'text-us-widget' ),
			'widget'     => __( 'Widget', 'text-us-widget' ),
			'display'    => __( 'Display', 'text-us-widget' ),
			'protection' => __( 'Spam Protection', 'text-us-widget' ),
			'advanced'   => __( 'Advanced', 'text-us-widget' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Device names, by key and by the short code the browser reports.
	 *
	 * @return array<string,string>
	 */
	private static function device_names() {
		return array(
			'mac'            => __( 'Mac', 'text-us-widget' ),
			'iphone'         => __( 'iPhone', 'text-us-widget' ),
			'ipad'           => __( 'iPad', 'text-us-widget' ),
			'android'        => __( 'Android phones', 'text-us-widget' ),
			'android_tablet' => __( 'Android tablets', 'text-us-widget' ),
			'windows'        => __( 'Windows', 'text-us-widget' ),
			'other'          => __( 'Other computers', 'text-us-widget' ),
		);
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save, preview and status
	 * on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="tuw-header">
			<div class="tuw-header-title">
				<span class="tuw-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="tuw-version"><?php echo esc_html( 'v' . TEXT_US_WIDGET_VERSION ); ?></span>
			</div>
			<nav class="tuw-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'text-us-widget' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="tuw-tab" role="tab" data-tuw-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap tuw-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			?>

			<form action="options.php" method="post" class="tuw-form" autocomplete="off">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<?php $this->render_sidebar(); ?>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings box.
	 *
	 * @param string               $id      Section ID.
	 * @param array<string,string> $section Section details from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox tuw-box" id="tuw-box-<?php echo esc_attr( str_replace( '_', '-', $id ) ); ?>" data-tuw-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="tuw-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section['render'] ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Text_Us_Widget_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="tuw-checkbox"><input type="checkbox" name="%1$s" value="1" data-tuw-key="%2$s" %3$s> <span>%4$s</span></label>',
			esc_attr( self::name( $key ) ),
			esc_attr( $key ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Number input.
	 *
	 * @param string $key    Setting key.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @return void
	 */
	private function number( $key, $min, $max, $suffix = '' ) {
		printf(
			'<input type="number" class="small-text" id="tuw-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" data-tuw-key="%1$s"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $this->plugin->get_setting( $key ),
			(int) $min,
			(int) $max,
			esc_html( $suffix )
		);
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $depends   Setting that has to be on for this row to
	 *                            apply ("!key" for one that has to be off).
	 * @return void
	 */
	private function row( $label, $content, $label_for = '', $depends = '' ) {
		echo '<tr' . ( '' !== $depends ? ' data-tuw-depends="' . esc_attr( $depends ) . '"' : '' ) . '><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * A set of radio buttons drawn as cards.
	 *
	 * @param string                           $key     Setting key.
	 * @param array<string,array<int,string>> $options value => array( label, description, icon ).
	 * @param string                           $class   Extra class for the group.
	 * @return void
	 */
	private function radio_cards( $key, array $options, $class = '' ) {
		$current = (string) $this->plugin->get_setting( $key );

		echo '<div class="tuw-cards ' . esc_attr( $class ) . '" role="radiogroup">';
		foreach ( $options as $value => $option ) {
			printf(
				'<label class="tuw-card"><input type="radio" name="%1$s" value="%2$s" data-tuw-key="%3$s" %4$s><span class="tuw-card-art">%5$s</span><span class="tuw-card-text"><strong>%6$s</strong>%7$s</span></label>',
				esc_attr( self::name( $key ) ),
				esc_attr( $value ),
				esc_attr( $key ),
				checked( $current, (string) $value, false ),
				$option[2], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG or static markup from this class.
				esc_html( $option[0] ),
				'' !== $option[1] ? '<span>' . esc_html( $option[1] ) . '</span>' : ''
			);
		}
		echo '</div>';
	}

	/*
	 * Overview -------------------------------------------------------------------
	 */

	/**
	 * The report period picked on the Overview tab.
	 *
	 * @return int Days.
	 */
	private static function range() {
		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report filter.

		return in_array( $range, self::RANGES, true ) ? $range : 30;
	}

	/**
	 * Overview: tiles and the daily chart.
	 *
	 * @return void
	 */
	public function render_activity() {
		$range  = self::range();
		$now    = Text_Us_Widget_Stats::totals( $range );
		$before = Text_Us_Widget_Stats::totals( $range, 1 );
		$rate   = $now['o'] > 0 ? round( 100 * min( $now['t'], $now['o'] ) / $now['o'] ) : 0;
		$tiles  = array(
			array( 'square-mouse-pointer', $now['o'], $before['o'], __( 'Opened', 'text-us-widget' ), number_format_i18n( $now['o'] ) ),
		);

		if ( $this->plugin->wants_turnstile() || $now['v'] > 0 ) {
			$tiles[] = array( 'shield-check', $now['v'], $before['v'], __( 'Passed the check', 'text-us-widget' ), number_format_i18n( $now['v'] ) );
		}

		$tiles[] = array( 'message-square-text', $now['t'], $before['t'], __( 'Texts started', 'text-us-widget' ), number_format_i18n( $now['t'] ) );
		/* translators: %s: percentage. */
		$tiles[] = array( 'gauge', -1, -1, __( 'Text rate', 'text-us-widget' ), sprintf( __( '%s%%', 'text-us-widget' ), number_format_i18n( $rate ) ) );
		?>
		<div class="tuw-range" role="group" aria-label="<?php esc_attr_e( 'Period', 'text-us-widget' ); ?>">
			<?php foreach ( self::RANGES as $days ) : ?>
				<a class="tuw-range-link<?php echo $days === $range ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $days, admin_url( 'admin.php?page=' . self::PAGE ) ) . '#overview' ); ?>"<?php echo $days === $range ? ' aria-current="true"' : ''; ?>>
					<?php
					/* translators: %d: number of days. */
					echo esc_html( sprintf( __( '%d days', 'text-us-widget' ), $days ) );
					?>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="tuw-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="tuw-stat">
					<span class="tuw-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="tuw-stat-num"><?php echo esc_html( $tile[4] ); ?><?php echo $tile[1] >= 0 ? self::change( $tile[1], $tile[2] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in change(). ?></span>
					<span class="tuw-stat-label"><?php echo esc_html( $tile[3] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		$this->render_chart( $range );

		$why = $this->plugin->why_off();
		if ( '' !== $why ) :
			?>
			<p class="tuw-callout is-warning"><?php echo self::icon( 'circle-alert', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $this->why_off_text( $why ) ); ?></span></p>
		<?php elseif ( 0 === array_sum( $now ) ) : ?>
			<p class="tuw-callout"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'No taps in this period yet. Taps by logged-in editors aren\'t counted (see Advanced), so try it from a phone that isn\'t logged in.', 'text-us-widget' ); ?></span></p>
			<?php
		endif;
	}

	/**
	 * A small "up 12%" note against the period before.
	 *
	 * @param int $now    This period.
	 * @param int $before The period before.
	 * @return string
	 */
	private static function change( $now, $before ) {
		if ( $before <= 0 || $now === $before ) {
			return '';
		}

		$pct = (int) round( 100 * ( $now - $before ) / $before );
		$up  = $pct > 0;

		return sprintf(
			'<span class="tuw-change %1$s" title="%2$s">%3$s%4$s</span>',
			$up ? 'is-up' : 'is-down',
			esc_attr__( 'Compared with the period before', 'text-us-widget' ),
			self::icon( $up ? 'arrow-up-right' : 'arrow-down-right', 13 ),
			esc_html( number_format_i18n( abs( $pct ) ) . '%' )
		);
	}

	/**
	 * Daily bars: opened (light) with texts started (solid) in front.
	 *
	 * @param int $range Days.
	 * @return void
	 */
	private function render_chart( $range ) {
		$days = Text_Us_Widget_Stats::daily( $range );
		$max  = 1;

		foreach ( $days as $day ) {
			$max = max( $max, $day['o'], $day['t'] );
		}

		$keys  = array_keys( $days );
		$label = function ( $day ) {
			return wp_date( 'M j', strtotime( $day . ' 12:00:00 UTC' ), new DateTimeZone( 'UTC' ) );
		};
		?>
		<div class="tuw-chart">
			<div class="tuw-chart-scale" aria-hidden="true"><span><?php echo esc_html( number_format_i18n( $max ) ); ?></span><span>0</span></div>
			<div class="tuw-chart-bars">
				<?php
				foreach ( $days as $day => $counts ) :
					$tip = sprintf(
						/* translators: 1: date, 2: times opened, 3: texts started. */
						__( '%1$s: opened %2$s, texts %3$s', 'text-us-widget' ),
						$label( $day ),
						number_format_i18n( $counts['o'] ),
						number_format_i18n( $counts['t'] )
					);
					?>
					<span class="tuw-chart-col" title="<?php echo esc_attr( $tip ); ?>">
						<span class="tuw-chart-o" style="height:<?php echo esc_attr( round( 100 * $counts['o'] / $max, 2 ) ); ?>%"></span>
						<span class="tuw-chart-t" style="height:<?php echo esc_attr( round( 100 * $counts['t'] / $max, 2 ) ); ?>%"></span>
						<span class="screen-reader-text"><?php echo esc_html( $tip ); ?></span>
					</span>
				<?php endforeach; ?>
			</div>
			<div class="tuw-chart-axis" aria-hidden="true">
				<span><?php echo esc_html( $label( reset( $keys ) ) ); ?></span>
				<span><?php echo esc_html( $label( $keys[ (int) floor( count( $keys ) / 2 ) ] ) ); ?></span>
				<span><?php echo esc_html( $label( end( $keys ) ) ); ?></span>
			</div>
		</div>
		<p class="tuw-legend">
			<span><i class="tuw-swatch is-o"></i><?php esc_html_e( 'Opened', 'text-us-widget' ); ?></span>
			<span><i class="tuw-swatch is-t"></i><?php esc_html_e( 'Texts started', 'text-us-widget' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Overview: opens and texts per device.
	 *
	 * @return void
	 */
	public function render_by_device() {
		$rows  = Text_Us_Widget_Stats::devices( self::range() );
		$names = self::device_names();
		$codes = array_flip( Text_Us_Widget_Plugin::DEVICES );
		$total = 0;

		foreach ( $rows as $row ) {
			$total += $row['o'];
		}

		if ( ! $rows ) {
			echo '<p class="tuw-empty">' . self::icon( 'info', 16 ) . esc_html__( 'Nothing recorded in this period yet.', 'text-us-widget' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}
		?>
		<table class="widefat striped tuw-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Device', 'text-us-widget' ); ?></th>
					<th scope="col" class="tuw-num"><?php esc_html_e( 'Opened', 'text-us-widget' ); ?></th>
					<th scope="col" class="tuw-num"><?php esc_html_e( 'Texts', 'text-us-widget' ); ?></th>
					<th scope="col" class="tuw-share-col"><?php esc_html_e( 'Share of opens', 'text-us-widget' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $code => $row ) :
					$key   = isset( $codes[ $code ] ) ? $codes[ $code ] : 'other';
					$share = $total > 0 ? round( 100 * $row['o'] / $total ) : 0;
					?>
					<tr>
						<td><span class="tuw-device"><?php echo self::device_icon( $key, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( isset( $names[ $key ] ) ? $names[ $key ] : $code ); ?></span></td>
						<td class="tuw-num"><?php echo esc_html( number_format_i18n( $row['o'] ) ); ?></td>
						<td class="tuw-num"><?php echo esc_html( number_format_i18n( $row['t'] ) ); ?></td>
						<td class="tuw-share-col"><span class="tuw-share"><span style="width:<?php echo esc_attr( $share ); ?>%"></span></span><span class="tuw-share-pct"><?php echo esc_html( number_format_i18n( $share ) . '%' ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The icon for a device type.
	 *
	 * @param string $key  Device key.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function device_icon( $key, $size = 18 ) {
		$icons = array(
			'mac'            => 'laptop',
			'iphone'         => 'smartphone',
			'ipad'           => 'tablet',
			'android'        => 'smartphone',
			'android_tablet' => 'tablet',
			'windows'        => 'monitor',
			'other'          => 'monitor-cog',
		);

		return self::icon( isset( $icons[ $key ] ) ? $icons[ $key ] : 'monitor', $size );
	}

	/**
	 * Overview: the pages people tap from most.
	 *
	 * @return void
	 */
	public function render_top_pages() {
		$rows = Text_Us_Widget_Stats::pages( self::range(), 10 );

		if ( ! $rows ) {
			echo '<p class="tuw-empty">' . self::icon( 'info', 16 ) . esc_html__( 'Nothing recorded in this period yet.', 'text-us-widget' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}
		?>
		<table class="widefat striped tuw-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'text-us-widget' ); ?></th>
					<th scope="col" class="tuw-num"><?php esc_html_e( 'Opened', 'text-us-widget' ); ?></th>
					<th scope="col" class="tuw-num"><?php esc_html_e( 'Texts', 'text-us-widget' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php
							$post = $row['post_id'] ? get_post( $row['post_id'] ) : null;
							if ( $post ) {
								$title = get_the_title( $post );
								printf(
									'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
									esc_url( get_permalink( $post ) ),
									esc_html( '' !== $title ? $title : __( '(no title)', 'text-us-widget' ) )
								);
							} else {
								echo '<span class="tuw-muted">' . esc_html__( 'Other pages', 'text-us-widget' ) . '</span>';
							}
							?>
						</td>
						<td class="tuw-num"><?php echo esc_html( number_format_i18n( $row['o'] ) ); ?></td>
						<td class="tuw-num"><?php echo esc_html( number_format_i18n( $row['t'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/*
	 * Widget -----------------------------------------------------------------------
	 */

	/**
	 * Widget: the number and the pre-filled message.
	 *
	 * @return void
	 */
	public function render_number() {
		$plugin = $this->plugin;
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Mobile number', 'text-us-widget' ),
				function () use ( $plugin ) {
					printf(
						'<input type="tel" class="regular-text" id="tuw-number" name="%1$s" value="%2$s" placeholder="+1 555 123 4567" maxlength="40" data-tuw-key="number_display">',
						esc_attr( self::name( 'number_display' ) ),
						esc_attr( (string) $plugin->get_setting( 'number_display' ) )
					);
					echo '<p class="description tuw-number-hint" data-tuw-number-hint>' . esc_html__( 'With + and the country code, so it works for visitors abroad too.', 'text-us-widget' ) . '</p>';
				},
				'tuw-number'
			);

			$this->row(
				__( 'Pre-filled message', 'text-us-widget' ),
				function () use ( $plugin ) {
					printf(
						'<input type="text" class="large-text" id="tuw-message" name="%1$s" value="%2$s" maxlength="300" placeholder="%3$s">',
						esc_attr( self::name( 'message' ) ),
						esc_attr( (string) $plugin->get_setting( 'message' ) ),
						esc_attr__( 'Hi! I have a question about…', 'text-us-widget' )
					);
					echo '<p class="description">' . esc_html__( 'Optional. Typed into the message for them, ready to edit or send. Handy for knowing a text came from your website.', 'text-us-widget' ) . '</p>';
				},
				'tuw-message'
			);

			$this->row(
				__( 'Show the number', 'text-us-widget' ),
				function () {
					$this->checkbox( 'show_number', __( 'Show the number under the Send button', 'text-us-widget' ), __( 'For people who\'d rather copy it, or whose computer can\'t open text links. With Turnstile on, it only appears after the check.', 'text-us-widget' ) );
				}
			);
			?>
		</table>
		<?php
	}

	/**
	 * Widget: every piece of text.
	 *
	 * @return void
	 */
	public function render_wording() {
		$fields = array(
			'title'    => array( __( 'Title', 'text-us-widget' ), __( 'First line of the button.', 'text-us-widget' ), 120 ),
			'subtitle' => array( __( 'Subtitle', 'text-us-widget' ), __( 'Second line, on tablets and computers.', 'text-us-widget' ), 120 ),
			'button'   => array( __( 'Button label', 'text-us-widget' ), '', 30 ),
			'heading'  => array( __( 'Panel heading', 'text-us-widget' ), '', 120 ),
			'text'     => array( __( 'Panel text', 'text-us-widget' ), '', 300 ),
			'send'     => array( __( 'Send button', 'text-us-widget' ), __( 'The link that opens Messages.', 'text-us-widget' ), 60 ),
		);
		?>
		<table class="form-table" role="presentation">
			<?php
			foreach ( $fields as $key => $field ) {
				$this->row(
					$field[0],
					function () use ( $key, $field ) {
						printf(
							'<input type="text" class="%1$s" id="tuw-%2$s" name="%3$s" value="%4$s" placeholder="%5$s" maxlength="%6$d" data-tuw-key="%2$s" data-tuw-label>',
							'button' === $key ? 'regular-text tuw-short' : ( 'text' === $key ? 'large-text' : 'regular-text' ),
							esc_attr( $key ),
							esc_attr( self::name( $key ) ),
							esc_attr( $this->plugin->label( $key ) ),
							esc_attr( Text_Us_Widget_Plugin::default_label( $key ) ),
							(int) $field[2]
						);
						if ( '' !== $field[1] ) {
							echo '<p class="description">' . esc_html( $field[1] ) . '</p>';
						}
					},
					'tuw-' . $key
				);
			}
			?>
		</table>
		<?php
	}

	/*
	 * Display ------------------------------------------------------------------------
	 */

	/**
	 * Display: which devices get the widget.
	 *
	 * @return void
	 */
	public function render_devices() {
		$names   = self::device_names();
		$enabled = $this->plugin->devices();
		$apps    = Text_Us_Widget_Widget::app_icons();
		$notes   = array(
			'mac'            => array( 'apple', 'a', __( 'Opens Messages', 'text-us-widget' ) ),
			'iphone'         => array( 'apple', 'a', __( 'Opens Messages', 'text-us-widget' ) ),
			'ipad'           => array( 'apple', 'a', __( 'Opens Messages', 'text-us-widget' ) ),
			'android'        => array( 'android', 'g', __( 'Opens their texting app', 'text-us-widget' ) ),
			'android_tablet' => array( 'android', 'g', __( 'Only works if the tablet can text', 'text-us-widget' ) ),
			'windows'        => array( '', 'o', __( 'Opens Phone Link, if they\'ve set it up', 'text-us-widget' ) ),
			'other'          => array( '', 'o', __( 'ChromeOS and Linux; most can\'t text', 'text-us-widget' ) ),
		);
		?>
		<div class="tuw-devices">
			<?php foreach ( Text_Us_Widget_Plugin::DEVICES as $key => $code ) : ?>
				<label class="tuw-device-card">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'devices' ) ); ?>[]" value="<?php echo esc_attr( $key ); ?>" data-tuw-device="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $key, $enabled, true ) ); ?>>
					<span class="tuw-device-art">
						<?php echo self::device_icon( $key, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php if ( '' !== $notes[ $key ][0] ) : ?>
							<span class="tuw-device-brand"><?php echo Text_Us_Widget_Icons::brand( $notes[ $key ][0], 11 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
						<?php endif; ?>
					</span>
					<span class="tuw-device-text">
						<strong><?php echo esc_html( $names[ $key ] ); ?></strong>
						<span><img src="<?php echo esc_url( $apps[ $notes[ $key ][1] ] ); ?>" alt="" width="14" height="14"><?php echo esc_html( $notes[ $key ][2] ); ?></span>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description tuw-devices-note"><?php esc_html_e( 'iPhone, iPad and Mac get the iMessage icon; Android gets the Messages icon. The device is checked in the visitor\'s browser, so this works with page caching.', 'text-us-widget' ); ?></p>
		<?php
	}

	/**
	 * Display: corner, phone layout, theme, color, icon and spacing.
	 *
	 * @return void
	 */
	public function render_look() {
		$plugin    = $this->plugin;
		$site_icon = get_site_icon_url( 64 );
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Corner', 'text-us-widget' ),
				function () {
					$this->radio_cards(
						'corner',
						array(
							'right' => array( __( 'Bottom right', 'text-us-widget' ), '', '<span class="tuw-mini is-right"><i></i></span>' ),
							'left'  => array( __( 'Bottom left', 'text-us-widget' ), '', '<span class="tuw-mini is-left"><i></i></span>' ),
						),
						'is-compact'
					);
					echo '<p class="description">' . esc_html__( 'On tablets and computers. Pick the corner your chat or "back to top" button isn\'t in.', 'text-us-widget' ) . '</p>';
				}
			);

			$this->row(
				__( 'On phones', 'text-us-widget' ),
				function () {
					$this->radio_cards(
						'phone_layout',
						array(
							'bar'    => array( __( 'Full-width bar', 'text-us-widget' ), __( 'Title and button', 'text-us-widget' ), '<span class="tuw-mini is-phone is-bar"><i></i></span>' ),
							'button' => array( __( 'Compact button', 'text-us-widget' ), __( 'Icon and button, in the corner', 'text-us-widget' ), '<span class="tuw-mini is-phone is-button"><i></i></span>' ),
						),
						'is-compact'
					);
				}
			);

			$this->row(
				__( 'Colors', 'text-us-widget' ),
				function () {
					$this->radio_cards(
						'theme',
						array(
							'dark'  => array( __( 'Dark', 'text-us-widget' ), '', self::icon( 'moon', 18 ) ),
							'light' => array( __( 'Light', 'text-us-widget' ), '', self::icon( 'sun', 18 ) ),
							'auto'  => array( __( 'Automatic', 'text-us-widget' ), __( 'Matches dark mode', 'text-us-widget' ), self::icon( 'sun-moon', 18 ) ),
						),
						'is-compact is-three'
					);
				}
			);

			$this->row(
				__( 'Button color', 'text-us-widget' ),
				function () use ( $plugin ) {
					printf(
						'<input type="text" class="tuw-color" id="tuw-accent" name="%1$s" value="%2$s" data-default-color="#0a84ff" data-tuw-key="accent">',
						esc_attr( self::name( 'accent' ) ),
						esc_attr( (string) $plugin->get_setting( 'accent' ) )
					);
					echo '<p class="description">' . esc_html__( 'The default is the blue Apple uses. The text on it switches to black for very light colors.', 'text-us-widget' ) . '</p>';
				},
				'tuw-accent'
			);

			$this->row(
				__( 'Icon', 'text-us-widget' ),
				function () use ( $plugin, $site_icon ) {
					$apps = Text_Us_Widget_Widget::app_icons();
					$this->radio_cards(
						'icon',
						array(
							'app'  => array( __( 'Messages app', 'text-us-widget' ), __( 'Matches the visitor\'s device', 'text-us-widget' ), '<img src="' . esc_url( $apps['a'] ) . '" alt="" width="26" height="26"><img src="' . esc_url( $apps['g'] ) . '" alt="" width="26" height="26">' ),
							'site' => array( __( 'Your site icon', 'text-us-widget' ), $site_icon ? '' : __( 'Not set yet', 'text-us-widget' ), $site_icon ? '<img src="' . esc_url( $site_icon ) . '" alt="" width="26" height="26">' : self::icon( 'image', 18 ) ),
						),
						'is-compact'
					);

					if ( ! $site_icon ) {
						printf(
							'<p class="description">%s</p>',
							sprintf(
								/* translators: %s: link to the Site Identity settings. */
								esc_html__( 'Your site icon is set under %s. Until there is one, the Messages icons are used.', 'text-us-widget' ),
								'<a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=title_tagline' ) ) . '">' . esc_html__( 'Appearance > Customize > Site Identity', 'text-us-widget' ) . '</a>'
							)
						);
					}
				}
			);

			$this->row(
				__( 'Distance from the edge', 'text-us-widget' ),
				function () {
					echo '<span class="tuw-inline"><label for="tuw-offset_x">' . esc_html__( 'Side', 'text-us-widget' ) . '</label>';
					$this->number( 'offset_x', 0, 200, 'px' );
					echo '<label for="tuw-offset_y">' . esc_html__( 'Bottom', 'text-us-widget' ) . '</label>';
					$this->number( 'offset_y', 0, 200, 'px' );
					echo '</span>';
					echo '<p class="description">' . esc_html__( 'Move it clear of a cookie banner or another floating button. On phones it\'s kept within 16px of the edge.', 'text-us-widget' ) . '</p>';
				}
			);
			?>
		</table>
		<?php
	}

	/**
	 * Display: when the widget loads and appears.
	 *
	 * @return void
	 */
	public function render_timing() {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Page speed', 'text-us-widget' ),
				function () {
					$this->checkbox( 'on_interaction', __( 'Load on first interaction', 'text-us-widget' ) );
					echo '<p class="description">' . esc_html__( 'The widget isn\'t added until the visitor scrolls, moves the mouse, taps or presses a key. PageSpeed Insights and Lighthouse never interact, so they never see it and your score doesn\'t change. Once it has loaded, a small cookie makes it load straight away on their next page views.', 'text-us-widget' ) . '</p>';
				}
			);

			$this->row(
				__( 'Slide in after', 'text-us-widget' ),
				function () {
					$this->number( 'delay', 0, 600, __( 'seconds, or when the visitor scrolls down', 'text-us-widget' ) );
					echo '<p class="description">' . esc_html__( '0 shows it straight away. On later page views it\'s already in place.', 'text-us-widget' ) . '</p>';
				},
				'tuw-delay'
			);

			$this->row(
				__( 'After it\'s closed', 'text-us-widget' ),
				function () {
					echo '<span class="tuw-inline">' . esc_html__( 'Hide it for', 'text-us-widget' ) . ' ';
					$this->number( 'hide_days', 0, 365, __( 'days', 'text-us-widget' ) );
					echo '</span>';
					echo '<p class="description">' . esc_html__( 'Visitors can close the button with its small X. 0 hides it until they close their browser.', 'text-us-widget' ) . '</p>';
				},
				'tuw-hide_days'
			);
			?>
		</table>
		<?php
	}

	/**
	 * Display: pages to leave the widget off.
	 *
	 * @return void
	 */
	public function render_pages() {
		printf(
			'<textarea class="large-text code" id="tuw-exclude" name="%1$s" rows="4" placeholder="/checkout/&#10;/cart/&#10;/members/*">%2$s</textarea>',
			esc_attr( self::name( 'exclude' ) ),
			esc_textarea( (string) $this->plugin->get_setting( 'exclude' ) )
		);
		echo '<p class="description">' . esc_html__( 'One path per line, like /checkout/. End with * to include everything under it: /members/* covers every page in /members/. Full URLs work too.', 'text-us-widget' ) . '</p>';
	}

	/*
	 * Spam protection -------------------------------------------------------------------
	 */

	/**
	 * Spam protection: Turnstile switch, keys and test.
	 *
	 * @return void
	 */
	public function render_turnstile() {
		$plugin     = $this->plugin;
		$secret     = (string) $plugin->get_setting( 'secret_key' );
		$site_const = Text_Us_Widget_Turnstile::site_key_constant();
		$sec_const  = Text_Us_Widget_Turnstile::secret_key_constant();
		$test_keys  = Text_Us_Widget_Turnstile::is_test_key( Text_Us_Widget_Turnstile::site_key( $plugin ) ) || Text_Us_Widget_Turnstile::is_test_key( Text_Us_Widget_Turnstile::secret_key( $plugin ) );
		?>
		<label class="tuw-master tuw-master-inline">
			<input type="checkbox" name="<?php echo esc_attr( self::name( 'turnstile' ) ); ?>" value="1" data-tuw-key="turnstile" <?php checked( 1, (int) $plugin->get_setting( 'turnstile' ) ); ?>>
			<span><?php esc_html_e( 'Hide my number behind a Turnstile check', 'text-us-widget' ); ?></span>
		</label>

		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Site key', 'text-us-widget' ),
				function () use ( $plugin, $site_const ) {
					printf(
						'<input type="text" class="regular-text code" id="tuw-site-key" name="%1$s" value="%2$s" placeholder="0x4AAAAAAA…" spellcheck="false" %3$s>',
						esc_attr( self::name( 'site_key' ) ),
						esc_attr( $site_const ? (string) TEXT_US_WIDGET_TURNSTILE_SITE_KEY : (string) $plugin->get_setting( 'site_key' ) ),
						$site_const ? 'disabled' : ''
					);
					if ( $site_const ) {
						echo '<p class="description">' . esc_html__( 'Set in wp-config.php (TEXT_US_WIDGET_TURNSTILE_SITE_KEY).', 'text-us-widget' ) . '</p>';
					}
				},
				'tuw-site-key',
				'turnstile'
			);

			$this->row(
				__( 'Secret key', 'text-us-widget' ),
				function () use ( $secret, $sec_const ) {
					if ( $sec_const ) {
						echo '<input type="password" class="regular-text code" value="••••••••••••" disabled>';
						echo '<p class="description">' . esc_html__( 'Set in wp-config.php (TEXT_US_WIDGET_TURNSTILE_SECRET_KEY).', 'text-us-widget' ) . '</p>';
						return;
					}

					printf(
						'<input type="password" class="regular-text code" id="tuw-secret-key" name="%1$s" value="" placeholder="%2$s" autocomplete="new-password" spellcheck="false">',
						esc_attr( self::name( 'secret_key' ) ),
						'' !== $secret ? esc_attr__( 'Saved. Leave blank to keep it.', 'text-us-widget' ) : '0x4AAAAAAA…'
					);

					if ( '' !== $secret ) {
						echo '<p class="description">';
						printf(
							/* translators: %s: last four characters of the saved key. */
							esc_html__( 'A key ending in %s is saved.', 'text-us-widget' ),
							'<code>' . esc_html( substr( $secret, -4 ) ) . '</code>'
						);
						echo ' <label class="tuw-checkbox tuw-clear"><input type="checkbox" name="' . esc_attr( self::name( 'secret_clear' ) ) . '" value="1"> ' . esc_html__( 'Remove it', 'text-us-widget' ) . '</label></p>';
					}
				},
				'tuw-secret-key',
				'turnstile'
			);

			$this->row(
				__( 'Test', 'text-us-widget' ),
				function () {
					echo '<button type="button" class="button" data-tuw-test>' . self::icon( 'flask-conical', 16 ) . esc_html__( 'Test the keys', 'text-us-widget' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
					echo '<div class="tuw-test-area" data-tuw-test-area hidden><div class="tuw-test-widget" data-tuw-test-widget></div></div>';
					echo '<p class="tuw-test-result" data-tuw-test-result role="status" aria-live="polite"></p>';
					echo '<p class="description">' . esc_html__( 'Runs a real check here, with the keys in the form (saved or not), and asks Cloudflare to confirm it.', 'text-us-widget' ) . '</p>';
				},
				'',
				'turnstile'
			);
			?>
		</table>

		<?php if ( $test_keys ) : ?>
			<p class="tuw-callout is-warning"><?php echo self::icon( 'flask-conical', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'These are Cloudflare\'s test keys: every visitor passes, bots included. Use them to try things out, then swap in your own.', 'text-us-widget' ); ?></span></p>
		<?php endif; ?>

		<div class="tuw-steps">
			<h3><?php echo Text_Us_Widget_Icons::brand( 'cloudflare', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Getting your keys (free, about two minutes)', 'text-us-widget' ); ?></h3>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: link to the Cloudflare dashboard. */
						esc_html__( 'Sign in to the %s (a free account is fine) and open Turnstile.', 'text-us-widget' ),
						'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Cloudflare dashboard', 'text-us-widget' ) . self::icon( 'external-link', 12 ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: this site's domain. */
						esc_html__( 'Add a widget, and add %s under Hostnames.', 'text-us-widget' ),
						'<code>' . esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Leave Widget Mode on Managed, then copy the site key and secret key into the boxes above.', 'text-us-widget' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Spam protection: what happens without Turnstile.
	 *
	 * @return void
	 */
	public function render_no_check() {
		?>
		<div data-tuw-depends="!turnstile">
			<p class="tuw-intro"><?php esc_html_e( 'Without Turnstile, the number is in the page\'s code like any other link, where scrapers can read it.', 'text-us-widget' ); ?></p>
			<?php $this->checkbox( 'direct', __( 'Skip the panel: tapping the button opens Messages straight away', 'text-us-widget' ), __( 'One tap fewer for visitors. The panel\'s heading and text aren\'t shown.', 'text-us-widget' ) ); ?>
		</div>
		<p class="tuw-callout" data-tuw-depends-show="turnstile"><?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'With Turnstile on, the panel always opens first, because that\'s where the check runs.', 'text-us-widget' ); ?></span></p>
		<?php
	}

	/*
	 * Advanced -----------------------------------------------------------------------------
	 */

	/**
	 * Advanced: counting options and the reset button.
	 *
	 * @return void
	 */
	public function render_tracking() {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Your team', 'text-us-widget' ),
				function () {
					$this->checkbox( 'exclude_editors', __( 'Don\'t count taps by logged-in editors and admins', 'text-us-widget' ) );
				}
			);

			$this->row(
				__( 'Google Analytics', 'text-us-widget' ),
				function () {
					$this->checkbox( 'ga4', __( 'Also send taps to Google Analytics 4', 'text-us-widget' ) );
					echo '<p class="description">';
					printf(
						/* translators: 1: event name, 2: event name. */
						esc_html__( 'Sends %1$s and %2$s events when gtag.js or Google Tag Manager is on the page. Mark text_us_text as a key event to count it as a conversion.', 'text-us-widget' ),
						'<code>text_us_open</code>',
						'<code>text_us_text</code>'
					);
					echo '</p>';
				}
			);

			$this->row(
				__( 'Keep taps for', 'text-us-widget' ),
				function () {
					$this->number( 'retention', 0, 120, __( 'months', 'text-us-widget' ) );
					echo '<p class="description">' . esc_html__( 'Older taps are deleted once a day. 0 keeps them forever.', 'text-us-widget' ) . '</p>';
				},
				'tuw-retention'
			);

			$this->row(
				__( 'Start over', 'text-us-widget' ),
				function () {
					echo '<button type="button" class="button" data-tuw-reset>' . self::icon( 'trash-2', 16 ) . esc_html__( 'Delete all recorded taps', 'text-us-widget' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
					echo '<p class="tuw-test-result" data-tuw-reset-result role="status" aria-live="polite"></p>';
				}
			);
			?>
		</table>
		<?php
	}

	/**
	 * Advanced: hooks and tricks for developers.
	 *
	 * @return void
	 */
	public function render_developer() {
		$items = array(
			array( '<a href="#text-us">', __( 'Any link to #text-us (a menu item, a button in a page builder) opens the panel, or goes straight to Messages with "Skip the panel" on. So does any element with a data-tuw-open attribute.', 'text-us-widget' ) ),
			array( 'textUsWidget.open()', __( 'Opens it from your own script, once the widget has loaded.', 'text-us-widget' ) ),
			array( "document.addEventListener( 'text-us-widget', … )", __( 'Fires on every tap. event.detail.action is "open" or "text"; event.detail.device is the device type.', 'text-us-widget' ) ),
			array( 'text_us_widget_show', __( 'Filter: return false to leave the widget off the current page.', 'text-us-widget' ) ),
			array( 'text_us_widget_config', __( 'Filter: the settings handed to the browser (see assets/widget.js).', 'text-us-widget' ) ),
			array( 'text_us_widget_icons', __( 'Filter: icon URLs for Apple devices, Android and the rest.', 'text-us-widget' ) ),
			array( 'text_us_widget_client_ip', __( 'Filter: the visitor IP used for rate limits, for unusual proxy setups.', 'text-us-widget' ) ),
			array( 'TEXT_US_WIDGET_TURNSTILE_SITE_KEY, TEXT_US_WIDGET_TURNSTILE_SECRET_KEY', __( 'wp-config.php constants that override the keys on this page.', 'text-us-widget' ) ),
			array( 'TEXT_US_WIDGET_DISABLED', __( 'wp-config.php constant: true switches the widget off, for example on a staging copy.', 'text-us-widget' ) ),
		);
		?>
		<table class="tuw-table-kv tuw-hooks">
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr><td><code><?php echo esc_html( $item[0] ); ?></code></td><td><?php echo esc_html( $item[1] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/*
	 * Sidebar ------------------------------------------------------------------------------
	 */

	/**
	 * A sentence for why the widget isn't showing.
	 *
	 * @param string $why Code from Text_Us_Widget_Plugin::why_off().
	 * @return string
	 */
	private function why_off_text( $why ) {
		switch ( $why ) {
			case 'constant':
				return __( 'Switched off by TEXT_US_WIDGET_DISABLED in wp-config.php.', 'text-us-widget' );
			case 'off':
				return __( 'The widget is switched off. Turn it on in the Save box.', 'text-us-widget' );
			case 'number':
				return __( 'Add your number on the Widget tab and the widget will appear.', 'text-us-widget' );
			case 'devices':
				return __( 'No devices are ticked on the Display tab, so the widget isn\'t showing anywhere.', 'text-us-widget' );
			case 'keys':
				return __( 'Turnstile is on but the keys are missing, so the widget is hidden to keep your number private. Add them on the Spam Protection tab.', 'text-us-widget' );
		}

		return '';
	}

	/**
	 * Sidebar: Save, preview, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin  = $this->plugin;
		$names   = self::device_names();
		$devices = array();

		foreach ( $plugin->devices() as $key ) {
			$devices[] = $names[ $key ];
		}

		$why       = $plugin->why_off();
		$turnstile = $plugin->wants_turnstile();
		$status    = array(
			array( '' === $why, '' === $why ? __( 'Showing on your site', 'text-us-widget' ) : $this->why_off_text( $why ) ),
			/* translators: %s: phone number. */
			array( '' !== (string) $plugin->get_setting( 'number' ), '' !== (string) $plugin->get_setting( 'number' ) ? sprintf( __( 'Texts go to %s', 'text-us-widget' ), (string) $plugin->get_setting( 'number_display' ) ) : __( 'No number yet', 'text-us-widget' ) ),
			/* translators: %s: list of devices. */
			array( (bool) $devices, $devices ? sprintf( __( 'On %s', 'text-us-widget' ), wp_sprintf( '%l', $devices ) ) : __( 'No devices ticked', 'text-us-widget' ) ),
			array( $turnstile && Text_Us_Widget_Turnstile::ready( $plugin ), $turnstile ? ( Text_Us_Widget_Turnstile::ready( $plugin ) ? __( 'Number hidden behind Turnstile', 'text-us-widget' ) : __( 'Turnstile keys missing', 'text-us-widget' ) ) : __( 'Number is in the page (no Turnstile)', 'text-us-widget' ) ),
			array( (bool) $plugin->get_setting( 'on_interaction' ), $plugin->get_setting( 'on_interaction' ) ? __( 'Loads on first interaction', 'text-us-widget' ) : __( 'Loads with the page', 'text-us-widget' ) ),
		);
		?>
		<div class="postbox tuw-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'text-us-widget' ); ?>
				</h2>
			</div>
			<div class="inside">
				<label class="tuw-master">
					<input type="checkbox" name="<?php echo esc_attr( self::name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $plugin->get_setting( 'enabled' ) ); ?>>
					<span><?php esc_html_e( 'Widget on', 'text-us-widget' ); ?></span>
				</label>
				<p><?php esc_html_e( 'Settings on every tab are saved together, and your page cache is cleared so visitors see the change.', 'text-us-widget' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'text-us-widget' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox tuw-preview-box" data-tuw-panel="widget display protection">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Preview', 'text-us-widget' ); ?>
				</h2>
			</div>
			<div class="inside">
				<div class="tuw-seg" role="group" aria-label="<?php esc_attr_e( 'Preview on', 'text-us-widget' ); ?>">
					<button type="button" class="tuw-seg-btn is-active" data-tuw-preview="iphone" aria-pressed="true"><?php echo Text_Us_Widget_Icons::brand( 'apple', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'iPhone', 'text-us-widget' ); ?></button>
					<button type="button" class="tuw-seg-btn" data-tuw-preview="android" aria-pressed="false"><?php echo Text_Us_Widget_Icons::brand( 'android', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Android', 'text-us-widget' ); ?></button>
					<button type="button" class="tuw-seg-btn" data-tuw-preview="mac" aria-pressed="false"><?php echo self::icon( 'laptop', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Mac', 'text-us-widget' ); ?></button>
				</div>
				<div class="tuw-screen is-phone" data-tuw-screen>
					<div class="tuw-screen-inner" data-tuw-screen-inner>
						<div class="tuw-screen-page" aria-hidden="true">
							<span class="tuw-screen-url"></span>
							<span class="tuw-screen-h"></span>
							<span></span><span></span><span class="is-short"></span>
							<span class="tuw-screen-img"></span>
							<span></span><span></span><span class="is-short"></span>
						</div>
						<?php echo Text_Us_Widget_Widget::markup( $plugin, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in markup(). ?>
					</div>
				</div>
				<p class="description tuw-preview-note"><?php esc_html_e( 'Click the button to open the panel.', 'text-us-widget' ); ?></p>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'text-us-widget' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="tuw-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( TEXT_US_WIDGET_REPO ) );
		$check_url  = Text_Us_Widget_Updater::check_now_url();
		?>
		<div class="postbox tuw-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'text-us-widget' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'text-us-widget' ), '<strong>' . esc_html( TEXT_US_WIDGET_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Text_Us_Widget_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'text-us-widget' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( TEXT_US_WIDGET_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Text_Us_Widget_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'text-us-widget' ),
							'<a href="' . esc_url( TEXT_US_WIDGET_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>TEXT_US_WIDGET_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'text-us-widget' ),
							'<a href="' . esc_url( TEXT_US_WIDGET_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'text-us-widget' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/*
	 * AJAX ---------------------------------------------------------------------------------
	 */

	/**
	 * AJAX: check a token from the test widget with Cloudflare, using the
	 * secret typed in the form (or the saved one).
	 *
	 * @return void
	 */
	public function ajax_test_turnstile() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'text-us-widget' ) ), 403 );
		}

		$token  = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$typed  = isset( $_POST['secret'] ) && is_string( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';
		$secret = '' !== $typed ? $typed : Text_Us_Widget_Turnstile::secret_key( $this->plugin );

		if ( ! Text_Us_Widget_Turnstile::valid_key( $secret ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a secret key that starts with 0x.', 'text-us-widget' ) ) );
		}

		if ( '' === $token || strlen( $token ) > 2048 ) {
			wp_send_json_error( array( 'message' => __( 'The check didn\'t produce a token. Try again.', 'text-us-widget' ) ) );
		}

		$result = Text_Us_Widget_Turnstile::verify( $secret, $token, Text_Us_Widget_Request::client_ip() );

		if ( $result['ok'] ) {
			wp_send_json_success(
				array(
					'message' => Text_Us_Widget_Turnstile::is_test_key( $secret )
						? __( 'Working, with Cloudflare\'s test keys. Swap in your own before going live.', 'text-us-widget' )
						: __( 'Working: Cloudflare confirmed the check. Save to use these keys.', 'text-us-widget' ),
				)
			);
		}

		$codes = $result['codes'];

		if ( 'unavailable' === $result['error'] ) {
			$message = __( 'This server couldn\'t reach Cloudflare. Your host may block outgoing requests to challenges.cloudflare.com.', 'text-us-widget' );
		} elseif ( in_array( 'invalid-input-secret', $codes, true ) ) {
			$message = __( 'Cloudflare doesn\'t recognise this secret key. Copy it again from the Turnstile dashboard.', 'text-us-widget' );
		} elseif ( in_array( 'hostname-mismatch', $codes, true ) ) {
			$message = __( 'The check was solved on a different domain than this site.', 'text-us-widget' );
		} elseif ( in_array( 'invalid-input-response', $codes, true ) ) {
			$message = __( 'Cloudflare rejected the token. Do the site key and secret key belong to the same widget?', 'text-us-widget' );
		} else {
			$message = __( 'Cloudflare didn\'t confirm the check.', 'text-us-widget' );
		}

		wp_send_json_error(
			array(
				'message' => $message,
				'codes'   => $codes,
			)
		);
	}

	/**
	 * AJAX: delete every recorded tap.
	 *
	 * @return void
	 */
	public function ajax_reset_stats() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'text-us-widget' ) ), 403 );
		}

		Text_Us_Widget_Stats::reset();

		wp_send_json_success();
	}
}
