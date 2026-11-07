<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seiten-Cache leeren, wenn sich Live-Daten ändern.
 *
 * Preise, Status und Stufe kommen live aus der Tabelle, ein Seiten-Cache (FlyingPress) hält aber den alten Stand.
 * Ändert der Abgleich bei einem Deal mit Profil Preis, Status oder Stufe, werden das Profil, die Übersichten
 * /tools/ und /rubrik/<slug>/ und alle Seiten mit Bausteinen des Plugins (Startseite, Bestenlisten) geleert.
 * Dasselbe, wenn ein Profil veröffentlicht oder zurückgezogen wird.
 *
 * Unterstützt: FlyingPress (FlyingPress\Purge::purge_urls). Andere Caches über die Aktion adm_purge_urls.
 */
class ADM_Cache {

	/** @var int[] Profile, deren Seiten am Ende der Anfrage geleert werden. */
	private static $posts = array();

	/** @var bool Auch die Seiten mit Bausteinen leeren. */
	private static $lists = false;

	private static $hooked = false;

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/** Liefert der Abgleich neue Werte, die man auf den Seiten sieht? */
	public static function changed( array $row, array $data, $status ) {
		if ( $status !== $row['status'] ) {
			return true;
		}
		foreach ( array( 'price', 'currency', 'listing_type' ) as $k ) {
			if ( array_key_exists( $k, $data ) && null !== $data[ $k ] && (string) $data[ $k ] !== (string) $row[ $k ] ) {
				if ( 'price' === $k && '' !== (string) $row[ $k ] && (float) $data[ $k ] === (float) $row[ $k ] ) {
					continue;
				}
				return true;
			}
		}
		return false;
	}

	/** Profil zum Leeren vormerken (samt Übersichten und Seiten mit Bausteinen). */
	public static function mark( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		self::$posts[ $post_id ] = $post_id;
		self::$lists             = true;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/** Profil veröffentlicht oder zurückgezogen: Listen und Übersichten ändern sich. */
	public static function on_transition( $new, $old, $post ) {
		if ( $post instanceof WP_Post && ADM_Profiles::CPT === $post->post_type && $new !== $old && ( 'publish' === $new || 'publish' === $old ) ) {
			self::mark( $post->ID );
		}
	}

	/** Alle Adressen, die geleert werden müssen. */
	public static function urls() {
		$urls = array();
		foreach ( self::$posts as $pid ) {
			if ( 'publish' === get_post_status( $pid ) ) {
				$urls[] = get_permalink( $pid );
			}
			$terms = get_the_terms( $pid, ADM_Rubrics::TAX );
			foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
				$link = get_term_link( $t );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		if ( self::$lists ) {
			$archive = get_post_type_archive_link( ADM_Profiles::CPT );
			if ( $archive ) {
				$urls[] = $archive;
			}
			$urls = array_merge( $urls, self::block_pages() );
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/** Veröffentlichte Seiten mit Bausteinen des Plugins, dazu die Startseite. */
	public static function block_pages() {
		global $wpdb;
		$ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( post_content LIKE '%[adm\\_%' OR post_content LIKE '%adm-home%' ) LIMIT 200" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$urls = array( home_url( '/' ) );
		foreach ( (array) $ids as $id ) {
			$urls[] = get_permalink( (int) $id );
		}
		return $urls;
	}

	/** Vorgemerkte Seiten leeren. @return int Zahl der Adressen. */
	public static function flush() {
		if ( ! self::$posts && ! self::$lists ) {
			return 0;
		}
		$urls        = (array) apply_filters( 'adm_purge_urls', self::urls() );
		self::$posts = array();
		self::$lists = false;
		if ( ! $urls ) {
			return 0;
		}
		if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_urls' ) ) {
			try {
				\FlyingPress\Purge::purge_urls( $urls );
			} catch ( \Throwable $e ) {
				// Cache-Probleme dürfen den Abgleich nicht stören.
				unset( $e );
			}
		}
		do_action( 'adm_purged_urls', $urls );
		update_option( 'adm_last_purge', array( 'time' => time(), 'count' => count( $urls ) ), false );
		return count( $urls );
	}
}
