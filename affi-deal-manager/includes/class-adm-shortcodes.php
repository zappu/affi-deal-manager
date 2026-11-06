<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live-Bausteine für Tool-Profile. Preise, Status und Links kommen immer aus der Deal-Tabelle.
 *
 * [adm_deal_header]   Kopf: Brotkrumen, Titel (H1), Kurzbeschreibung, Eckdaten, Bild; für das Elementor-Layout
 * [adm_deal_box]      Deal-Kasten: Typ, Preis, Bewertung, Status mit Grund, Kauf-Button.
 *                     placement="sidebar" für die Seitenleiste des Layouts; im Text (placement="content")
 *                     erscheint er nur auf dem Handy, wenn in den Einstellungen „Seitenleiste“ gewählt ist
 * [adm_toc]           Sprungmenü zu den Abschnitten des Profils
 * [adm_deal_details]  Tabelle mit Preis, Rückgabefrist, Angebotsende, Rubrik
 * [adm_video id=""]   YouTube-Video mit Zwei-Klick-Lösung (lädt erst nach Klick von youtube-nocookie.com)
 * [adm_alternatives]  bis zu drei andere Tool-Profile aus derselben Rubrik, kaufbare zuerst;
 *                     heading="1" setzt Karte und Überschrift drumherum, ohne Alternativen bleibt alles leer
 *
 * Alle Bausteine nehmen optional deal="<slug>"; ohne Angabe gilt der Deal des aktuellen Profils.
 */
class ADM_Shortcodes {

	const NOTE = '* Werbelink: Kaufst du über diesen Link, erhalten wir eine Provision. Für dich ändert sich der Preis nicht.';

	/** Kurze Namen der Abschnitte für das Sprungmenü (id => Beschriftung). */
	const TOC = array(
		'auf-einen-blick' => 'Auf einen Blick',
		'kurz-erklaert'   => 'Kurz erklärt',
		'fuer-wen'        => 'Für wen',
		'funktionen'      => 'Funktionen',
		'inhalt'          => 'Inhalt',
		'kaeufer'         => 'Käuferstimmen',
		'videos'          => 'Videos',
		'details'         => 'Details',
		'faq'             => 'Fragen',
		'alternativen'    => 'Alternativen',
	);

	public static function init() {
		add_shortcode( 'adm_deal_header', array( __CLASS__, 'deal_header' ) );
		add_shortcode( 'adm_deal_box', array( __CLASS__, 'deal_box' ) );
		add_shortcode( 'adm_toc', array( __CLASS__, 'toc' ) );
		add_shortcode( 'adm_deal_details', array( __CLASS__, 'deal_details' ) );
		add_shortcode( 'adm_video', array( __CLASS__, 'video' ) );
		add_shortcode( 'adm_alternatives', array( __CLASS__, 'alternatives' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'the_content', array( __CLASS__, 'layout' ), 20 );
		add_filter( 'hello_elementor_page_title', array( __CLASS__, 'hide_theme_title' ) );
	}

	/** Profil-Layout aktiv: Einstellung „Seitenleiste“ und ein einzelnes Tool-Profil im Hauptinhalt. */
	private static function layout_active() {
		return 'sidebar' === ADM_Settings::get( 'box_placement' ) && is_singular( ADM_Profiles::CPT );
	}

	/**
	 * Baut um den Beitragsinhalt Kopfbereich und Seitenleiste mit Deal-Kasten.
	 * So braucht das Theme (oder Elementor) nur noch den Beitragsinhalt auszugeben.
	 */
	public static function layout( $content ) {
		// Nur für den Inhalt des aufgerufenen Profils, nicht in wp_head (SEO-Plugins) und nicht doppelt.
		if ( ! self::layout_active() || doing_action( 'wp_head' ) || (int) get_the_ID() !== (int) get_queried_object_id() || false !== strpos( $content, 'adm-page__cols' ) ) {
			return $content;
		}
		$head = false === strpos( $content, 'adm-head' ) ? do_shortcode( '[adm_deal_header]' ) : '';
		$side = do_shortcode( '[adm_deal_box placement="sidebar"]' );
		return '<div class="adm adm-page">' . $head . '<div class="adm-page__cols"><div class="adm-page__main">' . $content . '</div>'
			. ( '' !== $side ? '<div class="adm-page__side">' . $side . '</div>' : '' ) . '</div></div>';
	}

	/** Hello Elementor: eigenen Seitentitel weglassen, der Kopfbereich bringt die H1 mit. */
	public static function hide_theme_title( $show ) {
		return self::layout_active() ? false : $show;
	}

	public static function register_assets() {
		wp_register_style( 'adm-frontend', ADM_URL . 'assets/adm-frontend.css', array(), ADM_VERSION );
		wp_register_script( 'adm-video', '', array(), ADM_VERSION, true );
		wp_add_inline_script( 'adm-video', 'document.addEventListener("click",function(e){var b=e.target.closest(".adm-video__btn");if(!b)return;var v=b.closest(".adm-video");if(!v||!/^[A-Za-z0-9_-]{11}$/.test(v.dataset.id||""))return;var f=document.createElement("iframe");f.src="https://www.youtube-nocookie.com/embed/"+v.dataset.id+"?autoplay=1&rel=0";f.title=v.dataset.title||"Video";f.allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture";f.allowFullscreen=true;f.referrerPolicy="strict-origin-when-cross-origin";v.innerHTML="";v.appendChild(f);});' );
		if ( is_singular( ADM_Profiles::CPT ) ) {
			wp_enqueue_style( 'adm-frontend' );
		}
	}

	/** Grauer Grund und Kasten in der Seitenleiste, wenn das Profil-Layout aktiv ist. */
	public static function body_class( $classes ) {
		if ( self::layout_active() ) {
			$classes[] = 'adm-layout';
		}
		return $classes;
	}

	/** Stylesheet auch außerhalb der Profile, sobald ein Baustein benutzt wird. */
	private static function assets() {
		if ( ! wp_style_is( 'adm-frontend', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'adm-frontend' );
		return '';
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

	private static function price_parts( array $deal ) {
		$p = self::price( $deal );
		if ( 'kostenlos' === $p || '' === $p ) {
			return array( $p, '' );
		}
		return array( trim( str_replace( ' einmalig', '', $p ) ), 'einmalig' );
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

	/** Rubrik eines Deals als Begriff (oder null). */
	private static function rubric_term( array $deal ) {
		$slug = ADM_Rubrics::for_deal( $deal );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, ADM_Rubrics::TAX ) : null;
		return $term && ! is_wp_error( $term ) ? $term : null;
	}

	/* ---------------- Kopf ---------------- */

	public static function deal_header( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '', 'title' => '1' ), $atts, 'adm_deal_header' );
		$deal = self::deal( $atts );
		$post = get_post();
		if ( ! $deal || ! $post ) {
			return '';
		}
		$term  = self::rubric_term( $deal );
		$crumb = '<nav class="adm-crumbs" aria-label="Brotkrumen"><a href="' . esc_url( home_url( '/' ) ) . '">Start</a> / ';
		if ( $term ) {
			$crumb .= ( $term->count ? '<a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>' : esc_html( $term->name ) ) . ' / ';
		}
		$crumb .= esc_html( $deal['title'] ) . '</nav>';

		$img = get_the_post_thumbnail( $post, 'medium', array( 'alt' => '', 'loading' => 'eager' ) );
		if ( ! $img && $term ) {
			$img = ADM_Rubrics::image_html( $term->term_id, 'medium' );
		}
		$chips = array();
		$price = self::price( $deal );
		if ( '' !== $price ) {
			$chips[] = '<span class="adm-chip adm-chip--price">' . esc_html( $price ) . '</span>';
		}
		if ( 'kaufbar' !== $deal['status'] ) {
			$chips[] = '<span class="adm-chip adm-chip--off">' . esc_html( 'ausgelaufen' === $deal['status'] ? 'Ausgelaufen' : 'Nicht verfügbar' ) . '</span>';
		}
		if ( '' !== self::rating( $deal ) ) {
			$chips[] = '<span class="adm-chip"><span aria-hidden="true">★ </span>' . esc_html( number_format_i18n( (float) $deal['rating'], 1 ) . ' / 5 bei ' . number_format_i18n( (int) $deal['review_count'] ) . ' Bewertungen' ) . '</span>';
		}
		if ( $deal['return_days'] ) {
			$chips[] = '<span class="adm-chip">' . esc_html( (int) $deal['return_days'] . ' Tage Rückgabe' ) . '</span>';
		}
		if ( $term ) {
			$chips[] = $term->count ? '<a class="adm-chip adm-chip--rubric" href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>' : '<span class="adm-chip adm-chip--rubric">' . esc_html( $term->name ) . '</span>';
		}
		$gen    = (int) get_post_meta( $post->ID, '_adm_generated', true );
		$meta   = array_filter( array(
			$gen ? 'Stand ' . wp_date( 'd.m.Y', $gen ) : '',
		) );
		$lead = has_excerpt( $post ) ? get_the_excerpt( $post ) : $deal['description'];

		$out  = self::assets() . '<header class="adm adm-head">' . $crumb . '<div class="adm-head__card">';
		$out .= $img ? '<div class="adm-head__img">' . $img . '</div>' : '';
		$out .= '<div class="adm-head__body">' . ( '0' !== (string) $atts['title'] ? '<h1>' . esc_html( get_the_title( $post ) ) . '</h1>' : '' );
		$out .= '' !== trim( (string) $lead ) ? '<p class="adm-head__lead">' . esc_html( wp_strip_all_tags( $lead ) ) . '</p>' : '';
		$out .= $chips ? '<div class="adm-chips">' . implode( '', $chips ) . '</div>' : '';
		$out .= '</div></div>' . ( $meta ? '<p class="adm-head__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>' : '' ) . '</header>';
		return $out;
	}

	/* ---------------- Deal-Kasten ---------------- */

	public static function deal_box( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '', 'placement' => 'content' ), $atts, 'adm_deal_box' );
		$deal = self::deal( $atts );
		if ( ! $deal ) {
			return '';
		}
		$side  = 'sidebar' === $atts['placement'];
		$cls   = 'adm adm-box adm-box--' . sanitize_html_class( $deal['status'] ) . ( $side ? ' adm-box--side' : ' adm-box--inline' );
		if ( ! $side && 'sidebar' === ADM_Settings::get( 'box_placement' ) && is_singular( ADM_Profiles::CPT ) ) {
			$cls .= ' adm-only-mobile';
		}
		$link   = ADM_Links::url( $deal['url'], self::subid( $deal ) );
		$mark   = '<span class="adm-mark" aria-hidden="true">*</span>';
		$status = 'kaufbar' === $deal['status'] ? 'Erhältlich' : ( 'ausgelaufen' === $deal['status'] ? 'Ausgelaufen' : 'Nicht verfügbar' );
		list( $amount, $unit ) = self::price_parts( $deal );

		$out  = self::assets() . '<aside class="' . esc_attr( $cls ) . '" aria-label="Angebot">';
		$out .= '<div class="adm-box__head"><span>' . esc_html( ADM_DB::type_label( $deal['deal_type'] ) ) . '</span><span class="adm-pill">' . esc_html( $status ) . '</span></div>';
		$out .= '<p class="adm-box__name">' . esc_html( $deal['title'] ) . '</p>';
		if ( '' !== $amount ) {
			$out .= '<p class="adm-box__price">' . esc_html( $amount ) . ( '' !== $unit ? ' <small>' . esc_html( $unit ) . '</small>' : '' ) . '</p>';
		}
		$facts = array_filter( array(
			'' !== self::rating( $deal ) ? '★ ' . number_format_i18n( (float) $deal['rating'], 1 ) . ' von 5 bei ' . number_format_i18n( (int) $deal['review_count'] ) . ' Bewertungen auf AppSumo' : '',
			$deal['return_days'] ? (int) $deal['return_days'] . ' Tage Rückgabe bei AppSumo' : '',
			'kaufbar' === $deal['status'] ? 'Kein Abo, einmal bezahlen' : '',
		) );
		if ( $facts ) {
			$out .= '<ul class="adm-box__facts"><li>' . implode( '</li><li>', array_map( 'esc_html', $facts ) ) . '</li></ul>';
		}
		if ( 'kaufbar' !== $deal['status'] || '' !== (string) $deal['available_until'] ) {
			$out .= '<p class="adm-box__reason">' . esc_html( ADM_DB::reason( $deal ) ) . '</p>';
		}
		if ( 'kaufbar' === $deal['status'] ) {
			$out .= '<a class="adm-btn" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zum Angebot bei AppSumo' . $mark . '</a>';
		} elseif ( self::find_alternatives( $deal, 1 ) ) {
			$out .= '<a class="adm-btn" href="#alternativen">Kaufbare Alternativen ansehen</a>';
			$out .= '<a class="adm-btn adm-btn--quiet" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zur Warteliste bei AppSumo' . $mark . '</a>';
		} else {
			$out .= '<a class="adm-btn" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zur Warteliste bei AppSumo' . $mark . '</a>';
		}
		$out .= '<p class="adm-box__small">' . esc_html( trim( self::checked( $deal ) . '. ' . self::NOTE ) ) . '</p>';
		return $out . '</aside>';
	}

	/* ---------------- Sprungmenü ---------------- */

	public static function toc( $atts ) {
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
		preg_match_all( '#<section[^>]*\bid="([a-z0-9\-]+)"#', (string) $post->post_content, $m );
		$ids = $m[1];
		$deal = self::deal( array( 'deal' => '' ) );
		if ( $deal && false !== strpos( (string) $post->post_content, '[adm_alternatives' ) && self::find_alternatives( $deal, 1 ) ) {
			$ids[] = 'alternativen';
		}
		$links = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( isset( self::TOC[ $id ] ) && 'auf-einen-blick' !== $id ) {
				$links[] = '<a href="#' . esc_attr( $id ) . '">' . esc_html( self::TOC[ $id ] ) . '</a>';
			}
		}
		return count( $links ) > 2 ? self::assets() . '<nav class="adm adm-toc" aria-label="Inhalt">' . implode( '', $links ) . '</nav>' : '';
	}

	/* ---------------- Deal-Details ---------------- */

	public static function deal_details( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '' ), $atts, 'adm_deal_details' );
		$deal = self::deal( $atts );
		if ( ! $deal ) {
			return '';
		}
		$rows = array(
			'Preis'         => esc_html( self::price( $deal ) ),
			'Typ'           => esc_html( ADM_DB::type_label( $deal['deal_type'] ) ),
			'Status'        => esc_html( ADM_DB::reason( $deal ) ),
			'Angebot endet' => $deal['available_until'] ? esc_html( wp_date( 'd.m.Y', strtotime( $deal['available_until'] . ' UTC' ) ) ) : '',
			'Rückgabefrist' => $deal['return_days'] ? esc_html( (int) $deal['return_days'] . ' Tage bei AppSumo' ) : '',
			'Bewertung'     => esc_html( self::rating( $deal ) ),
		);
		$term = self::rubric_term( $deal );
		if ( $term ) {
			$rows['Rubrik'] = $term->count ? '<a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>' : esc_html( $term->name );
		} elseif ( '' !== ADM_Rubrics::for_deal( $deal ) ) {
			$rows['Rubrik'] = esc_html( ADM_Rubrics::label( ADM_Rubrics::for_deal( $deal ) ) );
		}
		$out = self::assets() . '<table class="adm-details"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				$out .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // Werte oben maskiert
			}
		}
		return $out . '</tbody></table><p class="adm-source">' . esc_html( self::checked( $deal ) ) . '. Preise in US-Dollar laut AppSumo, je nach Land zuzüglich Steuern.</p>';
	}

	/* ---------------- Video ---------------- */

	public static function video( $atts ) {
		$atts = shortcode_atts( array( 'id' => '', 'title' => 'Video' ), $atts, 'adm_video' );
		$id   = (string) $atts['id'];
		if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
			return '';
		}
		wp_enqueue_script( 'adm-video' );
		$privacy = get_privacy_policy_url();
		$note    = 'Beim Laden überträgt dein Browser Daten an YouTube (Google).' . ( $privacy ? ' Mehr in der <a href="' . esc_url( $privacy ) . '">Datenschutzerklärung</a>.' : '' );
		return self::assets() . sprintf(
			'<div class="adm adm-video" data-id="%1$s" data-title="%2$s"><div class="adm-video__consent"><button type="button" class="adm-video__btn">Video von YouTube laden</button><p class="adm-video__note">%3$s</p></div><noscript><p class="adm-video__note"><a href="https://www.youtube.com/watch?v=%1$s" rel="noopener" target="_blank">Video auf YouTube ansehen</a></p></noscript></div>',
			esc_attr( $id ),
			esc_attr( $atts['title'] ),
			$note
		);
	}

	/* ---------------- Alternativen ---------------- */

	public static function alternatives( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '', 'limit' => 3, 'heading' => '0' ), $atts, 'adm_alternatives' );
		$deal = self::deal( $atts );
		if ( ! $deal ) {
			return '';
		}
		$list = self::find_alternatives( $deal, max( 1, min( 6, (int) $atts['limit'] ) ) );
		if ( ! $list ) {
			return '';
		}
		$cards = '';
		foreach ( $list as $alt ) {
			$cards .= '<a class="adm-alt" href="' . esc_url( get_permalink( (int) $alt['post_id'] ) ) . '"><p class="adm-alt__name">' . esc_html( $alt['title'] ) . '</p>';
			if ( '' !== $alt['description'] ) {
				$cards .= '<p class="adm-alt__desc">' . esc_html( wp_trim_words( $alt['description'], 18 ) ) . '</p>';
			}
			$cards .= 'kaufbar' === $alt['status']
				? '<span class="adm-chip adm-chip--price">' . esc_html( self::price( $alt ) ) . '</span>'
				: '<span class="adm-chip">ausgelaufen</span>';
			$cards .= '</a>';
		}
		$grid = '<div class="adm adm-alts">' . $cards . '</div>';
		if ( '1' === (string) $atts['heading'] ) {
			return self::assets() . '<section class="adm-sec" id="alternativen"><h2>Alternativen</h2>' . $grid . '</section>';
		}
		return self::assets() . $grid;
	}

	/**
	 * Veröffentlichte Profile aus derselben Rubrik, sonst aus derselben AppSumo-Kategorie
	 * (erst gleiche Unterkategorie, dann gleicher Bereich), kaufbare zuerst.
	 */
	public static function find_alternatives( array $deal, $limit ) {
		global $wpdb;
		$t     = ADM_DB::table();
		$parts = array_map( 'trim', explode( '>', (string) $deal['category'] ) );
		$out   = array();
		$seen  = array( (int) $deal['id'] => true );
		$tries = array();

		$rubric = ADM_Rubrics::for_deal( $deal );
		if ( '' !== $rubric ) {
			$ids = get_posts( array(
				'post_type'      => ADM_Profiles::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => (int) $deal['post_id'] ? array( (int) $deal['post_id'] ) : array(),
				'tax_query'      => array( array( 'taxonomy' => ADM_Rubrics::TAX, 'field' => 'slug', 'terms' => $rubric ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			if ( $ids ) {
				$ids  = array_map( 'intval', $ids );
				$rows = $wpdb->get_results(
					"SELECT * FROM {$t} WHERE post_id IN (" . implode( ',', $ids ) . ") AND status IN ('kaufbar','ausgelaufen') ORDER BY (status = 'kaufbar') DESC, review_count DESC", // phpcs:ignore
					ARRAY_A
				);
				foreach ( (array) $rows as $r ) {
					if ( count( $out ) >= $limit ) {
						return $out;
					}
					if ( ! isset( $seen[ (int) $r['id'] ] ) ) {
						$seen[ (int) $r['id'] ] = true;
						$out[]                  = ADM_DB::decode( $r );
					}
				}
			}
			if ( count( $out ) >= $limit ) {
				return $out;
			}
		}
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

}
