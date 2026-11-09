<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teilen in sozialen Netzwerken.
 *
 * 1. Vorschaubild: Rank Math gibt das Beitragsbild als og:image aus. Rubrikbilder sind WebP, das zeigt LinkedIn in der
 *    Link-Vorschau oft nicht an. Für WebP legt das Plugin einmalig eine JPG-Kopie neben die Datei und gibt diese aus
 *    (Meta _adm_og_jpg am Anhang). Filter adm_og_jpg = false schaltet das ab.
 * 2. Feed /tools/feed/: Dienste wie Missinglettr lesen den Feed und übernehmen das Vorschaubild. Ein frisches Profil
 *    erscheint dort erst, wenn sein Screenshot da ist (oder endgültig fehlgeschlagen ist), höchstens 24 Stunden später.
 *    Profile ohne Hersteller-Website warten nicht. Filter adm_feed_wait (Sekunden, 0 = aus).
 */
class ADM_Social {

	const META_JPG = '_adm_og_jpg';

	public static function init() {
		add_filter( 'rank_math/opengraph/facebook/image_array', array( __CLASS__, 'og_jpg' ) );
		add_filter( 'rank_math/opengraph/twitter/image_array', array( __CLASS__, 'og_jpg' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'feed_query' ) );
	}

	/* ---------------- Vorschaubild als JPG ---------------- */

	/** @param array $img Rank-Math-Bild (url, id, width, height, type, alt). */
	public static function og_jpg( $img ) {
		if ( ! is_array( $img ) || empty( $img['url'] ) || ! preg_match( '/\.webp(\?.*)?$/i', (string) $img['url'] ) || ! apply_filters( 'adm_og_jpg', true ) ) {
			return $img;
		}
		$id = ! empty( $img['id'] ) ? (int) $img['id'] : (int) attachment_url_to_postid( preg_replace( '/\?.*$/', '', (string) $img['url'] ) );
		if ( ! $id ) {
			return $img;
		}
		$jpg = self::jpg_copy( $id );
		if ( ! $jpg ) {
			return $img;
		}
		$img['url']    = $jpg['url'];
		$img['width']  = $jpg['width'];
		$img['height'] = $jpg['height'];
		$img['type']   = 'image/jpeg';
		return $img;
	}

	/**
	 * JPG-Kopie eines WebP-Anhangs (einmal erzeugt, danach aus der Meta).
	 *
	 * @return array{url:string,width:int,height:int}|null
	 */
	public static function jpg_copy( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file          = get_attached_file( $attachment_id );
		if ( ! $file || ! preg_match( '/\.webp$/i', $file ) || ! file_exists( $file ) ) {
			return null;
		}
		$target = preg_replace( '/\.webp$/i', '-og.jpg', $file );
		$saved  = get_post_meta( $attachment_id, self::META_JPG, true );
		if ( is_array( $saved ) && ! empty( $saved['file'] ) && $saved['file'] === basename( $target ) && file_exists( $target ) ) {
			return self::jpg_info( $target, $saved );
		}
		if ( ! file_exists( $target ) ) {
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				return null;
			}
			$size = $editor->get_size();
			if ( ! empty( $size['width'] ) && $size['width'] > 1200 ) {
				$editor->resize( 1200, null );
			}
			$editor->set_quality( 85 );
			$res = $editor->save( $target, 'image/jpeg' );
			if ( is_wp_error( $res ) || empty( $res['path'] ) ) {
				return null;
			}
			$target = $res['path'];
		}
		$dim  = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $target ) : getimagesize( $target );
		$meta = array(
			'file'   => basename( $target ),
			'width'  => $dim ? (int) $dim[0] : 0,
			'height' => $dim ? (int) $dim[1] : 0,
		);
		update_post_meta( $attachment_id, self::META_JPG, $meta );
		return self::jpg_info( $target, $meta );
	}

	private static function jpg_info( $path, array $meta ) {
		$up  = wp_get_upload_dir();
		$url = str_replace( wp_normalize_path( $up['basedir'] ), $up['baseurl'], wp_normalize_path( $path ) );
		return array(
			'url'    => $url,
			'width'  => (int) $meta['width'],
			'height' => (int) $meta['height'],
		);
	}

	/* ---------------- Feed wartet auf den Screenshot ---------------- */

	/** Sekunden, die ein neues Profil höchstens auf seinen Screenshot wartet, bevor es im Feed erscheint. */
	public static function feed_wait() {
		if ( ! ADM_Shots::enabled() ) {
			return 0;
		}
		return max( 0, (int) apply_filters( 'adm_feed_wait', DAY_IN_SECONDS ) );
	}

	/** @param WP_Query $q */
	public static function feed_query( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_feed() || ! self::feed_wait() ) {
			return;
		}
		$type = (array) $q->get( 'post_type' );
		if ( ! in_array( ADM_Profiles::CPT, $type, true ) ) {
			return;
		}
		add_filter( 'posts_where', array( __CLASS__, 'feed_where' ), 10, 2 );
	}

	/**
	 * Frische Profile mit Hersteller-Website, aber ohne Screenshot (und ohne endgültigen Fehlschlag) ausblenden.
	 *
	 * @param string   $where
	 * @param WP_Query $q
	 */
	public static function feed_where( $where, $q ) {
		if ( ! $q->is_main_query() || ! $q->is_feed() ) {
			return $where;
		}
		remove_filter( 'posts_where', array( __CLASS__, 'feed_where' ), 10 );
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::feed_wait() );
		$t     = ADM_DB::table();
		$where .= $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_date_gmt < %s
				OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sm WHERE sm.post_id = {$wpdb->posts}.ID AND sm.meta_key IN (%s, %s) AND sm.meta_value <> '' )
				OR NOT ( EXISTS ( SELECT 1 FROM {$t} sd WHERE sd.post_id = {$wpdb->posts}.ID AND sd.website <> '' )
					OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sw WHERE sw.post_id = {$wpdb->posts}.ID AND sw.meta_key = %s AND sw.meta_value <> '' ) ) )",
			$since,
			ADM_Shots::META,
			ADM_Shots::META_FAIL,
			ADM_Shots::META_SITE
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $where;
	}
}
