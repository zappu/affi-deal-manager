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
 * [adm_bestenliste]   Rangliste veröffentlichter Profile (rubrik= oder tools=), nach Anteil zufriedener Käufer
 * [adm_bestenlisten]  Übersicht der Unterseiten mit Bestenlisten und ihrer Top-Empfehlung
 * [adm_best_top]      Teaser zu Platz 1 einer Bestenliste (page=Pfad oder ID)
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
		add_shortcode( 'adm_bestenliste', array( __CLASS__, 'bestenliste' ) );
		add_shortcode( 'adm_bestenlisten', array( __CLASS__, 'bestenlisten' ) );
		add_shortcode( 'adm_best_top', array( __CLASS__, 'best_top' ) );
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
		$out .= ADM_Promos::box_html( $deal ) . ADM_Spotlight::code_html( $deal );
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
		return $out . ADM_Spotlight::html( 'side', ADM_Rubrics::for_deal( $deal ), (int) $deal['id'] ) . ADM_Banners::html( 'side' ) . '</aside>';
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

	/**
	 * [adm_tools limit="6" rubrik="" heading="" rows="2"]
	 * rows="2": immer volle Reihen. Es werden 8 Profile geladen und je nach verfügbarer Breite
	 * 8 (4 Spalten), 6 (3 Spalten), 4 (2 Spalten) oder 3 (1 Spalte) gezeigt (Container-Abfrage, siehe CSS).
	 */
	public static function tools( $atts ) {
		$atts = shortcode_atts( array( 'limit' => 6, 'rubrik' => '', 'heading' => '', 'rows' => 0 ), $atts, 'adm_tools' );
		$fit  = 2 === (int) $atts['rows'];
		if ( $fit ) {
			$atts['limit'] = 8;
		}
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
		$grid = '<div class="adm adm-cards' . ( $fit ? ' adm-cards--rows2' : '' ) . '">' . $cards . '</div>';
		if ( $fit ) {
			$grid = '<div class="adm-fit">' . $grid . '</div>';
		}
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
		if ( $all < 1 ) {
			return null;
		}
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
			'<p class="adm-glance__label">' . esc_html( sprintf( "Was %d\xc2\xa0%% der Käufer bemängeln", $st['plow'] ) ) . '</p>',
			$content
		);
		// Kritik nach ihrem Anteil einordnen: höchstens drei Punkte (die häufigsten stehen oben).
		$content = preg_replace_callback(
			'#(<div class="adm-glance__col adm-glance__col--con">.*?<ul>)(.*?)(</ul>)#s',
			function ( $m ) {
				$items = preg_split( '#(?=<li>)#', $m[2], -1, PREG_SPLIT_NO_EMPTY );
				return $m[1] . implode( '', array_slice( $items, 0, 3 ) ) . $m[3];
			},
			$content,
			1
		);
		return $content;
	}

	/* ---------------- Bestenliste ---------------- */

	/**
	 * Untere Grenze des Anteils zufriedener Käufer (4 oder 5 Tacos), Wilson-Intervall mit z = 1,64.
	 * Tools mit wenigen Bewertungen rutschen so etwas nach hinten, statt mit 5 von 5 vorn zu stehen.
	 */
	public static function best_score( array $deal ) {
		$st = self::dist_stats( $deal );
		if ( ! $st || $st['all'] < 1 ) {
			return 0.0;
		}
		$n = (float) $st['all'];
		$p = $st['good'] / $n;
		$z = 1.64;
		$lb = ( $p + $z * $z / ( 2 * $n ) - $z * sqrt( ( $p * ( 1 - $p ) + $z * $z / ( 4 * $n ) ) / $n ) ) / ( 1 + $z * $z / $n );
		// Stufe Select (von AppSumo geprüft): kleiner Vorsprung.
		return $lb + ( isset( $deal['listing_type'] ) && 'select' === $deal['listing_type'] ? 0.03 : 0.0 );
	}

	/** Erster Punkt aus einer Spalte von „Auf einen Blick“ im gespeicherten Profil (pro oder con). */
	private static function glance_point( $content, $kind ) {
		if ( preg_match( '#adm-glance__col--' . $kind . '".*?<li>(.*?)</li>#s', (string) $content, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * Sortierte Einträge einer Bestenliste (ohne Begrenzung).
	 * rubrik: Slugs der Rubriken (Komma), tools: Slugs der Profile oder Deals (Komma), exclude: auszuschließende Slugs.
	 *
	 * @return array Einträge mit post, deal, score, buy.
	 */
	public static function best_items( array $atts ) {
		$atts = shortcode_atts( array( 'rubrik' => '', 'tools' => '', 'exclude' => '', 'limit' => 10 ), $atts, 'adm_bestenliste' );
		$args = array(
			'post_type'      => ADM_Profiles::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'no_found_rows'  => true,
		);
		$list = array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['tools'] ) ) );
		if ( $list ) {
			$args['post_name__in'] = $list;
		} elseif ( '' !== trim( (string) $atts['rubrik'] ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => ADM_Rubrics::TAX, 'field' => 'slug', 'terms' => array_map( 'sanitize_title', explode( ',', (string) $atts['rubrik'] ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} else {
			return array();
		}
		$skip  = array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['exclude'] ) ) );
		$items = array();
		foreach ( get_posts( $args ) as $p ) {
			$deal = ADM_DB::get_by_post( $p->ID );
			if ( ! $deal || in_array( $p->post_name, $skip, true ) || in_array( $deal['slug'], $skip, true ) ) {
				continue;
			}
			$items[] = array( 'post' => $p, 'deal' => $deal, 'score' => self::best_score( $deal ), 'buy' => 'kaufbar' === $deal['status'] ? 1 : 0 );
		}
		usort( $items, function ( $a, $b ) {
			if ( $a['buy'] !== $b['buy'] ) {
				return $b['buy'] - $a['buy'];
			}
			if ( abs( $a['score'] - $b['score'] ) > 0.0001 ) {
				return $b['score'] < $a['score'] ? -1 : 1;
			}
			return (int) $b['deal']['purchases'] - (int) $a['deal']['purchases'];
		} );
		return $items;
	}

	/**
	 * Ein Eintrag der Bestenliste; $badge ersetzt die Platzzahl (Top-Empfehlung).
	 * $teaser: kurze, positive Fassung für die Übersicht, ohne Kritik und ohne Preis (beides steht in der Liste und im Profil).
	 */
	private static function best_item_html( array $it, $rank, $badge = '', $teaser = false ) {
		$p    = $it['post'];
		$deal = $it['deal'];
		$st   = self::dist_stats( $deal );
		$term = null;
		$tt   = get_the_terms( $p->ID, ADM_Rubrics::TAX );
		if ( $tt && ! is_wp_error( $tt ) ) {
			$term = $tt[0];
		}
		list( $img_id, $is_shot ) = ADM_Shots::display_image_id( $p->ID, $term );
		$lead = has_excerpt( $p ) ? get_the_excerpt( $p ) : $deal['description'];
		$pro  = self::glance_point( $p->post_content, 'pro' );
		$con  = $teaser ? '' : self::glance_point( $p->post_content, 'con' );

		$chips = array();
		$price = self::price( $deal );
		if ( 'kaufbar' === $deal['status'] && '' !== $price && ! $teaser ) {
			$chips[] = '<span class="adm-chip adm-chip--price">' . esc_html( $price ) . '</span>';
		} elseif ( 'kaufbar' !== $deal['status'] ) {
			$chips[] = '<span class="adm-chip adm-chip--off">Ausgelaufen</span>';
		}
		if ( $st ) {
			$chips[] = '<span class="adm-chip">' . esc_html( sprintf( "%d\xc2\xa0%% zufrieden (%s Bewertungen)", $st['pgood'], number_format_i18n( $st['all'] ) ) ) . '</span>';
		}
		if ( (int) $deal['purchases'] > 0 ) {
			$chips[] = '<span class="adm-chip">' . esc_html( number_format_i18n( (int) $deal['purchases'] ) . '-mal gekauft' ) . '</span>';
		}
		if ( '' !== self::stage_label( $deal ) ) {
			$chips[] = '<span class="adm-chip adm-chip--stage adm-chip--' . esc_attr( $deal['listing_type'] ) . '">' . esc_html( self::stage_label( $deal ) ) . '</span>';
		}
		$link = ADM_Links::url( $deal['url'], 'beste-' . $deal['slug'] );

		$out  = '<li class="adm-best__item" id="platz-' . (int) $rank . '">';
		$out .= '' !== $badge ? '<span class="adm-best__rank adm-best__rank--badge">' . esc_html( $badge ) . '</span>' : '<span class="adm-best__rank" aria-label="Platz ' . (int) $rank . '">' . (int) $rank . '</span>';
		$out .= $img_id ? '<a class="adm-best__img' . ( $is_shot ? ' adm-best__img--shot' : '' ) . '" href="' . esc_url( get_permalink( $p ) ) . '" tabindex="-1" aria-hidden="true">' . wp_get_attachment_image( $img_id, 'medium_large', false, array( 'alt' => '', 'loading' => 'lazy', 'sizes' => '(max-width: 600px) 100vw, 240px' ) ) . '</a>' : '';
		$out .= '<div class="adm-best__body"><h3 class="adm-best__name"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $deal['title'] ) . '</a></h3>';
		$out .= '' !== trim( (string) $lead ) ? '<p class="adm-best__lead">' . esc_html( wp_strip_all_tags( $lead ) ) . '</p>' : '';
		if ( '' !== $pro || '' !== $con ) {
			$out .= '<ul class="adm-best__pc">' . ( '' !== $pro ? '<li class="adm-best__pro"><strong>Gelobt:</strong> ' . esc_html( $pro ) . '</li>' : '' ) . ( '' !== $con ? '<li class="adm-best__con"><strong>Kritisiert:</strong> ' . esc_html( $con ) . '</li>' : '' ) . '</ul>';
		}
		$out .= $chips ? '<div class="adm-chips">' . implode( '', $chips ) . '</div>' : '';
		$out .= '<div class="adm-best__cta"><a class="adm-btn adm-btn--quiet adm-btn--small" href="' . esc_url( get_permalink( $p ) ) . '">Zum Tool-Profil</a>';
		$out .= '' !== $link ? '<a class="adm-btn adm-btn--small" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">' . ( 'kaufbar' === $deal['status'] ? 'Zum Angebot' : 'Zur Warteliste' ) . '<span class="adm-mark" aria-label="Werbelink">*</span></a>' : '';
		$out .= '</div></div></li>';
		return $out;
	}

	/**
	 * [adm_bestenliste rubrik="seo" tools="slug,slug" limit="10"]
	 * Veröffentlichte Tool-Profile, kaufbare zuerst, geordnet nach best_score(), bei Gleichstand nach Käufen.
	 */
	public static function bestenliste( $atts ) {
		$atts  = (array) $atts;
		$items = self::best_items( $atts );
		if ( ! $items ) {
			return '';
		}
		$items = array_slice( $items, 0, max( 1, min( 30, (int) ( isset( $atts['limit'] ) ? $atts['limit'] : 10 ) ) ) );
		$out   = '';
		$ld    = array();
		foreach ( $items as $i => $it ) {
			$ld[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => get_permalink( $it['post'] ), 'name' => $it['deal']['title'] );
			$out .= self::best_item_html( $it, $i + 1 );
		}
		$note = '<p class="adm-source">Reihenfolge: kaufbare Angebote zuerst, dann nach dem Anteil der Bewertungen mit 4 oder 5 Tacos bei AppSumo. Bei wenigen Bewertungen ist dieser Anteil unsicherer, solche Tools rutschen etwas nach hinten. Tools mit der Stufe Select bekommen einen kleinen Vorsprung. Bei Gleichstand entscheidet die Zahl der Käufe. Stand ' . esc_html( wp_date( 'd.m.Y' ) ) . ', die Liste aktualisiert sich mit den Daten von AppSumo.</p>';
		$schema = '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListOrder' => 'https://schema.org/ItemListOrderDescending', 'numberOfItems' => count( $ld ), 'itemListElement' => $ld ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>';
		return self::assets() . ADM_Banners::html( 'list' ) . '<ol class="adm adm-best">' . $out . '</ol>' . $note . $schema;
	}

	/** Einträge der Bestenliste, die auf einer Seite steht (erster [adm_bestenliste] im Inhalt). */
	public static function page_best_items( $page ) {
		$page = get_post( $page );
		if ( ! $page || ! preg_match( '/' . get_shortcode_regex( array( 'adm_bestenliste' ) ) . '/', $page->post_content, $m ) ) {
			return array();
		}
		return self::best_items( (array) shortcode_parse_atts( $m[3] ) );
	}

	/** Pokal (Top-Empfehlung) und Haken-Siegel (Stufe Select) als Inline-SVG. */
	private static function icon( $name ) {
		if ( 'trophy' === $name ) {
			return '<svg class="adm-ico adm-ico--trophy" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false"><path fill="#f2b705" d="M7 3h10v2h3a1 1 0 0 1 1 1v2a5 5 0 0 1-4.6 5A5 5 0 0 1 13 15.9V18h3a1 1 0 0 1 1 1v2H7v-2a1 1 0 0 1 1-1h3v-2.1A5 5 0 0 1 7.6 13 5 5 0 0 1 3 8V6a1 1 0 0 1 1-1h3V3Zm10 4v3.9A3 3 0 0 0 19 8V7h-2ZM5 7v1a3 3 0 0 0 2 2.9V7H5Z"/><path fill="#fff" fill-opacity=".45" d="M9.5 5.5h1.6v5.2a2.6 2.6 0 0 1-1.6-2.4V5.5Z"/></svg>';
		}
		return '<svg class="adm-ico adm-ico--select" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="m12 1 2.6 2.1 3.3-.3.9 3.2 2.9 1.7-1.2 3.1 1.2 3.1-2.9 1.7-.9 3.2-3.3-.3L12 23l-2.6-2.1-3.3.3-.9-3.2-2.9-1.7 1.2-3.1L2.3 8.1l2.9-1.7.9-3.2 3.3.3L12 1Zm-1.3 14.6 6-6-1.4-1.4-4.6 4.6-2.3-2.3L7 11.9l3.7 3.7Z"/></svg>';
	}

	/** Kurzer, positiver Teaser zur Top-Empfehlung: Name, Zufriedenheit, Käufe, Select, ein Punkt aus dem Lob. */
	private static function top_teaser( array $items, $list_url = '' ) {
		$it   = $items[0];
		$p    = $it['post'];
		$deal = $it['deal'];
		$st   = self::dist_stats( $deal );
		$pro  = self::glance_point( $p->post_content, 'pro' );
		$fact = array();
		if ( $st ) {
			$fact[] = sprintf( "%d\xc2\xa0%% zufrieden (%s Bewertungen)", $st['pgood'], number_format_i18n( $st['all'] ) );
		}
		if ( (int) $deal['purchases'] > 0 ) {
			$fact[] = number_format_i18n( (int) $deal['purchases'] ) . '-mal gekauft';
		}
		$sel  = 'select' === $deal['listing_type'] ? ' <span class="adm-top__select" title="Von AppSumo geprüft und bewährt">' . self::icon( 'select' ) . 'AppSumo Select</span>' : '';
		$out  = '<div class="adm adm-top">' . self::icon( 'trophy' ) . '<div class="adm-top__body">';
		$out .= '<p class="adm-top__label">Top-Empfehlung</p>';
		$out .= '<p class="adm-top__name"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $deal['title'] ) . '</a>' . $sel . '</p>';
		$out .= $fact ? '<p class="adm-top__facts">' . esc_html( implode( ' · ', $fact ) ) . '</p>' : '';
		$out .= '' !== $pro ? '<p class="adm-top__pro"><strong>Gelobt:</strong> ' . esc_html( $pro ) . '</p>' : '';
		$out .= '' !== $list_url ? '<p class="adm-top__more"><a href="' . esc_url( $list_url ) . '">Alle ' . count( $items ) . ' Tools der Liste</a></p>' : '';
		return $out . '</div></div>';
	}

	/**
	 * [adm_best_top page="beste/seo-tools"] (Pfad oder ID der Seite mit der Bestenliste)
	 * Teaser zu Platz 1 dieser Liste, etwa unter einem Eintrag auf der Übersichtsseite.
	 */
	public static function best_top( $atts ) {
		$atts = shortcode_atts( array( 'page' => '' ), $atts, 'adm_best_top' );
		$ref  = trim( (string) $atts['page'] );
		$page = ctype_digit( $ref ) ? get_post( (int) $ref ) : get_page_by_path( trim( $ref, '/' ) );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return '';
		}
		$items = self::page_best_items( $page );
		return $items ? self::assets() . self::top_teaser( $items, get_permalink( $page ) ) : '';
	}

	/**
	 * [adm_bestenlisten parent="123"]
	 * Übersicht aller Bestenlisten: jede Unterseite mit [adm_bestenliste] bekommt Überschrift, ersten Absatz
	 * und den Teaser ihrer Top-Empfehlung. Die Auswahl steht nur auf der Unterseite.
	 */
	public static function bestenlisten( $atts ) {
		$atts   = shortcode_atts( array( 'parent' => 0 ), $atts, 'adm_bestenlisten' );
		$parent = (int) $atts['parent'] ? (int) $atts['parent'] : (int) get_the_ID();
		if ( ! $parent ) {
			return '';
		}
		$out = '';
		foreach ( get_pages( array( 'parent' => $parent, 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish' ) ) as $page ) {
			$items = self::page_best_items( $page );
			if ( ! $items ) {
				continue;
			}
			$url   = get_permalink( $page );
			$intro = preg_match( '#<p>(.*?)</p>#s', $page->post_content, $pm ) ? trim( wp_strip_all_tags( $pm[1] ) ) : '';
			$out  .= '<section class="adm-bests__sec"><h3><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $page ) ) . '</a></h3>';
			$out  .= '' !== $intro ? '<p>' . esc_html( $intro ) . '</p>' : '';
			$out  .= self::top_teaser( $items, $url ) . '</section>';
		}
		return '' === $out ? '' : self::assets() . ADM_Banners::html( 'list' ) . '<div class="adm-bests">' . $out . '</div>';
	}

}
