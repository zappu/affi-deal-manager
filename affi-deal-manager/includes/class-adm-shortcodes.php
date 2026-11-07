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
 * [adm_deals]         kaufbare Deals als Karten mit Werbelink, z. B. plus="only" für die AppSumo-Plus-Seite
 *                     (limit, orderby reviews|rating|price|newest, type software|kurs|ebook|vorlage,
 *                     desc="0" ohne die englische Kurzbeschreibung von AppSumo);
 *                     die Plus-Mitgliedschaft selbst erscheint nicht in der Liste
 * [adm_ratings]       Verteilung der Bewertungen bei AppSumo (Tacos 1 bis 5) als Balken mit Anteilen
 * [adm_rubrics]       alle Rubriken als Kacheln mit Bild und Zahl der veröffentlichten Profile (Startseite)
 * [adm_tools]         veröffentlichte Tool-Profile als Karten, kaufbare und meistbewertete zuerst;
 *                     limit, rubrik="<slug>", heading="…" setzt Abschnitt mit Überschrift und Link zu /tools/;
 *                     ohne veröffentlichte Profile bleibt alles leer
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
		add_shortcode( 'adm_deals', array( __CLASS__, 'deals' ) );
		add_shortcode( 'adm_rubrics', array( __CLASS__, 'rubrics' ) );
		add_shortcode( 'adm_tools', array( __CLASS__, 'tools' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'the_content', array( __CLASS__, 'glance_live' ), 15 );
		add_filter( 'the_content', array( __CLASS__, 'layout' ), 20 );
		add_shortcode( 'adm_ratings', array( __CLASS__, 'ratings' ) );
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
			. ( '' !== $side ? '<div class="adm-page__side">' . $side . '</div>' : '' ) . '</div></div>' . self::sticky_bar();
	}

	/** Hello Elementor: eigenen Seitentitel weglassen, der Kopfbereich bringt die H1 mit. */
	public static function hide_theme_title( $show ) {
		return self::layout_active() ? false : $show;
	}

	public static function register_assets() {
		wp_register_style( 'adm-frontend', ADM_URL . 'assets/adm-frontend.css', array(), ADM_VERSION );
		wp_register_script( 'adm-sticky', '', array(), ADM_VERSION, true );
		wp_add_inline_script( 'adm-sticky', '(function(){var bar=document.querySelector(".adm-sticky"),box=document.querySelector(".adm-box--inline");if(!bar||!box)return;var foot=document.querySelector("[data-elementor-type=footer]")||document.querySelector("body>footer,#site-footer"),t=0;function up(){t=0;var gone=box.getBoundingClientRect().bottom<0,end=foot&&foot.getBoundingClientRect().top<window.innerHeight;bar.hidden=!(gone&&!end);}function on(){if(!t)t=requestAnimationFrame(up);}window.addEventListener("scroll",on,{passive:true});window.addEventListener("resize",on);up();})();' );
		wp_register_script( 'adm-video', '', array(), ADM_VERSION, true );
		wp_add_inline_script( 'adm-video', 'document.addEventListener("click",function(e){var b=e.target.closest(".adm-video__btn");if(!b)return;var v=b.closest(".adm-video");if(!v||!/^[A-Za-z0-9_-]{11}$/.test(v.dataset.id||""))return;var f=document.createElement("iframe");f.src="https://www.youtube-nocookie.com/embed/"+v.dataset.id+"?autoplay=1&rel=0";f.title=v.dataset.title||"Video";f.allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture";f.allowFullscreen=true;f.referrerPolicy="strict-origin-when-cross-origin";v.innerHTML="";v.appendChild(f);});' );
		// Im Kopf laden, wenn die Seite Bausteine des Plugins enthält; sonst landet das Stylesheet erst im Fuß.
		$content = is_singular() ? (string) get_post_field( 'post_content', get_queried_object_id() ) : '';
		if ( is_singular( ADM_Profiles::CPT ) || false !== strpos( $content, '[adm_' ) || false !== strpos( $content, 'adm-home' ) ) {
			wp_enqueue_style( 'adm-frontend' );
		}
	}

	/** Grauer Grund und Kasten in der Seitenleiste, wenn das Profil-Layout aktiv ist. */
	public static function body_class( $classes ) {
		if ( self::layout_active() ) {
			$classes[] = 'adm-layout';
		}
		// Seiten im Stil der Startseite (Block-Gruppe mit der Klasse adm-home): grauer Grund und Stylesheet.
		if ( is_singular( 'page' ) && false !== strpos( (string) get_post_field( 'post_content', get_queried_object_id() ), 'adm-home' ) ) {
			$classes[] = 'adm-layout';
			$classes[] = 'adm-homepage';
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

		list( $img_id, $is_shot ) = ADM_Shots::display_image_id( $post->ID, $term );
		$site = ADM_Shots::website( $deal );
		$img  = $img_id ? wp_get_attachment_image( $img_id, $is_shot ? 'medium_large' : 'medium', false, array( 'alt' => $is_shot ? 'Screenshot der Website von ' . $deal['title'] : '', 'loading' => 'eager', 'sizes' => $is_shot ? '(max-width: 600px) 100vw, 320px' : '120px' ) ) : '';
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
		if ( (int) $deal['purchases'] > 0 ) {
			$chips[] = '<span class="adm-chip">' . esc_html( number_format_i18n( (int) $deal['purchases'] ) . '-mal gekauft' ) . '</span>';
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
		if ( $img && $is_shot ) {
			$cap  = '' !== $site ? 'Screenshot: <a href="' . esc_url( $site ) . '" target="_blank" rel="noopener">' . esc_html( ADM_Shots::host( $site ) ) . '</a>' : 'Screenshot der Website';
			$out .= '<figure class="adm-head__img adm-head__img--shot">' . $img . '<figcaption>' . $cap . '</figcaption></figure>';
		} elseif ( $img ) {
			$out .= '<div class="adm-head__img">' . $img . '</div>';
		}
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
		$mark   = '<span class="adm-mark" aria-label="Werbelink">*</span>';
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
		$site = ADM_Shots::website( $deal );
		if ( '' !== $site ) {
			$out .= '<a class="adm-box__site" href="' . esc_url( $site ) . '" target="_blank" rel="noopener">Website des Herstellers<span aria-hidden="true"> ↗</span><span class="screen-reader-text"> (öffnet in neuem Fenster)</span></a>';
		}
		// Der Werbehinweis steht zentral im Footer; der Kasten zeigt nur den Stand. Per Filter wieder einschaltbar.
		$note = (string) apply_filters( 'adm_box_note', '' );
		$out .= '<p class="adm-box__small">' . esc_html( trim( self::checked( $deal ) . '.' . ( '' !== $note ? ' ' . $note : '' ) ) ) . '</p>';
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
		if ( '' !== self::stage_label( $deal ) ) {
			$rows['Stufe bei AppSumo'] = esc_html( self::stage_label( $deal ) . ': ' . self::stage_text( $deal ) );
		}
		if ( (int) $deal['purchases'] > 0 ) {
			$rows['Käufe bei AppSumo'] = esc_html( number_format_i18n( (int) $deal['purchases'] ) );
		}
		if ( $deal['ranks'] ) {
			$rows['Platzierung'] = esc_html( implode( ', ', array_map( array( __CLASS__, 'rank_text' ), $deal['ranks'] ) ) );
		}
		$site = ADM_Shots::website( $deal );
		if ( '' !== $site ) {
			$rows['Website'] = '<a href="' . esc_url( $site ) . '" target="_blank" rel="noopener">' . esc_html( ADM_Shots::host( $site ) ) . ' <span aria-hidden="true">↗</span></a>';
		}
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

	/* ---------------- Deal-Liste ---------------- */

	/** Die Plus-Mitgliedschaft selbst (Produkt „AppSumo Plus …“), kein Deal für die Liste. */
	public static function is_membership( array $deal ) {
		return 0 === strpos( strtolower( (string) $deal['name'] ), 'appsumo plus' ) || 0 === strpos( (string) $deal['slug'], 'plus-20' );
	}

	public static function deals( $atts ) {
		$atts = shortcode_atts( array( 'plus' => 'exclude', 'limit' => 12, 'orderby' => 'reviews', 'type' => '', 'desc' => '1' ), $atts, 'adm_deals' );
		$plus = in_array( $atts['plus'], array( 'only', 'all', 'exclude' ), true ) ? $atts['plus'] : 'exclude';
		$max  = max( 1, min( 48, (int) $atts['limit'] ) );
		$rows = adm_get_deals( array( 'plus' => $plus, 'limit' => $max + 3, 'orderby' => $atts['orderby'], 'type' => $atts['type'] ) );
		$out  = '';
		$n    = 0;
		foreach ( $rows as $d ) {
			if ( self::is_membership( $d ) || $n >= $max ) {
				continue;
			}
			$n++;
			$chips = array();
			$price = self::price( $d );
			if ( '' !== $price ) {
				$chips[] = '<span class="adm-chip adm-chip--price">' . esc_html( $price ) . '</span>';
			}
			if ( '' !== self::rating( $d ) ) {
				$chips[] = '<span class="adm-chip" title="' . esc_attr( self::rating( $d ) ) . '"><span aria-hidden="true">★ ' . esc_html( number_format_i18n( (float) $d['rating'], 1 ) . ' (' . number_format_i18n( (int) $d['review_count'] ) . ')' ) . '</span><span class="screen-reader-text">' . esc_html( self::rating( $d ) ) . '</span></span>';
			}
			$link = ADM_Links::url( $d['url'], 'liste-' . $d['slug'] );
			$out .= '<article class="adm-card adm-card--deal"><h3 class="adm-card__name">' . esc_html( $d['title'] ) . '</h3>';
			$out .= '0' !== (string) $atts['desc'] && '' !== trim( (string) $d['description'] ) ? '<p class="adm-card__lead">' . esc_html( wp_trim_words( $d['description'], 22 ) ) . '</p>' : '';
			$out .= $chips ? '<div class="adm-chips">' . implode( '', $chips ) . '</div>' : '';
			$out .= '' !== $link ? '<a class="adm-btn adm-btn--small" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Bei AppSumo ansehen<span class="adm-mark" aria-label="Werbelink">*</span></a>' : '';
			$out .= '</article>';
		}
		if ( '' === $out ) {
			return self::assets() . '<p class="adm-empty-note">Gerade gibt es hier keine kaufbaren Angebote.</p>';
		}
		return self::assets() . '<div class="adm adm-cards adm-cards--deals">' . $out . '</div>';
	}

	/* ---------------- Startseite: Rubriken und Tool-Profile ---------------- */

	public static function rubrics( $atts ) {
		$atts  = shortcode_atts( array( 'hide_empty' => '0' ), $atts, 'adm_rubrics' );
		$terms = get_terms( array(
			'taxonomy'   => ADM_Rubrics::TAX,
			'hide_empty' => '1' === (string) $atts['hide_empty'],
			'orderby'    => 'name',
		) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$out = '';
		foreach ( $terms as $t ) {
			$img  = ADM_Rubrics::image_id( $t->term_id );
			$out .= '<a class="adm-rub" href="' . esc_url( get_term_link( $t ) ) . '">';
			$out .= $img ? '<span class="adm-rub__img">' . wp_get_attachment_image( $img, 'medium', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</span>' : '';
			$out .= '<span class="adm-rub__name">' . esc_html( $t->name ) . '</span>';
			$out .= '<span class="adm-rub__count">' . esc_html( $t->count ? ( 1 === (int) $t->count ? '1 Tool-Profil' : number_format_i18n( $t->count ) . ' Tool-Profile' ) : 'Profile folgen' ) . '</span>';
			$out .= '</a>';
		}
		return self::assets() . '<div class="adm adm-rubgrid">' . $out . '</div>';
	}

	public static function tools( $atts ) {
		$atts = shortcode_atts( array( 'limit' => 6, 'rubrik' => '', 'heading' => '' ), $atts, 'adm_tools' );
		$args = array(
			'post_type'      => ADM_Profiles::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 24, (int) $atts['limit'] ) ),
			'no_found_rows'  => true,
			'adm_sort'       => 1,
		);
		if ( '' !== $atts['rubrik'] ) {
			$args['tax_query'] = array( array( 'taxonomy' => ADM_Rubrics::TAX, 'field' => 'slug', 'terms' => sanitize_title( $atts['rubrik'] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$q = new WP_Query( $args );
		if ( ! $q->posts ) {
			return '';
		}
		$cards = '';
		foreach ( $q->posts as $p ) {
			$cards .= ADM_Archive::card( $p, '' === $atts['rubrik'] );
		}
		$grid = '<div class="adm adm-cards">' . $cards . '</div>';
		if ( '' === trim( (string) $atts['heading'] ) ) {
			return self::assets() . $grid;
		}
		return self::assets() . '<section class="adm-home-sec"><div class="adm-home-sec__head"><h2>' . esc_html( $atts['heading'] ) . '</h2><a href="' . esc_url( get_post_type_archive_link( ADM_Profiles::CPT ) ) . '">Alle Tools</a></div>' . $grid . '</section>';
	}

	/**
	 * Schmale Leiste mit Preis und Kauf-Button am unteren Rand (nur Handy, Profil-Layout).
	 * Erscheint, sobald der Kasten oben im Text aus dem Bild gescrollt ist, und verschwindet vor der Fußzeile.
	 */
	private static function sticky_bar() {
		$deal = self::deal( array( 'deal' => '' ) );
		if ( ! $deal || ! (bool) apply_filters( 'adm_sticky_bar', true, $deal ) ) {
			return '';
		}
		$buy  = 'kaufbar' === $deal['status'];
		$link = ADM_Links::url( $deal['url'], self::subid( $deal ) . '-leiste' );
		if ( '' === $link ) {
			return '';
		}
		list( $amount ) = self::price_parts( $deal );
		wp_enqueue_script( 'adm-sticky' );
		return '<div class="adm adm-sticky" hidden><div class="adm-sticky__info"><span class="adm-sticky__name">' . esc_html( $deal['title'] ) . '</span>'
			. ( $buy && '' !== $amount ? '<span class="adm-sticky__price">' . esc_html( $amount . ( 'kostenlos' === $amount ? '' : ' einmalig' ) ) . '</span>' : '<span class="adm-sticky__price">Ausgelaufen</span>' )
			. '</div><a class="adm-btn adm-sticky__btn" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">' . ( $buy ? 'Zum Angebot' : 'Warteliste' ) . '<span class="adm-mark" aria-label="Werbelink">*</span></a></div>';
	}

	/* ---------------- Verteilung der Bewertungen ---------------- */

	/** Anteile: Zahl gesamt, Zahl und Prozent mit 4 bis 5 und mit 1 bis 3 Tacos. */
	public static function dist_stats( array $deal ) {
		$d = isset( $deal['dist'] ) ? $deal['dist'] : array();
		if ( ! $d ) {
			return null;
		}
		$all  = array_sum( $d );
		$good = $d[4] + $d[5];
		$low  = $d[1] + $d[2] + $d[3];
		$pg   = (int) round( 100 * $good / $all );
		return array( 'all' => $all, 'good' => $good, 'low' => $low, 'pgood' => $pg, 'plow' => 100 - $pg );
	}

	public static function ratings( $atts ) {
		$atts = shortcode_atts( array( 'deal' => '' ), $atts, 'adm_ratings' );
		$deal = self::deal( $atts );
		return $deal ? self::assets() . self::dist_html( $deal ) : '';
	}

	/** Balken 5 bis 1 Taco mit Anzahl und Satz zu den Anteilen. */
	public static function stage_label( array $deal ) {
		$map = array( 'select' => 'AppSumo Select', 'launchpad' => 'LaunchPad', 'radar' => 'Radar' );
		return isset( $deal['listing_type'], $map[ $deal['listing_type'] ] ) ? $map[ $deal['listing_type'] ] : '';
	}

	public static function stage_text( array $deal ) {
		$map = array(
			'select'    => 'von AppSumo geprüft und bewährt',
			'launchpad' => 'Tool mit ersten Verkäufen',
			'radar'     => 'ganz neues Tool',
		);
		return isset( $map[ $deal['listing_type'] ] ) ? $map[ $deal['listing_type'] ] : '';
	}

	public static function rank_text( $r ) {
		return 'Platz ' . (int) $r['rank'] . ' in ' . $r['name'];
	}

	/** Stufe, Platzierung und Käufe als Chips. */
	public static function facts_html( array $deal ) {
		$chips = array();
		if ( '' !== self::stage_label( $deal ) ) {
			$page    = get_page_by_path( 'ratgeber/was-ist-appsumo' );
			$label   = esc_html( self::stage_label( $deal ) . ': ' . self::stage_text( $deal ) );
			$chips[] = $page ? '<a class="adm-chip adm-chip--stage adm-chip--' . esc_attr( $deal['listing_type'] ) . '" href="' . esc_url( get_permalink( $page ) ) . '#radar-launchpad-und-select">' . $label . '</a>' : '<span class="adm-chip adm-chip--stage adm-chip--' . esc_attr( $deal['listing_type'] ) . '">' . $label . '</span>';
		}
		foreach ( array_slice( $deal['ranks'], 0, 2 ) as $r ) {
			$chips[] = '<span class="adm-chip" title="' . esc_attr( 'Charts bei AppSumo, ' . (int) $r['size'] . ' Tools in dieser Liste' ) . '">' . esc_html( self::rank_text( $r ) ) . '</span>';
		}
		if ( (int) $deal['purchases'] > 0 ) {
			$chips[] = '<span class="adm-chip">' . esc_html( number_format_i18n( (int) $deal['purchases'] ) . '-mal gekauft' ) . '</span>';
		}
		return $chips ? '<div class="adm-chips adm-dist__facts">' . implode( '', $chips ) . '</div>' : '';
	}

	public static function dist_html( array $deal ) {
		$st    = self::dist_stats( $deal );
		$facts = self::facts_html( $deal );
		if ( ! $st ) {
			return '' !== $facts ? '<div class="adm-dist">' . $facts . '</div>' : '';
		}
		$max  = max( $deal['dist'] );
		$bars = '';
		for ( $i = 5; $i >= 1; $i-- ) {
			$n     = (int) $deal['dist'][ $i ];
			$w     = $max ? max( $n ? 1 : 0, (int) round( 100 * $n / $max ) ) : 0;
			$bars .= '<li><span class="adm-dist__k">' . $i . ( 1 === $i ? ' Taco' : ' Tacos' ) . '</span><span class="adm-dist__bar"><span class="adm-dist__fill adm-dist__fill--' . ( $i >= 4 ? 'good' : 'low' ) . '" style="width:' . $w . '%"></span></span><span class="adm-dist__n">' . number_format_i18n( $n ) . '</span></li>';
		}
		$sum = sprintf(
			'<strong>%d %%</strong> der %s Bewertungen bei AppSumo vergeben 4 oder 5 Tacos, %d %% vergeben 3 oder weniger.',
			$st['pgood'],
			number_format_i18n( $st['all'] ),
			$st['plow']
		);
		return '<div class="adm-dist">' . $facts . '<p class="adm-dist__sum">' . $sum . '</p><ul class="adm-dist__bars">' . $bars . '</ul>'
			. '<p class="adm-source">Tacos sind die Sterne bei AppSumo. ' . esc_html( self::checked( $deal ) ) . '.</p></div>';
	}

	/**
	 * „Auf einen Blick“ mit Live-Zahlen: Verteilung der Bewertungen oben, Spalten mit Anteilen beschriftet.
	 * Ändert nur die Ausgabe, nicht den gespeicherten Text des Profils.
	 */
	public static function glance_live( $content ) {
		if ( ! is_singular( ADM_Profiles::CPT ) || doing_action( 'wp_head' ) || false === strpos( $content, 'adm-glance' ) || false !== strpos( $content, 'adm-dist' ) ) {
			return $content;
		}
		$deal = self::deal( array( 'deal' => '' ) );
		$st   = $deal ? self::dist_stats( $deal ) : null;
		$box  = $deal ? self::dist_html( $deal ) : '';
		if ( '' === $box ) {
			return $content;
		}
		$content = preg_replace_callback(
			'#<section class="adm-glance"[^>]*>\s*<h2>Auf einen Blick</h2>#',
			function ( $m ) use ( $box ) {
				return $m[0] . $box;
			},
			$content,
			1
		);
		if ( ! $st ) {
			return $content;
		}
		$content = str_replace( '<p class="adm-glance__label">Stärken</p>', '<p class="adm-glance__label">Was Käufer loben</p>', $content );
		$content = str_replace(
			'<p class="adm-glance__label">Schwächen</p>',
			'<p class="adm-glance__label">' . esc_html( sprintf( 'Kritik aus den %s schwächeren Bewertungen (%d %%)', number_format_i18n( $st['low'] ), $st['plow'] ) ) . '</p>',
			$content
		);
		return $content;
	}

}
