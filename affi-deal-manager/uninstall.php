<?php
/**
 * Entfernt Tabelle, Optionen und geplante Aufgaben des Thoka Affi Deal Manager.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'adm_deals' ); // phpcs:ignore WordPress.DB

foreach ( array( 'adm_settings', 'adm_db_version', 'adm_health', 'adm_sitemap_read', 'adm_last_tick' ) as $opt ) {
	delete_option( $opt );
}
delete_transient( 'adm_sync_lock' );
delete_transient( 'adm_update_manifest' );
wp_clear_scheduled_hook( 'adm_sync_tick' );
