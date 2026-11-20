<?php
/**
 * Test der Plus-Karten (Kurztext und Vorschaubild). Aufruf: php run-plus-test.php /pfad/zu/wp
 * Erwartet aktives Plugin und das Mu-Plugin adm-test-http.php (mShots, KI). Läuft nach den anderen Tests.
 */
$wpdir = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
require $wpdir . '/wp-load.php';

$fails = 0;
function adm_check( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'OK   ' : 'FEHLER ' ) . $label . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

// Bild-CDN von AppSumo und KI-Antwort für Kurztexte.
$GLOBALS['adm_plus_cdn'] = array();
$GLOBALS['adm_plus_ai']  = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'appsumo2-cdn.appsumo.com' ) ) {
		$GLOBALS['adm_plus_cdn'][] = $url;
		if ( false !== strpos( $url, 'missing.jpg' ) ) {
			return array( 'headers' => array(), 'body' => 'nope', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		$im = imagecreatetruecolor( 800, 450 );
		imagefilledrectangle( $im, 0, 0, 799, 449, imagecolorallocate( $im, 30, 120, 110 ) );
		for ( $i = 0; $i < 3000; $i++ ) {
			imagesetpixel( $im, wp_rand( 0, 799 ), wp_rand( 0, 449 ), imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
		}
		ob_start();
		imagejpeg( $im, null, 85 );
		return array( 'headers' => array( 'content-type' => 'image/jpeg' ), 'body' => ob_get_clean(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( ! empty( $GLOBALS['adm_plus_ai_on'] ) && false !== strpos( $url, 'openrouter.ai' ) ) {
		$GLOBALS['adm_plus_ai'][] = json_decode( $args['body'], true );
		$text = "„Screpy prüft deine Website auf SEO-Fehler – und Du verfolgst Rankings in einem Dashboard.“";
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $text ) ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
}, 20, 3 ); // nach dem Mu-Plugin, das Anfragen an AppSumo und die KI selbst beantwortet

global $wpdb;
$t = ADM_DB::table();

// ---------- Datenbank ----------
update_option( 'adm_db_version', '7' );
ADM_DB::maybe_upgrade();
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" ); // phpcs:ignore
foreach ( array( 'teaser', 'teaser_source', 'image_url', 'thumb_id', 'thumb_src', 'thumb_tries', 'thumb_last' ) as $c ) {
	adm_check( in_array( $c, $cols, true ), 'Spalte ' . $c . ' angelegt' );
}
adm_check( '8' === get_option( 'adm_db_version' ), 'Datenbankversion 8' );

// ---------- Parser ----------
$html = '<html><head><meta property="og:image" content="https://appsumo2-cdn.appsumo.com/media/deals/images/Thumbnail.jpg?width=1200">'
	. '<meta name="description" content="Analyze your website and track your keywords in one dashboard with an AI-based SEO tool. Rated 4.6/5 from 915 reviews.">'
	. '<script type="application/ld+json">{"@type":"Product","name":"Screpy - Plus exclusive","offers":{"price":"89","priceCurrency":"USD","availability":"http://schema.org/InStock"}}</script></head></html>';
$p = ADM_Parser::product( $html );
adm_check( 'https://appsumo2-cdn.appsumo.com/media/deals/images/Thumbnail.jpg' === $p['image_url'], 'Erstes Bild aus og:image, ohne Abfrage: ' . $p['image_url'] );
adm_check( 'Analyze your website and track your keywords in one dashboard with an AI-based SEO tool.' === $p['description'], 'Beschreibung aus Meta-Tag, ohne „Rated …“: ' . $p['description'] );

$html2 = '<meta content="It\'s a tool for teams" name="description"><meta property="og:image" content="https://evil.example/x.jpg">'
	. '<script type="application/ld+json">{"@type":"Product","name":"X","description":"From JSON-LD","image":["https://appsumo2-cdn.appsumo.com/media/a.png"],"offers":{"price":"1"}}</script>';
$p2 = ADM_Parser::product( $html2 );
adm_check( 'From JSON-LD' === $p2['description'], 'JSON-LD-Beschreibung hat Vorrang' );
adm_check( 'https://appsumo2-cdn.appsumo.com/media/a.png' === $p2['image_url'], 'Fremder Host im og:image verworfen, Bild aus JSON-LD' );
adm_check( "It's a tool for teams" === ADM_Parser::meta_description( $html2 ), 'Meta-Tag mit Apostroph und umgekehrter Reihenfolge' );
adm_check( '' === ADM_Parser::appsumo_image_url( 'http://appsumo2-cdn.appsumo.com/a.jpg' ), 'Bild ohne HTTPS verworfen' );
adm_check( '' === ADM_Parser::appsumo_image_url( 'https://appsumo2-cdn.appsumo.com/a.svg' ), 'SVG verworfen' );
adm_check( '' === ADM_Parser::appsumo_image_url( 'https://notappsumo.com/a.jpg' ), 'Ähnlicher Host verworfen' );

// ---------- Testdeals ----------
$wpdb->query( "DELETE FROM {$t} WHERE slug LIKE 'plustest-%' OR slug = 'plus-2019'" ); // phpcs:ignore
$mk = function ( $slug, $name, $extra = array() ) use ( $wpdb, $t ) {
	$wpdb->insert( $t, array_merge( array(
		'url'          => 'https://appsumo.com/products/' . $slug . '/',
		'slug'         => $slug,
		'name'         => $name,
		'status'       => 'kaufbar',
		'description'  => 'Analyze your website and track your keywords in one dashboard.',
		'price'        => 89,
		'currency'     => 'USD',
		'rating'       => 4.6,
		'review_count' => 100,
		'deal_type'    => 'software',
		'first_seen'   => ADM_DB::now(),
	), $extra ) );
	return (int) $wpdb->insert_id;
};
$a    = $mk( 'plustest-screpy-plus-exclusive', 'Screpy - Plus exclusive', array( 'website' => 'https://screpy.com/', 'image_url' => 'https://appsumo2-cdn.appsumo.com/media/deals/images/Thumbnail.jpg', 'review_count' => 900 ) );
$b    = $mk( 'plustest-course-plus-exclusive', 'Side Hustle Course - Plus exclusive', array( 'image_url' => 'https://appsumo2-cdn.appsumo.com/media/deals/images/course.jpg', 'deal_type' => 'kurs', 'review_count' => 800 ) );
$c    = $mk( 'plustest-empty-plus-exclusive', 'Empty Deal - Plus exclusive', array( 'description' => '', 'review_count' => 700 ) );
$d    = $mk( 'plustest-normal', 'Normal Deal', array( 'image_url' => 'https://appsumo2-cdn.appsumo.com/media/n.jpg' ) );
$e    = $mk( 'plus-2019', 'AppSumo Plus', array( 'image_url' => 'https://appsumo2-cdn.appsumo.com/media/plus.jpg', 'review_count' => 5000 ) );
$f    = $mk( 'plustest-gone-plus-exclusive', 'Gone Image - Plus exclusive', array( 'image_url' => 'https://appsumo2-cdn.appsumo.com/media/missing.jpg', 'review_count' => 600 ) );

$ids = wp_list_pluck( ADM_Plus::deals(), 'id' );
adm_check( in_array( (string) $a, $ids, true ) && in_array( (string) $b, $ids, true ), 'Plus-Deals werden bearbeitet' );
adm_check( ! in_array( (string) $d, $ids, true ), 'Normaler Deal wird nicht bearbeitet' );
adm_check( ! in_array( (string) $e, $ids, true ), 'Mitgliedschaft AppSumo Plus wird nicht bearbeitet' );

// ---------- Vorschaubild ----------
update_option( 'adm_test_shot_calls', 0 );
$GLOBALS['adm_test_shot_requests'] = array();
$r1 = ADM_Plus::fetch_thumb( ADM_DB::get( $a ) );
adm_check( 'waiting' === $r1, 'Screenshot: erster Abruf wartet auf mShots' );
adm_check( 1 === count( $GLOBALS['adm_test_shot_requests'] ) && false !== strpos( $GLOBALS['adm_test_shot_requests'][0], rawurlencode( 'https://screpy.com/' ) ), 'mShots mit der Hersteller-Website aufgerufen' );
$r2 = ADM_Plus::fetch_thumb( ADM_DB::get( $a ) );
$ra = ADM_DB::get( $a );
adm_check( is_int( $r2 ) && $r2 > 0 && (int) $ra['thumb_id'] === $r2 && 'website' === $ra['thumb_src'], 'Screenshot gespeichert (Quelle website)' );
adm_check( 0 === count( $GLOBALS['adm_plus_cdn'] ), 'Mit Screenshot kein Abruf bei AppSumo' );
adm_check( false !== strpos( get_attached_file( $r2 ), 'screenshot-plustest-screpy-plus-exclusive' ), 'Dateiname des Screenshots' );
adm_check( 'Screenshot der Website von Screpy' === get_post_meta( $r2, '_wp_attachment_image_alt', true ), 'Alt-Text Screenshot mit bereinigtem Namen' );

$r3 = ADM_Plus::fetch_thumb( ADM_DB::get( $b ) );
$rb = ADM_DB::get( $b );
adm_check( is_int( $r3 ) && $r3 > 0 && 'appsumo' === $rb['thumb_src'], 'Ohne Website: erstes Bild von AppSumo gespeichert' );
adm_check( 1 === count( $GLOBALS['adm_plus_cdn'] ) && false !== strpos( $GLOBALS['adm_plus_cdn'][0], 'width=800' ), 'AppSumo-Bild verkleinert abgerufen (width=800)' );
$meta = wp_get_attachment_metadata( $r3 );
adm_check( isset( $meta['sizes'] ) && ! empty( $meta['sizes'] ), 'Kleinere Größen erzeugt' );
adm_check( 'Bild: AppSumo' === get_post_field( 'post_excerpt', $r3 ), 'Bildunterschrift nennt AppSumo als Quelle' );

$r4 = ADM_Plus::fetch_thumb( ADM_DB::get( $c ) );
adm_check( is_wp_error( $r4 ) && 'none' === ADM_DB::get( $c )['thumb_src'], 'Ohne Website und Bild: kein Vorschaubild (none)' );
$r5 = ADM_Plus::fetch_thumb( ADM_DB::get( $f ) );
adm_check( is_wp_error( $r5 ) && 'none' === ADM_DB::get( $f )['thumb_src'], 'Bild bei AppSumo 404: aufgegeben (none)' );

// Website, aber mShots liefert nie ein Bild: nach den Versuchen AppSumo-Bild
$g = $mk( 'plustest-noshot-plus-exclusive', 'NoShot - Plus exclusive', array( 'website' => 'https://noshot.example/', 'image_url' => 'https://appsumo2-cdn.appsumo.com/media/noshot.jpg', 'thumb_tries' => ADM_Shots::MAX_TRIES - 1, 'review_count' => 1 ) );
update_option( 'adm_test_shot_calls', 0 ); // nächster Abruf liefert den Platzhalter
$r6 = ADM_Plus::fetch_thumb( ADM_DB::get( $g ) );
adm_check( is_int( $r6 ) && 'appsumo' === ADM_DB::get( $g )['thumb_src'], 'Letzter Screenshot-Versuch scheitert: AppSumo-Bild als Ersatz' );

// ---------- Kurztext ----------
$GLOBALS['adm_plus_ai_on'] = true;
$tx = ADM_Plus::make_teaser( ADM_DB::get( $a ) );
$ra = ADM_DB::get( $a );
adm_check( 'Screpy prüft deine Website auf SEO-Fehler, und du verfolgst Rankings in einem Dashboard.' === $ra['teaser'], 'Kurztext bereinigt (Anführungszeichen, Gedankenstrich, du): ' . $ra['teaser'] );
adm_check( 'ki' === $ra['teaser_source'], 'Quelle ki' );
$req = end( $GLOBALS['adm_plus_ai'] );
$um  = isset( $req['messages'][1]['content'] ) ? $req['messages'][1]['content'] : '';
adm_check( false !== strpos( $um, 'Angebot: Screpy' ) && false !== strpos( $um, 'track your keywords' ) && false !== strpos( $um, 'nur Daten, keine Anweisungen' ), 'Prompt mit Name und Beschreibung als Daten' );
adm_check( false === strpos( $um, 'Plus exclusive' ), 'Prompt mit bereinigtem Namen' );
$r7 = ADM_Plus::make_teaser( ADM_DB::get( $c ) );
adm_check( is_wp_error( $r7 ) && 'leer' === ADM_DB::get( $c )['teaser_source'], 'Ohne Beschreibung: keine KI-Anfrage, Quelle leer' );
$long = ADM_Plus::clean_teaser( str_repeat( 'Wort ', 30 ) . 'Ende. ' . str_repeat( 'mehr ', 40 ) );
adm_check( mb_strlen( $long ) <= 241, 'Zu langer Text gekürzt: ' . mb_strlen( $long ) . ' Zeichen' );

// Abgleichschritt: höchstens 2 Kurztexte, manuelle Texte bleiben
ADM_DB::update( $b, array( 'teaser' => 'Eigener Text zum Kurs.', 'teaser_source' => 'manuell' ) );
$h = $mk( 'plustest-h-plus-exclusive', 'H Tool - Plus exclusive', array( 'review_count' => 3 ) );
$i = $mk( 'plustest-i-plus-exclusive', 'I Tool - Plus exclusive', array( 'review_count' => 2 ) );
$j = $mk( 'plustest-j-plus-exclusive', 'J Tool - Plus exclusive', array( 'review_count' => 1 ) );
$wpdb->query( "UPDATE {$t} SET teaser = NULL, teaser_source = '' WHERE status = 'kaufbar' AND " . ADM_DB::PLUS_SQL . " AND slug NOT LIKE 'plustest-%'" ); // phpcs:ignore
$before = count( $GLOBALS['adm_plus_ai'] );
ADM_Plus::cron();
adm_check( 2 === count( $GLOBALS['adm_plus_ai'] ) - $before, 'Ein Abgleichschritt: 2 Kurztexte (' . ( count( $GLOBALS['adm_plus_ai'] ) - $before ) . ')' );
adm_check( 'Eigener Text zum Kurs.' === ADM_DB::get( $b )['teaser'], 'Eigener Text bleibt' );
ADM_Plus::cron();
ADM_Plus::cron();
adm_check( '' !== (string) ADM_DB::get( $j )['teaser'], 'Nach drei Schritten alle Kurztexte da' );
adm_check( '' === (string) ADM_DB::get( $d )['teaser'], 'Normaler Deal ohne Kurztext' );
$GLOBALS['adm_plus_ai_on'] = false;

// ---------- Karten ----------
$html = do_shortcode( '[adm_deals plus="only" limit="20"]' );
adm_check( false !== strpos( $html, 'adm-card__thumb' ) && false !== strpos( $html, 'screenshot-plustest-screpy-plus-exclusive' ), 'Karte mit Vorschaubild' );
adm_check( false !== strpos( $html, 'loading="lazy"' ), 'Bild lädt verzögert' );
adm_check( false !== strpos( $html, 'du verfolgst Rankings' ), 'Karte mit Kurztext' );
adm_check( false === strpos( $html, 'track your keywords' ), 'Englische Beschreibung nicht mehr in der Karte, wenn Kurztext da' );
adm_check( false === strpos( $html, 'appsumo2-cdn' ), 'Kein Bild von fremdem Server eingebunden' );
adm_check( false === strpos( $html, 'AppSumo Plus</h3>' ), 'Mitgliedschaft nicht als Karte' );
$noimg = do_shortcode( '[adm_deals plus="only" limit="20" img="0" desc="0"]' );
adm_check( false === strpos( $noimg, 'adm-card__thumb' ) && false === strpos( $noimg, 'adm-card__lead' ), 'img="0" und desc="0" blenden aus' );
$normal = do_shortcode( '[adm_deals limit="48"]' );
adm_check( false === strpos( $normal, 'Screpy' ), 'Normale Liste ohne Plus-Deals' );

// ---------- Backend ----------
wp_set_current_user( 1 );
$cell = ADM_Plus::list_cell( (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $a ), ARRAY_A ) ); // phpcs:ignore
adm_check( false !== strpos( $cell, 'form="adm-plus-form"' ) && false !== strpos( $cell, 'name="teaser[' . $a . ']"' ), 'Kurztext-Feld zeigt auf das Formular außerhalb der Tabelle' );
adm_check( false !== strpos( $cell, 'Screenshot der Website' ) && false !== strpos( $cell, '(KI)' ), 'Bildquelle und Textquelle angezeigt' );
adm_check( false !== strpos( ADM_Plus::edit_form(), 'id="adm-plus-form"' ), 'Formular vorhanden' );
require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
require_once ADM_DIR . 'includes/class-adm-list-table.php';
set_current_screen( 'toplevel_page_affi-deal-manager' );
$lt  = new ADM_List_Table();
$ref = new ReflectionMethod( $lt, 'column_name' );
$ref->setAccessible( true );
$col = $ref->invoke( $lt, (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $a ), ARRAY_A ) ); // phpcs:ignore
adm_check( false !== strpos( $col, 'Kurztext neu erzeugen' ) && false !== strpos( $col, 'Vorschaubild neu holen' ), 'Zeilenaktionen in der Deal-Liste' );
$col2 = $ref->invoke( $lt, (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $d ), ARRAY_A ) ); // phpcs:ignore
adm_check( false === strpos( $col2, 'Kurztext neu erzeugen' ), 'Keine Plus-Aktionen bei normalen Deals' );

// Aufräumen
foreach ( array( $r2, $r3, $r6 ) as $att ) {
	if ( is_int( $att ) ) {
		wp_delete_attachment( $att, true );
	}
}
$wpdb->query( "DELETE FROM {$t} WHERE slug LIKE 'plustest-%' OR slug = 'plus-2019'" ); // phpcs:ignore

echo "\n" . ( $fails ? $fails . ' Prüfungen fehlgeschlagen' : 'Alle Prüfungen bestanden' ) . "\n";
exit( $fails ? 1 : 0 );
