<?php
/**
 * Plugin Name:       Text Us Widget
 * Plugin URI:        https://www.contentpowered.com
 * Description:       A Text Us button that opens Messages on the devices you choose, with click tracking and optional Cloudflare Turnstile to hide your number from bots.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       text-us-widget
 * Update URI:        https://github.com/jmsprsns/text-us-widget
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$text_us_widget_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'TEXT_US_WIDGET_VERSION', $text_us_widget_headers['version'] );
define( 'TEXT_US_WIDGET_REPO', $text_us_widget_headers['repo'] );
define( 'TEXT_US_WIDGET_FILE', __FILE__ );
define( 'TEXT_US_WIDGET_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEXT_US_WIDGET_URL', plugin_dir_url( __FILE__ ) );

unset( $text_us_widget_headers );

require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-icons.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-updater.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-menu.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-feedback.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-request.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-turnstile.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-stats.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-api.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-widget.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-frontend.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-admin.php';
require_once TEXT_US_WIDGET_DIR . 'includes/class-text-us-widget-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Text_Us_Widget_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Text_Us_Widget_Plugin
 */
function text_us_widget_plugin() {
	return Text_Us_Widget_Plugin::instance();
}
add_action( 'plugins_loaded', 'text_us_widget_plugin' );

/**
 * Create the clicks table and schedule the daily clean-up.
 *
 * @return void
 */
function text_us_widget_activate() {
	Text_Us_Widget_Stats::install();
	Text_Us_Widget_Stats::schedule();
}
register_activation_hook( TEXT_US_WIDGET_FILE, 'text_us_widget_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and clicks stay put (uninstall.php removes them).
 *
 * @return void
 */
function text_us_widget_deactivate() {
	wp_clear_scheduled_hook( Text_Us_Widget_Stats::CRON );
}
register_deactivation_hook( TEXT_US_WIDGET_FILE, 'text_us_widget_deactivate' );
