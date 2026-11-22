<?php
/**
 * Cross-request lock: one row in the options table, taken with INSERT IGNORE.
 *
 * add_option() cannot be used: WordPress runs it as INSERT ... ON DUPLICATE KEY
 * UPDATE, so two parallel requests would both "win".
 *
 * @package CatCodeFormLeads
 */

namespace CatCode\FormLeads\Core;

defined( 'ABSPATH' ) || exit;

class Lock {

	const PREFIX = 'catcode_flc_dup_';

	/**
	 * True for the first caller of this name within $ttl seconds.
	 *
	 * @param string $hash Hash of what must happen once.
	 * @param int    $ttl  Window in seconds.
	 */
	public static function once( string $hash, int $ttl ): bool {
		global $wpdb;
		$name     = self::PREFIX . $hash;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $name, time() - $ttl ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppress );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return (int) $rows > 0;
	}

	/** Forget the marks older than $ttl seconds. */
	public static function prune( int $ttl ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( self::PREFIX ) . '%', time() - $ttl ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
