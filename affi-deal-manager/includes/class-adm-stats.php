<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Einnahmen je Seite: Klicks, Verkäufe und Provision je subId1 aus dem Impact-Bericht
 * „Performance by Sub ID“ (partner_performance_by_subid), einmal täglich für 7, 30 und 90 Tage.
 *
 * Jeder Werbelink der Seite trägt eine subId1: den Slug des Profils, „beste-<deal>“ (Bestenlisten),
 * „liste-<deal>“ (Deal-Karten), „footer-plus“ (Fußzeile) oder eine eigene aus [adm_link].
 */
class ADM_Stats {

	const OPTION = 'adm_impact_stats';
	const SLUG   = 'affi-deal-manager-stats';
	const RANGES = array( 7, 30, 90 );

	public static function init() {
		add_action( 'adm_tick_done', array( __CLASS__, 'maybe_refresh' ) );
		add_action( 'admin_post_adm_stats_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	public static function data() {
		$d = get_option( self::OPTION );
		return is_array( $d ) ? $d : array();
	}

	/** Höchstens alle 20 Stunden, nur mit Zugang zur Impact-API. */
	public static function maybe_refresh() {
		$d = self::data();
		if ( ADM_Impact::configured() && ( empty( $d['time'] ) || time() - (int) $d['time'] > 20 * HOUR_IN_SECONDS ) ) {
			self::refresh();
		}
	}

	/** Name des Programms (Spalte Campaign im Bericht), Standard AppSumo. */
	private static function campaign_name() {
		$r = ADM_Impact::get( 'Campaigns/' . rawurlencode( (string) ADM_Settings::get( 'program_id' ) ) );
		return is_array( $r['data'] ) && ! empty( $r['data']['CampaignName'] ) ? (string) $r['data']['CampaignName'] : 'AppSumo';
	}

	/**
	 * Bericht für einen Zeitraum (bis gestern einschließlich heute), Zeilen je subId1.
	 *
	 * @return array|WP_Error subid => clicks, raw_clicks, actions, sales, earnings
	 */
	public static function fetch( $days, $campaign ) {
		$args = array(
			'START_DATE' => wp_date( 'Y-m-d', time() - ( (int) $days - 1 ) * DAY_IN_SECONDS ),
			'END_DATE'   => wp_date( 'Y-m-d' ),
			'Program'    => (string) ADM_Settings::get( 'program_id' ),
		);
		$rows = array();
		for ( $page = 1; $page <= 10; $page++ ) {
			$r = ADM_Impact::get( 'Reports/partner_performance_by_subid', $args + array( 'Page' => (string) $page ) );
			if ( '' !== $r['error'] || ! is_array( $r['data'] ) ) {
				return new WP_Error( 'adm_stats', '' !== $r['error'] ? $r['error'] : 'Keine Daten' );
			}
			foreach ( isset( $r['data']['Records'] ) ? (array) $r['data']['Records'] : array() as $rec ) {
				if ( ! is_array( $rec ) || ( isset( $rec['Campaign'] ) && '' !== (string) $rec['Campaign'] && (string) $rec['Campaign'] !== $campaign ) ) {
					continue;
				}
				$id = isset( $rec['pubsubid1_'] ) ? (string) $rec['pubsubid1_'] : '';
				if ( ! isset( $rows[ $id ] ) ) {
					$rows[ $id ] = array( 'clicks' => 0, 'raw_clicks' => 0, 'actions' => 0, 'sales' => 0.0, 'earnings' => 0.0 );
				}
				$rows[ $id ]['clicks']     += (int) ( isset( $rec['Clicks'] ) ? $rec['Clicks'] : 0 );
				$rows[ $id ]['raw_clicks'] += (int) ( isset( $rec['raw_clicks'] ) ? $rec['raw_clicks'] : 0 );
				$rows[ $id ]['actions']    += (int) ( isset( $rec['Actions'] ) ? $rec['Actions'] : 0 );
				$rows[ $id ]['sales']      += (float) ( isset( $rec['sale_amount'] ) ? $rec['sale_amount'] : 0 );
				$rows[ $id ]['earnings']   += (float) ( isset( $rec['Earnings'] ) ? $rec['Earnings'] : 0 );
			}
			$pages = isset( $r['data']['@numpages'] ) ? (int) $r['data']['@numpages'] : 1;
			if ( $page >= $pages ) {
				break;
			}
		}
		return $rows;
	}

	public static function refresh() {
		$campaign = self::campaign_name();
		$out      = array( 'time' => time(), 'campaign' => $campaign, 'ranges' => array(), 'error' => '' );
		foreach ( self::RANGES as $days ) {
			$rows = self::fetch( $days, $campaign );
			if ( is_wp_error( $rows ) ) {
				$out['error'] = $rows->get_error_message();
				break;
			}
			$out['ranges'][ $days ] = $rows;
		}
		update_option( self::OPTION, $out, false );
		return $out;
	}

	/** Lesbarer Name zu einer subId1 und Link zur Seite. @return array{0:string,1:string} */
	public static function label( $subid ) {
		$subid = (string) $subid;
		if ( '' === $subid ) {
			return array( 'Ohne Sub-ID (andere Websites oder Links ohne Kennung)', '' );
		}
		$fixed = (array) apply_filters( 'adm_stats_labels', array( 'footer-plus' => 'Fußzeile: AppSumo Plus', 'aktion' => 'Aktionshinweis', 'beispiel' => 'Beispiel-Link (Einstellungen)' ) );
		if ( isset( $fixed[ $subid ] ) ) {
			return array( $fixed[ $subid ], '' );
		}
		foreach ( array( 'beste-' => 'Bestenliste', 'liste-' => 'Deal-Karte', 'aktion-' => 'Aktionshinweis' ) as $prefix => $what ) {
			if ( 0 === strpos( $subid, $prefix ) ) {
				$deal = ADM_DB::get_by_slug( substr( $subid, strlen( $prefix ) ) );
				return array( $what . ': ' . ( $deal ? $deal['title'] : substr( $subid, strlen( $prefix ) ) ), $deal && $deal['post_id'] ? (string) get_permalink( (int) $deal['post_id'] ) : '' );
			}
		}
		$post = get_page_by_path( $subid, OBJECT, ADM_Profiles::CPT );
		if ( $post ) {
			return array( 'Profil: ' . get_the_title( $post ), (string) get_permalink( $post ) );
		}
		$deal = ADM_DB::get_by_slug( $subid );
		if ( $deal ) {
			return array( 'Deal: ' . $deal['title'], $deal['post_id'] ? (string) get_permalink( (int) $deal['post_id'] ) : '' );
		}
		return array( $subid, '' );
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_stats_refresh' );
		if ( ADM_Impact::configured() ) {
			self::refresh();
			ADM_Promos::refresh();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . ( isset( $_GET['days'] ) ? '&days=' . (int) $_GET['days'] : '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		exit;
	}

	private static function money( $v ) {
		return number_format_i18n( (float) $v, 2 ) . ' $';
	}

	public static function page() {
		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification
		$days = in_array( $days, self::RANGES, true ) ? $days : 30;
		$d    = self::data();
		$rows = isset( $d['ranges'][ $days ] ) ? (array) $d['ranges'][ $days ] : array();
		uasort( $rows, function ( $a, $b ) {
			return array( $b['earnings'], $b['actions'], $b['clicks'] ) <=> array( $a['earnings'], $a['actions'], $a['clicks'] );
		} );
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<div class="wrap"><h1>Thoka Affi Deal Manager: Einnahmen</h1>';
		if ( ! ADM_Impact::configured() ) {
			echo '<div class="notice notice-warning inline"><p>Für diese Übersicht braucht das Plugin den Zugang zur Impact-API (' . esc_html( ADM_Impact::SID_CONSTANT . ', ' . ADM_Impact::TOKEN_CONSTANT ) . ' in der wp-config.php).</p></div></div>';
			return;
		}
		echo '<p>';
		foreach ( self::RANGES as $r ) {
			echo $r === $days ? '<strong>' . (int) $r . ' Tage</strong> · ' : '<a href="' . esc_url( add_query_arg( 'days', $r, $base ) ) . '">' . (int) $r . ' Tage</a> · ';
		}
		echo esc_html( ! empty( $d['time'] ) ? 'Stand ' . wp_date( 'd.m.Y H:i', (int) $d['time'] ) . ', Programm ' . $d['campaign'] : 'Noch nicht abgerufen' );
		echo ' · <a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adm_stats_refresh&days=' . $days ), 'adm_stats_refresh' ) ) . '">Jetzt aktualisieren</a></p>';
		if ( ! empty( $d['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>Impact meldet: ' . esc_html( $d['error'] ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr><th>Seite (subId1)</th><th style="text-align:right">Klicks</th><th style="text-align:right">Verkäufe</th><th style="text-align:right">Conversion</th><th style="text-align:right">Umsatz</th><th style="text-align:right">Provision</th><th style="text-align:right">je Klick</th></tr></thead><tbody>';
		$sum = array( 'clicks' => 0, 'actions' => 0, 'sales' => 0.0, 'earnings' => 0.0 );
		if ( ! $rows ) {
			echo '<tr><td colspan="7">Keine Klicks in diesem Zeitraum.</td></tr>';
		}
		foreach ( $rows as $id => $row ) {
			list( $label, $url ) = self::label( $id );
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] += $row[ $k ];
			}
			echo '<tr><td>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>' : esc_html( $label ) ) . ( '' !== (string) $id ? ' <code style="font-size:11px">' . esc_html( $id ) . '</code>' : '' ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( number_format_i18n( $row['clicks'] ) ) . '</td><td style="text-align:right">' . esc_html( number_format_i18n( $row['actions'] ) ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( $row['clicks'] ? number_format_i18n( 100 * $row['actions'] / $row['clicks'], 1 ) . ' %' : '–' ) . '</td>';
			echo '<td style="text-align:right">' . esc_html( self::money( $row['sales'] ) ) . '</td><td style="text-align:right"><strong>' . esc_html( self::money( $row['earnings'] ) ) . '</strong></td>';
			echo '<td style="text-align:right">' . esc_html( $row['clicks'] ? self::money( $row['earnings'] / $row['clicks'] ) : '–' ) . '</td></tr>';
		}
		echo '</tbody>';
		if ( $rows ) {
			echo '<tfoot><tr><th>Summe</th><th style="text-align:right">' . esc_html( number_format_i18n( $sum['clicks'] ) ) . '</th><th style="text-align:right">' . esc_html( number_format_i18n( $sum['actions'] ) ) . '</th><th style="text-align:right">' . esc_html( $sum['clicks'] ? number_format_i18n( 100 * $sum['actions'] / $sum['clicks'], 1 ) . ' %' : '–' ) . '</th><th style="text-align:right">' . esc_html( self::money( $sum['sales'] ) ) . '</th><th style="text-align:right">' . esc_html( self::money( $sum['earnings'] ) ) . '</th><th style="text-align:right">' . esc_html( $sum['clicks'] ? self::money( $sum['earnings'] / $sum['clicks'] ) : '–' ) . '</th></tr></tfoot>';
		}
		echo '</table><p class="description">Quelle: Impact, Bericht „Performance by Sub ID“, nur Programm ' . esc_html( isset( $d['campaign'] ) ? $d['campaign'] : 'AppSumo' ) . '. Klicks sind eindeutige Klicks. Verkäufe und Provision erscheinen bei Impact oft erst mit einigen Tagen Verzug. Aktualisierung einmal täglich.</p>';
		ADM_Promos::admin_table();
		echo '</div>';
	}
}
