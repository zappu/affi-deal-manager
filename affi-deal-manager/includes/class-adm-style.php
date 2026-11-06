<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stilprüfung der KI-Texte nach den Regeln aus „vermenschlichen“.
 *
 * fix()   korrigiert, was sich gefahrlos mechanisch korrigieren lässt (großes „Du“ mitten im Satz).
 * check() findet typische KI-Muster und liefert Hinweise für Protokoll und Profil-Kasten. Am Text ändert es nichts.
 */
class ADM_Style {

	/** Wörter, die in KI-Texten auffällig oft stehen, mit schlichterem Ersatz als Hinweis. */
	const WORDS = array(
		'nahtlos'        => 'nahtlos',
		'revolution'     => 'revolutionär',
		'eintauchen'     => 'eintauchen',
		'beleuchte'      => 'beleuchten',
		'facettenreich'  => 'facettenreich',
		'bahnbrechend'   => 'bahnbrechend',
		'game-changer'   => 'Game-Changer',
		'gamechanger'    => 'Game-Changer',
		'landschaft'     => 'Landschaft',
		'fungiert als'   => 'fungiert als',
		'dient als'      => 'dient als',
		'verfügt über'   => 'verfügt über',
		'darüber hinaus' => 'Darüber hinaus',
		'es ist wichtig zu beachten' => 'es ist wichtig zu beachten',
		'bemerkenswert'  => 'bemerkenswert',
		'unterstreicht'  => 'unterstreicht',
		'heutigen digitalen' => 'in der heutigen digitalen Welt',
	);

	/** Großes „Du“ mitten im Satz klein schreiben (nach Kleinbuchstabe, Ziffer oder Komma). */
	public static function fix( $text ) {
		return preg_replace_callback(
			'/(?<=[a-zäöüß0-9,;)] )(Du|Dein|Deine|Deinen|Deinem|Deiner|Deines|Dir|Dich)\b/u',
			function ( $m ) {
				return mb_strtolower( $m[1] );
			},
			(string) $text
		);
	}

	/** „die sprechende Person“ aus Videozusammenfassungen durch „die Person im Video“ ersetzen. */
	public static function video_wording( $text ) {
		return preg_replace(
			array( '/\b([Dd])ie sprechende Person\b/u', '/\b([Dd])er sprechenden Person\b/u' ),
			array( '$1ie Person im Video', '$1er Person im Video' ),
			(string) $text
		);
	}

	/** fix() auf alle Zeichenketten einer KI-Antwort anwenden. */
	public static function fix_all( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::fix_all( $v );
			}
			return $data;
		}
		return is_string( $data ) ? self::fix( $data ) : $data;
	}

	/**
	 * Hinweise zu einem fertigen Text (HTML erlaubt).
	 *
	 * @param string $html Text.
	 * @return string[] Hinweise, leer wenn alles passt.
	 */
	public static function check( $html ) {
		$t     = html_entity_decode( wp_strip_all_tags( preg_replace( '/\[[^\]]+\]/', ' ', (string) $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$low   = mb_strtolower( $t );
		$hints = array();

		$dashes = preg_match_all( '/\s[–—]\s|\s-\s/u', $t );
		if ( $dashes ) {
			$hints[] = $dashes . ' Gedankenstrich' . ( 1 === $dashes ? '' : 'e' );
		}
		$np = preg_match_all( '/nicht nur\b[^.!?]{0,120}\bsondern\b/u', $low );
		if ( $np ) {
			$hints[] = $np . '× „nicht nur … sondern“';
		}
		$du = preg_match_all( '/(?<=[a-zäöüß0-9,;)] )(Du|Dein\w*|Dir|Dich)\b/u', $t );
		if ( $du ) {
			$hints[] = $du . '× großes „Du“';
		}
		$found = array();
		foreach ( self::WORDS as $needle => $label ) {
			if ( false !== strpos( $low, $needle ) ) {
				$found[ $label ] = true;
			}
		}
		if ( $found ) {
			$hints[] = 'Floskeln: ' . implode( ', ', array_keys( $found ) );
		}
		$vague = preg_match_all( '/\b(es heißt|nutzer berichten|viele nutzer|experten (sagen|empfehlen|meinen|raten)|studien zeigen|man sagt)\b/u', $low );
		if ( $vague ) {
			$hints[] = $vague . '× unbestimmte Quelle („es heißt“, „Nutzer berichten“)';
		}
		if ( preg_match( '/\b(wir haben (es |das tool )?getestet|in unserem test|unser test)\b/u', $low ) ) {
			$hints[] = 'behauptet einen eigenen Test';
		}
		return $hints;
	}
}
