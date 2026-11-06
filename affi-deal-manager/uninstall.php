<?php
/**
 * Entfernt Tabelle, Optionen und geplante Aufgaben des Thoka Affi Deal Manager.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'adm_deals' ); // phpcs:ignore WordPress.DB

// Tool-Profile (Beiträge) bleiben erhalten, sie sind redaktionelle Inhalte.
foreach ( array( 'adm_settings', 'adm_db_version', 'adm_health', 'adm_sitemap_read', 'adm_sitemap_retry', 'adm_last_tick', 'adm_profile_queue', 'adm_profile_log', 'adm_profile_paused', 'adm_rewrite_version' ) as $opt ) {
	delete_option( $opt );
}
delete_transient( 'adm_sync_lock' );
delete_transient( 'adm_update_manifest' );
wp_clear_scheduled_hook( 'adm_sync_tick' );
wp_clear_scheduled_hook( 'adm_profile_tick' );
delete_transient( 'adm_profile_lock' );
