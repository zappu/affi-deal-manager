<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KI-Anbindung: OpenRouter, OpenAI oder Anthropic.
 */
class ADM_AI {

	public static function configured() {
		return '' !== ADM_Settings::ai_key();
	}

	/**
	 * Eine Anfrage an das eingestellte Modell.
	 *
	 * @param string $system     Systemanweisung.
	 * @param string $user       Nutzernachricht.
	 * @param int    $max_tokens Höchstlänge der Antwort.
	 * @return string|WP_Error Antworttext.
	 */
	public static function chat( $system, $user, $max_tokens = 6000 ) {
		$provider = (string) ADM_Settings::get( 'ai_provider' );
		$key      = ADM_Settings::ai_key( $provider );
		if ( '' === $key ) {
			return new WP_Error( 'adm_ai_key', self::missing_key_message() );
		}
		$model    = ADM_Settings::ai_model();

		if ( 'anthropic' === $provider ) {
			$url     = 'https://api.anthropic.com/v1/messages';
			$headers = array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			);
			$body    = array(
				'model'       => $model,
				'max_tokens'  => (int) $max_tokens,
				'temperature' => 0.4,
				'system'      => $system,
				'messages'    => array( array( 'role' => 'user', 'content' => $user ) ),
			);
		} else {
			$url     = 'openai' === $provider ? 'https://api.openai.com/v1/chat/completions' : 'https://openrouter.ai/api/v1/chat/completions';
			$headers = array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'application/json',
			);
			if ( 'openrouter' === $provider ) {
				$headers['HTTP-Referer'] = home_url( '/' );
				$headers['X-Title']      = 'Thoka Affi Deal Manager';
			}
			$body = array(
				'model'       => $model,
				'max_tokens'  => (int) $max_tokens,
				'temperature' => 0.4,
				'messages'    => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			);
		}

		$res = wp_remote_post( $url, array(
			'timeout' => 180,
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = '';
			if ( is_array( $json ) && isset( $json['error'] ) ) {
				$msg = is_array( $json['error'] ) && isset( $json['error']['message'] ) ? $json['error']['message'] : ( is_string( $json['error'] ) ? $json['error'] : '' );
			}
			return new WP_Error( 'adm_ai_http', 'KI-Anfrage fehlgeschlagen (HTTP ' . $code . ')' . ( $msg ? ': ' . wp_strip_all_tags( substr( (string) $msg, 0, 200 ) ) : '' ) );
		}
		if ( 'anthropic' === $provider ) {
			$text = '';
			foreach ( isset( $json['content'] ) && is_array( $json['content'] ) ? $json['content'] : array() as $part ) {
				if ( isset( $part['type'], $part['text'] ) && 'text' === $part['type'] ) {
					$text .= $part['text'];
				}
			}
		} else {
			$text = isset( $json['choices'][0]['message']['content'] ) ? (string) $json['choices'][0]['message']['content'] : '';
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'adm_ai_empty', 'Die KI hat keinen Text geliefert.' );
		}
		return $text;
	}

	public static function missing_key_message() {
		$p = (string) ADM_Settings::get( 'ai_provider' );
		$c = isset( ADM_Settings::AI_PROVIDERS[ $p ] ) ? ADM_Settings::AI_PROVIDERS[ $p ]['constant'] : 'THOKA_OPENROUTER_API_KEY';
		return 'Kein API-Schlüssel: ' . $c . ' fehlt in der wp-config.php.';
	}

	/** JSON-Objekt aus einer Antwort lösen (auch mit Codeblock drumherum). */
	public static function parse_json( $text ) {
		$text  = (string) $text;
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $data ) ? $data : null;
	}
}
