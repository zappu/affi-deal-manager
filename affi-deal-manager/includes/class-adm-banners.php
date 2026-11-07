<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aktionsbanner von AppSumo (Impact Ads), nur nach Freigabe.
 *
 * Alle drei Stunden sucht das Plugin unter den Bannern des Programms nach neuen Aktionsbannern
 * (Black Friday, Cyber Monday, Sale …, nicht produktbezogen) und meldet sie per E-Mail.
 * Nach Freigabe im Backend wird das Bild einmal in die Mediathek kopiert (kein Zählpixel, kein Fremd-Server
 * beim Besucher) und je nach Format auf der Startseite ([adm_promos]) oder unter dem Deal-Kasten gezeigt.
 * Ende: Enddatum laut Impact, sonst 14 Tage nach Freigabe, oder per Klick.
 */
class ADM_Banners {

	const OPTION  = 'adm_impact_banners';
	const EVERY   = 3 * HOUR_IN_SECONDS;
	const DAYS    = 14;
	const PATTERN = '/black[\s_-]*friday|bf\d{2}|cyber[\s_-]*(monday|week)|holiday|christmas|xmas|weihnacht|new[\s_-]*year|anniversary|birthday|prime[\s_-]*day|\bsale\b/i';

	public static function init() {
		add_action( 'adm_tick_done', array( __CLASS__, 'maybe_refresh' ), 20 );
		add_action( 'admin_post_adm_banner', array( __CLASS__, 'handle_action' ) );
	}

	public static function data() {
		$d = get_option( self::OPTION );
		return is_array( $d ) ? $d + array( 'time' => 0, 'items' => array(), 'error' => '' ) : array( 'time' => 0, 'items' => array(), 'error' => '' );
	}

	private static function save( array $d ) {
		update_option( self::OPTION, $d, false );
	}

	public static function maybe_refresh() {
		$d = self::data();
		if ( ADM_Impact::configured() && time() - (int) $d['time'] > self::EVERY ) {
			self::refresh();
		}
	}

	/** Ist das ein Aktionsbanner für den ganzen Shop? */
	public static function is_promo( array $ad ) {
		$text    = ( isset( $ad['Name'] ) ? $ad['Name'] : '' ) . ' ' . ( isset( $ad['Description'] ) ? $ad['Description'] : '' );
		$landing = isset( $ad['LandingPageUrl'] ) ? (string) $ad['LandingPageUrl'] : '';
		return 'BANNER' === strtoupper( isset( $ad['Type'] ) ? (string) $ad['Type'] : '' )
			&& false === strpos( $landing, '/products/' )
			&& (bool) preg_match( self::PATTERN, $text );
	}

	private static function ts( $v ) {
		$t = '' !== (string) $v ? strtotime( (string) $v ) : false;
		return $t ? (int) $t : 0;
	}

	public static function refresh() {
		$d       = self::data();
		$program = (string) ADM_Settings::get( 'program_id' );
		$new     = array();
		$found   = array();
		for ( $page = 1; $page <= 10; $page++ ) {
			$r = ADM_Impact::get( 'Ads', array( 'CampaignId' => $program, 'Type' => 'BANNER', 'PageSize' => '1000', 'Page' => (string) $page ) );
			if ( '' !== $r['error'] ) {
				$d['error'] = $r['error'];
				break;
			}
			$d['error'] = '';
			foreach ( is_array( $r['data'] ) && isset( $r['data']['Ads'] ) ? (array) $r['data']['Ads'] : array() as $ad ) {
				if ( ! is_array( $ad ) || empty( $ad['Id'] ) || ! self::is_promo( $ad ) ) {
					continue;
				}
				$start = self::ts( isset( $ad['StartDate'] ) ? $ad['StartDate'] : '' );
				$end   = self::ts( isset( $ad['EndDate'] ) ? $ad['EndDate'] : '' );
				if ( ( $end && $end < time() ) || ( ! $end && $start && $start < time() - 60 * DAY_IN_SECONDS ) ) {
					continue; // vorbei oder alt
				}
				$id           = (string) $ad['Id'];
				$found[ $id ] = true;
				if ( isset( $d['items'][ $id ] ) ) {
					$d['items'][ $id ]['end'] = $end;
					continue;
				}
				$creative = isset( $ad['CreativeUrl'] ) ? (string) $ad['CreativeUrl'] : '';
				$d['items'][ $id ] = array(
					'id'       => $id,
					'name'     => sanitize_text_field( (string) $ad['Name'] ),
					'w'        => (int) ( isset( $ad['Width'] ) ? $ad['Width'] : 0 ),
					'h'        => (int) ( isset( $ad['Height'] ) ? $ad['Height'] : 0 ),
					'start'    => $start,
					'end'      => $end,
					'landing'  => ADM_Links::is_appsumo( isset( $ad['LandingPageUrl'] ) ? (string) $ad['LandingPageUrl'] : '' ) ? esc_url_raw( $ad['LandingPageUrl'] ) : 'https://appsumo.com/',
					'creative' => '' !== $creative ? esc_url_raw( 0 === strpos( $creative, '//' ) ? 'https:' . $creative : $creative ) : '',
					'status'   => 'neu',
					'att'      => 0,
					'expires'  => 0,
				);
				$new[] = $id;
			}
			$pages = is_array( $r['data'] ) && isset( $r['data']['@numpages'] ) ? (int) $r['data']['@numpages'] : 1;
			if ( $page >= $pages ) {
				break;
			}
		}
		// Abgelaufene freigegebene Banner beenden.
		$changed = false;
		foreach ( $d['items'] as $id => $b ) {
			if ( 'frei' === $b['status'] && $b['expires'] && $b['expires'] < time() ) {
				$d['items'][ $id ]['status'] = 'beendet';
				$changed                     = true;
			}
		}
		$d['time'] = time();
		self::save( $d );
		if ( $new ) {
			self::notify( $new );
		}
		if ( $changed ) {
			ADM_Cache::purge_all();
		}
		return $new;
	}

	private static function notify( array $ids ) {
		$to = ADM_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$d    = self::data();
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$body = 'AppSumo hat bei Impact ' . ( 1 === count( $ids ) ? 'einen neuen Aktionsbanner' : count( $ids ) . ' neue Aktionsbanner' ) . " bereitgestellt:\n\n";
		foreach ( $ids as $id ) {
			$b     = $d['items'][ $id ];
			$body .= '• ' . $b['name'] . ' (' . $b['w'] . ' × ' . $b['h'] . ', ' . self::slot_label( self::slot( $b ) ) . ")\n";
			$body .= '  Vorschau: ' . $b['creative'] . "\n";
			$body .= $b['end'] ? '  Läuft bis: ' . wp_date( 'd.m.Y H:i', $b['end'] ) . "\n" : '';
		}
		$body .= "\nNichts erscheint ohne deine Freigabe. Freigeben oder ablehnen:\n" . admin_url( 'admin.php?page=' . ADM_Stats::SLUG . '#banner' ) . "\n";
		wp_mail( $to, '[' . $site . '] Neue AppSumo-Aktionsbanner zur Freigabe', $body );
	}

	/** Platz nach Format: breite Banner auf die Startseite, kompakte unter den Deal-Kasten. */
	public static function slot( array $b ) {
		if ( $b['w'] >= 700 && $b['h'] > 0 && $b['w'] / $b['h'] >= 2.2 ) {
			return 'home';
		}
		if ( $b['w'] >= 280 && $b['w'] <= 620 && $b['h'] > 0 && $b['w'] / $b['h'] >= 1.2 ) {
			return 'side';
		}
		return '';
	}

	public static function slot_label( $slot ) {
		return 'home' === $slot ? 'Startseite' : ( 'side' === $slot ? 'unter dem Deal-Kasten' : 'Format passt zu keinem Platz' );
	}

	/** Bild einmal in die Mediathek kopieren. @return int|WP_Error */
	private static function import( array $b ) {
		if ( '' === $b['creative'] ) {
			return new WP_Error( 'adm_banner', 'Keine Bildadresse.' );
		}
		$res = wp_remote_get( $b['creative'], array( 'timeout' => 25, 'redirection' => 5, 'reject_unsafe_urls' => true ) );
		$typ = is_wp_error( $res ) ? '' : strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		$ext = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp' );
		$typ = trim( explode( ';', $typ )[0] );
		$body = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! isset( $ext[ $typ ] ) || strlen( $body ) < 500 || strlen( $body ) > 3 * MB_IN_BYTES ) {
			return new WP_Error( 'adm_banner', 'Bild konnte nicht geladen werden.' );
		}
		$up = wp_upload_bits( 'appsumo-banner-' . $b['id'] . '.' . $ext[ $typ ], null, $body );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'adm_banner', $up['error'] );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = wp_insert_attachment( array( 'post_mime_type' => $typ, 'post_title' => 'AppSumo-Banner ' . $b['name'], 'post_status' => 'inherit' ), $up['file'] );
		if ( ! $att || is_wp_error( $att ) ) {
			return new WP_Error( 'adm_banner', 'Bild konnte nicht gespeichert werden.' );
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		update_post_meta( $att, '_wp_attachment_image_alt', 'Aktion bei AppSumo' );
		return (int) $att;
	}

	public static function approve( $id ) {
		$d = self::data();
		if ( ! isset( $d['items'][ $id ] ) ) {
			return new WP_Error( 'adm_banner', 'Banner nicht gefunden.' );
		}
		$b   = $d['items'][ $id ];
		$att = $b['att'] && wp_attachment_is_image( $b['att'] ) ? (int) $b['att'] : self::import( $b );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$d['items'][ $id ]['att']     = $att;
		$d['items'][ $id ]['status']  = 'frei';
		$d['items'][ $id ]['expires'] = $b['end'] ? $b['end'] : time() + self::DAYS * DAY_IN_SECONDS;
		self::save( $d );
		ADM_Cache::purge_all();
		return $att;
	}

	public static function set_status( $id, $status ) {
		$d = self::data();
		if ( isset( $d['items'][ $id ] ) ) {
			$d['items'][ $id ]['status'] = $status;
			self::save( $d );
			ADM_Cache::purge_all();
		}
	}

	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_banner' );
		$id  = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		$do  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$msg = '';
		if ( 'approve' === $do ) {
			$r   = self::approve( $id );
			$msg = is_wp_error( $r ) ? $r->get_error_message() : 'Banner freigegeben.';
		} elseif ( 'end' === $do ) {
			self::set_status( $id, 'beendet' );
			$msg = 'Banner beendet.';
		} elseif ( 'reject' === $do ) {
			self::set_status( $id, 'abgelehnt' );
			$msg = 'Banner abgelehnt.';
		}
		set_transient( 'adm_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . ADM_Stats::SLUG . '#banner' ) );
		exit;
	}

	/** Freigegebener, laufender Banner für einen Platz (der zuletzt freigegebene zuerst). */
	public static function current( $slot ) {
		$best = null;
		foreach ( self::data()['items'] as $b ) {
			if ( 'frei' !== $b['status'] || ( $b['expires'] && $b['expires'] < time() ) || ( $b['start'] && $b['start'] > time() ) || self::slot( $b ) !== $slot || ! $b['att'] ) {
				continue;
			}
			if ( ! $best || (int) $b['id'] > (int) $best['id'] ) {
				$best = $b;
			}
		}
		return $best;
	}

	/** Tracking-Link des Banners mit Ziel und subId1. */
	public static function link( array $b ) {
		$base = sprintf( 'https://%s/c/%s/%s/%s', ADM_Settings::get( 'tracking_host' ), rawurlencode( (string) ADM_Settings::get( 'partner_id' ) ), rawurlencode( $b['id'] ), rawurlencode( (string) ADM_Settings::get( 'program_id' ) ) );
		return $base . '?u=' . rawurlencode( $b['landing'] ) . '&subId1=' . rawurlencode( 'banner-' . $b['id'] );
	}

	public static function html( $slot ) {
		$b = self::current( $slot );
		if ( ! $b ) {
			return '';
		}
		$img = wp_get_attachment_image( (int) $b['att'], 'full', false, array( 'alt' => 'Aktion bei AppSumo', 'loading' => 'home' === $slot ? 'eager' : 'lazy', 'class' => 'adm-banner__img' ) );
		if ( '' === $img ) {
			return '';
		}
		return '<div class="adm-banner adm-banner--' . esc_attr( $slot ) . '"><a href="' . esc_url( self::link( $b ) ) . '" rel="sponsored nofollow noopener" target="_blank">' . $img . '</a><span class="adm-banner__ad">Anzeige<span class="adm-mark" aria-hidden="true">*</span></span></div>';
	}

	/** Liste im Backend (Seite „Einnahmen“). */
	public static function admin_table() {
		$d = self::data();
		echo '<h2 id="banner" style="margin-top:28px">Aktionsbanner von AppSumo</h2>';
		echo '<p>' . esc_html( $d['time'] ? 'Stand ' . wp_date( 'd.m.Y H:i', (int) $d['time'] ) . '. Neue Banner kommen per E-Mail, erscheinen aber erst nach Freigabe. Ende laut Impact, sonst nach ' . self::DAYS . ' Tagen.' : 'Noch nicht abgerufen.' ) . '</p>';
		if ( '' !== $d['error'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( 'Impact meldet: ' . $d['error'] ) . '</p></div>';
		}
		$items = $d['items'];
		uasort( $items, function ( $a, $b ) {
			return (int) $b['id'] <=> (int) $a['id'];
		} );
		echo '<table class="widefat striped"><thead><tr><th style="width:220px">Vorschau</th><th>Banner</th><th>Platz</th><th>Status</th><th></th></tr></thead><tbody>';
		if ( ! $items ) {
			echo '<tr><td colspan="5">Keine Aktionsbanner.</td></tr>';
		}
		$labels = array( 'neu' => 'wartet auf Freigabe', 'frei' => 'freigegeben', 'beendet' => 'beendet', 'abgelehnt' => 'abgelehnt' );
		foreach ( $items as $b ) {
			$url  = function ( $do ) use ( $b ) {
				return wp_nonce_url( admin_url( 'admin-post.php?action=adm_banner&do=' . $do . '&id=' . rawurlencode( $b['id'] ) ), 'adm_banner' );
			};
			$slot = self::slot( $b );
			$prev = $b['att'] ? wp_get_attachment_image( (int) $b['att'], 'medium', false, array( 'style' => 'max-width:200px;height:auto' ) ) : ( '' !== $b['creative'] ? '<img src="' . esc_url( $b['creative'] ) . '" alt="" style="max-width:200px;height:auto" loading="lazy" referrerpolicy="no-referrer">' : '' );
			echo '<tr><td>' . $prev . '</td><td>' . esc_html( $b['name'] ) . '<br><small>' . esc_html( $b['w'] . ' × ' . $b['h'] . ( $b['end'] ? ', läuft bis ' . wp_date( 'd.m.Y', $b['end'] ) : '' ) ) . '</small></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( self::slot_label( $slot ) ) . '</td><td>' . esc_html( $labels[ $b['status'] ] . ( 'frei' === $b['status'] && $b['expires'] ? ' bis ' . wp_date( 'd.m.Y', $b['expires'] ) : '' ) ) . '</td><td>';
			if ( 'frei' === $b['status'] ) {
				echo '<a class="button" href="' . esc_url( $url( 'end' ) ) . '">Beenden</a>';
			} elseif ( '' !== $slot ) {
				echo '<a class="button button-primary" href="' . esc_url( $url( 'approve' ) ) . '">Freigeben</a> ';
				echo 'neu' === $b['status'] ? '<a class="button" href="' . esc_url( $url( 'reject' ) ) . '">Ablehnen</a>' : '';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
