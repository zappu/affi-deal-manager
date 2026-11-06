<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quellen für Tool-Profile: Bewertungen bei AppSumo und Testvideos auf YouTube.
 * Alle Inhalte sind fremde Daten. Bewertungstexte werden nur für die Auswertung verwendet und nicht gespeichert.
 */
class ADM_Sources {

	/**
	 * Bewertungen eines Deals: die neuesten und die kritischsten als getrennte Gruppen.
	 *
	 * @param int $appsumo_id Deal-Nummer bei AppSumo (sku in den Produktdaten).
	 * @return array|WP_Error array( total, newest => [], critical => [] )
	 */
	public static function reviews( $appsumo_id ) {
		$appsumo_id = (int) $appsumo_id;
		if ( $appsumo_id <= 0 ) {
			return new WP_Error( 'adm_reviews_id', 'Deal-Nummer bei AppSumo unbekannt.' );
		}
		$out   = array( 'total' => 0, 'newest' => array(), 'critical' => array() );
		$seen  = array();
		$plans = array(
			'newest'   => array( 'sort' => 'created', 'order' => 'desc', 'n' => (int) ADM_Settings::get( 'reviews_newest' ) ),
			'critical' => array( 'sort' => 'rating', 'order' => 'asc', 'n' => (int) ADM_Settings::get( 'reviews_critical' ) ),
		);
		foreach ( $plans as $group => $p ) {
			if ( $p['n'] <= 0 ) {
				continue;
			}
			$url = sprintf( 'https://appsumo.com/api/v2/deals/%d/reviews/?page=1&sort=%s&order=%s&from=0&items_per_page=%d', $appsumo_id, $p['sort'], $p['order'], $p['n'] );
			$res = ADM_Sync::fetch( $url );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$json = json_decode( $res, true );
			if ( ! is_array( $json ) || ! isset( $json['comments'] ) || ! is_array( $json['comments'] ) ) {
				return new WP_Error( 'adm_reviews_format', 'Bewertungen bei AppSumo haben ein unbekanntes Format.' );
			}
			if ( isset( $json['meta']['total'] ) ) {
				$out['total'] = (int) $json['meta']['total'];
			}
			foreach ( $json['comments'] as $c ) {
				$id = isset( $c['id'] ) ? (int) $c['id'] : 0;
				if ( ! $id || isset( $seen[ $id ] ) || ! empty( $c['incentivized'] ) || ( isset( $c['level'] ) && (int) $c['level'] > 0 ) || ( isset( $c['approved'] ) && ! $c['approved'] ) ) {
					continue;
				}
				$text = self::clean( ( isset( $c['title'] ) ? $c['title'] . '. ' : '' ) . ( isset( $c['comment'] ) ? $c['comment'] : '' ), 600 );
				if ( strlen( $text ) < 20 ) {
					continue;
				}
				$seen[ $id ]     = true;
				$out[ $group ][] = array(
					'rating'    => isset( $c['rating'] ) ? (int) $c['rating'] : 0,
					'date'      => isset( $c['created'] ) ? substr( (string) $c['created'], 0, 10 ) : '',
					'purchased' => ! empty( $c['purchased'] ),
					'text'      => $text,
				);
			}
		}
		return $out;
	}

	/**
	 * Testvideos: mit YouTube-Schlüssel eigene Suche (deutsche Videos zuerst), sonst die Liste von der AppSumo-Seite.
	 *
	 * @param array $deal Deal aus der Tabelle.
	 * @param int   $max  Anzahl.
	 * @return array Liste aus id, title, description, channel, date, lang.
	 */
	public static function videos( array $deal, $max = 2 ) {
		$picked = array();
		$key    = ADM_Settings::youtube_key();
		$name   = '' !== $deal['name'] ? $deal['name'] : $deal['slug'];
		if ( '' !== $key ) {
			foreach ( array( array( $name . ' deutsch', 'de' ), array( $name . ' review', '' ) ) as $q ) {
				foreach ( self::youtube_search( $key, $q[0], $q[1] ) as $v ) {
					if ( count( $picked ) >= $max ) {
						break 2;
					}
					if ( isset( $picked[ $v['id'] ] ) || ! self::mentions( $v['title'] . ' ' . $v['description'], $name ) ) {
						continue;
					}
					$v['lang']          = $q[1];
					$picked[ $v['id'] ] = $v;
				}
			}
		}
		if ( count( $picked ) < $max ) {
			$list = array_filter( (array) $deal['videos'], function ( $v ) {
				return empty( $v['appsumo'] );
			} );
			usort( $list, function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			} );
			foreach ( $list as $v ) {
				$id = self::youtube_id( isset( $v['embed'] ) ? $v['embed'] : '' );
				if ( ! $id || isset( $picked[ $id ] ) ) {
					continue;
				}
				$picked[ $id ] = array(
					'id'          => $id,
					'title'       => (string) $v['name'],
					'description' => (string) $v['description'],
					'channel'     => '',
					'date'        => (string) $v['date'],
					'lang'        => '',
				);
				if ( count( $picked ) >= $max ) {
					break;
				}
			}
		}
		return array_values( $picked );
	}

	private static function youtube_search( $key, $query, $lang ) {
		$args = array(
			'part'            => 'snippet',
			'type'            => 'video',
			'videoEmbeddable' => 'true',
			'maxResults'      => 6,
			'q'               => $query,
			'key'             => $key,
		);
		if ( 'de' === $lang ) {
			$args['relevanceLanguage'] = 'de';
			$args['regionCode']        = 'DE';
		}
		$res = wp_safe_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.googleapis.com/youtube/v3/search' ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$out  = array();
		foreach ( isset( $json['items'] ) && is_array( $json['items'] ) ? $json['items'] : array() as $it ) {
			$id = isset( $it['id']['videoId'] ) ? (string) $it['id']['videoId'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
				continue;
			}
			$sn    = isset( $it['snippet'] ) ? $it['snippet'] : array();
			$out[] = array(
				'id'          => $id,
				'title'       => self::clean( isset( $sn['title'] ) ? $sn['title'] : '', 200 ),
				'description' => self::clean( isset( $sn['description'] ) ? $sn['description'] : '', 500 ),
				'channel'     => self::clean( isset( $sn['channelTitle'] ) ? $sn['channelTitle'] : '', 100 ),
				'date'        => isset( $sn['publishedAt'] ) ? substr( (string) $sn['publishedAt'], 0, 10 ) : '',
			);
		}
		return $out;
	}

	/** Video gehört zum Tool, wenn das erste Wort des Namens (ab 3 Zeichen) vorkommt. */
	private static function mentions( $text, $name ) {
		$words = preg_split( '/[^\\p{L}\\p{N}]+/u', strtolower( $name ), -1, PREG_SPLIT_NO_EMPTY );
		$first = '';
		foreach ( $words as $w ) {
			if ( strlen( $w ) >= 3 ) {
				$first = $w;
				break;
			}
		}
		return '' === $first || false !== strpos( strtolower( $text ), $first );
	}

	/** YouTube-ID aus einer Einbettungs- oder Seitenadresse. */
	public static function youtube_id( $url ) {
		if ( preg_match( '#(?:youtube(?:-nocookie)?\\.com/(?:embed/|watch\\?v=|shorts/)|youtu\\.be/)([A-Za-z0-9_-]{11})#', (string) $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private static function clean( $v, $max ) {
		$v = html_entity_decode( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$v = trim( preg_replace( '/\\s+/u', ' ', wp_strip_all_tags( $v ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}
}
