<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Website des Herstellers und Screenshot der Startseite als Produktbild.
 *
 * Website: aus den Seitendaten bei AppSumo (Spalte website), im Profil überschreibbar (Meta _adm_website).
 * Screenshot: einmal pro Profil über den Dienst mShots von WordPress.com (Filter adm_screenshot_service),
 * gespeichert in der Mediathek (Meta _adm_shot). Besucher laden nur das eigene Bild, kein Fremddienst.
 * mShots liefert beim ersten Abruf ein Platzhalterbild und erzeugt den Screenshot im Hintergrund;
 * ein späterer Lauf holt ihn ab (frühestens nach 5 Minuten, höchstens 12 Versuche, dann Meta _adm_shot_failed).
 */
class ADM_Shots {

	const META       = '_adm_shot';
	const META_SITE  = '_adm_website';
	const META_TRIES = '_adm_shot_tries';
	const META_FAIL  = '_adm_shot_failed';
	const META_LAST  = '_adm_shot_last';
	const MAX_TRIES  = 12;
	/** Mindestabstand zwischen zwei Abrufen je Profil (mShots braucht Zeit zum Erzeugen). */
	const RETRY_WAIT = 300;

	public static function init() {
		add_action( ADM_Sync::HOOK, array( __CLASS__, 'cron' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_reset_failed' ) );
	}

	/** Einmalig nach 0.8.3: zu früh abgebrochene Screenshots neu versuchen. */
	public static function maybe_reset_failed() {
		if ( get_option( 'adm_shots_retry_v' ) === '2' ) {
			return;
		}
		delete_post_meta_by_key( self::META_FAIL );
		delete_post_meta_by_key( self::META_TRIES );
		update_option( 'adm_shots_retry_v', '2', false );
	}

	public static function enabled() {
		return (bool) (int) ADM_Settings::get( 'screenshots' );
	}

	/** Website eines Profils: eigene Angabe im Profil, sonst aus den AppSumo-Daten. */
	public static function website( array $deal ) {
		$own = ! empty( $deal['post_id'] ) ? (string) get_post_meta( (int) $deal['post_id'], self::META_SITE, true ) : '';
		$url = '' !== $own ? $own : ( isset( $deal['website'] ) ? (string) $deal['website'] : '' );
		return self::clean_url( $url );
	}

	/** Nur http(s), keine AppSumo-Adressen, ohne Abfrage und Anker. */
	public static function clean_url( $url ) {
		$url = trim( str_replace( '\\/', '/', (string) $url ) );
		$p   = wp_parse_url( $url );
		if ( ! $p || empty( $p['host'] ) || empty( $p['scheme'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = strtolower( $p['host'] );
		if ( preg_match( '/(^|\.)appsumo\.com$/', $host ) || ! preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}$/', $host ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? $p['path'] : '/';
		return esc_url_raw( strtolower( $p['scheme'] ) . '://' . $host . ( '' !== $path ? $path : '/' ) );
	}

	/** Hostname ohne www. für Beschriftungen. */
	public static function host( $url ) {
		$h = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $h ) );
	}

	/** Mediathek-ID des Screenshots (0, wenn keiner da ist). */
	public static function image_id( $post_id ) {
		$id = (int) get_post_meta( (int) $post_id, self::META, true );
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/** Profile, die noch einen Screenshot brauchen. */
	public static function pending( $limit = 50 ) {
		global $wpdb;
		$t   = ADM_DB::table();
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT d.post_id FROM {$t} d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id AND p.post_type = %s AND p.post_status IN ('publish','draft','pending','future')
			 WHERE d.post_id > 0 ORDER BY (p.post_status = 'publish') DESC, d.review_count DESC LIMIT 500",
			ADM_Profiles::CPT
		) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		foreach ( (array) $ids as $pid ) {
			$pid = (int) $pid;
			if ( self::image_id( $pid ) || get_post_meta( $pid, self::META_FAIL, true ) || (int) get_post_meta( $pid, self::META_LAST, true ) > time() - self::RETRY_WAIT ) {
				continue;
			}
			$deal = ADM_DB::get_by_post( $pid );
			if ( $deal && '' !== self::website( $deal ) ) {
				$out[] = $pid;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** WP-Cron und Server-Cron: ein paar Screenshots pro Schritt. */
	public static function cron() {
		if ( self::enabled() ) {
			self::process( 3 );
		}
	}

	/**
	 * @param int $max Höchstzahl Abrufe in diesem Schritt.
	 * @return array Bericht: saved, waiting, failed.
	 */
	public static function process( $max = 3 ) {
		$report = array( 'saved' => 0, 'waiting' => 0, 'failed' => 0 );
		foreach ( self::pending( $max ) as $pid ) {
			$r = self::fetch( $pid );
			if ( is_int( $r ) && $r > 0 ) {
				$report['saved']++;
			} elseif ( 'waiting' === $r ) {
				$report['waiting']++;
			} else {
				$report['failed']++;
			}
		}
		return $report;
	}

	/** Adresse beim Screenshot-Dienst. */
	public static function service_url( $site ) {
		$site = preg_replace( '#^http://#i', 'https://', (string) $site ); // fast jede Website läuft über https, der Dienst liefert dafür schneller ein Bild
		$url  = 'https://s.wordpress.com/mshots/v1/' . rawurlencode( $site ) . '?w=1280&h=800';
		return (string) apply_filters( 'adm_screenshot_service', $url, $site );
	}

	/**
	 * Screenshot für ein Profil abrufen und speichern.
	 *
	 * @return int|string|WP_Error Mediathek-ID, „waiting“ (Dienst erzeugt noch) oder Fehler.
	 */
	public static function fetch( $post_id ) {
		$deal = ADM_DB::get_by_post( $post_id );
		$site = $deal ? self::website( $deal ) : '';
		if ( '' === $site ) {
			return new WP_Error( 'adm_shot_site', 'Keine Website bekannt.' );
		}
		$tries = (int) get_post_meta( $post_id, self::META_TRIES, true ) + 1;
		update_post_meta( $post_id, self::META_TRIES, $tries );
		update_post_meta( $post_id, self::META_LAST, time() );

		$res = wp_remote_get( self::service_url( $site ), array( 'timeout' => 25, 'redirection' => 3 ) );
		$ok  = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
		$typ = $ok ? strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ) : '';
		$body = $ok ? (string) wp_remote_retrieve_body( $res ) : '';
		if ( ! $ok || ( false === strpos( $typ, 'image/jpeg' ) && false === strpos( $typ, 'image/png' ) ) || strlen( $body ) < 15000 ) {
			if ( $tries >= self::MAX_TRIES ) {
				update_post_meta( $post_id, self::META_FAIL, time() );
				return new WP_Error( 'adm_shot_failed', 'Kein Screenshot nach ' . $tries . ' Versuchen.' );
			}
			return 'waiting';
		}
		$ext  = false !== strpos( $typ, 'png' ) ? 'png' : 'jpg';
		$name = 'screenshot-' . sanitize_title( $deal['slug'] ) . '.' . $ext;
		$up   = wp_upload_bits( $name, null, $body );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'adm_shot_upload', $up['error'] );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$host = self::host( $site );
		$att  = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/' . ( 'png' === $ext ? 'png' : 'jpeg' ),
				'post_title'     => 'Screenshot ' . $deal['title'],
				'post_excerpt'   => 'Screenshot: ' . $host,
				'post_content'   => 'Screenshot der Website ' . $site . ' vom ' . wp_date( 'd.m.Y' ) . '.',
				'post_status'    => 'inherit',
			),
			$up['file'],
			$post_id
		);
		if ( is_wp_error( $att ) || ! $att ) {
			return is_wp_error( $att ) ? $att : new WP_Error( 'adm_shot_insert', 'Bild konnte nicht gespeichert werden.' );
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		update_post_meta( $att, '_wp_attachment_image_alt', 'Screenshot der Website von ' . $deal['title'] );
		update_post_meta( $post_id, self::META, $att );
		update_post_meta( $post_id, '_adm_shot_date', time() );
		delete_post_meta( $post_id, self::META_TRIES );
		// Beitragsbild: Screenshot statt Rubrikbild (ein selbst gesetztes Bild bleibt).
		$thumb = (int) get_post_thumbnail_id( $post_id );
		if ( ! $thumb || self::is_rubric_image( $thumb ) ) {
			set_post_thumbnail( $post_id, $att );
		}
		return (int) $att;
	}

	/** Screenshot verwerfen, damit er neu geholt wird (Bild bleibt in der Mediathek). */
	public static function reset( $post_id ) {
		$old = self::image_id( $post_id );
		delete_post_meta( $post_id, self::META );
		delete_post_meta( $post_id, self::META_TRIES );
		delete_post_meta( $post_id, self::META_FAIL );
		delete_post_meta( $post_id, self::META_LAST );
		if ( $old && (int) get_post_thumbnail_id( $post_id ) === $old ) {
			delete_post_thumbnail( $post_id );
			ADM_Rubrics::assign_image( $post_id );
		}
	}

	private static function is_rubric_image( $att ) {
		$terms = get_terms( array( 'taxonomy' => ADM_Rubrics::TAX, 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $tid ) {
			if ( (int) ADM_Rubrics::image_id( $tid ) === (int) $att ) {
				return true;
			}
		}
		return false;
	}

	/** Bild für Kopf und Karten: Screenshot, sonst Beitragsbild, sonst Rubrikbild. */
	public static function display_image_id( $post_id, $term = null ) {
		$id = self::image_id( $post_id );
		if ( $id ) {
			return array( $id, true );
		}
		$id = (int) get_post_thumbnail_id( $post_id );
		if ( ! $id && $term ) {
			$id = ADM_Rubrics::image_id( $term->term_id );
		}
		return array( $id, false );
	}
}
