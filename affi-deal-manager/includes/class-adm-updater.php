<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-hosted Updates über update.json (Schema wie bei den anderen Plugins):
 * https://mediainfo.space/Thoka_Plugins/affi-deal-manager/update.json
 *
 * Sicherheit:
 * - "Update URI"-Header im Plugin verhindert, dass WordPress.org einen gleichnamigen Fremd-Slug einspielt.
 * - Manifest und Paket nur über HTTPS und nur vom erlaubten Host.
 * - Optional: SHA-256-Prüfsumme aus der update.json wird vor der Installation geprüft.
 * - Optional: geheimer Token (Einstellungen), wird als Header X-Update-Token und als Parameter "token" gesendet.
 */
class ADM_Updater {

	const SLUG     = 'affi-deal-manager';
	const HOST     = 'mediainfo.space';
	const MANIFEST = 'https://mediainfo.space/Thoka_Plugins/affi-deal-manager/update.json';
	const CACHE    = 'adm_update_manifest';

	public static function init() {
		add_filter( 'update_plugins_' . self::HOST, array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
		add_action( 'admin_post_adm_check_update', array( __CLASS__, 'handle_check' ) );
	}

	private static function basename() {
		return plugin_basename( ADM_FILE );
	}

	private static function token_headers() {
		$token = (string) ADM_Settings::get( 'update_token' );
		return '' !== $token ? array( 'X-Update-Token' => $token ) : array();
	}

	private static function with_token( $url ) {
		$token = (string) ADM_Settings::get( 'update_token' );
		return '' !== $token ? add_query_arg( 'token', rawurlencode( $token ), $url ) : $url;
	}

	private static function url_ok( $url ) {
		$p = wp_parse_url( (string) $url );
		return $p && isset( $p['scheme'], $p['host'] ) && 'https' === $p['scheme'] && strtolower( $p['host'] ) === self::HOST;
	}

	/** @return array|null Geprüftes Manifest oder null. */
	public static function manifest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached ? $cached : null; // leeres Array = letzter Abruf fehlgeschlagen
			}
		}
		$res = wp_remote_get( self::with_token( self::MANIFEST ), array(
			'timeout' => 15,
			'headers' => array_merge( array( 'Accept' => 'application/json' ), self::token_headers() ),
		) );
		$data = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json )
				&& isset( $json['version'], $json['download_url'] )
				&& preg_match( '/^\d+(\.\d+){0,3}([\-+][A-Za-z0-9.\-]+)?$/', (string) $json['version'] )
				&& self::url_ok( $json['download_url'] ) ) {
				if ( isset( $json['sha256'] ) && ! preg_match( '/^[a-f0-9]{64}$/i', (string) $json['sha256'] ) ) {
					unset( $json['sha256'] );
				}
				$data = $json;
			}
		}
		set_transient( self::CACHE, $data ? $data : array(), $data ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/** Filter update_plugins_{host}: meldet WordPress die aktuelle Version. */
	public static function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== self::basename() ) {
			return $update;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $update;
		}
		return array(
			'id'           => 'mediainfo.space/' . self::SLUG,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'version'      => $m['version'],
			'url'          => isset( $m['homepage'] ) && self::url_ok( $m['homepage'] ) ? $m['homepage'] : 'https://' . self::HOST . '/Thoka_Plugins/',
			'package'      => $m['download_url'],
			'tested'       => isset( $m['tested'] ) ? sanitize_text_field( $m['tested'] ) : '',
			'requires'     => isset( $m['requires'] ) ? sanitize_text_field( $m['requires'] ) : '',
			'requires_php' => isset( $m['requires_php'] ) ? sanitize_text_field( $m['requires_php'] ) : '',
			'autoupdate'   => false,
			'icons'        => array(),
			'banners'      => array(),
			'translations' => array(),
		);
	}

	/** Detailfenster „Details anzeigen“. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $result;
		}
		$sections = array();
		if ( ! empty( $m['sections'] ) && is_array( $m['sections'] ) ) {
			foreach ( array( 'description', 'changelog', 'installation' ) as $k ) {
				if ( ! empty( $m['sections'][ $k ] ) ) {
					$sections[ $k ] = wp_kses_post( $m['sections'][ $k ] );
				}
			}
		}
		return (object) array(
			'name'          => isset( $m['name'] ) ? sanitize_text_field( $m['name'] ) : 'Thoka Affi Deal Manager',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => isset( $m['author'] ) ? wp_kses_post( $m['author'] ) : '',
			'homepage'      => isset( $m['homepage'] ) && self::url_ok( $m['homepage'] ) ? $m['homepage'] : 'https://' . self::HOST . '/Thoka_Plugins/',
			'requires'      => isset( $m['requires'] ) ? sanitize_text_field( $m['requires'] ) : '',
			'tested'        => isset( $m['tested'] ) ? sanitize_text_field( $m['tested'] ) : '',
			'requires_php'  => isset( $m['requires_php'] ) ? sanitize_text_field( $m['requires_php'] ) : '',
			'last_updated'  => isset( $m['last_updated'] ) ? sanitize_text_field( $m['last_updated'] ) : '',
			'download_link' => $m['download_url'],
			'sections'      => $sections,
		);
	}

	/**
	 * Lädt das Paket selbst herunter (damit der Token mitgesendet werden kann)
	 * und prüft die SHA-256-Summe, falls im Manifest angegeben.
	 */
	public static function download( $reply, $package, $upgrader, $hook_extra = array() ) {
		$m = self::manifest();
		if ( ! $m || $package !== $m['download_url'] ) {
			return $reply; // nicht unser Paket
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $package );
		if ( ! $tmp ) {
			return new WP_Error( 'adm_update_tmp', 'Temporäre Datei konnte nicht angelegt werden.' );
		}
		$res = wp_safe_remote_get( self::with_token( $package ), array(
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $tmp,
			'headers'  => self::token_headers(),
		) );
		if ( is_wp_error( $res ) ) {
			@unlink( $tmp );
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			@unlink( $tmp );
			return new WP_Error( 'adm_update_http', 'Update-Download fehlgeschlagen (HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . ').' );
		}
		if ( ! empty( $m['sha256'] ) ) {
			$actual = hash_file( 'sha256', $tmp );
			if ( ! $actual || ! hash_equals( strtolower( $m['sha256'] ), strtolower( $actual ) ) ) {
				@unlink( $tmp );
				return new WP_Error( 'adm_update_hash', 'Prüfsumme (SHA-256) des Updates stimmt nicht – Installation abgebrochen.' );
			}
		}
		return $tmp;
	}

	public static function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_check_update' );
		delete_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		self::manifest( true );
		wp_update_plugins();
		wp_safe_redirect( add_query_arg( array( 'page' => 'affi-deal-manager-settings', 'checked' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
