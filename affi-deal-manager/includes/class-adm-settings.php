<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einstellungen des Plugins in der Option adm_settings.
 */
class ADM_Settings {

	const OPTION = 'adm_settings';

	/** Modus des Abgleichs. */
	const MODES = array(
		'wpcron'     => 'Automatisch über WP-Cron',
		'servercron' => 'Über Server-Cron (Plesk)',
		'off'        => 'Aus, nur manuell',
	);

	/** Takt in Minuten => Beschriftung. */
	const INTERVALS = array(
		5    => 'alle 5 Minuten',
		10   => 'alle 10 Minuten',
		15   => 'alle 15 Minuten',
		30   => 'alle 30 Minuten',
		60   => 'stündlich',
		120  => 'alle 2 Stunden',
		360  => 'alle 6 Stunden',
		720  => 'alle 12 Stunden',
		1440 => 'einmal am Tag',
	);

	/** KI-Anbieter => Beschriftung und Standardmodell. */
	const AI_PROVIDERS = array(
		'openrouter' => array( 'label' => 'OpenRouter', 'model' => 'anthropic/claude-sonnet-4.5' ),
		'openai'     => array( 'label' => 'OpenAI', 'model' => 'gpt-4.1' ),
		'anthropic'  => array( 'label' => 'Anthropic', 'model' => 'claude-sonnet-4-5' ),
	);

	/** Geheime Felder: werden nie im Formular ausgegeben, leere Eingabe behält den Wert. */
	const SECRET_KEYS = array( 'ai_key', 'youtube_key' );

	public static function defaults() {
		return array(
			'mode'                  => 'wpcron',
			'interval'              => 5,
			'window_start'          => 0,
			'window_end'            => 24,
			'sitemap_hour'          => 3,
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
			'cron_secret'           => '',
			'ai_provider'           => 'openrouter',
			'ai_key'                => '',
			'ai_model'              => '',
			'youtube_key'           => '',
			'title_template'        => '{tool} ohne Abo: Funktionen, Käuferstimmen und Alternativen',
			'seo_title_template'    => '{tool} ohne Abo (Lifetime Deal): Funktionen und Alternativen',
			'batch_profiles'        => 20,
			'auto_profiles'         => 0,
			'reviews_newest'        => 60,
			'reviews_critical'      => 40,
		);
	}

	/** Eingestelltes Modell oder Standardmodell des Anbieters. */
	public static function ai_model() {
		$all = self::all();
		if ( '' !== trim( (string) $all['ai_model'] ) ) {
			return (string) $all['ai_model'];
		}
		$p = isset( self::AI_PROVIDERS[ $all['ai_provider'] ] ) ? $all['ai_provider'] : 'openrouter';
		return self::AI_PROVIDERS[ $p ]['model'];
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		// Version 0.1.0 kannte nur den Schalter „enabled“.
		if ( ! isset( $saved['mode'] ) && isset( $saved['enabled'] ) ) {
			$saved['mode'] = $saved['enabled'] ? 'wpcron' : 'off';
		}
		unset( $saved['enabled'] );
		return array_merge( self::defaults(), $saved );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Geheimer Schlüssel für den Server-Cron, wird beim ersten Bedarf erzeugt. */
	public static function cron_secret( $regenerate = false ) {
		$all = self::all();
		if ( $regenerate || ! preg_match( '/^[A-Za-z0-9]{32}$/', (string) $all['cron_secret'] ) ) {
			$all['cron_secret'] = wp_generate_password( 32, false, false );
			update_option( self::OPTION, $all, false );
		}
		return $all['cron_secret'];
	}

	/**
	 * Prüft und speichert die Eingaben aus dem Formular.
	 *
	 * @param array $in Rohwerte.
	 * @return array Gespeicherte Werte.
	 */
	public static function save( array $in ) {
		$d    = self::defaults();
		$cur  = self::all();
		$mode = isset( $in['mode'] ) ? sanitize_key( $in['mode'] ) : $cur['mode'];
		$int  = isset( $in['interval'] ) ? (int) $in['interval'] : (int) $cur['interval'];
		$out  = array(
			'mode'                  => isset( self::MODES[ $mode ] ) ? $mode : ( isset( self::MODES[ $cur['mode'] ] ) ? $cur['mode'] : $d['mode'] ),
			'interval'              => isset( self::INTERVALS[ $int ] ) ? $int : ( isset( self::INTERVALS[ (int) $cur['interval'] ] ) ? (int) $cur['interval'] : $d['interval'] ),
			'window_start'          => self::range( $in, 'window_start', 0, 23, $cur['window_start'] ),
			'window_end'            => self::range( $in, 'window_end', 1, 24, $cur['window_end'] ),
			'sitemap_hour'          => self::range( $in, 'sitemap_hour', 0, 23, $cur['sitemap_hour'] ),
			'partner_id'            => self::digits( $in, 'partner_id', $d['partner_id'] ),
			'ad_id'                 => self::digits( $in, 'ad_id', $d['ad_id'] ),
			'program_id'            => self::digits( $in, 'program_id', $d['program_id'] ),
			'tracking_host'         => self::host( isset( $in['tracking_host'] ) ? $in['tracking_host'] : '', $d['tracking_host'] ),
			'sitemap_url'           => self::appsumo_url( isset( $in['sitemap_url'] ) ? $in['sitemap_url'] : '', $d['sitemap_url'] ),
			'batch_size'            => self::range( $in, 'batch_size', 1, 100, $d['batch_size'] ),
			'delay'                 => self::range( $in, 'delay', 0, 10, $d['delay'] ),
			'recheck_available_hrs' => self::range( $in, 'recheck_available_hrs', 6, 168, $d['recheck_available_hrs'] ),
			'recheck_other_days'    => self::range( $in, 'recheck_other_days', 1, 180, $d['recheck_other_days'] ),
			'notify_email'          => is_email( isset( $in['notify_email'] ) ? trim( (string) $in['notify_email'] ) : '' ) ? sanitize_email( $in['notify_email'] ) : $d['notify_email'],
			'update_token'          => isset( $in['update_token'] ) ? sanitize_text_field( (string) $in['update_token'] ) : '',
			'cron_secret'           => $cur['cron_secret'],
			'ai_provider'           => isset( $in['ai_provider'], self::AI_PROVIDERS[ $in['ai_provider'] ] ) ? $in['ai_provider'] : $cur['ai_provider'],
			'ai_key'                => self::secret( $in, 'ai_key', $cur ),
			'ai_model'              => isset( $in['ai_model'] ) ? substr( preg_replace( '/[^A-Za-z0-9._:\/\-]/', '', (string) $in['ai_model'] ), 0, 100 ) : $cur['ai_model'],
			'youtube_key'           => self::secret( $in, 'youtube_key', $cur ),
			'title_template'        => self::template( $in, 'title_template', $cur ),
			'seo_title_template'    => self::template( $in, 'seo_title_template', $cur ),
			'batch_profiles'        => self::range( $in, 'batch_profiles', 1, 50, $cur['batch_profiles'] ),
			'auto_profiles'         => array_key_exists( 'mode', $in ) ? ( empty( $in['auto_profiles'] ) ? 0 : 1 ) : (int) $cur['auto_profiles'],
			'reviews_newest'        => self::range( $in, 'reviews_newest', 0, 100, $cur['reviews_newest'] ),
			'reviews_critical'      => self::range( $in, 'reviews_critical', 0, 100, $cur['reviews_critical'] ),
		);
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/**
	 * Liegt die Stunde im Zeitfenster? Start gleich Ende oder 0 bis 24 heißt rund um die Uhr,
	 * Start größer Ende geht über Mitternacht (z. B. 22 bis 6).
	 */
	public static function in_window( $hour, $start = null, $end = null ) {
		$start = null === $start ? (int) self::get( 'window_start' ) : (int) $start;
		$end   = null === $end ? (int) self::get( 'window_end' ) : (int) $end;
		$hour  = (int) $hour;
		if ( $start === $end % 24 ) {
			return true;
		}
		return $start < $end ? ( $hour >= $start && $hour < $end ) : ( $hour >= $start || $hour < $end );
	}

	/** Stunden pro Tag im Zeitfenster. */
	public static function window_hours() {
		$n = 0;
		for ( $h = 0; $h < 24; $h++ ) {
			$n += self::in_window( $h ) ? 1 : 0;
		}
		return $n;
	}

	/** Geheimnis: leere Eingabe behält den alten Wert, Häkchen „löschen“ entfernt ihn. */
	private static function secret( array $in, $key, array $cur ) {
		if ( ! empty( $in[ $key . '_delete' ] ) ) {
			return '';
		}
		$v = isset( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
		return '' !== $v ? substr( preg_replace( '/[^\x21-\x7E]/', '', $v ), 0, 300 ) : (string) $cur[ $key ];
	}

	/** Titelvorlage muss {tool} enthalten. */
	private static function template( array $in, $key, array $cur ) {
		$v = isset( $in[ $key ] ) ? trim( sanitize_text_field( (string) $in[ $key ] ) ) : '';
		return false !== strpos( $v, '{tool}' ) ? substr( $v, 0, 200 ) : (string) $cur[ $key ];
	}

	private static function digits( array $in, $key, $fallback ) {
		$v = isset( $in[ $key ] ) ? preg_replace( '/\D/', '', (string) $in[ $key ] ) : '';
		return '' !== $v ? substr( $v, 0, 12 ) : $fallback;
	}

	private static function range( array $in, $key, $min, $max, $fallback ) {
		if ( ! isset( $in[ $key ] ) || '' === trim( (string) $in[ $key ] ) ) {
			return (int) $fallback;
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
