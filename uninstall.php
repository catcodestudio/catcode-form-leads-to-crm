<?php
/**
 * Removes the data, unless the owner chose to keep it in the settings.
 *
 * @package CatCodeFormLeads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'catcode_flc_tick' );

$catcode_flc_settings = get_option( 'catcode_flc_settings', array() );
// The log holds names and contacts, so it goes with the plugin unless the owner chose to keep it.
if ( ! is_array( $catcode_flc_settings ) || ! isset( $catcode_flc_settings['delete_data'] ) || '0' !== (string) $catcode_flc_settings['delete_data'] ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table and options, removed on request.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}catcode_flc_log" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'catcode\\_flc\\_%' OR option_name LIKE '\\_transient\\_catcode\\_flc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_catcode\\_flc\\_%'" );
	// phpcs:enable
}
