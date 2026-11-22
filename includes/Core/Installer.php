<?php
/**
 * Table, cron and upgrades.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Core;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION = '1';

	const DB_OPTION = 'catcode_flc_db_version';

	const CRON = 'catcode_flc_tick';

	const SCHEDULE = 'catcode_flc_5min';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'catcode_flc_log';
	}

	public static function activate(): void {
		self::install();
		// boot() has not run in the activation request, and WordPress refuses an unknown schedule.
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minutes, the retry step.
		self::ensure_cron();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** An update does not run the activation hook, so the table is checked on every load. */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_key char(32) NOT NULL DEFAULT '',
				created_at bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(20) NOT NULL DEFAULT '',
				form_id varchar(64) NOT NULL DEFAULT '',
				form_title varchar(191) NOT NULL DEFAULT '',
				target varchar(100) NOT NULL DEFAULT '',
				status varchar(12) NOT NULL DEFAULT 'queued',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_try bigint(20) unsigned NOT NULL DEFAULT 0,
				claimed_at bigint(20) unsigned NOT NULL DEFAULT 0,
				remote_id varchar(64) NOT NULL DEFAULT '',
				http_code smallint(5) unsigned NOT NULL DEFAULT 0,
				error text NULL,
				lead_data longtext NULL,
				PRIMARY KEY  (id),
				KEY status_next (status,next_try),
				KEY created_at (created_at),
				KEY lead_key (lead_key)
			) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules Known schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'catcode-form-leads-to-crm' ),
		);
		return $schedules;
	}

	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}
}
