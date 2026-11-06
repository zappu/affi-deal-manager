<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live-Bausteine für Tool-Profile. Preise, Status und Links kommen immer aus der Deal-Tabelle.
 *
 * [adm_deal_box]      Kasten oben: Typ, Preis, Bewertung, Status mit Grund, Kauf-Button
 * [adm_deal_details]  Tabelle mit Preis, Rückgabefrist, Angebotsende, Kategorie
 * [adm_video id=""]   YouTube-Video über youtube-nocookie.com
 * [adm_alternatives]  bis zu drei andere Tool-Profile aus derselben Kategorie, kaufbare zuerst
 *
 * Alle Bausteine nehmen optional deal="<slug>"; ohne Angabe gilt der Deal des aktuellen Profils.
 */
class ADM_Shortcodes {

	private static $css_done = false;

	public static function init() {
		add_shortcode( 'adm_deal_box', array( __CLASS__, 'deal_box' ) );
		add_shortcode( 'adm_deal_details', array( __CLASS__, 'deal_details' ) );
		add_shortcode( 'adm_video', array( __CLASS__, 'video' ) );
		add_shortcode( 'adm_alternatives', array( __CLASS__, 'alternatives' ) );
	}

	/** Deal zum Shortcode: per deal="slug" oder über das aktuelle Profil. */
	private static function deal( $atts ) {
		if ( ! empty( $atts['deal'] ) ) {
			return ADM_DB::get_by_slug( $atts['deal'] );
		}
		$post_id = get_the_ID();
		return $post_id ? ADM_DB::get_by_post( $post_id ) : null;
	}

	private static function subid( array $deal ) {
		$slug = get_post_field( 'post_name', get_the_ID() );
		return $slug ? $slug : $deal['slug'];
	}

	public static function price( array $deal ) {
		if ( null === $deal['price'] || '' === (string) $deal['price'] ) {
			return '';
		}
		if ( 0.0 === (float) $deal['price'] ) {
			return 'kostenlos';
		}
		$p   = (float) $deal['price'];
		$num = number_format_i18n( $p, floor( $p ) === $p ? 0 : 2 );
		return ( 'USD' === $deal['currency'] ? $num . ' $' : $num . ' ' . $deal['currency'] ) . ' einmalig';
	}

	private static function rating( array $deal ) {
		if ( null === $deal['rating'] || '' === (string) $deal['rating'] || ! (int) $deal['review_count'] ) {
			return '';
		}
		return sprintf( '%s von 5 Sternen bei %s Bewertungen auf AppSumo', number_format_i18n( (float) $deal['rating'], 1 ), number_format_i18n( (int) $deal['review_count'] ) );
	}

	private static function checked( array $deal ) {
		return $deal['last_checked'] ? 'Stand: ' . wp_date( 'd.m.Y', strtotime( $deal['last_checked'] . ' UTC' ) ) : '';
	}

	public static function deal_box( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '' ), $atts, 'adm_deal_box' );
		$deal = self::deal( $atts );
		if ( ! $deal ) {
			return '';
		}
		$sub   = self::subid( $deal );
		$link  = ADM_Links::url( $deal['url'], $sub );
		$mark  = '<span class="adm-mark" aria-hidden="true">*</span>';
		$lines = array_filter( array( self::price( $deal ), self::rating( $deal ) ) );

		$out  = self::css();
		$out .= '<div class="adm-box adm-box--' . esc_attr( $deal['status'] ) . '">';
		$out .= '<div class="adm-box__head"><span class="adm-box__type">' . esc_html( ADM_DB::type_label( $deal['deal_type'] ) ) . '</span>';
		$out .= '<span class="adm-box__status">' . esc_html( 'kaufbar' === $deal['status'] ? 'Erhältlich' : ( 'ausgelaufen' === $deal['status'] ? 'Ausgelaufen' : 'Nicht verfügbar' ) ) . '</span></div>';
		$out .= '<p class="adm-box__name">' . esc_html( $deal['name'] ) . '</p>';
		foreach ( $lines as $l ) {
			$out .= '<p class="adm-box__line">' . esc_html( $l ) . '</p>';
		}
		$out .= '<p class="adm-box__reason">' . esc_html( ADM_DB::reason( $deal ) ) . '</p>';
		if ( 'kaufbar' === $deal['status'] ) {
			$out .= '<p><a class="adm-btn" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zum Angebot bei AppSumo' . $mark . '</a></p>';
		} else {
			$out .= '<p><a class="adm-btn" href="#alternativen">Kaufbare Alternativen ansehen</a></p>';
			$out .= '<p class="adm-box__small"><a href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zur Warteliste bei AppSumo' . $mark . '</a></p>';
		}
		$out .= '<p class="adm-box__small">' . esc_html( trim( self::checked( $deal ) . '. ' . self::NOTE ) ) . '</p>';
		$out .= '</div>';
		return $out;
	}

	const NOTE = '* Werbelink: Kaufst du über diesen Link, erhalten wir eine Provision. Für dich ändert sich der Preis nicht.';

	public static function deal_details( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '' ), $atts, 'adm_deal_details' );
		$deal = self::deal( $atts );
		if ( ! $deal ) {
			return '';
		}
		$rows = array(
			'Preis'          => self::price( $deal ),
			'Typ'            => ADM_DB::type_label( $deal['deal_type'] ),
			'Status'         => ADM_DB::reason( $deal ),
			'Angebot endet'  => $deal['available_until'] ? wp_date( 'd.m.Y', strtotime( $deal['available_until'] . ' UTC' ) ) : '',
			'Rückgabefrist'  => $deal['return_days'] ? (int) $deal['return_days'] . ' Tage bei AppSumo' : '',
			'Bewertung'      => self::rating( $deal ),
			'Kategorie bei AppSumo' => $deal['category'],
		);
		$out = self::css() . '<table class="adm-details"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				$out .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
			}
		}
		$out .= '</tbody></table><p class="adm-box__small">' . esc_html( self::checked( $deal ) ) . '. Preise in US-Dollar laut AppSumo, je nach Land zuzüglich Steuern.</p>';
		return $out;
	}

	public static function video( $atts ) {
		$atts = shortcode_atts( array( 'id' => '', 'title' => 'Video' ), $atts, 'adm_video' );
		$id   = (string) $atts['id'];
		if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
			return '';
		}
		return self::css() . sprintf(
			'<div class="adm-video"><iframe src="https://www.youtube-nocookie.com/embed/%s" title="%s" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>',
			esc_attr( $id ),
			esc_attr( $atts['title'] )
		);
	}

	public static function alternatives( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '', 'limit' => 3 ), $atts, 'adm_alternatives' );
		$deal = self::deal( $atts );
		if ( ! $deal ) {
			return '';
		}
		$list = self::find_alternatives( $deal, max( 1, min( 6, (int) $atts['limit'] ) ) );
		if ( ! $list ) {
			return '<p>Zu dieser Kategorie gibt es noch keine weiteren Profile.</p>';
		}
		$out = self::css() . '<ul class="adm-alts">';
		foreach ( $list as $alt ) {
			$bits = array_filter( array( self::price( $alt ), 'kaufbar' === $alt['status'] ? 'erhältlich' : 'ausgelaufen' ) );
			$out .= '<li><a href="' . esc_url( get_permalink( (int) $alt['post_id'] ) ) . '">' . esc_html( $alt['name'] ) . '</a> <span class="adm-alts__meta">' . esc_html( implode( ', ', $bits ) ) . '</span>';
			if ( '' !== $alt['description'] ) {
				$out .= '<br><span class="adm-alts__desc">' . esc_html( wp_trim_words( $alt['description'], 18 ) ) . '</span>';
			}
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Veröffentlichte Profile aus derselben Kategorie (erst gleiche Unterkategorie, dann gleicher Bereich), kaufbare zuerst.
	 */
	public static function find_alternatives( array $deal, $limit ) {
		global $wpdb;
		$t     = ADM_DB::table();
		$parts = array_map( 'trim', explode( '>', (string) $deal['category'] ) );
		$out   = array();
		$seen  = array( (int) $deal['id'] => true );
		$tries = array();
		if ( count( $parts ) >= 2 ) {
			$tries[] = $parts[0] . ' > ' . $parts[1];
		}
		if ( '' !== $parts[0] ) {
			$tries[] = $parts[0];
		}
		foreach ( $tries as $prefix ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT d.* FROM {$t} d INNER JOIN {$wpdb->posts} p ON p.ID = d.post_id AND p.post_status = 'publish' AND p.post_type = %s
				 WHERE d.category LIKE %s AND d.id <> %d AND d.status IN ('kaufbar','ausgelaufen')
				 ORDER BY (d.status = 'kaufbar') DESC, d.review_count DESC LIMIT %d",
				ADM_Profiles::CPT,
				$wpdb->esc_like( $prefix ) . '%',
				(int) $deal['id'],
				(int) $limit + 3
			), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				if ( count( $out ) >= $limit ) {
					break 2;
				}
				if ( isset( $seen[ (int) $r['id'] ] ) ) {
					continue;
				}
				$seen[ (int) $r['id'] ] = true;
				$out[]                  = ADM_DB::decode( $r );
			}
		}
		return $out;
	}

	/** Grundgestaltung einmal pro Seite; über das Theme frei überschreibbar. */
	private static function css() {
		if ( self::$css_done ) {
			return '';
		}
		self::$css_done = true;
		return '<style id="adm-css">'
			. '.adm-box{border:1px solid #d9e2dc;border-radius:10px;padding:18px 20px;margin:0 0 28px;background:#f6faf7}'
			. '.adm-box--ausgelaufen,.adm-box--ignoriert{background:#faf7f6;border-color:#e6d9d6}'
			. '.adm-box__head{display:flex;justify-content:space-between;gap:12px;font-size:.85em;text-transform:uppercase;letter-spacing:.04em;color:#5b6b60}'
			. '.adm-box--kaufbar .adm-box__status{color:#1a7f37;font-weight:600}.adm-box--ausgelaufen .adm-box__status{color:#b32d2e;font-weight:600}'
			. '.adm-box__name{font-size:1.3em;font-weight:700;margin:6px 0}.adm-box p{margin:4px 0}'
			. '.adm-box__reason{color:#3c4a40}.adm-box__small{font-size:.82em;color:#6b766e}'
			. '.adm-btn{display:inline-block;margin:10px 0 6px;padding:10px 18px;border-radius:6px;background:#1a7f37;color:#fff!important;text-decoration:none;font-weight:600}'
			. '.adm-box--ausgelaufen .adm-btn{background:#3c4a40}'
			. '.adm-details{width:100%;border-collapse:collapse;margin:8px 0}.adm-details th,.adm-details td{text-align:left;padding:8px 10px;border-bottom:1px solid #e3e8e4;vertical-align:top}.adm-details th{width:38%;font-weight:600}'
			. '.adm-video{position:relative;aspect-ratio:16/9;margin:10px 0 12px}.adm-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0;border-radius:8px}'
			. '.adm-source{font-size:.88em;color:#6b766e}.adm-alts{padding-left:1.2em}.adm-alts li{margin:0 0 10px}.adm-alts__meta{color:#6b766e;font-size:.9em}.adm-alts__desc{font-size:.92em}'
			. '</style>';
	}
}
