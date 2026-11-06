<?php
/**
 * Abgleichstest gegen Testdaten. Aufruf: php run-sync-test.php /pfad/zu/wp
 * Erwartet aktives Plugin und das Mu-Plugin adm-test-http.php.
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

$mails = array();
add_filter( 'pre_wp_mail', function ( $r, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
}, 10, 2 );
$changes = array();
add_action( 'adm_deal_status_changed', function ( $id, $old, $new ) use ( &$changes ) {
	$changes[] = array( $id, $old, $new );
}, 10, 3 );

adm_check( class_exists( 'ADM_Sync' ), 'Plugin geladen' );
$ev = wp_get_scheduled_event( 'adm_sync_tick' );
adm_check( $ev && 'adm_every_5' === $ev->schedule, 'Cron geplant mit Standardtakt 5 Minuten' );

// Zeitplan-Einstellungen
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'interval' => 15 ) ) );
ADM_Sync::reschedule();
$ev = wp_get_scheduled_event( 'adm_sync_tick' );
adm_check( $ev && 'adm_every_15' === $ev->schedule && 900 === (int) $ev->interval, 'Takt 15 Minuten übernommen' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'interval' => 7 ) ) );
adm_check( 15 === (int) ADM_Settings::get( 'interval' ), 'Ungültiger Takt wird verworfen' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'mode' => 'off' ) ) );
ADM_Sync::reschedule();
adm_check( ! wp_next_scheduled( 'adm_sync_tick' ), 'Modus „aus“ entfernt den Cron' );
$r = ADM_Sync::tick( false, 'wpcron' );
adm_check( 'Abgleich ist ausgeschaltet.' === $r['skipped'], 'Modus „aus“: automatischer Schritt läuft nicht' );

adm_check( ADM_Settings::in_window( 3, 0, 24 ) && ADM_Settings::in_window( 23, 0, 24 ), 'Zeitfenster 0 bis 24: immer' );
adm_check( ADM_Settings::in_window( 23, 22, 6 ) && ADM_Settings::in_window( 2, 22, 6 ) && ! ADM_Settings::in_window( 12, 22, 6 ) && ! ADM_Settings::in_window( 6, 22, 6 ), 'Zeitfenster 22 bis 6 über Mitternacht' );
adm_check( ADM_Settings::in_window( 9, 9, 17 ) && ! ADM_Settings::in_window( 17, 9, 17 ), 'Zeitfenster 9 bis 17' );

$h = (int) wp_date( 'G' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'mode' => 'wpcron', 'window_start' => ( $h + 1 ) % 24, 'window_end' => ( $h + 2 ) % 24 ?: 24 ) ) );
$r = ADM_Sync::tick( false, 'wpcron' );
adm_check( 'Außerhalb des Zeitfensters.' === $r['skipped'], 'Außerhalb des Zeitfensters wird nichts abgerufen' );
$r = ADM_Sync::tick( false, 'servercron' );
adm_check( 'Aufruf passt nicht zum eingestellten Modus.' === $r['skipped'], 'Server-Aufruf im WP-Cron-Modus wird abgelehnt' );

// Server-Cron über REST
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'mode' => 'servercron', 'window_start' => 0, 'window_end' => 24 ) ) );
ADM_Sync::reschedule();
$secret = ADM_Settings::cron_secret();
adm_check( ! wp_next_scheduled( 'adm_sync_tick' ) && 32 === strlen( $secret ), 'Server-Cron: kein WP-Cron, Schlüssel erzeugt' );
do_action( 'rest_api_init' );
$req = new WP_REST_Request( 'POST', '/affi-deal-manager/v1/tick' );
adm_check( 403 === rest_do_request( $req )->get_status(), 'REST ohne Schlüssel: 403' );
$req->set_header( 'X-ADM-Key', 'falsch' );
adm_check( 403 === rest_do_request( $req )->get_status(), 'REST mit falschem Schlüssel: 403' );
update_option( 'adm_test_scenario', 1 );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'delay' => 0, 'batch_size' => 2 ) ) );
delete_option( ADM_Sync::SITEMAP_READ );
$req->set_header( 'X-ADM-Key', $secret );
$res = rest_do_request( $req );
$data = $res->get_data();
adm_check( 200 === $res->get_status() && 2 === $data['checked'] && 'servercron' === $data['source'] && get_option( 'adm_last_servercron' ) >= time() - 60, 'REST mit Schlüssel: Schritt ausgeführt (' . wp_json_encode( $data ) . ')' );
// Reihenfolge: aktive Deals (Priorität 0.5) stehen in der Sitemap hinten, werden aber zuerst geprüft.
$lo = adm_get_deal( 'leadocean' );
$nw = adm_get_deal( 'neuronwriter' );
$o1 = adm_get_deal( 'old-deal-1' );
$checked = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . ADM_DB::table() . ' WHERE last_checked IS NOT NULL' );
$checked_active = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . ADM_DB::table() . ' WHERE last_checked IS NOT NULL AND sitemap_priority >= 0.5' );
adm_check( 2 === $checked && 2 === $checked_active && ! $o1['last_checked'], 'Aktive Deals zuerst geprüft, alte noch nicht' );
adm_check( '0.50' === number_format( (float) $lo['sitemap_priority'], 2 ) && '0.25' === number_format( (float) $o1['sitemap_priority'], 2 ), 'Sitemap-Priorität gespeichert' );

$get = new WP_REST_Request( 'GET', '/affi-deal-manager/v1/tick' );
$get->set_param( 'key', $secret );
adm_check( 200 === rest_do_request( $get )->get_status(), 'REST per GET mit ?key= für „URL abrufen“ in Plesk' );
$old = ADM_Settings::cron_secret();
adm_check( $old === ADM_Settings::cron_secret() && $old !== ADM_Settings::cron_secret( true ), 'Schlüssel bleibt stabil und lässt sich neu erzeugen' );


// Sitemap-Zeitpunkt
update_option( ADM_Sync::SITEMAP_READ, time() );
adm_check( ! ADM_Sync::sitemap_due(), 'Sitemap heute schon gelesen: nicht fällig' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'sitemap_hour' => 0 ) ) );
update_option( ADM_Sync::SITEMAP_READ, time() - DAY_IN_SECONDS - 60 );
adm_check( ADM_Sync::sitemap_due(), 'Sitemap gestern gelesen, Uhrzeit erreicht: fällig' );
update_option( ADM_Sync::SITEMAP_RETRY, time() + 600 );
adm_check( ! ADM_Sync::sitemap_due(), 'Nach Fehler: Wartezeit wird eingehalten' );
delete_option( ADM_Sync::SITEMAP_RETRY );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'sitemap_hour' => 3 ) ) );

// Altes Format aus 0.1.0
update_option( 'adm_settings', array( 'enabled' => 0, 'batch_size' => 9 ) );
adm_check( 'off' === ADM_Settings::get( 'mode' ) && 9 === (int) ADM_Settings::get( 'batch_size' ), 'Einstellungen aus 0.1.0 werden übernommen' );

ADM_Settings::save( array_merge( ADM_Settings::defaults(), array( 'delay' => 0, 'batch_size' => 100 ) ) );
ADM_Sync::reschedule();

// Szenario 1: Grundstand (Rest abarbeiten)
$r1 = ADM_Sync::tick( true );
$r2 = ADM_Sync::tick( true );
echo 'Bericht: ' . wp_json_encode( array( $r1, $r2 ) ) . "\n";
$c = ADM_DB::counts();
adm_check( 3 === $c['kaufbar'], 'genau 3 Deals kaufbar (' . $c['kaufbar'] . ')' );
adm_check( 60 === $c['ignoriert'], '60 Deals ignoriert (' . $c['ignoriert'] . ')' );
adm_check( 0 === $c['neu'], 'keine ungeprüften Deals' );

$lo = adm_get_deal( 'leadocean' );
adm_check( $lo && 'LeadOcean' === $lo['name'] && 49.0 === (float) $lo['price'] && 'USD' === $lo['currency'], 'LeadOcean: Name und Preis' );
adm_check( $lo && 4.88 === (float) $lo['rating'] && 141 === (int) $lo['review_count'], 'LeadOcean: Bewertung' );
adm_check( $lo && 'Sales & Leads > Lead Generation' === $lo['category'], 'LeadOcean: Kategorie (' . ( $lo ? $lo['category'] : '' ) . ')' );
adm_check( $lo && 1 === count( $lo['faq'] ) && "What if it's not for me?" === $lo['faq'][0]['q'], 'LeadOcean: FAQ dekodiert' );
adm_check( $lo && 60 === (int) $lo['return_days'], 'LeadOcean: Rückgabefrist' );
adm_check( $lo && (int) $lo['was_available'] === 1, 'LeadOcean: was_available gesetzt' );
adm_check( $lo && 'Bei AppSumo erhältlich' === $lo['reason'] && null === $lo['available_until'], 'Platzhalter-Enddatum verworfen: ' . ( $lo ? $lo['reason'] : '' ) );
adm_check( $lo && 'software' === $lo['deal_type'] && 'software' === $lo['appsumo_type'] && 'auto' === $lo['deal_type_source'], 'LeadOcean: Typ Software aus dem Link' );
$fp = adm_get_deal( 'free-playbook' );
$fc = adm_get_deal( 'founder-course' );
adm_check( $fp && 'ebook' === $fp['deal_type'] && 'courses-learning' === $fp['appsumo_type'] && 0.0 === (float) $fp['price'], 'Playbook: E-Book, kostenlos' );
adm_check( $fp && null === $fp['available_until'], 'Playbook: Platzhalter-Enddatum (364 Tage) verworfen' );
adm_check( $fc && 'kurs' === $fc['deal_type'], 'Masterclass: Kurs' );
adm_check( $fc && 'Bei AppSumo erhältlich, Angebot läuft bis ' . wp_date( 'd.m.Y', strtotime( $fc['available_until'] . ' UTC' ) ) === $fc['reason'] && strtotime( $fc['available_until'] . ' UTC' ) < time() + 21 * DAY_IN_SECONDS, 'Masterclass: echtes Enddatum bleibt (' . $fc['reason'] . ')' );
adm_check( 'ebook' === ADM_Parser::classify( 'courses-learning', 'Million Dollar Weekend: The Lost Chapter', '' ) && 'kurs' === ADM_Parser::classify( 'courses-learning', 'Starting From Zero', 'Learn how to turn your side hustle into a fully booked freelance business' ) && 'vorlage' === ADM_Parser::classify( 'templates', 'X', '' ) && '' === ADM_Parser::classify( '', 'X', '' ), 'Typ-Erkennung: Chapter, booked, Templates, unbekannt' );
adm_check( 'kurs' === ADM_Parser::classify( 'courses-learning', 'Facebook Ads Bootcamp', '' ), 'Typ-Erkennung: „Facebook“ ist kein Buch' );
// Echte Ausschnitte von appsumo.com (06.11.2026): FuseBase ohne Link, Playbook mit &
adm_check( 'software' === ADM_Parser::appsumo_type( ']},{\"id\":19,\"slug\":\"deal_type\",\"name\":\"Deal type\",\"type\":\"enumeration\",\"values\":[{\"name\":\"Software\",\"slug\":\"software\"}]},{\"id\":8,\"slug\":\"best_for\"' ), 'Echter Ausschnitt FuseBase: software' );
adm_check( 'courses-learning' === ADM_Parser::appsumo_type( ']},{\"id\":19,\"slug\":\"deal_type\",\"name\":\"Deal type\",\"type\":\"enumeration\",\"values\":[{\"name\":\"Courses & learning\",\"slug\":\"courses-learning\"}]},{\"id\":1,\"slug\":\"product_type\"' ), 'Echter Ausschnitt Playbook: courses-learning' );
$free = adm_get_deals( array( 'type' => 'ebook', 'free' => true ) );
adm_check( 1 === count( $free ) && 'free-playbook' === $free[0]['slug'], 'adm_get_deals: kostenlose E-Books' );
adm_check( 1 === count( adm_get_deals( array( 'type' => 'software' ) ) ), 'adm_get_deals: kaufbare Software' );
adm_check( 'software' === adm_get_deal( 'neuronwriter' )['deal_type'], 'NEURONwriter: Typ aus eingebetteten Seitendaten (maskiert)' );
$tc = ADM_DB::type_counts();
adm_check( 1 === $tc['software'] && 1 === $tc['ebook'] && 1 === $tc['kurs'], 'Zählung kaufbar nach Typ: ' . wp_json_encode( $tc ) );
// Manuelle Korrektur übersteht den Abgleich
ADM_DB::set_type( (int) $fc['id'], 'ebook' );
ADM_DB::update( (int) $fc['id'], array( 'next_check' => ADM_DB::now() ) );
ADM_Sync::tick( true );
$fc = adm_get_deal( 'founder-course' );
adm_check( 'ebook' === $fc['deal_type'] && 'manuell' === $fc['deal_type_source'], 'Manueller Typ bleibt nach dem Abgleich' );
ADM_DB::set_type( (int) $fc['id'], '' );
ADM_Sync::tick( true );
$fc = adm_get_deal( 'founder-course' );
adm_check( 'kurs' === $fc['deal_type'] && 'auto' === $fc['deal_type_source'], 'Zurück auf automatisch erkennt wieder Kurs' );
adm_check( false === ADM_DB::set_type( (int) $fc['id'], 'quatsch' ), 'Ungültiger Typ wird abgelehnt' );
adm_check( $lo && strtotime( $lo['next_check'] . ' UTC' ) > time() + 23 * HOUR_IN_SECONDS, 'LeadOcean: nächste Prüfung in etwa 24 Stunden' );

$nw = adm_get_deal( 'neuronwriter' );
adm_check( $nw && 'ignoriert' === $nw['status'] && 'SoldOut' === $nw['availability'], 'NEURONwriter: ausverkauft und nie kaufbar gesehen = ignoriert' );
adm_check( $nw && 'Ausverkauft, nie kaufbar gesehen' === $nw['reason'], 'Grund ignoriert: ' . ( $nw ? $nw['reason'] : '' ) );
adm_check( $nw && 3 === count( $nw['videos'] ), 'NEURONwriter: 3 gültige Videos (javascript-URL verworfen)' );
adm_check( $nw && 1 === (int) $nw['videos'][0]['appsumo'] && 0 === (int) $nw['videos'][1]['appsumo'], 'NEURONwriter: AppSumo-eigenes Video markiert' );
adm_check( $nw && 'SEO Optimization Made Easy with NeuronWriter' === $nw['videos'][1]['name'], 'NEURONwriter: Präfix im Videotitel entfernt' );
adm_check( $nw && strtotime( $nw['next_check'] . ' UTC' ) > time() + 29 * DAY_IN_SECONDS, 'NEURONwriter: nächste Prüfung in etwa 30 Tagen' );

$gone = adm_get_deal( 'gone-deal' );
adm_check( $gone && 'ignoriert' === $gone['status'] && false !== strpos( $gone['last_error'], '404' ), 'gone-deal: 404 erkannt' );
adm_check( $gone && 0 === strpos( $gone['reason'], 'Seite bei AppSumo entfernt' ), 'Grund 404: ' . ( $gone ? $gone['reason'] : '' ) );

$link = adm_affiliate_url( 'https://appsumo.com/products/leadocean/', 'Tool LeadOcean' );
adm_check( 'https://appsumo.8odi.net/c/5548040/2928062/7443?u=https%3A%2F%2Fappsumo.com%2Fproducts%2Fleadocean%2F&subId1=tool-leadocean' === $link, 'Affiliate-Link: ' . $link );
adm_check( '' === adm_affiliate_url( 'https://evil.example/products/x/' ), 'Affiliate-Link: fremde Adresse abgelehnt' );
$sc = do_shortcode( '[adm_link deal="leadocean" subid="test"]Zum Deal[/adm_link]' );
adm_check( false !== strpos( $sc, 'rel="sponsored nofollow noopener"' ) && false !== strpos( $sc, 'subId1=test' ) && false !== strpos( $sc, 'Werbelink' ), 'Shortcode: ' . $sc );

$ua = isset( $GLOBALS['adm_test_requests'][0]['ua'] ) ? $GLOBALS['adm_test_requests'][0]['ua'] : '';
adm_check( false !== strpos( $ua, 'ThokaAffiDealManager/' ), 'Eigene Kennung beim Abruf: ' . $ua );

// Szenario 2: LeadOcean verschwindet aus der Sitemap
update_option( 'adm_test_scenario', 2 );
delete_option( ADM_Sync::SITEMAP_READ );
$r3 = ADM_Sync::tick( true );
echo 'Bericht 2: ' . wp_json_encode( $r3 ) . "\n";
$lo = adm_get_deal( 'leadocean' );
adm_check( $lo && 'ausgelaufen' === $lo['status'] && 0 === (int) $lo['in_sitemap'], 'LeadOcean: nach Entfernen aus der Sitemap ausgelaufen' );
adm_check( $lo && 'Nicht mehr in der AppSumo-Sitemap, ausgelaufen seit ' . wp_date( 'd.m.Y' ) === $lo['reason'], 'Grund ausgelaufen: ' . ( $lo ? $lo['reason'] : '' ) );
adm_check( 'Noch nicht abgerufen' === ADM_DB::reason( array( 'status' => 'neu' ) ), 'Grund ungeprüft' );
adm_check( 'Angebot endete am 01.10.2026, ausgelaufen seit 02.10.2026' === ADM_DB::reason( array( 'status' => 'ausgelaufen', 'availability' => 'InStock', 'in_sitemap' => 1, 'available_until' => '2026-10-01 10:00:00', 'last_changed' => '2026-10-02 10:00:00' ) ), 'Grund Angebotsende' );
adm_check( in_array( array( (int) $lo['id'], 'kaufbar', 'ausgelaufen' ), $changes, true ), 'Hook adm_deal_status_changed ausgelöst' );

// Szenario 3: kaputte Sitemap ändert nichts und meldet sich per Mail
update_option( 'adm_test_scenario', 3 );
delete_option( ADM_Sync::SITEMAP_READ );
$before = ADM_DB::counts();
$r4     = ADM_Sync::read_sitemap();
adm_check( 0 === strpos( $r4, 'Fehler' ), 'Kaputte Sitemap erkannt: ' . $r4 );
adm_check( ADM_DB::counts() === $before, 'Kaputte Sitemap ändert keine Status' );
adm_check( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'Abgleich gestört' ), 'Störungs-Mail verschickt' );
ADM_Sync::read_sitemap();
adm_check( 1 === count( $mails ), 'Höchstens eine Mail pro Tag' );

// Sperre
set_transient( ADM_Sync::LOCK, 1, 60 );
$r5 = ADM_Sync::tick( true );
adm_check( '' !== $r5['skipped'], 'Gesperrter Abgleich läuft nicht doppelt' );
delete_transient( ADM_Sync::LOCK );
update_option( 'adm_test_scenario', 1 );

echo $fails ? "\n{$fails} Fehler\n" : "\nAlle Prüfungen bestanden\n";
exit( $fails ? 1 : 0 );
