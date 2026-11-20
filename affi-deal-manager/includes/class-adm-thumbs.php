<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vorschaubilder für die Deal-Karten aller kaufbaren Deals (alle Typen, auch Plus): [adm_deals] und Profil-Karten ohne Screenshot.
 *
 * Bild (Spalte thumb_id, Bild in der Mediathek):
 * 0. Hat der Deal ein Tool-Profil mit Screenshot, zeigt die Karte diesen (kein zweiter Abruf).
 *    Läuft der Screenshot fürs Profil noch, wartet dieser Deal.
 * 1. Screenshot der Hersteller-Website über mShots, gleiche Wartelogik wie ADM_Shots.
 * 2. Gibt es keine Website oder klappt der Screenshot nicht, das erste Bild des Deals bei AppSumo (Spalte image_url).
 * 3. Sonst bleibt die Karte ohne Bild (thumb_src „none“).
 * Besucher laden nur Bilder aus der eigenen Mediathek. Reihenfolge: meiste Bewertungen zuerst (wie die Karten).
 */
class ADM_Thumbs {

	/** Höchstzahl Bildabrufe je Schritt. */
	const PER_TICK = 4;
	/** Breite des AppSumo-Bildes beim Abruf (CDN skaliert über ?width=). */
	const APPSUMO_WIDTH = 800;

	/** @var bool Pro Aufruf nur einmal arbeiten. */
	private static $done = false;

	public static function init() {
		// Wie ADM_Plus: WP-Cron-Takt, Server-Cron (ADM_Sync::rest_tick) und „Jetzt abgleichen“, unabhängig vom Zeitfenster.
		add_action( ADM_Sync::HOOK, array( __CLASS__, 'cron' ), 26 );
		add_action( 'adm_tick_done', array( __CLASS__, 'cron' ), 31 );
		add_action( 'admin_post_adm_thumb', array( __CLASS__, 'handle' ) );
	}

	/** Für Tests: mehrmals im selben Aufruf. */
	public static function reset_run() {
		self::$done = false;
	}

	/**
	 * Kaufbare Deals, die ein eigenes Vorschaubild brauchen.
	 * Deals mit Profil nur, wenn der Screenshot fürs Profil gescheitert ist oder nie kommen kann (keine Website).
	 */
	public static function pending( $limit = 10 ) {
		global $wpdb;
		$t    = ADM_DB::table();
		$wait = gmdate( 'Y-m-d H:i:s', time() - ADM_Shots::RETRY_WAIT );
		$sql  = "SELECT d.* FROM {$t} d WHERE d.status = 'kaufbar' AND d.name <> '' AND NOT " . str_replace( array( 'name', 'slug' ), array( 'd.name', 'd.slug' ), ADM_Plus::MEMBERSHIP_SQL ) . "
			AND d.thumb_id = 0 AND d.thumb_src <> 'none' AND ( d.thumb_last IS NULL OR d.thumb_last < %s )
			AND ( d.post_id = 0
				OR NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = d.post_id )
				OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = d.post_id AND f.meta_key = %s )
				OR ( d.website = '' AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} w WHERE w.post_id = d.post_id AND w.meta_key IN (%s, %s) AND w.meta_value <> '' ) ) )
			ORDER BY d.review_count DESC, d.id ASC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $wait, ADM_Shots::META_FAIL, ADM_Shots::META, ADM_Shots::META_SITE, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( 'ADM_DB', 'decode' ), (array) $rows );
	}

	/**
	 * Ein Schritt: ein paar Bilder.
	 *
	 * @return array Bericht: saved, waiting, failed.
	 */
	public static function cron() {
		$report = array( 'saved' => 0, 'waiting' => 0, 'failed' => 0 );
		if ( self::$done ) {
			return $report;
		}
		self::$done = true;
		foreach ( self::pending( self::PER_TICK ) as $d ) {
			$r = self::fetch_thumb( $d );
			if ( is_int( $r ) && $r > 0 ) {
				$report['saved']++;
			} elseif ( 'waiting' === $r ) {
				$report['waiting']++;
			} else {
				$report['failed']++;
			}
		}
		if ( $report['saved'] ) {
			ADM_Cache::mark_lists();
		}
		return $report;
	}

	/** Bild für eine Deal-Karte: Screenshot des Tool-Profils, sonst das eigene Vorschaubild. */
	public static function card_image( array $deal ) {
		$pid = isset( $deal['post_id'] ) ? (int) $deal['post_id'] : 0;
		if ( $pid ) {
			$shot = ADM_Shots::image_id( $pid );
			if ( $shot ) {
				return $shot;
			}
		}
		return self::image_id( $deal );
	}

	/** Screenshot fürs Tool-Profil endgültig gescheitert? */
	public static function profile_shot_failed( array $deal ) {
		$pid = isset( $deal['post_id'] ) ? (int) $deal['post_id'] : 0;
		return $pid && get_post_meta( $pid, ADM_Shots::META_FAIL, true );
	}

	/** Mediathek-ID des Vorschaubilds (0, wenn keins da ist). */
	public static function image_id( array $deal ) {
		$id = isset( $deal['thumb_id'] ) ? (int) $deal['thumb_id'] : 0;
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * Vorschaubild holen: erst Screenshot der Hersteller-Website, sonst erstes Bild bei AppSumo.
	 *
	 * @return int|string|WP_Error Mediathek-ID, „waiting“ (mShots erzeugt noch) oder Fehler.
	 */
	public static function fetch_thumb( array $deal ) {
		$id    = (int) $deal['id'];
		$site  = self::profile_shot_failed( $deal ) ? '' : ADM_Shots::clean_url( isset( $deal['website'] ) ? $deal['website'] : '' ); // Screenshot fürs Profil schon gescheitert: gleich das AppSumo-Bild
		$tries = (int) $deal['thumb_tries'];

		if ( '' !== $site && $tries < ADM_Shots::MAX_TRIES ) {
			$tries++;
			ADM_DB::update( $id, array( 'thumb_tries' => $tries, 'thumb_last' => ADM_DB::now() ) );
			$res  = wp_remote_get( ADM_Shots::service_url( $site ), array( 'timeout' => 25, 'redirection' => 3 ) );
			$ok   = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
			$typ  = $ok ? strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ) : '';
			$body = $ok ? (string) wp_remote_retrieve_body( $res ) : '';
			if ( $ok && ( false !== strpos( $typ, 'image/jpeg' ) || false !== strpos( $typ, 'image/png' ) ) && strlen( $body ) >= 15000 ) {
				$ext = false !== strpos( $typ, 'png' ) ? 'png' : 'jpg';
				return self::save( $deal, $body, $ext, 'website', array(
					'title'   => 'Screenshot ' . $deal['title'],
					'caption' => 'Screenshot: ' . ADM_Shots::host( $site ),
					'content' => 'Screenshot der Website ' . $site . ' vom ' . wp_date( 'd.m.Y' ) . '.',
					'alt'     => 'Screenshot der Website von ' . $deal['title'],
				) );
			}
			if ( $tries < ADM_Shots::MAX_TRIES ) {
				return 'waiting';
			}
		}

		// Ersatz: erstes Bild des Deals bei AppSumo.
		ADM_DB::update( $id, array( 'thumb_last' => ADM_DB::now() ) );
		$url = ADM_Parser::appsumo_image_url( isset( $deal['image_url'] ) ? $deal['image_url'] : '' );
		if ( '' === $url ) {
			ADM_DB::update( $id, array( 'thumb_src' => 'none' ) );
			return new WP_Error( 'adm_thumb_none', 'Kein Screenshot und kein Bild bei AppSumo.' );
		}
		$res  = wp_remote_get( add_query_arg( array( 'width' => self::APPSUMO_WIDTH, 'quality' => 85 ), $url ), array( 'timeout' => 20, 'redirection' => 2 ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$typ  = $code ? strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ) : '';
		$body = 200 === $code ? (string) wp_remote_retrieve_body( $res ) : '';
		$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
		$ext  = '';
		foreach ( $exts as $mime => $e ) {
			if ( false !== strpos( $typ, $mime ) ) {
				$ext = $e;
			}
		}
		if ( '' === $ext || strlen( $body ) < 2000 ) {
			// Netzfehler: beim nächsten Mal noch einmal; Datei fehlt oder kein Bild: aufgeben.
			if ( in_array( $code, array( 403, 404, 410 ), true ) || ( 200 === $code && '' === $ext ) ) {
				ADM_DB::update( $id, array( 'thumb_src' => 'none' ) );
			}
			return new WP_Error( 'adm_thumb_appsumo', 'Bild bei AppSumo nicht abrufbar (HTTP ' . $code . ').' );
		}
		return self::save( $deal, $body, $ext, 'appsumo', array(
			'title'   => 'Vorschaubild ' . $deal['title'],
			'caption' => 'Bild: AppSumo',
			'content' => 'Erstes Bild des Angebots bei AppSumo (' . $url . '), abgerufen am ' . wp_date( 'd.m.Y' ) . '.',
			'alt'     => $deal['title'] . ' bei AppSumo',
		) );
	}

	/**
	 * Bild in der Mediathek speichern und dem Deal zuordnen.
	 *
	 * @return int|WP_Error Mediathek-ID.
	 */
	private static function save( array $deal, $body, $ext, $src, array $meta ) {
		$name = ( 'website' === $src ? 'screenshot-' : 'vorschau-' ) . sanitize_title( $deal['slug'] ) . '.' . $ext;
		$up   = wp_upload_bits( $name, null, $body );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'adm_thumb_upload', $up['error'] );
		}
		$check = wp_check_filetype_and_ext( $up['file'], $name );
		if ( empty( $check['type'] ) || 0 !== strpos( (string) $check['type'], 'image/' ) ) {
			wp_delete_file( $up['file'] );
			ADM_DB::update( (int) $deal['id'], array( 'thumb_src' => 'none' ) );
			return new WP_Error( 'adm_thumb_type', 'Die Datei ist kein Bild.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'],
				'post_title'     => $meta['title'],
				'post_excerpt'   => $meta['caption'],
				'post_content'   => $meta['content'],
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		if ( is_wp_error( $att ) || ! $att ) {
			return is_wp_error( $att ) ? $att : new WP_Error( 'adm_thumb_insert', 'Bild konnte nicht gespeichert werden.' );
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		update_post_meta( $att, '_wp_attachment_image_alt', $meta['alt'] );
		update_post_meta( $att, '_adm_thumb_deal', (int) $deal['id'] );
		ADM_DB::update( (int) $deal['id'], array( 'thumb_id' => (int) $att, 'thumb_src' => $src, 'thumb_tries' => 0 ) );
		return (int) $att;
	}

	/** Bild verwerfen, damit es neu geholt wird (Datei bleibt in der Mediathek). */
	public static function reset_thumb( $id ) {
		ADM_DB::update( (int) $id, array( 'thumb_id' => 0, 'thumb_src' => '', 'thumb_tries' => 0, 'thumb_last' => null ) );
	}

	/* ---------------- Backend ---------------- */

	public static function action_url( $id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'adm_thumb', 'id' => (int) $id ), admin_url( 'admin-post.php' ) ), 'adm_thumb' );
	}

	/** „Vorschaubild neu holen“ in der Deal-Liste. */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_thumb' );
		$id   = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		$deal = $id ? ADM_DB::get( $id ) : null;
		if ( ! $deal ) {
			$msg = 'Deal nicht gefunden.';
		} else {
			self::reset_thumb( $id );
			$r   = self::fetch_thumb( ADM_DB::get( $id ) );
			$msg = is_int( $r ) ? 'Neues Vorschaubild gespeichert.' : ( 'waiting' === $r ? 'Der Screenshot wird erzeugt und in den nächsten Schritten abgeholt.' : 'Vorschaubild: ' . $r->get_error_message() );
			ADM_Cache::mark_lists();
			ADM_Cache::flush();
		}
		set_transient( 'adm_notice_' . get_current_user_id(), $msg, 60 );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : add_query_arg( array( 'page' => ADM_Admin::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Anzeige in der Deal-Liste: kleines Bild mit Quelle. */
	public static function list_cell( array $item ) {
		$pid  = (int) $item['post_id'];
		$shot = $pid ? ADM_Shots::image_id( $pid ) : 0;
		$img  = $shot ? $shot : self::image_id( $item );
		if ( $img ) {
			$labels = array( 'appsumo' => 'Bild von AppSumo', 'website' => 'Screenshot der Website' );
			$label  = $shot ? 'Screenshot aus dem Tool-Profil' : ( isset( $labels[ $item['thumb_src'] ] ) ? $labels[ $item['thumb_src'] ] : '' );
			return '<br>' . wp_get_attachment_image( $img, array( 120, 75 ), false, array( 'style' => 'margin-top:4px;width:120px;height:auto;border:1px solid #dcdcde;border-radius:3px' ) )
				. ' <span style="color:#646970">' . esc_html( $label ) . '</span>';
		}
		if ( 'none' === (string) $item['thumb_src'] ) {
			return '<br><span style="color:#646970">Kein Vorschaubild möglich</span>';
		}
		return '';
	}
}
