<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artikel mit Deals (Ratgeber, Vergleiche): Seiten und Beiträge, die einen Deal über [adm_deal_box deal="…"]
 * oder [adm_link deal="…"] einbinden.
 *
 * - Läuft ein solcher Deal aus, steht über dem Artikel ein Hinweis mit Datum und einem Link zu kaufbaren Alternativen.
 * - Beim Auslaufen geht eine E-Mail an die Störungsadresse mit den betroffenen Artikeln.
 * - Ziel des Buttons „Kaufbare Alternativen ansehen“ außerhalb des eigenen Profils: das Profil, Abschnitt Alternativen.
 *
 * Das Datum des Auslaufens steht in der Option adm_deal_ended (Deal-ID => Zeitstempel).
 */
class ADM_Articles {

	const ENDED = 'adm_deal_ended';

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'notice' ), 8 );
		add_action( 'adm_deal_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 3 );
	}

	/** Slugs der Deals, die ein Inhalt per Shortcode einbindet. */
	public static function deal_slugs( $content ) {
		if ( false === strpos( (string) $content, '[adm_' ) ) {
			return array();
		}
		preg_match_all( '/\[adm_(?:deal_box|link|deal_header)\b[^\]]*?\bdeal\s*=\s*["\']([a-z0-9-]+)["\']/i', (string) $content, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	}

	/** Seit wann ist der Deal ausgelaufen? Zeitstempel oder 0, wenn unbekannt. */
	public static function ended_at( $deal_id ) {
		$all = (array) get_option( self::ENDED, array() );
		return isset( $all[ (int) $deal_id ] ) ? (int) $all[ (int) $deal_id ] : 0;
	}

	/**
	 * Wo stehen kaufbare Alternativen zu einem Deal? Profil mit Abschnitt Alternativen, sonst die Rubrik, sonst leer.
	 */
	public static function alternatives_url( array $deal ) {
		$pid = (int) $deal['post_id'];
		if ( $pid && is_singular( ADM_Profiles::CPT ) && (int) get_queried_object_id() === $pid ) {
			return '#alternativen';
		}
		if ( $pid && 'publish' === get_post_status( $pid ) && ADM_Shortcodes::find_alternatives( $deal, 1 ) ) {
			return get_permalink( $pid ) . '#alternativen';
		}
		$slug = ADM_Rubrics::for_deal( $deal );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, ADM_Rubrics::TAX ) : null;
		if ( $term && ! is_wp_error( $term ) && $term->count ) {
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : $link;
		}
		return '';
	}

	/** Hinweis über Artikeln, deren Deal ausgelaufen ist (nicht in Tool-Profilen, die haben den Deal-Kasten). */
	public static function notice( $content ) {
		if ( ! is_singular() || is_singular( ADM_Profiles::CPT ) || ! in_the_loop() || ! is_main_query() || doing_action( 'wp_head' ) ) {
			return $content;
		}
		if ( false !== strpos( (string) $content, 'adm-ended' ) ) {
			return $content;
		}
		$boxes = '';
		foreach ( self::deal_slugs( $content ) as $slug ) {
			$deal = ADM_DB::get_by_slug( $slug );
			if ( ! $deal || 'ausgelaufen' !== $deal['status'] ) {
				continue;
			}
			$boxes .= self::notice_html( $deal );
		}
		if ( '' === $boxes ) {
			return $content;
		}
		ADM_Shortcodes::enqueue_assets();
		return $boxes . $content;
	}

	public static function notice_html( array $deal ) {
		$name  = '' !== (string) $deal['title'] ? $deal['title'] : $deal['name'];
		$when  = self::ended_at( (int) $deal['id'] );
		$text  = sprintf( '%s ist bei AppSumo derzeit nicht mehr als Lifetime-Deal erhältlich.', $name );
		$text .= $when
			? sprintf( ' Preise und Angaben zum Deal im Text galten bis %s.', wp_date( 'j. F Y', $when ) )
			: ' Preise und Angaben zum Deal im Text beziehen sich auf die Zeit, als er erhältlich war.';
		$alt   = self::alternatives_url( $deal );
		$out   = '<aside class="adm adm-ended" role="note"><p class="adm-ended__title"><strong>Angebot ausgelaufen</strong></p><p>' . esc_html( $text ) . '</p>';
		$out  .= '' !== $alt ? '<p><a class="adm-ended__link" href="' . esc_url( $alt ) . '">Kaufbare Alternativen ansehen</a></p>' : '';
		return $out . '</aside>';
	}

	/* ---------------- Statuswechsel ---------------- */

	public static function on_status_changed( $id, $old, $new ) {
		$all = (array) get_option( self::ENDED, array() );
		if ( 'ausgelaufen' === $new ) {
			if ( empty( $all[ (int) $id ] ) ) {
				$all[ (int) $id ] = time();
				update_option( self::ENDED, $all, false );
			}
			if ( 'kaufbar' === $old ) {
				self::notify( (int) $id );
			}
		} elseif ( 'kaufbar' === $new && isset( $all[ (int) $id ] ) ) {
			unset( $all[ (int) $id ] );
			update_option( self::ENDED, $all, false );
		}
	}

	/** Veröffentlichte Seiten und Beiträge, die den Deal einbinden. */
	public static function articles_for( $slug ) {
		global $wpdb;
		$like = '%deal=_' . $wpdb->esc_like( $slug ) . '_%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s",
			$like
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( in_array( $slug, self::deal_slugs( $r->post_content ), true ) ) {
				$out[] = (int) $r->ID;
			}
		}
		return $out;
	}

	private static function notify( $deal_id ) {
		$deal = ADM_DB::get( $deal_id );
		if ( ! $deal ) {
			return;
		}
		$ids = self::articles_for( $deal['slug'] );
		$to  = ADM_Settings::get( 'notify_email' );
		if ( ! $ids || ! is_email( $to ) ) {
			return;
		}
		$name  = '' !== (string) $deal['title'] ? $deal['title'] : $deal['name'];
		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		$body  = $name . " ist bei AppSumo ausgelaufen. Diese Artikel binden den Deal ein:\n\n";
		foreach ( $ids as $pid ) {
			$body .= '• ' . get_the_title( $pid ) . "\n  " . get_permalink( $pid ) . "\n  Bearbeiten: " . admin_url( 'post.php?post=' . $pid . '&action=edit' ) . "\n";
		}
		$body .= "\nÜber den Artikeln steht jetzt automatisch ein Hinweis, dass das Angebot ausgelaufen ist. Preise und Rechenbeispiele im Text bleiben, wie sie sind. Du kannst den Artikel überarbeiten, auf Entwurf setzen oder so lassen.\n";
		wp_mail( $to, '[' . $site . '] Deal ausgelaufen: ' . $name, $body );
	}
}
