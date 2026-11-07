<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Impact-Deep-Links für AppSumo:
 * https://<tracking_host>/c/<partner_id>/<ad_id>/<program_id>?u=<AppSumo-URL>&subId1=<Seite>
 */
class ADM_Links {

	public static function init() {
		add_shortcode( 'adm_link', array( __CLASS__, 'shortcode' ) );
		// Werbelinks, die nicht aus dem Plugin kommen (Elementor-Widgets, Editor), bekommen rel="sponsored nofollow".
		add_filter( 'the_content', array( __CLASS__, 'mark_links' ), 999 );
		add_filter( 'elementor/frontend/the_content', array( __CLASS__, 'mark_links' ), 999 );
		add_filter( 'widget_text', array( __CLASS__, 'mark_links' ), 999 );
	}

	/** Hosts, deren Links als Werbelinks gelten: Impact-Tracking-Host und AppSumo. */
	public static function ad_hosts() {
		$hosts = array( 'appsumo.com', 'www.appsumo.com' );
		$track = strtolower( trim( (string) ADM_Settings::get( 'tracking_host' ) ) );
		if ( '' !== $track ) {
			$hosts[] = $track;
		}
		return (array) apply_filters( 'adm_ad_hosts', $hosts );
	}

	/**
	 * Ergänzt rel="sponsored nofollow" an Links zu AppSumo oder zum Tracking-Host.
	 * Vorhandene rel-Werte bleiben erhalten.
	 */
	public static function mark_links( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<a ' ) ) {
			return $html;
		}
		$hosts = self::ad_hosts();
		return preg_replace_callback(
			'#<a\s[^>]*>#i',
			function ( $m ) use ( $hosts ) {
				$tag = $m[0];
				if ( ! preg_match( '#\shref\s*=\s*(["\'])(.*?)\1#i', $tag, $h ) ) {
					return $tag;
				}
				$host = strtolower( (string) wp_parse_url( html_entity_decode( $h[2] ), PHP_URL_HOST ) );
				if ( '' === $host || ! in_array( $host, $hosts, true ) ) {
					return $tag;
				}
				if ( preg_match( '#\srel\s*=\s*(["\'])(.*?)\1#i', $tag, $r ) ) {
					$rel = array_filter( preg_split( '/\s+/', strtolower( $r[2] ) ) );
					$new = implode( ' ', array_unique( array_merge( $rel, array( 'sponsored', 'nofollow' ) ) ) );
					return str_replace( $r[0], ' rel="' . esc_attr( $new ) . '"', $tag );
				}
				return preg_replace( '#^<a\s#i', '<a rel="sponsored nofollow" ', $tag );
			},
			$html
		);
	}

	/** Nur AppSumo-Adressen werden verlinkt. */
	public static function is_appsumo( $url ) {
		$p = wp_parse_url( (string) $url );
		return $p && isset( $p['scheme'], $p['host'] ) && 'https' === $p['scheme']
			&& in_array( strtolower( $p['host'] ), array( 'appsumo.com', 'www.appsumo.com' ), true );
	}

	public static function configured() {
		return '' !== (string) ADM_Settings::get( 'partner_id' ) && '' !== (string) ADM_Settings::get( 'ad_id' ) && '' !== (string) ADM_Settings::get( 'program_id' );
	}

	/**
	 * @param string $url   AppSumo-Adresse.
	 * @param string $subid Kennung der Seite (subId1).
	 * @return string Affiliate-Link; ohne Einstellungen die unveränderte Adresse; leer bei fremden Adressen.
	 */
	public static function url( $url, $subid = '' ) {
		$url = esc_url_raw( (string) $url, array( 'https' ) );
		if ( ! self::is_appsumo( $url ) ) {
			return '';
		}
		if ( ! self::configured() ) {
			return $url;
		}
		$link = sprintf(
			'https://%s/c/%s/%s/%s',
			ADM_Settings::get( 'tracking_host' ),
			rawurlencode( (string) ADM_Settings::get( 'partner_id' ) ),
			rawurlencode( (string) ADM_Settings::get( 'ad_id' ) ),
			rawurlencode( (string) ADM_Settings::get( 'program_id' ) )
		);
		$link .= '?u=' . rawurlencode( $url );
		$subid = sanitize_title( (string) $subid );
		if ( '' !== $subid ) {
			$link .= '&subId1=' . rawurlencode( substr( $subid, 0, 64 ) );
		}
		return $link;
	}

	/**
	 * [adm_link deal="neuronwriter" subid="tool-neuronwriter"]Zum Deal bei AppSumo[/adm_link]
	 * [adm_link url="https://appsumo.com/products/plus-2019/"]AppSumo Plus ansehen[/adm_link] für Seiten ohne Deal in der Tabelle
	 * (nur HTTPS-Adressen auf appsumo.com). Ohne subid wird der Slug der aktuellen Seite verwendet.
	 */
	public static function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'deal' => '', 'url' => '', 'subid' => '', 'class' => '' ), $atts, 'adm_link' );
		$deal = '' !== $atts['deal'] ? ADM_DB::get_by_slug( $atts['deal'] ) : null;
		if ( ! $deal && '' !== $atts['url'] && self::is_appsumo( $atts['url'] ) ) {
			$deal = array( 'url' => $atts['url'], 'slug' => ADM_DB::slug_from_url( $atts['url'] ), 'status' => 'kaufbar' );
		}
		if ( ! $deal ) {
			return '' !== $content ? do_shortcode( $content ) : '';
		}
		$subid = '' !== $atts['subid'] ? $atts['subid'] : ( get_post_field( 'post_name', get_the_ID() ) ?: $deal['slug'] );
		$href  = self::url( $deal['url'], $subid );
		$text  = '' !== trim( (string) $content ) ? do_shortcode( $content ) : esc_html( 'Zum Deal bei AppSumo' );
		$class = trim( 'adm-link adm-status-' . sanitize_html_class( $deal['status'] ) . ' ' . sanitize_html_class( $atts['class'] ) );
		return sprintf(
			'<a href="%s" class="%s" rel="sponsored nofollow noopener" target="_blank">%s<span class="adm-mark" aria-label="Werbelink">*</span></a>',
			esc_url( $href ),
			esc_attr( $class ),
			$text
		);
	}
}
