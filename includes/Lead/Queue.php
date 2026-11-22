<?php
/**
 * One row per lead and target. The row is written before any network call, so a
 * CRM that is down, a timeout or a dead PHP process cannot lose a lead.
 *
 * Delivery runs at the end of the request that captured the lead, after the
 * answer went to the visitor where the server allows it. What is left is picked
 * up by cron.
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Lead;

use CatCode\FormLeads\Core\Installer;
use CatCode\FormLeads\Core\Lock;
use CatCode\FormLeads\Core\Settings;
use CatCode\FormLeads\Targets\Registry;

defined( 'ABSPATH' ) || exit;

class Queue {

	/** Seconds before the 2nd, 3rd and 4th attempt. */
	const BACKOFF = array( 60, 300, 1800 );

	/** A row "sending" for longer than this belongs to a process that died. */
	const STALE = 600;

	/** KeyCRM allows 20 requests a minute, and one cron run must stay short. */
	const BATCH = 15;

	/** @var int[] Rows added in this request. */
	private static $own = array();

	/**
	 * @param array<string,mixed>  $lead    Lead.
	 * @param array<string,string> $targets Target => '' or the reason to skip it.
	 */
	public static function add( array $lead, array $targets ): void {
		global $wpdb;

		$now  = time();
		$json = (string) wp_json_encode( $lead, JSON_UNESCAPED_UNICODE );

		foreach ( $targets as $target => $skip ) {
			$done = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Installer::table(),
				array(
					'lead_key'   => (string) $lead['key'],
					'created_at' => $now,
					'source'     => (string) $lead['source'],
					'form_id'    => mb_substr( (string) $lead['form_id'], 0, 64 ),
					'form_title' => mb_substr( (string) $lead['form_title'], 0, 191 ),
					'target'     => mb_substr( (string) $target, 0, 100 ),
					'status'     => '' === $skip ? 'queued' : 'skipped',
					'next_try'   => $now,
					'error'      => $skip,
					'lead_data'  => $json,
				)
			);
			if ( $done && '' === $skip ) {
				self::$own[] = (int) $wpdb->insert_id;
			}
		}

		if ( array() !== self::$own && false === has_action( 'shutdown', array( __CLASS__, 'flush' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
		}
	}

	/** End of the request: let the visitor go, then deliver what this request queued. */
	public static function flush(): void {
		$ids = self::$own;

		self::$own = array();

		if ( array() === $ids ) {
			return;
		}

		ignore_user_abort( true );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		foreach ( $ids as $id ) {
			self::deliver( $id );
		}
	}

	/** Cron: retries due, rows a dead process left behind, housekeeping. */
	public static function tick(): void {
		global $wpdb;

		$table = Installer::table();
		$now   = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'failed', error = %s WHERE status = 'sending' AND claimed_at < %d AND attempts > %d", __( 'The server stopped during every attempt.', 'catcode-form-leads-to-crm' ), $now - self::STALE, count( self::BACKOFF ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'retry', next_try = %d WHERE status = 'sending' AND claimed_at < %d", $now, $now - self::STALE ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('queued','retry') AND next_try <= %d ORDER BY next_try ASC LIMIT %d", $now, self::BATCH ) );
		// phpcs:enable

		foreach ( (array) $ids as $id ) {
			self::deliver( (int) $id );
		}

		self::prune();
	}

	/**
	 * Try one row. Taking it is an atomic UPDATE, so cron and the end of the request
	 * can never send the same row twice.
	 */
	public static function deliver( int $id ): void {
		global $wpdb;

		$table = Installer::table();
		$now   = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sending', claimed_at = %d, attempts = attempts + 1 WHERE id = %d AND status IN ('queued','retry')", $now, $id ) );
		if ( ! $taken ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return;
		}

		$lead = json_decode( (string) $row['lead_data'], true );
		$pair = Registry::for_delivery( (string) $row['target'] );

		if ( ! is_array( $lead ) || null === $pair ) {
			self::finish( $row, array(
				'ok'        => false,
				'retry'     => false,
				'status'    => 0,
				'remote_id' => '',
				'error'     => __( 'This connection no longer exists in the settings.', 'catcode-form-leads-to-crm' ),
			) );
			return;
		}

		try {
			$result = $pair[0]->send( $lead, $pair[1] );
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'        => false,
				'retry'     => true,
				'status'    => 0,
				'remote_id' => '',
				'error'     => $e->getMessage(),
			);
		}
		self::finish( $row, $result );
	}

	/**
	 * @param array<string,mixed> $row    Row.
	 * @param array<string,mixed> $result Result of Target::send().
	 */
	private static function finish( array $row, array $result ): void {
		global $wpdb;

		$attempts = (int) $row['attempts'];
		$fields   = array(
			'http_code' => (int) $result['status'],
			'remote_id' => mb_substr( (string) $result['remote_id'], 0, 64 ),
			'error'     => mb_substr( (string) $result['error'], 0, 1000 ),
		);

		if ( ! empty( $result['ok'] ) ) {
			$fields['status'] = 'sent';
			$fields['error']  = '';
		} elseif ( ! empty( $result['retry'] ) && $attempts <= count( self::BACKOFF ) ) {
			$fields['status']   = 'retry';
			$fields['next_try'] = time() + self::BACKOFF[ $attempts - 1 ];
		} else {
			$fields['status'] = 'failed';
		}

		$wpdb->update( Installer::table(), $fields, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( 'failed' === $fields['status'] ) {
			/**
			 * A lead did not reach its target and will not be tried again by itself.
			 *
			 * @param array<string,mixed> $row    Row of the log.
			 * @param array<string,mixed> $result What the target answered.
			 */
			do_action( 'catcode_flc_delivery_failed', $row, $result );
		}
	}

	/** Leads are personal data: they are kept only as long as the settings say. */
	public static function prune(): void {
		global $wpdb;

		$days = max( 1, (int) Settings::get( 'retention', '30' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Installer::table() . " WHERE created_at < %d AND status NOT IN ('queued','retry','sending')", time() - $days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.

		Lock::prune( Capture::DUPLICATE_WINDOW );
	}

	/** How many leads did not reach a target during the last week. */
	public static function failed_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table() . " WHERE status = 'failed' AND created_at > %d", time() - 7 * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
	}

	/**
	 * @param string $status Status filter, '' for all.
	 * @param int    $limit  Rows.
	 * @param int    $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( string $status, int $limit, int $offset ): array {
		global $wpdb;
		$table = Installer::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		if ( '' !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	public static function count( string $status ): int {
		global $wpdb;
		$table = Installer::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
		if ( '' !== $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable
	}

	/** Remove everything from the log, except what is still waiting to be delivered. */
	public static function clear(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Installer::table() . " WHERE status NOT IN ('queued','retry','sending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table.
	}
}
