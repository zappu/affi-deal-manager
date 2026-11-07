<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Übersichtsseiten im Stil der Profile: /tools/ (alle Tool-Profile) und /rubrik/<slug>/ (eine Rubrik).
 *
 * Aktiv mit dem Profil-Layout (Einstellung „Deal-Kasten“ = Profil-Layout). Gibt es in Elementor Pro eine
 * Archiv-Vorlage, die auf die Seite passt, hat diese Vorrang. Abschalten per Filter adm_archive_layout.
 * Sortierung: kaufbare zuerst, dann nach Zahl der Bewertungen bei AppSumo.
 */
class ADM_Archive {

	const PER_PAGE = 24;

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'pre_get_posts', array( __CLASS__, 'query' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 10, 2 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		// Seitentitel und Beschreibung: Rank Math kennt den Beitragstyp oft nicht und zeigt „Tool-Profile Archive“.
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'meta_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'meta_description' ), 20 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'meta_title' ), 20 );
		// Vorschaubild beim Teilen einer Rubrik: das Rubrikbild.
		add_action( 'rank_math/opengraph/facebook/add_additional_images', array( __CLASS__, 'og_image' ) );
		add_action( 'rank_math/opengraph/twitter/add_additional_images', array( __CLASS__, 'og_image' ) );
	}

	/** @param object $image Rank-Math-Bildsammlung (RankMath\OpenGraph\Image). */
	public static function og_image( $image ) {
		$term = self::current_term();
		if ( ! $term || ! is_object( $image ) || ! method_exists( $image, 'add_image_by_id' ) ) {
			return;
		}
		$id = ADM_Rubrics::image_id( $term->term_id );
		if ( $id ) {
			$image->add_image_by_id( $id );
		}
	}

	/** Überschrift (H1) und Einleitung einer Übersicht. */
	public static function heading( $term = null ) {
		if ( $term ) {
			$h1   = $term->name . ': Software ohne Abo';
			$lead = '' !== trim( (string) $term->description ) ? $term->description : 'Tool-Profile aus der Rubrik ' . $term->name . ': Software, die du bei AppSumo einmal bezahlst statt monatlich, ausgewertet aus Käuferbewertungen und Videos.';
		} else {
			$h1   = 'Software ohne Abo: alle Tool-Profile';
			$lead = 'Tools, die du bei AppSumo einmal bezahlst statt monatlich. Jedes Profil wertet die Käuferbewertungen bei AppSumo und Videos zum Tool aus.';
		}
		return array(
			(string) apply_filters( 'adm_archive_title', $h1, $term ),
			wp_strip_all_tags( (string) apply_filters( 'adm_archive_lead', $lead, $term ) ),
		);
	}

	private static function current_term() {
		return is_tax( ADM_Rubrics::TAX ) ? get_queried_object() : null;
	}

	/**
	 * Titel im Browser und in den Suchergebnissen.
	 * Übersicht: „Software ohne Abo: alle Tool-Profile“, Rubrik: „SEO: Software ohne Abo kaufen“, ab Seite 2 mit Seitenzahl.
	 */
	public static function meta_title( $title ) {
		if ( is_admin() || ! self::is_overview() ) {
			return $title;
		}
		$term  = self::current_term();
		$new   = $term ? $term->name . ': Software ohne Abo kaufen' : self::heading( null )[0];
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$new .= ' - Seite ' . $paged;
		}
		return (string) apply_filters( 'adm_archive_meta_title', $new, $term );
	}

	public static function meta_description( $desc ) {
		if ( is_admin() || ! self::is_overview() ) {
			return $desc;
		}
		$lead = self::heading( self::current_term() )[1];
		return function_exists( 'mb_substr' ) && mb_strlen( $lead ) > 160 ? rtrim( mb_substr( $lead, 0, 157 ) ) . '…' : $lead;
	}

	/** Eine Übersicht der Tool-Profile (ohne Prüfung der Einstellung). */
	public static function is_overview() {
		return is_post_type_archive( ADM_Profiles::CPT ) || is_tax( ADM_Rubrics::TAX );
	}

	/** Plugin-Layout für diese Übersicht aktiv. */
	public static function active() {
		if ( is_admin() || ! self::is_overview() || 'sidebar' !== ADM_Settings::get( 'box_placement' ) ) {
			return false;
		}
		if ( function_exists( 'elementor_location_exits' ) && elementor_location_exits( 'archive', true ) ) {
			return false;
		}
		return (bool) apply_filters( 'adm_archive_layout', true );
	}

	public static function template( $template ) {
		return self::active() ? ADM_DIR . 'templates/archive.php' : $template;
	}

	public static function query( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! ( $q->is_post_type_archive( ADM_Profiles::CPT ) || $q->is_tax( ADM_Rubrics::TAX ) ) ) {
			return;
		}
		$q->set( 'post_type', ADM_Profiles::CPT );
		$q->set( 'posts_per_page', self::PER_PAGE );
		$q->set( 'adm_sort', 1 );
	}

	/** Kaufbare zuerst, dann meiste Bewertungen, dann Titel. */
	public static function clauses( $clauses, $q ) {
		if ( ! $q->get( 'adm_sort' ) ) {
			return $clauses;
		}
		global $wpdb;
		$t                   = ADM_DB::table();
		$clauses['join']    .= " LEFT JOIN {$t} adm_d ON adm_d.post_id = {$wpdb->posts}.ID";
		$clauses['orderby']  = "(adm_d.status = 'kaufbar') DESC, adm_d.review_count DESC, {$wpdb->posts}.post_title ASC";
		$clauses['groupby']  = "{$wpdb->posts}.ID";
		return $clauses;
	}

	public static function body_class( $classes ) {
		if ( self::active() ) {
			$classes[] = 'adm-layout';
			$classes[] = 'adm-overview';
		}
		return $classes;
	}

	/** Leere Übersichten nicht in den Suchindex. */
	public static function robots( $robots ) {
		if ( self::is_overview() && ! have_posts() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	public static function assets() {
		if ( self::active() ) {
			wp_enqueue_style( 'adm-frontend' );
		}
	}

	/* ---------------- Ausgabe ---------------- */

	/** Ganze Übersicht für die Vorlage templates/archive.php. */
	public static function render() {
		global $wp_query;
		$term  = is_tax( ADM_Rubrics::TAX ) ? get_queried_object() : null;
		$all   = get_post_type_archive_link( ADM_Profiles::CPT );
		$total = (int) $wp_query->found_posts;

		$crumb = '<nav class="adm-crumbs" aria-label="Brotkrumen"><a href="' . esc_url( home_url( '/' ) ) . '">Start</a> / ';
		$crumb .= $term ? '<a href="' . esc_url( $all ) . '">Tools</a> / ' . esc_html( $term->name ) : 'Tools';
		$crumb .= '</nav>';

		list( $h1, $lead ) = self::heading( $term );
		$img = $term ? ADM_Rubrics::image_html( $term->term_id, 'medium' ) : '';

		$out  = '<main id="content" class="site-main adm adm-page adm-overview">';
		$out .= '<header class="adm-head">' . $crumb . '<div class="adm-head__card">';
		$out .= $img ? '<div class="adm-head__img">' . $img . '</div>' : '';
		$out .= '<div class="adm-head__body"><h1>' . esc_html( $h1 ) . '</h1>';
		$out .= '<p class="adm-head__lead">' . esc_html( wp_strip_all_tags( $lead ) ) . '</p>';
		$out .= $total ? '<div class="adm-chips"><span class="adm-chip">' . esc_html( 1 === $total ? '1 Tool-Profil' : number_format_i18n( $total ) . ' Tool-Profile' ) . '</span></div>' : '';
		$out .= '</div></div></header>';

		$out .= self::rubric_nav( $term );

		if ( have_posts() ) {
			$out .= '<div class="adm-cards">';
			while ( have_posts() ) {
				the_post();
				$out .= self::card( get_post(), ! $term );
			}
			wp_reset_postdata();
			$out .= '</div>';
			$pages = paginate_links( array(
				'type'      => 'array',
				'prev_text' => 'Zurück',
				'next_text' => 'Weiter',
			) );
			if ( $pages ) {
				$out .= '<nav class="adm-pages" aria-label="Seiten">' . implode( '', $pages ) . '</nav>';
			}
		} else {
			$out .= '<section class="adm-sec adm-empty"><h2>Noch keine Tool-Profile</h2><p>'
				. ( $term ? 'In dieser Rubrik ist noch kein Profil veröffentlicht. <a href="' . esc_url( $all ) . '">Alle Tool-Profile ansehen</a>' : 'Die ersten Profile erscheinen hier, sobald sie veröffentlicht sind.' )
				. '</p></section>';
		}
		return $out . '</main>';
	}

	/** Rubriken mit veröffentlichten Profilen als Leiste, aktuelle markiert. */
	private static function rubric_nav( $current ) {
		$terms = get_terms( array(
			'taxonomy'   => ADM_Rubrics::TAX,
			'hide_empty' => true,
			'orderby'    => 'name',
		) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$links = array( '<a href="' . esc_url( get_post_type_archive_link( ADM_Profiles::CPT ) ) . '"' . ( $current ? '' : ' aria-current="page"' ) . '>Alle</a>' );
		foreach ( $terms as $t ) {
			$is     = $current && (int) $current->term_id === (int) $t->term_id;
			$links[] = '<a href="' . esc_url( get_term_link( $t ) ) . '"' . ( $is ? ' aria-current="page"' : '' ) . '>' . esc_html( $t->name ) . ' <span>' . (int) $t->count . '</span></a>';
		}
		return '<nav class="adm-toc adm-rubnav" aria-label="Rubriken">' . implode( '', $links ) . '</nav>';
	}

	/** Karte eines Profils mit Live-Daten aus der Deal-Tabelle. */
	public static function card( $post, $show_rubric = true ) {
		$deal = ADM_DB::get_by_post( $post->ID );
		$name = $deal ? $deal['title'] : get_the_title( $post );
		$lead = has_excerpt( $post ) ? get_the_excerpt( $post ) : ( $deal ? $deal['description'] : '' );

		$chips = array();
		if ( $deal ) {
			if ( 'kaufbar' === $deal['status'] ) {
				$price = ADM_Shortcodes::price( $deal );
				if ( '' !== $price ) {
					$chips[] = '<span class="adm-chip adm-chip--price">' . esc_html( $price ) . '</span>';
				}
			} else {
				$chips[] = '<span class="adm-chip adm-chip--off">' . esc_html( 'ausgelaufen' === $deal['status'] ? 'Ausgelaufen' : 'Nicht verfügbar' ) . '</span>';
			}
			if ( null !== $deal['rating'] && '' !== (string) $deal['rating'] && (int) $deal['review_count'] ) {
				$full    = number_format_i18n( (float) $deal['rating'], 1 ) . ' von 5 Sternen bei ' . number_format_i18n( (int) $deal['review_count'] ) . ' Bewertungen auf AppSumo';
				$chips[] = '<span class="adm-chip" title="' . esc_attr( $full ) . '"><span aria-hidden="true">★ ' . esc_html( number_format_i18n( (float) $deal['rating'], 1 ) . ' (' . number_format_i18n( (int) $deal['review_count'] ) . ')' ) . '</span><span class="screen-reader-text">' . esc_html( $full ) . '</span></span>';
			}
		}
		$rubric = '';
		if ( $show_rubric ) {
			$terms = get_the_terms( $post->ID, ADM_Rubrics::TAX );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$rubric = '<p class="adm-card__rubric">' . esc_html( $terms[0]->name ) . '</p>';
			}
		}

		$out  = '<article class="adm-card">';
		$out .= $rubric;
		$out .= '<h2 class="adm-card__name"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a></h2>';
		$out .= '' !== trim( (string) $lead ) ? '<p class="adm-card__lead">' . esc_html( wp_trim_words( wp_strip_all_tags( $lead ), 22 ) ) . '</p>' : '';
		$out .= $chips ? '<div class="adm-chips">' . implode( '', $chips ) . '</div>' : '';
		$out .= '</article>';
		return $out;
	}
}
