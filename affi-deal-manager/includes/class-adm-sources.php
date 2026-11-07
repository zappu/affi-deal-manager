<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quellen für Tool-Profile: Bewertungen bei AppSumo und Testvideos auf YouTube.
 * Alle Inhalte sind fremde Daten. Bewertungstexte werden nur für die Auswertung verwendet und nicht gespeichert.
 */
class ADM_Sources {

	/** Letzter Fehler der YouTube-Suche (z. B. Kontingent erschöpft), für Hinweise im Protokoll. */
	public static $youtube_error = '';

	/** Videos bis zu dieser Länge lässt das Plugin von Gemini zusammenfassen. */
	const MAX_SUMMARY_SECONDS = 1500;

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
	 * Videos zum Tool: mit YouTube-Schlüssel eigene Suche, dazu die Liste von der AppSumo-Seite (nie das AppSumo-eigene Video).
	 * Kandidaten werden bewertet: Tests, Anleitungen und deutsche Videos zuerst, Werbevideos (AppSumo-Rabatt, viele Links,
	 * sehr kurz) nach hinten. Mit Gemini-Schlüssel bekommt jedes gewählte Video eine Zusammenfassung seines Inhalts.
	 *
	 * @param array $deal Deal aus der Tabelle.
	 * @param int   $max  Anzahl.
	 * @return array Liste aus id, title, description, channel, date, lang, duration, promo, summary.
	 */
	public static function videos( array $deal, $max = 2 ) {
		$cands = array();
		$key   = ADM_Settings::youtube_key();
		$name  = '' !== $deal['name'] ? ( isset( $deal['title'] ) ? $deal['title'] : ADM_DB::display_name( $deal ) ) : $deal['slug'];
		if ( '' !== $key ) {
			foreach ( array( array( $name . ' deutsch', 'de' ), array( $name . ' review', '' ) ) as $q ) {
				foreach ( self::youtube_search( $key, $q[0], $q[1] ) as $v ) {
					if ( ! isset( $cands[ $v['id'] ] ) ) {
						$v['query_de']      = 'de' === $q[1];
						$cands[ $v['id'] ] = $v;
					}
				}
			}
		}
		foreach ( (array) $deal['videos'] as $v ) {
			if ( ! empty( $v['appsumo'] ) ) {
				continue;
			}
			$id = self::youtube_id( isset( $v['embed'] ) ? $v['embed'] : '' );
			if ( ! $id || isset( $cands[ $id ] ) ) {
				continue;
			}
			$cands[ $id ] = array(
				'id'          => $id,
				'title'       => self::clean( isset( $v['name'] ) ? $v['name'] : '', 200 ),
				'description' => self::clean( isset( $v['description'] ) ? $v['description'] : '', 2000 ),
				'channel'     => '',
				'date'        => isset( $v['date'] ) ? substr( (string) $v['date'], 0, 10 ) : '',
				'query_de'    => false,
				'from_appsumo_list' => true,
			);
		}
		if ( '' !== $key && $cands ) {
			foreach ( self::youtube_details( $key, array_keys( $cands ) ) as $id => $d ) {
				$cands[ $id ] = array_merge( $cands[ $id ], array_filter( $d, function ( $x ) {
					return '' !== $x && null !== $x;
				} ) );
			}
		}
		$list = array();
		foreach ( $cands as $v ) {
			$v = array_merge( array( 'lang' => '', 'duration' => 0, 'channel' => '', 'date' => '', 'description' => '' ), $v );
			// Videos aus der eigenen Suche müssen das Tool nennen; die AppSumo-Liste gehört ohnehin zum Deal.
			if ( empty( $v['from_appsumo_list'] ) && ! self::mentions( $v['title'] . ' ' . $v['description'], $name, ADM_Shots::website( $deal ) ) ) {
				continue;
			}
			if ( $v['duration'] && $v['duration'] < 60 ) {
				continue; // Shorts
			}
			if ( '' === $v['lang'] && ! empty( $v['query_de'] ) && preg_match( '/\b(deutsch|german|anleitung|erfahrung|test)\b/iu', $v['title'] ) ) {
				$v['lang'] = 'de';
			}
			list( $v['score'], $v['promo'] ) = self::score( $v );
			$list[] = $v;
		}
		usort( $list, function ( $a, $b ) {
			return $b['score'] <=> $a['score'] ?: strcmp( (string) $b['date'], (string) $a['date'] );
		} );
		$picked  = array();
		$timeout = false;
		foreach ( array_slice( $list, 0, $max ) as $v ) {
			$v['summary'] = '';
			// Lange Videos dauern bei Gemini zu lange; nach einer Zeitüberschreitung keine weitere Anfrage im selben Durchlauf.
			if ( ! $timeout && ADM_AI::video_configured() && ( ! $v['duration'] || $v['duration'] <= self::MAX_SUMMARY_SECONDS ) ) {
				$sum = ADM_AI::video_summary( $v['id'], $name );
				if ( is_wp_error( $sum ) && false !== stripos( $sum->get_error_message(), 'timed out' ) ) {
					$timeout = true;
				}
				if ( ! is_wp_error( $sum ) ) {
					$v['summary'] = $sum['text'];
					$v['promo']   = $v['promo'] || $sum['promo'];
					if ( '' === $v['lang'] && '' !== $sum['lang'] ) {
						$v['lang'] = $sum['lang'];
					}
				} else {
					$v['summary_error'] = $sum->get_error_message();
				}
			}
			unset( $v['query_de'], $v['from_appsumo_list'], $v['score'] );
			$v['description'] = self::clean( $v['description'], 500 );
			$picked[]         = $v;
		}
		return $picked;
	}

	/**
	 * Punkte für die Reihenfolge und ob es nach Werbevideo aussieht.
	 *
	 * @return array array( Punkte, Werbung )
	 */
	public static function score( array $v ) {
		$title = mb_strtolower( (string) $v['title'] );
		$desc  = (string) $v['description'];
		$links = preg_match_all( '#https?://#i', $desc );
		$score = 0;
		$promo = false;
		if ( 'de' === substr( (string) $v['lang'], 0, 2 ) ) {
			$score += 3;
		}
		if ( preg_match( '/\b(review|test|tutorial|anleitung|erfahrung|walkthrough|demo|how to|vs\.?|vergleich|einrichten|setup)\b/u', $title ) ) {
			$score += 2;
		}
		if ( preg_match( '/\b(appsumo|lifetime deal|ltd|coupon|discount|rabatt|gutschein|promo code|black friday)\b/u', $title ) ) {
			$score -= 2;
			$promo  = true;
		}
		if ( $links >= 4 ) {
			$score -= 2;
			$promo  = true;
		}
		$dur = (int) $v['duration'];
		if ( $dur ) {
			if ( $dur < 120 ) {
				$score -= 2;
			} elseif ( $dur >= 180 && $dur <= 40 * MINUTE_IN_SECONDS ) {
				$score += 1;
			}
		}
		$ts = $v['date'] ? strtotime( $v['date'] ) : 0;
		if ( $ts && $ts > time() - 540 * DAY_IN_SECONDS ) {
			$score += 1;
		}
		return array( $score, $promo );
	}

	/** Dauer, Sprache und volle Beschreibung über videos.list (1 Einheit je Aufruf statt 100 für die Suche). */
	private static function youtube_details( $key, array $ids ) {
		$ids = array_slice( array_values( array_filter( $ids, function ( $id ) {
			return (bool) preg_match( '/^[A-Za-z0-9_-]{11}$/', $id );
		} ) ), 0, 50 );
		if ( ! $ids ) {
			return array();
		}
		$url = add_query_arg( array(
			'part' => 'snippet,contentDetails',
			'id'   => implode( ',', $ids ),
			'key'  => rawurlencode( $key ),
		), 'https://www.googleapis.com/youtube/v3/videos' );
		$res = wp_safe_remote_get( $url, self::youtube_args() );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$out  = array();
		foreach ( isset( $json['items'] ) && is_array( $json['items'] ) ? $json['items'] : array() as $it ) {
			$id = isset( $it['id'] ) ? (string) $it['id'] : '';
			if ( ! in_array( $id, $ids, true ) ) {
				continue;
			}
			$sn   = isset( $it['snippet'] ) ? $it['snippet'] : array();
			$lang = isset( $sn['defaultAudioLanguage'] ) ? (string) $sn['defaultAudioLanguage'] : ( isset( $sn['defaultLanguage'] ) ? (string) $sn['defaultLanguage'] : '' );
			$out[ $id ] = array(
				'title'       => self::clean( isset( $sn['title'] ) ? $sn['title'] : '', 200 ),
				'description' => self::clean( isset( $sn['description'] ) ? $sn['description'] : '', 2000 ),
				'channel'     => self::clean( isset( $sn['channelTitle'] ) ? $sn['channelTitle'] : '', 100 ),
				'date'        => isset( $sn['publishedAt'] ) ? substr( (string) $sn['publishedAt'], 0, 10 ) : '',
				'lang'        => strtolower( substr( $lang, 0, 5 ) ),
				'duration'    => self::iso_duration( isset( $it['contentDetails']['duration'] ) ? $it['contentDetails']['duration'] : '' ),
			);
		}
		return $out;
	}

	/**
	 * Anfrage-Optionen für die YouTube-API. Der Referer ist die Adresse der eigenen Website, damit Schlüssel mit
	 * Website-Einschränkung („HTTP-Verweis-URLs“, z. B. software-ohne-abo.de/*) auch bei Abrufen vom Server gelten.
	 */
	private static function youtube_args() {
		return array(
			'timeout' => 15,
			'headers' => array( 'Referer' => (string) apply_filters( 'adm_youtube_referer', home_url( '/' ) ) ),
		);
	}

	/** ISO-8601-Dauer (PT1H2M3S) in Sekunden. */
	public static function iso_duration( $v ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', (string) $v, $m ) ) {
			return 0;
		}
		$m = array_pad( $m, 5, 0 );
		return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
	}

	/** Sprachname für die Anzeige. */
	public static function lang_label( $lang ) {
		$names = array( 'de' => 'Deutsch', 'en' => 'Englisch', 'es' => 'Spanisch', 'fr' => 'Französisch', 'it' => 'Italienisch', 'pt' => 'Portugiesisch', 'nl' => 'Niederländisch', 'hi' => 'Hindi' );
		$lang  = substr( strtolower( (string) $lang ), 0, 2 );
		return isset( $names[ $lang ] ) ? $names[ $lang ] : '';
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
		$res = wp_safe_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.googleapis.com/youtube/v3/search' ), self::youtube_args() );
		if ( is_wp_error( $res ) ) {
			self::$youtube_error = $res->get_error_message();
			return array();
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$err = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$why = isset( $err['error']['errors'][0]['reason'] ) ? (string) $err['error']['errors'][0]['reason'] : '';
			self::$youtube_error = 'quotaExceeded' === $why || 'dailyLimitExceeded' === $why
				? 'Tageskontingent der YouTube-API erschöpft (neu ab 9 Uhr)'
				: 'YouTube-Suche HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . ( $why ? ' (' . $why . ')' : '' ) . ( isset( $err['error']['message'] ) ? ': ' . wp_strip_all_tags( substr( (string) $err['error']['message'], 0, 140 ) ) : '' );
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
	/** Allgemeine Wörter, die als erstes Wort eines Namens nicht reichen (sonst passt „SEO Generator“ auf jedes SEO-Video). */
	const GENERIC_WORDS = array( 'the', 'seo', 'app', 'apps', 'web', 'site', 'page', 'pages', 'link', 'links', 'lead', 'leads', 'flow', 'email', 'mail', 'video', 'videos', 'chat', 'bot', 'smart', 'social', 'content', 'writer', 'write', 'book', 'books', 'form', 'forms', 'data', 'cloud', 'auto', 'quick', 'easy', 'get', 'pro', 'super', 'one', 'all', 'best', 'free', 'meta', 'blog', 'shop', 'sales', 'ads', 'image', 'photo', 'design', 'task', 'tasks', 'team', 'office', 'docs', 'note', 'notes', 'code', 'api', 'generator', 'review', 'reviews', 'tool', 'tools', 'my', 'go', 'ai' );

	/**
	 * Erwähnt der Text das Tool? Ein unverwechselbares erstes Wort reicht („Nuwtonic“),
	 * bei allgemeinen Wörtern muss der ganze Name vorkommen, auch zusammengeschrieben („SEO Generator“, „seogenerator“),
	 * und zusätzlich die Domain des Herstellers, AppSumo oder „Lifetime Deal“ (sonst passt „Flow AI“ auf Videos zu Google Flow).
	 */
	public static function mentions( $text, $name, $site = '' ) {
		$words = preg_split( '/[^\\p{L}\\p{N}]+/u', strtolower( $name ), -1, PREG_SPLIT_NO_EMPTY );
		$text  = strtolower( (string) $text );
		$first = '';
		foreach ( $words as $w ) {
			if ( strlen( $w ) >= 3 ) {
				$first = $w;
				break;
			}
		}
		if ( '' === $first ) {
			return true;
		}
		if ( ! in_array( $first, self::GENERIC_WORDS, true ) ) {
			return false !== strpos( $text, $first );
		}
		$flat = implode( ' ', preg_split( '/[^\\p{L}\\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
		$name = implode( ' ', $words );
		if ( false === strpos( ' ' . $flat . ' ', ' ' . $name . ' ' ) && false === strpos( str_replace( ' ', '', $flat ), str_replace( ' ', '', $name ) ) ) {
			return false;
		}
		$stem = preg_replace( '/\\.[a-z]{2,}$/', '', ADM_Shots::host( $site ) );
		if ( '' !== $stem && strlen( $stem ) >= 4 && false !== strpos( $text, $stem ) ) {
			return true;
		}
		return (bool) preg_match( '/appsumo|lifetime ?deal|\\bltd\\b/', $text );
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
