<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Impact-Schnittstelle (Partner-API), nur lesend.
 *
 * Zugang nur aus der wp-config.php: THOKA_IMPACT_ACCOUNT_SID und THOKA_IMPACT_AUTH_TOKEN, nie in der Datenbank.
 * Vorerst nur ein Verbindungstest, der zeigt, welche Daten die Schnittstelle zu AppSumo liefert
 * (Feldnamen, Kennzahlen eines Beispielprodukts). Zugangsdaten erscheinen nie in der Ausgabe.
 */
class ADM_Impact {

	const SID_CONSTANT   = 'THOKA_IMPACT_ACCOUNT_SID';
	const TOKEN_CONSTANT = 'THOKA_IMPACT_AUTH_TOKEN';
	const BASE           = 'https://api.impact.com/Mediapartners/';
	const TEST_OPTION    = 'adm_impact_test';

	public static function init() {
		add_action( 'admin_post_adm_impact_test', array( __CLASS__, 'handle_test' ) );
	}

	public static function sid() {
		return defined( self::SID_CONSTANT ) ? trim( (string) constant( self::SID_CONSTANT ) ) : '';
	}

	private static function token() {
		return defined( self::TOKEN_CONSTANT ) ? trim( (string) constant( self::TOKEN_CONSTANT ) ) : '';
	}

	public static function configured() {
		return '' !== self::sid() && '' !== self::token();
	}

	/**
	 * GET-Abruf relativ zum Partnerkonto, Antwort als JSON.
	 *
	 * @return array{code:int,data:mixed,error:string}
	 */
	public static function get( $path, array $args = array() ) {
		if ( ! self::configured() ) {
			return array( 'code' => 0, 'data' => null, 'error' => 'Zugangsdaten fehlen' );
		}
		$url = self::BASE . rawurlencode( self::sid() ) . '/' . ltrim( $path, '/' );
		if ( $args ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args ), $url );
		}
		$res = wp_remote_get( $url, array(
			'timeout' => 25,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( self::sid() . ':' . self::token() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			),
		) );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'data' => null, 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$err  = $code >= 400 ? ( is_array( $data ) && ! empty( $data['Message'] ) ? (string) $data['Message'] : 'HTTP ' . $code ) : '';
		return array( 'code' => $code, 'data' => $data, 'error' => self::mask( $err ) );
	}

	/** Zugangsdaten aus Texten entfernen. */
	private static function mask( $text ) {
		foreach ( array( self::sid(), self::token() ) as $secret ) {
			if ( '' !== $secret ) {
				$text = str_replace( $secret, '***', (string) $text );
			}
		}
		return (string) $text;
	}

	/** Erste Liste in einer Antwort (Impact packt Listen in Schlüssel wie Catalogs, Items, Campaigns). */
	private static function first_list( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) && isset( $v[0] ) && is_array( $v[0] ) && 0 !== strpos( (string) $k, '@' ) ) {
				return $v;
			}
		}
		return array();
	}

	/** Felder eines Eintrags mit kurzen Beispielwerten (Zahlen und kurze Texte). */
	private static function sample( array $row ) {
		$out = array();
		foreach ( $row as $k => $v ) {
			if ( is_scalar( $v ) || null === $v ) {
				$v         = (string) $v;
				$out[ $k ] = self::mask( function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 80 ) : substr( $v, 0, 80 ) );
			} else {
				$out[ $k ] = '[' . gettype( $v ) . ']';
			}
		}
		return $out;
	}

	/** Verbindungstest: Programme, Kataloge, Katalogeinträge und Kandidaten für Marktplatz-Produkte. */
	public static function test() {
		$program = (string) ADM_Settings::get( 'program_id' );
		$report  = array( 'zeit' => wp_date( 'd.m.Y H:i' ) );

		$r = self::get( 'Campaigns', array( 'PageSize' => '100' ) );
		$list = self::first_list( $r['data'] );
		$camp = array();
		foreach ( $list as $c ) {
			if ( isset( $c['CampaignId'] ) && (string) $c['CampaignId'] === $program ) {
				$camp = $c;
			}
		}
		$report['Campaigns'] = array( 'http' => $r['code'], 'fehler' => $r['error'], 'anzahl' => count( $list ), 'appsumo' => $camp ? self::sample( $camp ) : 'nicht gefunden' );

		$r    = self::get( 'Catalogs', array( 'CampaignId' => $program ) );
		$cats = self::first_list( $r['data'] );
		$report['Catalogs'] = array( 'http' => $r['code'], 'fehler' => $r['error'], 'kataloge' => array_map( function ( $c ) {
			return array_intersect_key( $c, array_flip( array( 'Id', 'Name', 'CampaignId', 'NumberOfItems', 'DateLastUpdated' ) ) );
		}, $cats ) );

		if ( $cats && isset( $cats[0]['Id'] ) ) {
			$r     = self::get( 'Catalogs/' . rawurlencode( (string) $cats[0]['Id'] ) . '/Items', array( 'PageSize' => '1' ) );
			$items = self::first_list( $r['data'] );
			$report['CatalogItems'] = array( 'http' => $r['code'], 'fehler' => $r['error'], 'beispiel' => $items ? self::sample( $items[0] ) : 'leer' );
		}

		$r     = self::get( 'Catalogs/ItemSearch', array( 'Keyword' => 'DocPro', 'PageSize' => '1' ) );
		$items = self::first_list( $r['data'] );
		$report['ItemSearch'] = array( 'http' => $r['code'], 'fehler' => $r['error'], 'beispiel' => $items ? self::sample( $items[0] ) : 'leer' );

		// Marktplatz-Produkte (dort zeigt die Oberfläche EPC und Conversion-Rate); der Pfad ist nicht dokumentiert.
		foreach ( array( 'MarketplaceProducts', 'Marketplace/Products', 'Products' ) as $p ) {
			$r     = self::get( $p, array( 'CampaignId' => $program, 'Keyword' => 'DocPro', 'PageSize' => '1' ) );
			$items = self::first_list( $r['data'] );
			$report[ 'Pfad ' . $p ] = array( 'http' => $r['code'], 'fehler' => $r['error'], 'beispiel' => $items ? self::sample( $items[0] ) : ( is_array( $r['data'] ) ? array_keys( $r['data'] ) : 'leer' ) );
		}
		update_option( self::TEST_OPTION, $report, false );
		return $report;
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_impact_test' );
		if ( self::configured() ) {
			self::test();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=affi-deal-manager-settings#impact-api' ) );
		exit;
	}

	/** Zeile in den Einstellungen: Status, Testknopf, letztes Ergebnis. */
	public static function settings_row() {
		$ok  = self::configured();
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=adm_impact_test' ), 'adm_impact_test' );
		echo '<tr id="impact-api"><th scope="row">Impact-API</th><td>';
		echo '<p>' . esc_html( self::SID_CONSTANT . ': ' . ( '' !== self::sid() ? 'gesetzt' : 'fehlt' ) . ' · ' . self::TOKEN_CONSTANT . ': ' . ( '' !== self::token() ? 'gesetzt' : 'fehlt' ) ) . '</p>';
		if ( $ok ) {
			echo '<p><a class="button" href="' . esc_url( $url ) . '">Verbindung testen</a></p>';
		}
		echo '<p class="description">Nur lesend. Account SID und Auth Token stehen nur in der wp-config.php und erscheinen nirgends in der Ausgabe.</p>';
		$last = get_option( self::TEST_OPTION );
		if ( $ok && $last ) {
			echo '<details open><summary>Letzter Test</summary><pre style="white-space:pre-wrap;max-height:420px;overflow:auto;background:#f6f7f7;padding:10px">' . esc_html( wp_json_encode( $last, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
		}
		echo '</td></tr>';
	}
}
