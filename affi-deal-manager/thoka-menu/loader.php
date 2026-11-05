<?php
/**
 * Thoka Admin-Menü – Loader.
 *
 * Jedes Thoka-Plugin bringt eine Kopie des Ordners thoka-menu/ mit und bindet
 * nur diese Datei ein. Geladen wird immer die höchste vorhandene Version der
 * Bibliothek, egal aus welchem Plugin sie stammt. Beim Aktualisieren der
 * Bibliothek ändert sich in dieser Datei nur die Versionszeile.
 *
 * @package Thoka
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['thoka_menu_versions']['1.0.0'] = __DIR__ . '/class-thoka-admin-menu.php';

if ( ! function_exists( 'thoka_menu_load' ) ) {
	/**
	 * Lädt die höchste registrierte Version der Bibliothek (einmalig).
	 *
	 * @return bool True, wenn die Klasse Thoka_Admin_Menu verfügbar ist.
	 */
	function thoka_menu_load() {
		if ( class_exists( 'Thoka_Admin_Menu', false ) ) {
			return true;
		}
		if ( empty( $GLOBALS['thoka_menu_versions'] ) || ! is_array( $GLOBALS['thoka_menu_versions'] ) ) {
			return false;
		}

		$versions = $GLOBALS['thoka_menu_versions'];
		uksort( $versions, 'version_compare' );

		foreach ( array_reverse( $versions ) as $file ) {
			if ( is_readable( $file ) ) {
				require_once $file;
				break;
			}
		}

		if ( ! class_exists( 'Thoka_Admin_Menu', false ) ) {
			return false;
		}

		Thoka_Admin_Menu::init();
		return true;
	}

	add_action( 'plugins_loaded', 'thoka_menu_load', 1 );
}
