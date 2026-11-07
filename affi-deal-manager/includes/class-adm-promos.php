<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aktionen und Gutscheincodes von AppSumo aus der Impact-API (Deals, PromoCodes, Promotions).
 *
 * Alle drei Stunden abgerufen. Neue Aktionen meldet eine E-Mail an die Adresse „E-Mail bei Störungen“.
 * Laufende Aktionen erscheinen im Deal-Kasten (passend zum Produkt oder für alle Produkte) und über
 * [adm_promos] (Startseite). Texte von Impact sind Daten und werden nur maskiert ausgegeben.
 */
class ADM_Promos {

	const OPTION = 'adm_impact_promos';
	const SEEN   = 'adm_impact_promos_seen';
	const EVERY  = 3 * HOUR_IN_SECONDS;

	public static function init() {
		add_action( 'adm_tick_done', array( __CLASS__, 'maybe_refresh' ) );
		add_shortcode( 'adm_promos', array( __CLASS__, 'shortcode' ) );
	}

	public static function data() {
		$d = get_option( self::OPTION );
		return is_array( $d ) ? $d : array( 'time' => 0, 'items' => array(), 'errors' => array() );
	}

	public static function maybe_refresh() {
		$d = self::data();
		if ( ADM_Impact::configured() && time() - (int) $d['time'] > self::EVERY ) {
			self::refresh();
		}
	}

	/** Erster vorhandener Wert aus mehreren möglichen Feldnamen. */
	private static function pick( array $row, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) && '' !== trim( (string) $row[ $k ] ) ) {
				return trim( (string) $row[ $k ] );
			}
		}
		return '';
	}

	private static function time( $v ) {
		$t = '' !== (string) $v ? strtotime( (string) $v ) : false;
		return $t ? (int) $t : 0;
	}

	/** Produkt-Adressen oder Slugs, auf die eine Aktion beschränkt ist (leer = ganzer Shop). */
	private static function products( array $row ) {
		$out = array();
		foreach ( array( 'Products', 'ProductUrls', 'LandingPages', 'Skus' ) as $k ) {
			if ( empty( $row[ $k ] ) ) {
				continue;
			}
			$list = is_array( $row[ $k ] ) ? $row[ $k ] : explode( ',', (string) $row[ $k ] );
			array_walk_recursive( $list, function ( $v ) use ( &$out ) {
				if ( is_scalar( $v ) && preg_match( '#/products/([a-z0-9\-]+)#i', (string) $v, $m ) ) {
					$out[] = strtolower( $m[1] );
				}
			} );
		}
		return array_values( array_unique( $out ) );
	}

	/** Einheitliche Form für Deals, Codes und Promotions. */
	public static function normalize( $type, array $row ) {
		$id    = self::pick( $row, array( 'Id', 'DealId', 'PromoCodeId', 'PromotionId' ) );
		$code  = self::pick( $row, array( 'Code', 'PromoCode', 'DefaultPromoCode', 'GenericRedemptionCode' ) );
		$name  = self::pick( $row, array( 'Name', 'Title', 'DealName', 'PromotionTitle' ) );
		$desc  = self::pick( $row, array( 'Description', 'DealDescription' ) );
		$state = strtoupper( self::pick( $row, array( 'State', 'Status', 'DealState' ) ) );
		$disc  = '';
		$pct   = self::pick( $row, array( 'DiscountPercent', 'DiscountPercentage', 'DiscountPercentRangeStart' ) );
		$amt   = self::pick( $row, array( 'DiscountAmount', 'DiscountMaximum' ) );
		if ( '' !== $pct && (float) $pct > 0 ) {
			$disc = rtrim( rtrim( number_format( (float) $pct, 2, '.', '' ), '0' ), '.' ) . ' % Rabatt';
		} elseif ( '' !== $amt && (float) $amt > 0 ) {
			$disc = number_format_i18n( (float) $amt, 2 ) . ' ' . self::pick( $row, array( 'DiscountCurrency', 'Currency' ) ) . ' Rabatt';
		}
		$url = self::pick( $row, array( 'LandingPage', 'LandingPageUrl', 'Url', 'TrackingLink' ) );
		return array(
			'key'      => $type . ':' . ( '' !== $id ? $id : md5( $name . $code ) ),
			'type'     => $type,
			'name'     => $name,
			'desc'     => function_exists( 'mb_substr' ) ? mb_substr( $desc, 0, 400 ) : substr( $desc, 0, 400 ),
			'code'     => $code,
			'state'    => $state,
			'start'    => self::time( self::pick( $row, array( 'StartDate', 'StartTime', 'EffectiveDate', 'ActiveDate' ) ) ),
			'end'      => self::time( self::pick( $row, array( 'EndDate', 'EndTime', 'ExpirationDate', 'ExpireDate' ) ) ),
			'discount' => $disc,
			'url'      => ADM_Links::is_appsumo( $url ) ? esc_url_raw( $url ) : '',
			'products' => self::products( $row ),
		);
	}

	public static function refresh() {
		$program = (string) ADM_Settings::get( 'program_id' );
		$items   = array();
		$errors  = array();
		// Deals gibt es für Partner nur unter Campaigns/<ID>/Deals, Codes und Promotions mit Filter CampaignId.
		foreach ( array(
			'deal'      => array( 'Campaigns/' . rawurlencode( $program ) . '/Deals', 'Deals', array( 'PageSize' => '100' ) ),
			'code'      => array( 'PromoCodes', 'PromoCodes', array( 'CampaignId' => $program, 'PageSize' => '100' ) ),
			'promotion' => array( 'Promotions', 'Promotions', array( 'CampaignId' => $program, 'PageSize' => '100' ) ),
		) as $type => $cfg ) {
			$r = ADM_Impact::get( $cfg[0], $cfg[2] );
			if ( '' !== $r['error'] ) {
				$errors[ $type ] = $r['error'];
				continue;
			}
			foreach ( is_array( $r['data'] ) && isset( $r['data'][ $cfg[1] ] ) ? (array) $r['data'][ $cfg[1] ] : array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( isset( $row['CampaignId'] ) && '' !== (string) $row['CampaignId'] && (string) $row['CampaignId'] !== $program ) {
					continue;
				}
				$p = self::normalize( $type, $row );
				if ( in_array( $p['state'], array( 'EXPIRED', 'INACTIVE', 'DEACTIVATED', 'DELETED' ), true ) || ( $p['end'] && $p['end'] < time() ) ) {
					continue;
				}
				$items[ $p['key'] ] = $p;
			}
		}
		$before = self::active_keys( self::data()['items'] );
		update_option( self::OPTION, array( 'time' => time(), 'items' => $items, 'errors' => $errors ), false );
		self::notify_new( $items );
		if ( self::active_keys( $items ) !== $before ) {
			ADM_Cache::purge_all(); // Hinweise stehen in jedem Deal-Kasten
		}
		return $items;
	}

	private static function active_keys( array $items ) {
		$keys = array_keys( self::active( $items ) );
		sort( $keys );
		return $keys;
	}

	/** Laufende Aktionen (Start erreicht, Ende nicht überschritten). */
	public static function active( $items = null ) {
		$items = null === $items ? self::data()['items'] : $items;
		$now   = time();
		return array_filter( (array) $items, function ( $p ) use ( $now ) {
			return ( ! $p['start'] || $p['start'] <= $now ) && ( ! $p['end'] || $p['end'] >= $now ) && '' !== ( $p['name'] . $p['code'] );
		} );
	}

	/** Aktionen, die für einen Deal gelten: für den ganzen Shop oder genau dieses Produkt. */
	public static function for_deal( array $deal ) {
		$slug = preg_match( '#/products/([a-z0-9\-]+)#i', (string) $deal['url'], $m ) ? strtolower( $m[1] ) : '';
		return array_filter( self::active(), function ( $p ) use ( $slug ) {
			return ! $p['products'] || ( '' !== $slug && in_array( $slug, $p['products'], true ) );
		} );
	}

	/** E-Mail für Aktionen, die noch nicht gemeldet wurden. */
	public static function notify_new( array $items ) {
		$seen = (array) get_option( self::SEEN, array() );
		$new  = array_diff_key( $items, array_flip( $seen ) );
		if ( ! $new ) {
			return 0;
		}
		update_option( self::SEEN, array_slice( array_values( array_unique( array_merge( $seen, array_keys( $new ) ) ) ), -500 ), false );
		$to = ADM_Settings::get( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return 0;
		}
		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		$names = array( 'deal' => 'Aktion', 'code' => 'Gutscheincode', 'promotion' => 'Promotion' );
		$body  = 'Bei AppSumo (Impact) gibt es ' . ( 1 === count( $new ) ? 'eine neue Aktion' : count( $new ) . ' neue Aktionen' ) . ":\n\n";
		foreach ( $new as $p ) {
			$body .= '• ' . $names[ $p['type'] ] . ': ' . ( '' !== $p['name'] ? $p['name'] : $p['code'] ) . "\n";
			$body .= '  Zeitraum: ' . ( $p['start'] ? wp_date( 'd.m.Y H:i', $p['start'] ) : 'sofort' ) . ' bis ' . ( $p['end'] ? wp_date( 'd.m.Y H:i', $p['end'] ) : 'offen' ) . "\n";
			$body .= '' !== $p['code'] ? '  Code: ' . $p['code'] . "\n" : '';
			$body .= '' !== $p['discount'] ? '  Rabatt: ' . $p['discount'] . "\n" : '';
			$body .= $p['products'] ? '  Nur für: ' . implode( ', ', $p['products'] ) . "\n" : '  Gilt für: alle Produkte' . "\n";
			$body .= '' !== $p['desc'] ? '  ' . $p['desc'] . "\n" : '';
			$body .= "\n";
		}
		$body .= "Laufende Aktionen erscheinen automatisch im Deal-Kasten der Profile und auf der Startseite und verschwinden nach dem Ende wieder.\n\n";
		$body .= 'Übersicht: ' . admin_url( 'admin.php?page=' . ADM_Stats::SLUG ) . "\n";
		wp_mail( $to, '[' . $site . '] Neue AppSumo-Aktion: ' . ( '' !== reset( $new )['name'] ? reset( $new )['name'] : reset( $new )['code'] ), $body );
		return count( $new );
	}

	/** Eine Zeile Text für Kasten und Startseite. */
	public static function line( array $p ) {
		$parts = array( '' !== $p['name'] ? $p['name'] : 'Aktion bei AppSumo' );
		if ( '' !== $p['discount'] ) {
			$parts[] = $p['discount'];
		}
		if ( $p['end'] ) {
			$parts[] = 'bis ' . wp_date( 'd.m.Y', $p['end'] );
		}
		$out = esc_html( implode( ', ', $parts ) );
		if ( '' !== $p['code'] ) {
			$out .= ' · Code <code class="adm-promo__code">' . esc_html( $p['code'] ) . '</code>';
		}
		return $out;
	}

	/** Hinweis im Deal-Kasten. */
	public static function box_html( array $deal ) {
		if ( 'kaufbar' !== $deal['status'] ) {
			return '';
		}
		$list = self::for_deal( $deal );
		if ( ! $list ) {
			return '';
		}
		$out = '';
		foreach ( array_slice( $list, 0, 2 ) as $p ) {
			$out .= '<p class="adm-promo"><span class="adm-promo__label">Aktion</span> ' . self::line( $p ) . '</p>';
		}
		return $out;
	}

	/** [adm_promos]: laufende Aktionen für den ganzen Shop als Leiste, sonst nichts. */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'all' => '' ), $atts, 'adm_promos' );
		$list = array_filter( self::active(), function ( $p ) use ( $atts ) {
			return '' !== $atts['all'] || ! $p['products'];
		} );
		if ( ! $list ) {
			return '';
		}
		wp_enqueue_style( 'adm-frontend' );
		$out = '';
		foreach ( array_slice( $list, 0, 3 ) as $p ) {
			$link = ADM_Links::url( '' !== $p['url'] ? $p['url'] : 'https://appsumo.com/', 'aktion' );
			$out .= '<div class="adm-promo adm-promo--bar"><span class="adm-promo__label">Aktion bei AppSumo</span> <span>' . self::line( $p ) . '</span>';
			$out .= '' !== $link ? ' <a class="adm-btn adm-btn--small" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zur Aktion<span class="adm-mark" aria-label="Werbelink">*</span></a>' : '';
			$out .= '</div>';
		}
		return '<div class="adm adm-promos">' . $out . '</div>';
	}

	/** Liste im Backend (Seite „Einnahmen“). */
	public static function admin_table() {
		$d = self::data();
		echo '<h2 style="margin-top:28px">Aktionen von AppSumo</h2>';
		echo '<p>' . esc_html( $d['time'] ? 'Stand ' . wp_date( 'd.m.Y H:i', (int) $d['time'] ) . ', Abruf alle drei Stunden. Neue Aktionen gehen per E-Mail an ' . ADM_Settings::get( 'notify_email' ) . '.' : 'Noch nicht abgerufen.' ) . '</p>';
		foreach ( (array) $d['errors'] as $type => $err ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( ucfirst( $type ) . ': ' . $err . ( false !== strpos( $err, '403' ) ? ' (dem Token fehlt der passende Scope)' : '' ) ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr><th>Art</th><th>Aktion</th><th>Code</th><th>Zeitraum</th><th>Gilt für</th><th>Auf der Seite</th></tr></thead><tbody>';
		if ( ! $d['items'] ) {
			echo '<tr><td colspan="6">Keine laufenden oder geplanten Aktionen.</td></tr>';
		}
		$active = self::active( $d['items'] );
		foreach ( $d['items'] as $key => $p ) {
			echo '<tr><td>' . esc_html( array( 'deal' => 'Aktion', 'code' => 'Code', 'promotion' => 'Promotion' )[ $p['type'] ] ) . '</td><td>' . esc_html( $p['name'] ) . ( '' !== $p['desc'] ? '<br><small>' . esc_html( $p['desc'] ) . '</small>' : '' ) . '</td><td>' . esc_html( $p['code'] ) . '</td>';
			echo '<td>' . esc_html( ( $p['start'] ? wp_date( 'd.m.Y H:i', $p['start'] ) : 'sofort' ) . ' bis ' . ( $p['end'] ? wp_date( 'd.m.Y H:i', $p['end'] ) : 'offen' ) ) . '</td><td>' . esc_html( $p['products'] ? implode( ', ', $p['products'] ) : 'alle Produkte' ) . '</td><td>' . ( isset( $active[ $key ] ) ? 'ja' : 'ab Start' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
