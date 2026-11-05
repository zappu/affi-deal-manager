<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einstellungen des Plugins in der Option adm_settings.
 */
class ADM_Settings {

	const OPTION = 'adm_settings';

	public static function defaults() {
		return array(
			'enabled'               => 1,
			'partner_id'            => '5548040',
			'ad_id'                 => '2928062',
			'program_id'            => '7443',
			'tracking_host'         => 'appsumo.8odi.net',
			'sitemap_url'           => 'https://appsumo.com/sitemap.xml',
			'batch_size'            => 15,
			'delay'                 => 2,
			'recheck_available_hrs' => 24,
			'recheck_other_days'    => 30,
			'notify_email'          => get_option( 'admin_email' ),
			'update_token'          => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Prüft und speichert die Eingaben aus dem Formular.
	 *
	 * @param array $in Rohwerte.
	 * @return array Gespeicherte Werte.
	 */
	public static function save( array $in ) {
		$d   = self::defaults();
		$out = array(
			'enabled'               => empty( $in['enabled'] ) ? 0 : 1,
			'partner_id'            => self::digits( $in, 'partner_id', $d['partner_id'] ),
			'ad_id'                 => self::digits( $in, 'ad_id', $d['ad_id'] ),
			'program_id'            => self::digits( $in, 'program_id', $d['program_id'] ),
			'tracking_host'         => self::host( isset( $in['tracking_host'] ) ? $in['tracking_host'] : '', $d['tracking_host'] ),
			'sitemap_url'           => self::appsumo_url( isset( $in['sitemap_url'] ) ? $in['sitemap_url'] : '', $d['sitemap_url'] ),
			'batch_size'            => self::range( $in, 'batch_size', 1, 50, $d['batch_size'] ),
			'delay'                 => self::range( $in, 'delay', 0, 10, $d['delay'] ),
			'recheck_available_hrs' => self::range( $in, 'recheck_available_hrs', 6, 168, $d['recheck_available_hrs'] ),
			'recheck_other_days'    => self::range( $in, 'recheck_other_days', 1, 180, $d['recheck_other_days'] ),
			'notify_email'          => is_email( isset( $in['notify_email'] ) ? trim( (string) $in['notify_email'] ) : '' ) ? sanitize_email( $in['notify_email'] ) : $d['notify_email'],
			'update_token'          => isset( $in['update_token'] ) ? sanitize_text_field( (string) $in['update_token'] ) : '',
		);
		update_option( self::OPTION, $out, false );
		return $out;
	}

	private static function digits( array $in, $key, $fallback ) {
		$v = isset( $in[ $key ] ) ? preg_replace( '/\D/', '', (string) $in[ $key ] ) : '';
		return '' !== $v ? substr( $v, 0, 12 ) : $fallback;
	}

	private static function range( array $in, $key, $min, $max, $fallback ) {
		if ( ! isset( $in[ $key ] ) || '' === trim( (string) $in[ $key ] ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $in[ $key ] ) );
	}

	private static function host( $v, $fallback ) {
		$v = strtolower( trim( (string) $v ) );
		return preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $v ) ? $v : $fallback;
	}

	/** Nur HTTPS-Adressen auf appsumo.com sind als Sitemap erlaubt. */
	private static function appsumo_url( $v, $fallback ) {
		$v = esc_url_raw( trim( (string) $v ), array( 'https' ) );
		$p = wp_parse_url( $v );
		if ( ! $p || empty( $p['host'] ) || ! in_array( strtolower( $p['host'] ), array( 'appsumo.com', 'www.appsumo.com' ), true ) ) {
			return $fallback;
		}
		return $v;
	}
}
