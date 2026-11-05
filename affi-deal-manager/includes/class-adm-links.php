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
	 * Ohne subid wird der Slug der aktuellen Seite verwendet.
	 */
	public static function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'deal' => '', 'subid' => '', 'class' => '' ), $atts, 'adm_link' );
		$deal = ADM_DB::get_by_slug( $atts['deal'] );
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
