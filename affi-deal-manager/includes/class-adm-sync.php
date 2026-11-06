<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abgleich mit AppSumo.
 *
 * Jeder Schritt (Takt und Zeitfenster einstellbar, über WP-Cron oder Server-Cron):
 * 1. einmal am Tag ab der eingestellten Uhrzeit die Sitemap lesen: neue Produkte anlegen,
 *    geänderte (lastmod) fällig setzen, verschwundene kaufbare Deals auf „ausgelaufen“ setzen;
 * 2. die fälligen Produktseiten abrufen (Anzahl und Pause einstellbar) und die schema.org-Daten übernehmen.
 */
class ADM_Sync {

	const HOOK          = 'adm_sync_tick';
	const LOCK          = 'adm_sync_lock';
	const HEALTH        = 'adm_health';
	const SITEMAP_READ  = 'adm_sitemap_read';
	const SITEMAP_RETRY = 'adm_sitemap_retry';
	const FAIL_LIMIT    = 10;
	const TIME_BUDGET   = 200; // Sekunden pro Schritt
	const REST_NS       = 'affi-deal-manager/v1';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'cron_tick' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		self::ensure_schedule();
	}

	/** Für jeden wählbaren Takt einen eigenen WP-Cron-Zeitplan. */
	public static function schedules( $s ) {
		foreach ( ADM_Settings::INTERVALS as $min => $label ) {
			$s[ 'adm_every_' . $min ] = array( 'interval' => $min * MINUTE_IN_SECONDS, 'display' => 'Affi Deal Manager: ' . $label );
		}
		return $s;
	}

	public static function recurrence() {
		return 'adm_every_' . (int) ADM_Settings::get( 'interval' );
	}

	/** Plant den WP-Cron passend zu Modus und Takt (oder entfernt ihn). */
	public static function ensure_schedule() {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::HOOK ) : false;
		if ( 'wpcron' !== ADM_Settings::get( 'mode' ) ) {
			if ( $event ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		if ( $event && $event->schedule === self::recurrence() ) {
			return;
		}
		self::reschedule();
	}

	public static function reschedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		wp_clear_scheduled_hook( self::HOOK );
		if ( 'wpcron' === ADM_Settings::get( 'mode' ) ) {
			wp_schedule_event( time() + 60, self::recurrence(), self::HOOK );
		}
	}

	/** Aktivierung. */
	public static function schedule() {
		self::reschedule();
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function cron_tick() {
		self::tick( false, 'wpcron' );
	}

	/* ---------------- Server-Cron ---------------- */

	public static function rest_routes() {
		register_rest_route( self::REST_NS, '/tick', array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => array( __CLASS__, 'rest_tick' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
	}

	public static function rest_permission( WP_REST_Request $req ) {
		$key = (string) $req->get_header( 'x_adm_key' );
		if ( '' === $key ) {
			$key = (string) $req->get_param( 'key' );
		}
		$secret = (string) ADM_Settings::get( 'cron_secret' );
		if ( '' === $secret || '' === $key || ! hash_equals( $secret, $key ) ) {
			return new WP_Error( 'adm_forbidden', 'Schlüssel fehlt oder ist falsch.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_tick( WP_REST_Request $req ) {
		if ( 'servercron' !== ADM_Settings::get( 'mode' ) ) {
			return new WP_REST_Response( array( 'skipped' => 'Modus „Server-Cron“ ist nicht eingeschaltet.' ), 409 );
		}
		return new WP_REST_Response( self::tick( false, 'servercron' ), 200 );
	}

	public static function server_cron_url() {
		return rest_url( self::REST_NS . '/tick' );
	}

	/* ---------------- Ablauf ---------------- */

	/**
	 * Ein Abgleichschritt.
	 *
	 * @param bool   $force  Auch außerhalb von Modus und Zeitfenster ausführen (Knopf „Jetzt abgleichen“).
	 * @param string $source wpcron, servercron oder manuell.
	 * @return array Kurzbericht.
	 */
	public static function tick( $force = false, $source = 'manuell' ) {
		$report = array( 'sitemap' => '', 'checked' => 0, 'errors' => 0, 'skipped' => '', 'source' => $source );
		if ( ! $force ) {
			$mode = ADM_Settings::get( 'mode' );
			if ( 'off' === $mode ) {
				$report['skipped'] = 'Abgleich ist ausgeschaltet.';
				return $report;
			}
			if ( $mode !== $source ) {
				$report['skipped'] = 'Aufruf passt nicht zum eingestellten Modus.';
				return $report;
			}
			if ( ! ADM_Settings::in_window( (int) wp_date( 'G' ) ) ) {
				$report['skipped'] = 'Außerhalb des Zeitfensters.';
				return $report;
			}
		}
		if ( get_transient( self::LOCK ) ) {
			$report['skipped'] = 'Ein Abgleich läuft bereits.';
			return $report;
		}
		set_transient( self::LOCK, 1, self::TIME_BUDGET + 2 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_BUDGET + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$start = time();

		try {
			if ( self::sitemap_due() ) {
				$report['sitemap'] = self::read_sitemap();
			}
			$rows  = ADM_DB::due( (int) ADM_Settings::get( 'batch_size' ) );
			$delay = (int) ADM_Settings::get( 'delay' );
			foreach ( $rows as $i => $row ) {
				// Platz für einen weiteren Abruf (bis 20 s) lassen, Rest im nächsten Schritt.
				if ( time() - $start > self::TIME_BUDGET - 25 ) {
					$report['skipped'] = 'Zeitlimit erreicht, der Rest folgt im nächsten Schritt.';
					break;
				}
				if ( $i > 0 && $delay > 0 ) {
					sleep( $delay );
				}
				$ok = self::check_row( $row );
				$report['checked']++;
				if ( ! $ok ) {
					$report['errors']++;
				}
			}
		} finally {
			delete_transient( self::LOCK );
		}
		update_option( 'adm_last_tick', array( 'time' => time(), 'report' => $report ), false );
		return $report;
	}

	/**
	 * Sitemap fällig? Einmal pro Kalendertag ab der eingestellten Stunde (Ortszeit),
	 * nach einem Fehler frühestens nach einer Stunde erneut, spätestens nach zwei Tagen in jedem Fall.
	 */
	public static function sitemap_due( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$retry = (int) get_option( self::SITEMAP_RETRY, 0 );
		if ( $retry ) {
			return $now >= $retry;
		}
		$last = (int) get_option( self::SITEMAP_READ, 0 );
		if ( ! $last || $now - $last > 2 * DAY_IN_SECONDS ) {
			return true;
		}
		return wp_date( 'Y-m-d', $last ) !== wp_date( 'Y-m-d', $now ) && (int) wp_date( 'G', $now ) >= (int) ADM_Settings::get( 'sitemap_hour' );
	}

	/** Sitemap lesen und mit der Tabelle abgleichen. */
	public static function read_sitemap() {
		$res = self::fetch( ADM_Settings::get( 'sitemap_url' ) );
		if ( is_wp_error( $res ) ) {
			self::failure( 'Sitemap nicht abrufbar: ' . $res->get_error_message() );
			update_option( self::SITEMAP_RETRY, time() + HOUR_IN_SECONDS, false ); // in einer Stunde erneut
			return 'Fehler: ' . $res->get_error_message();
		}
		delete_option( self::SITEMAP_RETRY );
		$items = ADM_Parser::sitemap( $res );
		$map   = ADM_DB::url_map();

		$known_in_sitemap = 0;
		foreach ( $map as $r ) {
			$known_in_sitemap += (int) $r['in_sitemap'];
		}
		// Schutz gegen kaputte oder abgeschnittene Sitemaps: Verschwundene erst werten, wenn die Sitemap plausibel groß ist.
		if ( count( $items ) < 50 || ( $known_in_sitemap > 0 && count( $items ) < 0.5 * $known_in_sitemap ) ) {
			self::failure( 'Sitemap enthält nur ' . count( $items ) . ' Produkte (bisher ' . $known_in_sitemap . '). Abgleich der Sitemap übersprungen.' );
			update_option( self::SITEMAP_READ, time(), false );
			return 'Fehler: Sitemap unvollständig (' . count( $items ) . ' Produkte)';
		}

		$new     = array();
		$changed = 0;
		$now     = ADM_DB::now();
		$seen    = array();
		foreach ( $items as $it ) {
			$seen[ $it['url'] ] = true;
			if ( ! isset( $map[ $it['url'] ] ) ) {
				$new[] = $it;
				continue;
			}
			$r      = $map[ $it['url'] ];
			$update = array();
			if ( ! (int) $r['in_sitemap'] ) {
				$update['in_sitemap'] = 1;
				$update['next_check'] = $now;
			}
			if ( $it['lastmod'] && $it['lastmod'] !== (string) $r['sitemap_lastmod'] ) {
				$update['sitemap_lastmod'] = $it['lastmod'];
				$update['next_check']      = $now;
			}
			if ( null !== $it['priority'] && ( null === $r['sitemap_priority'] || (float) $it['priority'] !== (float) $r['sitemap_priority'] ) ) {
				$update['sitemap_priority'] = $it['priority'];
				// Nur ein Aufstieg (alter Deal wird wieder aktiv) macht den Eintrag sofort fällig.
				if ( null !== $r['sitemap_priority'] && (float) $it['priority'] > (float) $r['sitemap_priority'] ) {
					$update['next_check'] = $now;
				}
			}
			if ( $update ) {
				ADM_DB::update( $r['id'], $update );
				$changed += isset( $update['next_check'] ) ? 1 : 0;
			}
		}
		if ( $new ) {
			ADM_DB::insert_new( $new );
		}
		$gone = 0;
		foreach ( $map as $url => $r ) {
			if ( isset( $seen[ $url ] ) || ! (int) $r['in_sitemap'] ) {
				continue;
			}
			$update = array( 'in_sitemap' => 0 );
			if ( 'kaufbar' === $r['status'] ) {
				$update['status']       = 'ausgelaufen';
				$update['last_changed'] = $now;
				do_action( 'adm_deal_status_changed', (int) $r['id'], 'kaufbar', 'ausgelaufen' );
			}
			ADM_DB::update( $r['id'], $update );
			$gone++;
		}
		update_option( self::SITEMAP_READ, time(), false );
		self::success();
		return sprintf( '%d Produkte, %d neu, %d geändert, %d nicht mehr in der Sitemap', count( $items ), count( $new ), $changed, $gone );
	}

	/**
	 * Eine Produktseite abrufen und den Eintrag aktualisieren.
	 *
	 * @return bool Erfolg.
	 */
	public static function check_row( array $row ) {
		$now = ADM_DB::now();
		$res = self::fetch( $row['url'], $code );

		if ( is_wp_error( $res ) && in_array( (int) $code, array( 404, 410 ), true ) ) {
			$status = self::gone_status( $row );
			self::set_status( $row, $status, array(
				'availability' => 'Gone',
				'last_checked' => $now,
				'next_check'   => self::later( 'other' ),
				'fail_count'   => 0,
				'last_error'   => 'Seite nicht mehr vorhanden (HTTP ' . (int) $code . ')',
			) );
			return true;
		}
		if ( is_wp_error( $res ) ) {
			return self::row_failed( $row, $res->get_error_message() );
		}
		$data = ADM_Parser::product( $res );
		if ( ! $data ) {
			return self::row_failed( $row, 'Keine Produktdaten (schema.org) gefunden' );
		}

		$available = ADM_Parser::is_available( $data['availability'] );
		if ( $available ) {
			$status = 'kaufbar';
		} else {
			$status = self::gone_status( $row );
		}
		$data['was_available'] = $available || (int) $row['was_available'] ? 1 : 0;
		$data['last_checked']  = $now;
		$data['next_check']    = self::later( $available ? 'available' : 'other' );
		$data['fail_count']    = 0;
		$data['last_error']    = '';
		if ( (string) $row['price'] !== '' && null !== $data['price'] && (float) $row['price'] !== (float) $data['price'] ) {
			$data['last_changed'] = $now;
		}
		self::set_status( $row, $status, $data );
		self::success();
		return true;
	}

	/** Nicht (mehr) kaufbar: „ausgelaufen“, wenn der Deal kaufbar war oder ein Profil hat, sonst „ignoriert“. */
	private static function gone_status( array $row ) {
		return ( (int) $row['was_available'] || 'kaufbar' === $row['status'] || 'ausgelaufen' === $row['status'] || (int) $row['post_id'] ) ? 'ausgelaufen' : 'ignoriert';
	}

	private static function set_status( array $row, $status, array $data ) {
		$data['status'] = $status;
		if ( $status !== $row['status'] ) {
			$data['last_changed'] = ADM_DB::now();
		}
		ADM_DB::update( $row['id'], $data );
		if ( $status !== $row['status'] && 'neu' !== $row['status'] ) {
			do_action( 'adm_deal_status_changed', (int) $row['id'], $row['status'], $status );
		}
	}

	private static function row_failed( array $row, $message ) {
		$fails = (int) $row['fail_count'] + 1;
		ADM_DB::update( $row['id'], array(
			'fail_count' => min( 999, $fails ),
			'last_error' => substr( $message, 0, 255 ),
			'next_check' => gmdate( 'Y-m-d H:i:s', time() + min( DAY_IN_SECONDS, $fails * HOUR_IN_SECONDS ) ),
		) );
		self::failure( $row['url'] . ': ' . $message );
		return false;
	}

	private static function later( $kind ) {
		$secs = 'available' === $kind
			? (int) ADM_Settings::get( 'recheck_available_hrs' ) * HOUR_IN_SECONDS
			: (int) ADM_Settings::get( 'recheck_other_days' ) * DAY_IN_SECONDS;
		// Etwas Streuung, damit nicht alle Einträge zur selben Minute fällig werden.
		return gmdate( 'Y-m-d H:i:s', time() + $secs + wp_rand( 0, 1800 ) );
	}

	/**
	 * HTTP-Abruf mit eigener Kennung. Nur HTTPS auf appsumo.com.
	 *
	 * @param string   $url  Adresse.
	 * @param int|null $code HTTP-Status (Ausgabe).
	 * @return string|WP_Error Inhalt oder Fehler.
	 */
	public static function fetch( $url, &$code = null ) {
		$code = 0;
		if ( ! ADM_Links::is_appsumo( $url ) ) {
			return new WP_Error( 'adm_url', 'Nur AppSumo-Adressen werden abgerufen.' );
		}
		$res = wp_safe_remote_get( $url, array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'Mozilla/5.0 (compatible; ThokaAffiDealManager/' . ADM_VERSION . '; +' . home_url( '/' ) . ')',
			'headers'     => array( 'Accept' => 'text/html,application/xml;q=0.9,*/*;q=0.8' ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'adm_http', 'HTTP ' . $code );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/* ---------------- Zustand und Benachrichtigung ---------------- */

	public static function health() {
		$h = get_option( self::HEALTH, array() );
		return array_merge( array( 'failures' => 0, 'last_success' => 0, 'last_error' => '', 'last_error_time' => 0, 'last_notified' => 0 ), is_array( $h ) ? $h : array() );
	}

	private static function success() {
		$h = self::health();
		$h['failures']     = 0;
		$h['last_success'] = time();
		update_option( self::HEALTH, $h, false );
	}

	private static function failure( $message ) {
		$h = self::health();
		$h['failures']++;
		$h['last_error']      = substr( (string) $message, 0, 500 );
		$h['last_error_time'] = time();
		$is_sitemap = 0 === strpos( (string) $message, 'Sitemap' );
		if ( ( $is_sitemap || $h['failures'] >= self::FAIL_LIMIT ) && time() - (int) $h['last_notified'] > DAY_IN_SECONDS ) {
			$h['last_notified'] = time();
			self::notify( $message, $h['failures'] );
		}
		update_option( self::HEALTH, $h, false );
	}

	private static function notify( $message, $failures ) {
		$to = ADM_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$body = "Der Abgleich mit AppSumo auf {$site} hat Probleme.\n\n"
			. "Letzter Fehler: {$message}\n"
			. "Fehler in Folge: {$failures}\n\n"
			. "Die gespeicherten Deals bleiben unverändert stehen. Preise und Status zeigen weiter das Datum des letzten erfolgreichen Abgleichs.\n\n"
			. 'Übersicht: ' . admin_url( 'admin.php?page=affi-deal-manager' ) . "\n\n"
			. "Diese Nachricht kommt höchstens einmal am Tag.\n";
		wp_mail( $to, '[' . $site . '] Affi Deal Manager: Abgleich gestört', $body );
	}
}
