<?php
/**
 * Test KI-Anbieter und Modellauswahl. Aufruf: php run-ai-test.php /pfad/zu/wp
 * Erwartet aktives Plugin und das Mu-Plugin adm-test-http.php.
 */
$wpdir = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
define( 'THOKA_OPENAI_API_KEY', 'oa-key' ); // im Mu-Plugin bewusst ohne, hier für die OpenAI-Prüfungen
require $wpdir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$fails = 0;
function adm_check( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'OK   ' : 'FEHLER ' ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

// Modelllisten der Anbieter (nach dem Mu-Plugin, das openrouter.ai sonst als Chat beantwortet)
$GLOBALS['adm_models_req'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$json = function ( $data, $code = 200 ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	if ( 'https://openrouter.ai/api/v1/models' === $url ) {
		$GLOBALS['adm_models_req'][] = $url;
		return $json( array( 'data' => array(
			array( 'id' => 'anthropic/claude-sonnet-5.5', 'name' => 'Anthropic: Claude Sonnet 5.5', 'created' => 300, 'architecture' => array( 'output_modalities' => array( 'text' ) ) ),
			array( 'id' => 'openai/gpt-6.1-sol', 'name' => 'OpenAI: GPT-6.1 Sol', 'created' => 400, 'architecture' => array( 'output_modalities' => array( 'text' ) ) ),
			array( 'id' => 'google/imagen-x', 'name' => 'Imagen', 'created' => 500, 'architecture' => array( 'output_modalities' => array( 'image' ) ) ),
			array( 'id' => 'bad id"><script>', 'name' => 'Böse', 'created' => 1 ),
		) ) );
	}
	if ( 'https://api.openai.com/v1/models' === $url ) {
		$GLOBALS['adm_models_req'][] = $url;
		return $json( array( 'data' => array(
			array( 'id' => 'gpt-6.1-sol', 'created' => 200 ),
			array( 'id' => 'gpt-6-luna', 'created' => 150 ),
			array( 'id' => 'gpt-realtime-2', 'created' => 300 ),
			array( 'id' => 'text-embedding-3-large', 'created' => 100 ),
			array( 'id' => 'gpt-image-2.6-flare', 'created' => 310 ),
			array( 'id' => 'o4-mini', 'created' => 50 ),
		) ) );
	}
	if ( 0 === strpos( $url, 'https://api.anthropic.com/v1/models' ) ) {
		$GLOBALS['adm_models_req'][] = $url;
		if ( empty( $args['headers']['x-api-key'] ) ) {
			return $json( array( 'error' => array( 'message' => 'no key' ) ), 401 );
		}
		return $json( array( 'data' => array(
			array( 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'created_at' => '2026-06-01T00:00:00Z' ),
			array( 'id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5', 'created_at' => '2026-07-01T00:00:00Z' ),
		) ) );
	}
	return $pre;
}, 20, 3 );

$orig = ADM_Settings::all();

// ---------- Modell passt zum Anbieter ----------
adm_check( ADM_Settings::model_fits( 'openrouter', 'anthropic/claude-sonnet-5.5' ), 'OpenRouter: anbieter/modell passt' );
adm_check( ! ADM_Settings::model_fits( 'openrouter', 'gpt-6.1-sol' ), 'OpenRouter: Modell ohne Anbieter passt nicht' );
adm_check( ! ADM_Settings::model_fits( 'openai', 'anthropic/claude-sonnet-5.5' ), 'OpenAI: OpenRouter-Name passt nicht' );
adm_check( ! ADM_Settings::model_fits( 'openai', 'claude-sonnet-5-5' ), 'OpenAI: Claude passt nicht' );
adm_check( ADM_Settings::model_fits( 'openai', 'gpt-6.1-sol' ), 'OpenAI: gpt passt' );
adm_check( ADM_Settings::model_fits( 'anthropic', 'claude-opus-5-5' ) && ! ADM_Settings::model_fits( 'anthropic', 'gpt-6.1-sol' ), 'Anthropic: nur claude-…' );

// Gespeicherte Kombination wie im Fehlerfall: OpenAI mit anthropic/claude-sonnet-5.5
update_option( 'adm_settings', array_merge( get_option( 'adm_settings', array() ), array( 'ai_provider' => 'openai', 'ai_model' => 'anthropic/claude-sonnet-5.5' ) ) );
adm_check( 'gpt-5.6-sol' === ADM_Settings::ai_model(), 'Unpassendes gespeichertes Modell: Standardmodell des Anbieters (' . ADM_Settings::ai_model() . ')' );

// Speichern
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openai', 'ai_model' => 'anthropic/claude-sonnet-5.5' ) ) );
adm_check( '' === ADM_Settings::get( 'ai_model' ), 'Speichern: unpassendes Modell wird verworfen' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openai', 'ai_model' => 'gpt-6.1-sol' ) ) );
adm_check( 'gpt-6.1-sol' === ADM_Settings::ai_model(), 'Speichern: Auswahl übernommen' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openai', 'ai_model' => '__custom', 'ai_model_custom' => 'gpt-6-astra-2026-11-01' ) ) );
adm_check( 'gpt-6-astra-2026-11-01' === ADM_Settings::ai_model(), 'Speichern: eigenes Modell hat Vorrang' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openai', 'ai_model' => '__custom', 'ai_model_custom' => '' ) ) );
adm_check( '' === ADM_Settings::get( 'ai_model' ), 'Speichern: „Eigenes Modell“ ohne Eingabe ist Standard' );

// ---------- Modelllisten ----------
ADM_AI::forget_models();
$or = ADM_AI::models( 'openrouter' );
$ids = wp_list_pluck( $or['models'], 'id' );
adm_check( $or['live'] && array( 'openai/gpt-6.1-sol', 'anthropic/claude-sonnet-5.5' ) === $ids, 'OpenRouter live: nur Text-Modelle, neueste zuerst, ungültige IDs verworfen' );
$oa  = ADM_AI::models( 'openai' );
$ids = wp_list_pluck( $oa['models'], 'id' );
adm_check( $oa['live'] && array( 'gpt-6.1-sol', 'gpt-6-luna', 'o4-mini' ) === $ids, 'OpenAI live: ohne Realtime, Bild und Embedding (' . implode( ', ', $ids ) . ')' );
$an  = ADM_AI::models( 'anthropic' );
adm_check( $an['live'] && 'claude-sonnet-5-5' === $an['models'][0]['id'] && false !== strpos( $an['models'][0]['label'], 'Claude Sonnet 5.5' ), 'Anthropic live mit Anzeigenamen, neueste zuerst' );
$n = count( $GLOBALS['adm_models_req'] );
ADM_AI::models( 'openai' );
adm_check( count( $GLOBALS['adm_models_req'] ) === $n, 'Liste wird zwischengespeichert' );
// Fehler: Ersatzliste
ADM_AI::forget_models();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( 'https://api.openai.com/v1/models' === $url && ! empty( $GLOBALS['adm_models_fail'] ) ) {
		return new WP_Error( 'http_request_failed', 'Timeout' );
	}
	return $pre;
}, 30, 3 );
$GLOBALS['adm_models_fail'] = true;
$oa = ADM_AI::models( 'openai' );
adm_check( ! $oa['live'] && 'gpt-5.6-sol' === $oa['models'][0]['id'] && ! empty( $oa['error'] ), 'Fehler beim Abruf: Kurzliste mit Hinweis' );
$GLOBALS['adm_models_fail'] = false;
ADM_AI::forget_models();

// ---------- OpenAI-Anfrage ----------
$GLOBALS['adm_test_ai_requests'] = array();
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openai', 'ai_model' => 'gpt-6.1-sol' ) ) );
ADM_AI::chat( 'sys', 'user', 400 );
$req = end( $GLOBALS['adm_test_ai_requests'] );
adm_check( $req && false !== strpos( $req['url'], 'api.openai.com' ) && 'gpt-6.1-sol' === $req['body']['model'], 'OpenAI mit gewähltem Modell' );
adm_check( isset( $req['body']['max_completion_tokens'] ) && ! isset( $req['body']['max_tokens'] ) && $req['body']['max_completion_tokens'] >= 4000, 'OpenAI: max_completion_tokens statt max_tokens' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openrouter', 'ai_model' => '' ) ) );
ADM_AI::chat( 'sys', 'user', 400 );
$req = end( $GLOBALS['adm_test_ai_requests'] );
adm_check( 400 === $req['body']['max_tokens'] && 'anthropic/claude-sonnet-5.5' === $req['body']['model'], 'OpenRouter: max_tokens und Standardmodell' );

// ---------- Einstellungsseite ----------
wp_set_current_user( 1 );
ob_start();
ADM_Admin::page_settings();
$html = ob_get_clean();
adm_check( false !== strpos( $html, '<select id="adm-ai_model" name="adm[ai_model]"' ) && false !== strpos( $html, 'name="adm[ai_model_custom]"' ), 'Einstellungen: Auswahlliste und Feld für eigenes Modell' );
adm_check( false !== strpos( $html, 'openai\/gpt-6.1-sol' ) && false !== strpos( $html, 'claude-opus-5-5' ) && false !== strpos( $html, 'gpt-6-luna' ), 'Einstellungen: Modelle aller drei Anbieter eingebettet' );
adm_check( false === strpos( $html, '<script>"' ) && false === strpos( $html, 'bad id"><script>' ), 'Keine ungefilterten IDs im HTML' );
adm_check( false !== strpos( $html, 'do=models' ), 'Link „Modelllisten neu laden“' );
if ( preg_match( '#<script>\s*\(function\(\)\{\s*var data = .*?\}\)\(\);\s*</script>#s', $html, $m ) ) {
	file_put_contents( sys_get_temp_dir() . '/adm-model-script.js', trim( preg_replace( '#^<script>|</script>$#', '', $m[0] ) ) );
}
adm_check( isset( $m[0] ), 'Skript der Modellauswahl gefunden' );

update_option( 'adm_settings', $orig );
ADM_AI::forget_models();
echo "\n" . ( $fails ? $fails . ' Prüfungen fehlgeschlagen' : 'Alle Prüfungen bestanden' ) . "\n";
exit( $fails ? 1 : 0 );
