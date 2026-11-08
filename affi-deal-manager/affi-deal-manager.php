<?php
/**
 * Plugin Name: Thoka Affi Deal Manager
 * Description: Gleicht täglich die Deals von AppSumo ab, führt Status, Preise und Bewertungen in einer eigenen Tabelle, baut Impact-Affiliate-Links und erstellt redaktionelle Tool-Profile mit KI.
 * Version: 0.13.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Thoka Network
 * Author URI: https://thoka.network
 * Text Domain: affi-deal-manager
 * Update URI: https://mediainfo.space/Thoka_Plugins/affi-deal-manager/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Gemeinsames Admin-Menü „Thoka“ (Bibliothek thoka-menu, lädt die höchste vorhandene Version).
require_once __DIR__ . '/thoka-menu/loader.php';

define( 'ADM_VERSION', '0.13.4' );
define( 'ADM_DB_VERSION', '7' );
define( 'ADM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADM_URL', plugin_dir_url( __FILE__ ) );
define( 'ADM_FILE', __FILE__ );

require_once ADM_DIR . 'includes/class-adm-settings.php';
require_once ADM_DIR . 'includes/class-adm-db.php';
require_once ADM_DIR . 'includes/class-adm-parser.php';
require_once ADM_DIR . 'includes/class-adm-links.php';
require_once ADM_DIR . 'includes/class-adm-sync.php';
require_once ADM_DIR . 'includes/class-adm-style.php';
require_once ADM_DIR . 'includes/class-adm-ai.php';
require_once ADM_DIR . 'includes/class-adm-sources.php';
require_once ADM_DIR . 'includes/class-adm-profiles.php';
require_once ADM_DIR . 'includes/class-adm-rubrics.php';
require_once ADM_DIR . 'includes/class-adm-shortcodes.php';
require_once ADM_DIR . 'includes/class-adm-archive.php';
require_once ADM_DIR . 'includes/class-adm-shots.php';
require_once ADM_DIR . 'includes/class-adm-cache.php';
require_once ADM_DIR . 'includes/class-adm-impact.php';
require_once ADM_DIR . 'includes/class-adm-stats.php';
require_once ADM_DIR . 'includes/class-adm-promos.php';
require_once ADM_DIR . 'includes/class-adm-banners.php';
require_once ADM_DIR . 'includes/class-adm-spotlight.php';
require_once ADM_DIR . 'includes/class-adm-newsletter.php';
require_once ADM_DIR . 'includes/class-adm-updater.php';
require_once ADM_DIR . 'includes/class-adm-admin.php';

register_activation_hook( __FILE__, array( 'ADM_DB', 'install' ) );
register_activation_hook( __FILE__, array( 'ADM_Sync', 'schedule' ) );
register_activation_hook( __FILE__, array( 'ADM_Profiles', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ADM_Sync', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'ADM_Profiles', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	ADM_DB::maybe_upgrade();
	ADM_Settings::purge_stored_keys();
	ADM_Sync::init();
	ADM_Links::init();
	ADM_Profiles::init();
	ADM_Rubrics::init();
	ADM_Shortcodes::init();
	ADM_Archive::init();
	ADM_Shots::init();
	ADM_Cache::init();
	ADM_Impact::init();
	ADM_Stats::init();
	ADM_Promos::init();
	ADM_Banners::init();
	ADM_Spotlight::init();
	ADM_Newsletter::init();
	ADM_Updater::init();
	ADM_Admin::init();
} );

/**
 * Liefert einen Deal als Array oder null. Für Vorlagen und spätere Module (Tool-Profile, Roundup-Quelle).
 *
 * @param string $slug AppSumo-Slug, z. B. "neuronwriter".
 * @return array|null
 */
function adm_get_deal( $slug ) {
	return ADM_DB::get_by_slug( $slug );
}

/**
 * Affiliate-Link für eine AppSumo-Adresse.
 *
 * @param string $url   AppSumo-Produktadresse.
 * @param string $subid Kennung der Seite für die Auswertung in Impact (subId1).
 * @return string
 */
function adm_affiliate_url( $url, $subid = '' ) {
	return ADM_Links::url( $url, $subid );
}

/**
 * Deals für Bereiche der Website, z. B. kostenlose E-Books:
 * adm_get_deals( array( 'type' => 'ebook', 'free' => true, 'limit' => 6 ) )
 *
 * @param array $args Siehe ADM_DB::query().
 * @return array
 */
function adm_get_deals( array $args = array() ) {
	return ADM_DB::query( $args );
}
