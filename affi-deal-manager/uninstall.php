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
foreach ( array( 'adm_settings', 'adm_db_version', 'adm_health', 'adm_sitemap_read', 'adm_sitemap_retry', 'adm_last_tick', 'adm_profile_queue', 'adm_profile_log', 'adm_profile_paused', 'adm_rewrite_version', 'adm_rubrics_version', 'adm_last_servercron', 'adm_profile_current', 'adm_video_queue', 'adm_profile_force', 'adm_shots_retry_v', 'adm_last_purge', 'adm_impact_test', 'adm_impact_query', 'adm_impact_stats', 'adm_impact_promos', 'adm_impact_promos_seen', 'adm_impact_banners', 'adm_spotlight', 'adm_newsletter_error', 'adm_newsletter_last', 'adm_seo_titles_fit', 'adm_profile_auto_day' ) as $opt ) {
	delete_option( $opt );
}
delete_transient( 'adm_sync_lock' );
delete_transient( 'adm_update_manifest' );
wp_clear_scheduled_hook( 'adm_sync_tick' );
wp_clear_scheduled_hook( 'adm_profile_tick' );
delete_transient( 'adm_profile_lock' );
// Zwischengespeicherte Videozusammenfassungen.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_adm\\_vsum%' OR option_name LIKE '\\_transient\\_timeout\\_adm\\_vsum%'" ); // phpcs:ignore WordPress.DB
// Rubriken (Begriffe) bleiben wie die Tool-Profile erhalten.
