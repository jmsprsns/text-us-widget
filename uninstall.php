<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its
 * settings, recorded taps and scheduled task. On a multisite this covers
 * every site.
 *
 * @package Text_Us_Widget
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the table, settings, rate-limit counters and scheduled task for
 * the current site.
 *
 * @return void
 */
function text_us_widget_uninstall_site() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table and counters on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}text_us_widget_clicks" );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_text_us_widget_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_text_us_widget_' ) . '%'
		)
	);
	// phpcs:enable

	foreach ( array( 'text_us_widget_settings', 'text_us_widget_db_version', 'text_us_widget_salt' ) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'text_us_widget_daily' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $text_us_widget_site ) {
		switch_to_blog( $text_us_widget_site );
		text_us_widget_uninstall_site();
		restore_current_blog();
	}
} else {
	text_us_widget_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-text-us-widget' );
