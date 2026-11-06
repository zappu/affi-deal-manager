<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Liest die schema.org-Daten (JSON-LD) einer AppSumo-Produktseite und die Sitemap.
 * Alle Inhalte stammen von einer fremden Seite und werden nur als Daten behandelt.
 */
class ADM_Parser {

	/**
	 * AppSumo setzt availabilityEnds bei laufenden Deals auf etwa ein Jahr ab Abruf.
	 * Enddaten weiter als diese Tage in der Zukunft gelten als Platzhalter.
	 */
	const PLACEHOLDER_DAYS = 300;

	/** Stichwörter für Lern-Deals (Name zählt vor Beschreibung). */
	const EBOOK_WORDS = array( 'ebook', 'e-book', 'book', 'buch', 'playbook', 'guide', 'chapter', 'checklist', 'handbook', 'workbook', 'pdf', 'swipe file', 'cheat sheet', 'report', 'manual', 'blueprint' );
	const COURSE_WORDS = array( 'course', 'kurs', 'masterclass', 'master class', 'bootcamp', 'academy', 'workshop', 'training', 'class', 'lessons', 'webinar', 'summit', 'event', 'program', 'challenge', 'coaching' );

	/**
	 * @param string $xml Inhalt der sitemap.xml.
	 * @return array Liste aus array( url, lastmod ) für Produktseiten.
	 */
	public static function sitemap( $xml ) {
		$out = array();
		if ( ! preg_match_all( '#<url>(.*?)</url>#si', (string) $xml, $m ) ) {
			return $out;
		}
		foreach ( $m[1] as $block ) {
			if ( ! preg_match( '#<loc>\s*([^<\s]+)\s*</loc>#i', $block, $loc ) ) {
				continue;
			}
			$url = html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
			if ( ! preg_match( '#^https://(www\.)?appsumo\.com/products/[a-z0-9][a-z0-9\-_.]*/$#i', $url ) ) {
				continue;
			}
			$lastmod = '';
			if ( preg_match( '#<lastmod>\s*(\d{4}-\d{2}-\d{2})#i', $block, $lm ) ) {
				$lastmod = $lm[1];
			}
			// AppSumo vergibt 0.5 an aktive und 0.25 an alte Deals; damit werden aktive zuerst geprüft.
			$priority = null;
			if ( preg_match( '#<priority>\s*([01](?:\.\d+)?)\s*</priority>#i', $block, $pr ) ) {
				$priority = number_format( max( 0, min( 1, (float) $pr[1] ) ), 2, '.', '' );
			}
			$out[ $url ] = array( 'url' => $url, 'lastmod' => $lastmod, 'priority' => $priority );
		}
		return array_values( $out );
	}

	/**
	 * @param string $html Produktseite.
	 * @return array|null Felder für die Deal-Tabelle oder null, wenn keine Produktdaten gefunden wurden.
	 */
	public static function product( $html ) {
		$items = self::ld_items( $html );
		$p     = null;
		$data  = array(
			'videos'       => array(),
			'faq'          => array(),
			'category'     => '',
			'category_url' => '',
		);
		foreach ( $items as $it ) {
			$type = isset( $it['@type'] ) ? $it['@type'] : '';
			if ( is_array( $type ) ) {
				$type = reset( $type );
			}
			if ( 'Product' === $type && null === $p ) {
				$p = $it;
			} elseif ( 'VideoObject' === $type ) {
				$data['videos'][] = $it;
			} elseif ( 'BreadcrumbList' === $type ) {
				self::breadcrumbs( $it, $data );
			} elseif ( 'FAQPage' === $type && isset( $it['mainEntity'] ) && is_array( $it['mainEntity'] ) ) {
				foreach ( $it['mainEntity'] as $q ) {
					$question = self::text( isset( $q['name'] ) ? $q['name'] : '', 300 );
					$answer   = self::text( isset( $q['acceptedAnswer']['text'] ) ? $q['acceptedAnswer']['text'] : '', 2000 );
					if ( '' !== $question && '' !== $answer && count( $data['faq'] ) < 20 ) {
						$data['faq'][] = array( 'q' => $question, 'a' => $answer );
					}
				}
			}
		}
		if ( ! $p || empty( $p['name'] ) ) {
			return null;
		}

		$name  = self::text( $p['name'], 255 );
		$offer = isset( $p['offers'] ) ? $p['offers'] : array();
		if ( is_array( $offer ) && isset( $offer[0] ) ) {
			$offer = $offer[0];
		}
		$price = null;
		if ( isset( $offer['price'] ) && is_numeric( $offer['price'] ) ) {
			$price = (float) $offer['price'];
		} elseif ( isset( $offer['lowPrice'] ) && is_numeric( $offer['lowPrice'] ) ) {
			$price = (float) $offer['lowPrice'];
		}
		$availability = '';
		if ( isset( $offer['availability'] ) ) {
			$availability = preg_replace( '#^https?://schema\.org/#i', '', (string) $offer['availability'] );
			$availability = preg_replace( '/[^A-Za-z]/', '', $availability );
		}
		$rating = null;
		$count  = 0;
		if ( isset( $p['aggregateRating']['ratingValue'] ) && is_numeric( $p['aggregateRating']['ratingValue'] ) ) {
			$rating = max( 0, min( 5, (float) $p['aggregateRating']['ratingValue'] ) );
			$count  = isset( $p['aggregateRating']['reviewCount'] ) ? max( 0, (int) $p['aggregateRating']['reviewCount'] ) : 0;
		}
		$return_days = null;
		if ( isset( $offer['hasMerchantReturnPolicy']['merchantReturnDays'] ) && is_numeric( $offer['hasMerchantReturnPolicy']['merchantReturnDays'] ) ) {
			$return_days = (int) $offer['hasMerchantReturnPolicy']['merchantReturnDays'];
		}

		$data['name']            = $name;
		$data['brand']           = self::text( isset( $p['brand']['name'] ) ? $p['brand']['name'] : '', 255 );
		$data['description']     = self::text( isset( $p['description'] ) ? $p['description'] : '', 2000 );
		$data['price']           = $price;
		$data['currency']        = isset( $offer['priceCurrency'] ) ? substr( preg_replace( '/[^A-Z]/', '', strtoupper( (string) $offer['priceCurrency'] ) ), 0, 8 ) : '';
		$data['availability']    = substr( $availability, 0, 40 );
		$data['available_until'] = self::real_end( isset( $offer['availabilityEnds'] ) ? $offer['availabilityEnds'] : '' );
		$data['rating']          = $rating;
		$data['review_count']    = $count;
		$data['return_days']     = $return_days;
		$data['videos']          = self::videos( $data['videos'], $name );
		$data['appsumo_type']    = self::appsumo_type( $html );
		$data['deal_type']       = self::classify( $data['appsumo_type'], $name, $data['description'] );
		return $data;
	}

	/**
	 * Deal-Typ laut AppSumo („Software“, „Courses & learning“, „Templates“).
	 * Quelle: Link /collections/deal-type/<slug>/ oder das Attribut deal_type in den Seitendaten.
	 */
	public static function appsumo_type( $html ) {
		$html = (string) $html;
		if ( preg_match( '#/collections/deal-type/([a-z0-9\-]+)/#i', $html, $m ) ) {
			return substr( strtolower( $m[1] ), 0, 60 );
		}
		// Eingebettete Seitendaten, Anführungszeichen ggf. als \" maskiert.
		if ( preg_match( '#deal_type\\\\?"\s*,\s*\\\\?"name\\\\?"\s*:\s*\\\\?"Deal type\\\\?".{0,120}?\\\\?"name\\\\?"\s*:\s*\\\\?"((?:[^"\\\\]|\\\\u[0-9a-fA-F]{4})+)#s', $html, $m ) ) {
			return substr( sanitize_title( html_entity_decode( str_replace( '\\u0026', '&', $m[1] ) ) ), 0, 60 );
		}
		return '';
	}

	/**
	 * Eigener Typ: software, kurs, ebook, vorlage oder leer.
	 * Lern-Deals werden über Stichwörter in Name und Beschreibung in Kurs oder E-Book geteilt, im Zweifel Kurs.
	 */
	public static function classify( $appsumo_type, $name, $description ) {
		$t = (string) $appsumo_type;
		if ( '' === $t ) {
			return '';
		}
		if ( 0 === strpos( $t, 'software' ) ) {
			return 'software';
		}
		if ( 0 === strpos( $t, 'template' ) ) {
			return 'vorlage';
		}
		if ( false === strpos( $t, 'course' ) && false === strpos( $t, 'learn' ) ) {
			return 'software';
		}
		foreach ( array( (string) $name, (string) $description ) as $text ) {
			$text   = ' ' . strtolower( $text ) . ' ';
			$ebook  = self::has_word( $text, self::EBOOK_WORDS );
			$course = self::has_word( $text, self::COURSE_WORDS );
			if ( $ebook && ! $course ) {
				return 'ebook';
			}
			if ( $course && ! $ebook ) {
				return 'kurs';
			}
		}
		return 'kurs';
	}

	private static function has_word( $text, array $words ) {
		foreach ( $words as $w ) {
			if ( preg_match( '/(?<![a-z])' . preg_quote( $w, '/' ) . '(s|es)?(?![a-z])/', $text ) ) {
				return true;
			}
		}
		return false;
	}

	/** Enddatum nur, wenn es kein Platzhalter (etwa ein Jahr ab Abruf) ist. */
	private static function real_end( $v ) {
		$dt = self::datetime( $v );
		if ( ! $dt ) {
			return null;
		}
		return strtotime( $dt . ' UTC' ) - time() > self::PLACEHOLDER_DAYS * DAY_IN_SECONDS ? null : $dt;
	}

	/** Kaufbar laut schema.org-Verfügbarkeit. */
	public static function is_available( $availability ) {
		return in_array( (string) $availability, array( 'InStock', 'LimitedAvailability', 'OnlineOnly', 'PreOrder', 'PreSale', 'BackOrder' ), true );
	}

	/** Alle JSON-LD-Objekte der Seite als flache Liste. */
	private static function ld_items( $html ) {
		$out = array();
		if ( ! preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', (string) $html, $m ) ) {
			return $out;
		}
		foreach ( $m[1] as $json ) {
			$data = json_decode( trim( $json ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
			foreach ( $list as $it ) {
				if ( is_array( $it ) ) {
					$out[] = $it;
				}
			}
		}
		return $out;
	}

	private static function breadcrumbs( array $it, array &$data ) {
		if ( empty( $it['itemListElement'] ) || ! is_array( $it['itemListElement'] ) ) {
			return;
		}
		$names = array();
		$url   = '';
		foreach ( $it['itemListElement'] as $el ) {
			if ( empty( $el['item']['@id'] ) ) {
				continue; // letzter Eintrag ist das Produkt selbst
			}
			$names[] = self::text( isset( $el['item']['name'] ) ? $el['item']['name'] : '', 80 );
			$url     = self::https( $el['item']['@id'] );
		}
		$data['category']     = substr( implode( ' > ', array_filter( $names ) ), 0, 255 );
		$data['category_url'] = $url;
	}

	/**
	 * Testvideos aufbereiten. Das AppSumo-eigene Deal-Video (Name = Produktname, ohne contentUrl)
	 * wird markiert und später nicht eingebettet.
	 */
	private static function videos( array $raw, $product_name ) {
		$out = array();
		foreach ( $raw as $v ) {
			$embed = self::https( isset( $v['embedUrl'] ) ? $v['embedUrl'] : '' );
			if ( '' === $embed ) {
				continue;
			}
			$name    = self::text( isset( $v['name'] ) ? $v['name'] : '', 255 );
			$content = self::https( isset( $v['contentUrl'] ) ? $v['contentUrl'] : '' );
			$own     = ( 0 === strcasecmp( $name, $product_name ) ) || '' === $content;
			$prefix  = $product_name . ':';
			if ( 0 === stripos( $name, $prefix ) ) {
				$name = trim( substr( $name, strlen( $prefix ) ) );
			}
			$out[] = array(
				'name'        => $name,
				'description' => self::text( isset( $v['description'] ) ? $v['description'] : '', 500 ),
				'embed'       => $embed,
				'url'         => $content,
				'thumb'       => self::https( isset( $v['thumbnailUrl'] ) ? $v['thumbnailUrl'] : '' ),
				'date'        => substr( (string) self::datetime( isset( $v['uploadDate'] ) ? $v['uploadDate'] : '' ), 0, 10 ),
				'appsumo'     => $own ? 1 : 0,
			);
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		return $out;
	}

	private static function text( $v, $max ) {
		$v = html_entity_decode( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$v = trim( preg_replace( '/[ \t]+/', ' ', wp_strip_all_tags( $v ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	private static function https( $v ) {
		$v = esc_url_raw( trim( (string) $v ), array( 'https' ) );
		return strlen( $v ) <= 255 ? $v : '';
	}

	/** ISO-Datum in UTC (Y-m-d H:i:s) oder null. */
	private static function datetime( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return null;
		}
		$ts = strtotime( $v );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
