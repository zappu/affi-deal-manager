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
adm_check( (bool) wp_next_scheduled( 'adm_sync_tick' ), 'Cron geplant' );

ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'delay' => 0, 'batch_size' => 50 ) ) );
update_option( 'adm_test_scenario', 1 );
delete_option( ADM_Sync::SITEMAP_READ );

// Szenario 1: Grundstand
$r1 = ADM_Sync::tick( true );
$r2 = ADM_Sync::tick( true );
echo 'Bericht: ' . wp_json_encode( array( $r1, $r2 ) ) . "\n";
$c = ADM_DB::counts();
adm_check( 1 === $c['kaufbar'], 'genau 1 Deal kaufbar (' . $c['kaufbar'] . ')' );
adm_check( 60 === $c['ignoriert'], '60 Deals ignoriert (' . $c['ignoriert'] . ')' );
adm_check( 0 === $c['neu'], 'keine ungeprüften Deals' );

$lo = adm_get_deal( 'leadocean' );
adm_check( $lo && 'LeadOcean' === $lo['name'] && 49.0 === (float) $lo['price'] && 'USD' === $lo['currency'], 'LeadOcean: Name und Preis' );
adm_check( $lo && 4.88 === (float) $lo['rating'] && 141 === (int) $lo['review_count'], 'LeadOcean: Bewertung' );
adm_check( $lo && 'Sales & Leads > Lead Generation' === $lo['category'], 'LeadOcean: Kategorie (' . ( $lo ? $lo['category'] : '' ) . ')' );
adm_check( $lo && 1 === count( $lo['faq'] ) && "What if it's not for me?" === $lo['faq'][0]['q'], 'LeadOcean: FAQ dekodiert' );
adm_check( $lo && 60 === (int) $lo['return_days'] && 0 === strpos( (string) $lo['available_until'], '2027-11-05' ), 'LeadOcean: Rückgabe und Laufzeit' );
adm_check( $lo && (int) $lo['was_available'] === 1, 'LeadOcean: was_available gesetzt' );
adm_check( $lo && strtotime( $lo['next_check'] . ' UTC' ) > time() + 23 * HOUR_IN_SECONDS, 'LeadOcean: nächste Prüfung in etwa 24 Stunden' );

$nw = adm_get_deal( 'neuronwriter' );
adm_check( $nw && 'ignoriert' === $nw['status'] && 'SoldOut' === $nw['availability'], 'NEURONwriter: ausverkauft und nie kaufbar gesehen = ignoriert' );
adm_check( $nw && 3 === count( $nw['videos'] ), 'NEURONwriter: 3 gültige Videos (javascript-URL verworfen)' );
adm_check( $nw && 1 === (int) $nw['videos'][0]['appsumo'] && 0 === (int) $nw['videos'][1]['appsumo'], 'NEURONwriter: AppSumo-eigenes Video markiert' );
adm_check( $nw && 'SEO Optimization Made Easy with NeuronWriter' === $nw['videos'][1]['name'], 'NEURONwriter: Präfix im Videotitel entfernt' );
adm_check( $nw && strtotime( $nw['next_check'] . ' UTC' ) > time() + 29 * DAY_IN_SECONDS, 'NEURONwriter: nächste Prüfung in etwa 30 Tagen' );

$gone = adm_get_deal( 'gone-deal' );
adm_check( $gone && 'ignoriert' === $gone['status'] && false !== strpos( $gone['last_error'], '404' ), 'gone-deal: 404 erkannt' );

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
