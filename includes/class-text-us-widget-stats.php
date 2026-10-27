<?php
/**
 * The clicks table: one row per counted tap, and the reports read from it.
 *
 * A row holds the time, the site's local day, what happened (the widget
 * was opened, the visitor passed the Turnstile check, or they tapped Send),
 * the type of device, the page and an anonymous connection hash that
 * changes daily. No IP, name or number is stored.
 *
 * @package Text_Us_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Click storage and reports.
 */
final class Text_Us_Widget_Stats {

	/**
	 * Bump when the table changes; dbDelta then runs on the next request.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'text_us_widget_db_version';

	/**
	 * Daily clean-up hook.
	 */
	const CRON = 'text_us_widget_daily';

	/**
	 * Events: code => setting-free name.
	 */
	const EVENTS = array(
		'o' => 'open',
		'v' => 'verify',
		't' => 'text',
	);

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'text_us_widget_clicks';
	}

	/**
	 * Create or update the table when the stored version is behind (also
	 * covers sites added to a network after activation). One autoloaded
	 * option read.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::install();
			self::schedule();
		}
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				day date NOT NULL,
				event char(1) NOT NULL DEFAULT '',
				device varchar(8) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				client char(16) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY day_event (day,event),
				KEY client_created (client,created_at),
				KEY created_at (created_at)
			) {$collate};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/**
	 * Schedule the daily clean-up if it isn't already.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Save a click.
	 *
	 * @param string $event   Event code from {@see EVENTS}.
	 * @param string $device  Device code from Text_Us_Widget_Plugin::DEVICES.
	 * @param int    $post_id Page it happened on (0 when not a single post or page).
	 * @param string $client  Connection hash.
	 * @return void
	 */
	public static function add( $event, $device, $post_id, $client ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table; writes aren't cached.
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'day'        => wp_date( 'Y-m-d' ),
				'event'      => $event,
				'device'     => $device,
				'post_id'    => (int) $post_id,
				'client'     => $client,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Clicks saved in the last few seconds, for one connection or all.
	 *
	 * @param int    $seconds How far back.
	 * @param string $client  Connection hash, or '' for every connection.
	 * @param string $event   Only this event, or '' for all.
	 * @param string $device  Only this device type, or '' for all.
	 * @return int
	 */
	public static function count_recent( $seconds, $client = '', $event = '', $device = '' ) {
		global $wpdb;

		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $seconds );
		$where = $wpdb->prepare( 'created_at >= %s', $since );

		if ( '' !== $client ) {
			$where .= $wpdb->prepare( ' AND client = %s', $client );
		}

		if ( '' !== $event ) {
			$where .= $wpdb->prepare( ' AND event = %s', $event );
		}

		if ( '' !== $device ) {
			$where .= $wpdb->prepare( ' AND device = %s', $device );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our table; $where is built from prepare() calls.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/**
	 * Delete clicks older than the retention period.
	 *
	 * @param int $months Months to keep; 0 keeps everything.
	 * @return void
	 */
	public static function prune( $months ) {
		global $wpdb;

		if ( $months <= 0 ) {
			return;
		}

		$table  = self::table();
		$today  = date_create_immutable( wp_date( 'Y-m-d' ) . ' 12:00:00', new DateTimeZone( 'UTC' ) );
		$cutoff = $today->modify( '-' . (int) $months . ' months' )->format( 'Y-m-d' );

		// In batches, so a big first clean-up doesn't lock the table for long.
		for ( $i = 0; $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s LIMIT 5000", $cutoff ) );

			if ( ! $deleted || $deleted < 5000 ) {
				break;
			}
		}
	}

	/**
	 * Delete every click.
	 *
	 * @return void
	 */
	public static function reset() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * First local day of a report period.
	 *
	 * @param int $days   Days in the period, today included.
	 * @param int $offset Periods back (1 = the period before).
	 * @return string Y-m-d.
	 */
	public static function start_day( $days, $offset = 0 ) {
		return self::add_days( wp_date( 'Y-m-d' ), 1 - (int) $days * ( 1 + (int) $offset ) );
	}

	/**
	 * Move a calendar date by whole days. Plain date arithmetic, so it never
	 * slips a day the way timestamps can across time zones and DST.
	 *
	 * @param string $day  Y-m-d.
	 * @param int    $days Days to add (negative to go back).
	 * @return string Y-m-d.
	 */
	public static function add_days( $day, $days ) {
		$date = date_create_immutable( $day . ' 12:00:00', new DateTimeZone( 'UTC' ) );

		return $date ? $date->modify( ( $days < 0 ? '-' : '+' ) . abs( (int) $days ) . ' days' )->format( 'Y-m-d' ) : $day;
	}

	/**
	 * Totals per event over a period.
	 *
	 * @param int $days   Days, today included.
	 * @param int $offset 1 for the period before.
	 * @return array{o:int,v:int,t:int}
	 */
	public static function totals( $days, $offset = 0 ) {
		global $wpdb;

		$table = self::table();
		$from  = self::start_day( $days, $offset );
		$to    = self::add_days( $from, (int) $days - 1 );
		$out   = array(
			'o' => 0,
			'v' => 0,
			't' => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event, COUNT(*) AS n FROM {$table} WHERE day BETWEEN %s AND %s GROUP BY event", $from, $to ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['event'] ] ) ) {
				$out[ $row['event'] ] = (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * Opens and texts for each day of a period, oldest first, with empty
	 * days filled in.
	 *
	 * @param int $days Days, today included.
	 * @return array<string,array{o:int,v:int,t:int}> Keyed by Y-m-d.
	 */
	public static function daily( $days ) {
		global $wpdb;

		$table = self::table();
		$from  = self::start_day( $days );
		$out   = array();

		for ( $i = 0; $i < $days; $i++ ) {
			$out[ self::add_days( $from, $i ) ] = array(
				'o' => 0,
				'v' => 0,
				't' => 0,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, event, COUNT(*) AS n FROM {$table} WHERE day >= %s GROUP BY day, event", $from ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ][ $row['event'] ] ) ) {
				$out[ $row['day'] ][ $row['event'] ] = (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * Opens and texts per device over a period, busiest first.
	 *
	 * @param int $days Days, today included.
	 * @return array<string,array{o:int,v:int,t:int}> Keyed by device code.
	 */
	public static function devices( $days ) {
		global $wpdb;

		$table = self::table();
		$out   = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT device, event, COUNT(*) AS n FROM {$table} WHERE day >= %s GROUP BY device, event", self::start_day( $days ) ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( ! isset( $out[ $row['device'] ] ) ) {
				$out[ $row['device'] ] = array(
					'o' => 0,
					'v' => 0,
					't' => 0,
				);
			}

			if ( isset( $out[ $row['device'] ][ $row['event'] ] ) ) {
				$out[ $row['device'] ][ $row['event'] ] = (int) $row['n'];
			}
		}

		uasort(
			$out,
			function ( $a, $b ) {
				return ( $b['t'] * 1000000 + $b['o'] ) - ( $a['t'] * 1000000 + $a['o'] );
			}
		);

		return $out;
	}

	/**
	 * The pages people open the widget and text from most.
	 *
	 * @param int $days  Days, today included.
	 * @param int $limit Rows.
	 * @return array<int,array{post_id:int,o:int,t:int}>
	 */
	public static function pages( $days, $limit = 10 ) {
		global $wpdb;

		$table = self::table();

		// MySQL can't order by an expression of SUM() aliases, so the sums
		// are repeated in ORDER BY.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, SUM(event = 'o') AS o, SUM(event = 't') AS t FROM {$table} WHERE day >= %s GROUP BY post_id ORDER BY SUM(event = 't') DESC, SUM(event = 'o') DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::start_day( $days ),
				(int) $limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'post_id' => (int) $row['post_id'],
				'o'       => (int) $row['o'],
				't'       => (int) $row['t'],
			);
		}

		return $out;
	}
}
