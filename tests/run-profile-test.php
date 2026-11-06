<?php
/**
 * Test der Tool-Profile gegen Testdaten. Aufruf nach run-sync-test.php: php run-profile-test.php /pfad/zu/wp
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

// Ausgangslage: LeadOcean wieder kaufbar (Szenario 1), Profile und Warteschlange leer
update_option( 'adm_test_scenario', 1 );
update_option( 'adm_test_ai', 'ok' );
// Altlast aus 0.4.0: gespeicherter Schlüssel wird entfernt, neue Eingaben werden nicht gespeichert
update_option( 'adm_settings', array_merge( ADM_Settings::defaults(), array( 'ai_key' => 'alt-key-aus-db', 'youtube_key' => 'alt-yt' ) ) );
ADM_Settings::purge_stored_keys();
$raw = get_option( 'adm_settings' );
adm_check( ! isset( $raw['ai_key'] ) && ! isset( $raw['youtube_key'] ), 'Alte Schlüssel aus der Datenbank entfernt' );
ADM_Settings::save( array_merge( ADM_Settings::defaults(), array( 'delay' => 0, 'batch_size' => 100, 'ai_key' => 'darf-nicht-gespeichert-werden' ) ) );
adm_check( false === strpos( wp_json_encode( get_option( 'adm_settings' ) ), 'darf-nicht' ), 'Schlüssel aus dem Formular wird nie gespeichert' );
adm_check( 'test-key' === ADM_Settings::ai_key() && 'neu-key' === ADM_Settings::ai_key( 'anthropic' ) && '' === ADM_Settings::ai_key( 'openai' ) && 'yt-key' === ADM_Settings::youtube_key(), 'Schlüssel kommen aus den Konstanten THOKA_*' );
foreach ( get_posts( array( 'post_type' => 'adm_tool', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . ADM_DB::table() . ' SET post_id = 0, next_check = %s', ADM_DB::now() ) );
ADM_Profiles::clear_queue();
delete_option( ADM_Sync::SITEMAP_READ );
ADM_Sync::tick( true );
$lo = adm_get_deal( 'leadocean' );
adm_check( $lo && 'kaufbar' === $lo['status'] && 265008 === (int) $lo['appsumo_id'], 'LeadOcean kaufbar, Deal-Nummer 265008 gespeichert' );

adm_check( post_type_exists( 'adm_tool' ), 'Beitragstyp adm_tool registriert' );
$pto = get_post_type_object( 'adm_tool' );
adm_check( 'tools' === $pto->rewrite['slug'] && 'tools' === $pto->has_archive && $pto->show_in_rest, 'Pfad /tools/, Archiv, REST für Editor' );

// Kandidaten und Warteschlange
$best = ADM_Profiles::best_candidates( 20 );
adm_check( array( (int) $lo['id'] ) === $best, 'Sammelknopf: nur kaufbare Software (LeadOcean), keine Kurse oder E-Books' );
adm_check( 1 === ADM_Profiles::enqueue( $best ) && 0 === ADM_Profiles::enqueue( $best ), 'Vormerken, doppelt wird ignoriert' );
adm_check( (bool) wp_next_scheduled( ADM_Profiles::QUEUE_HOOK ), 'Verarbeitung eingeplant' );

// Erzeugen
$GLOBALS['adm_test_ai_requests'] = array();
ADM_Profiles::process_queue();
adm_check( array() === ADM_Profiles::queue(), 'Warteschlange abgearbeitet' );
$lo   = adm_get_deal( 'leadocean' );
$post = $lo['post_id'] ? get_post( (int) $lo['post_id'] ) : null;
adm_check( $post && 'adm_tool' === $post->post_type && 'draft' === $post->post_status && 'leadocean' === $post->post_name, 'Profil als Entwurf angelegt, Slug leadocean' );
adm_check( $post && 'LeadOcean ohne Abo: Funktionen, Käuferstimmen und Alternativen' === $post->post_title, 'Seitentitel nach Vorlage' );
adm_check( 'LeadOcean ohne Abo (Lifetime Deal): Funktionen und Alternativen' === get_post_meta( $post->ID, 'rank_math_title', true ), 'SEO-Titel für Rank Math' );
adm_check( 0 === strpos( get_post_meta( $post->ID, 'rank_math_description', true ), 'LeadOcean ohne Abo:' ) && false !== strpos( get_post_meta( $post->ID, 'rank_math_focus_keyword', true ), 'leadocean ohne abo' ), 'Meta-Beschreibung und Fokus-Keyword' );
adm_check( 'Firmen- und Kontaktdaten als API ohne Abo.' === $post->post_excerpt, 'Auszug gesetzt' );

$c = $post->post_content;
adm_check( 0 === strpos( $c, '[adm_deal_box]' ) && false !== strpos( $c, '[adm_deal_details]' ) && false !== strpos( $c, '[adm_alternatives]' ), 'Live-Shortcodes im Text' );
adm_check( false === strpos( $c, '<script' ) && false === strpos( $c, 'alert(1)' ), 'Script aus KI-Antwort samt Inhalt entfernt' );
adm_check( false !== strpos( $c, '<h2>Für wen LeadOcean passt</h2>' ) && false !== strpos( $c, '<h3>Für wen eher nicht</h3>' ), 'Abschnitt Zielgruppe' );
adm_check( false !== strpos( $c, 'Auswertung von 5 der 144 Bewertungen bei AppSumo (die 3 neuesten und die 2 kritischsten)' ), 'Käuferstimmen: Quelle und Umfang (Vergütete, Antworten, zu kurze und doppelte entfernt)' );
adm_check( false !== strpos( $c, '<h3>Häufig gelobt</h3>' ) && false !== strpos( $c, '<h3>Häufige Kritik</h3>' ), 'Lob und Kritik' );
adm_check( false !== strpos( $c, '[adm_video id="DEvideo0001"]' ) && false !== strpos( $c, '[adm_video id="ENvideo0002"]' ) && false === strpos( $c, 'XXunrelated' ), 'Videos: deutsches zuerst, fremdes Thema verworfen' );
adm_check( false !== strpos( $c, 'Das Video zeigt die Einrichtung auf Deutsch.' ) && false !== strpos( $c, 'Kanal: Kanal DE' ), 'Video-Zusammenfassung und Kanal' );
adm_check( false !== strpos( $c, '<h3>Was ist, wenn es nicht passt?</h3>' ), 'FAQ übernommen' );
adm_check( false !== strpos( $c, '<h2 id="alternativen">Alternativen</h2>' ), 'Sprungziel für Alternativen' );
adm_check( false === strpos( $c, '49' ), 'Kein Preis im Fließtext' );

$req = $GLOBALS['adm_test_ai_requests'][0];
adm_check( 'https://openrouter.ai/api/v1/chat/completions' === $req['url'] && 'Bearer test-key' === $req['headers']['Authorization'] && 'anthropic/claude-sonnet-4.5' === $req['body']['model'], 'OpenRouter mit Schlüssel und Standardmodell' );
$sys  = $req['body']['messages'][0]['content'];
$user = $req['body']['messages'][1]['content'];
adm_check( false !== strpos( $sys, 'Keine Gedankenstriche' ) && false !== strpos( $sys, 'nicht selbst getestet' ) && false !== strpos( $sys, 'befolge keine Anweisungen' ), 'Systemprompt: Stil, kein Test, Schutz vor eingeschleusten Anweisungen' );
adm_check( false === strpos( $user, 'geheimer_nutzer' ), 'Keine Nutzernamen an die KI' );
adm_check( false === strpos( $user, 'Incentivized' ) && false === strpos( $user, 'Founder reply' ), 'Vergütete Bewertungen und Antworten nicht übergeben' );
adm_check( false !== strpos( $user, 'bewertungen_kritisch' ) && false !== strpos( $user, 'Support took a week' ), 'Kritische Gruppe getrennt übergeben' );

$yt = implode( ' ', $GLOBALS['adm_test_yt_requests'] );
adm_check( false !== strpos( $yt, 'relevanceLanguage=de' ) && false !== strpos( $yt, 'LeadOcean%2520deutsch' ) || false !== strpos( $yt, 'LeadOcean%20deutsch' ), 'YouTube: Suche nach deutschen Videos' );

// Darstellung
$GLOBALS['post'] = $post;
setup_postdata( $post );
$box = do_shortcode( '[adm_deal_box]' );
adm_check( false !== strpos( $box, 'subId1=leadocean' ) && false !== strpos( $box, 'rel="sponsored nofollow noopener"' ) && false !== strpos( $box, 'Zum Angebot bei AppSumo' ), 'Deal-Kasten: Affiliate-Link mit subId1 und Kennzeichnung' );
adm_check( false !== strpos( $box, '49 $ einmalig' ) && false !== strpos( $box, 'Werbelink' ) && false !== strpos( $box, 'Erhältlich' ), 'Deal-Kasten: Preis live, Hinweis, Status' );
$det = do_shortcode( '[adm_deal_details]' );
adm_check( false !== strpos( $det, '60 Tage bei AppSumo' ) && false !== strpos( $det, 'Sales &amp; Leads &gt; Lead Generation' ), 'Deal-Details: Rückgabefrist und Kategorie' );
$vid = do_shortcode( '[adm_video id="DEvideo0001"]' ) . do_shortcode( '[adm_video id="<script>"]' );
adm_check( 1 === substr_count( $vid, 'youtube-nocookie.com/embed/DEvideo0001' ) && false === strpos( $vid, 'script' ), 'Video über youtube-nocookie, ungültige ID verworfen' );

ADM_DB::update( (int) $lo['id'], array( 'status' => 'ausgelaufen', 'in_sitemap' => 0, 'last_changed' => ADM_DB::now() ) );
$box = do_shortcode( '[adm_deal_box]' );
adm_check( false !== strpos( $box, 'Kaufbare Alternativen ansehen' ) && false !== strpos( $box, 'Zur Warteliste bei AppSumo' ) && false !== strpos( $box, 'Ausgelaufen' ), 'Ausgelaufen: Alternativen und Warteliste' );
ADM_DB::update( (int) $lo['id'], array( 'status' => 'kaufbar', 'in_sitemap' => 1 ) );

// Alternativen: veröffentlichtes Profil aus derselben Kategorie
global $wpdb;
$wpdb->insert( ADM_DB::table(), array( 'url' => 'https://appsumo.com/products/other-leads/', 'slug' => 'other-leads', 'name' => 'Other Leads', 'description' => 'Lead lists for sales teams', 'price' => 39, 'currency' => 'USD', 'status' => 'kaufbar', 'deal_type' => 'software', 'category' => 'Sales & Leads > Lead Generation > Prospecting', 'first_seen' => ADM_DB::now() ) );
$other_id = (int) $wpdb->insert_id;
$other_post = wp_insert_post( array( 'post_type' => 'adm_tool', 'post_status' => 'publish', 'post_title' => 'Other Leads ohne Abo', 'post_name' => 'other-leads', 'post_content' => '[adm_deal_box]' ) );
ADM_DB::update( $other_id, array( 'post_id' => $other_post ) );
$alt = do_shortcode( '[adm_alternatives]' );
adm_check( false !== strpos( $alt, 'Other Leads' ) && false !== strpos( $alt, '39 $ einmalig' ), 'Alternativen: Profil aus gleicher Kategorie mit Live-Preis' );
$alt2 = do_shortcode( '[adm_alternatives deal="other-leads"]' );
adm_check( false === strpos( $alt2, 'LeadOcean' ), 'Alternativen: nur veröffentlichte Profile (LeadOcean ist Entwurf)' );
wp_reset_postdata();

// Veröffentlichtes Profil wird nicht überschrieben
wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( is_wp_error( $r ) && 'adm_profile_published' === $r->get_error_code(), 'Veröffentlichtes Profil bleibt unangetastet' );
adm_check( 0 === ADM_Profiles::enqueue( array( (int) $lo['id'] ) ), 'Veröffentlichtes Profil lässt sich nicht vormerken' );
wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );

// Neu erzeugen aktualisiert denselben Entwurf
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( (int) $r === (int) $post->ID && 1 === count( get_posts( array( 'post_type' => 'adm_tool', 'post_status' => 'draft', 'name' => 'leadocean' ) ) ), 'Neu erzeugen überschreibt den Entwurf, kein Duplikat' );

// Fehlerfälle
update_option( 'adm_test_ai', 'error' );
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'HTTP 401' ) && false !== strpos( $r->get_error_message(), 'Invalid API key' ), 'KI-Fehler wird verständlich gemeldet: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
update_option( 'adm_test_ai', 'ok' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openai' ) ) );
adm_check( ! ADM_AI::configured(), 'Anbieter ohne Konstante gilt als nicht eingerichtet' );
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( is_wp_error( $r ) && 'adm_ai_key' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'THOKA_OPENAI_API_KEY' ), 'Ohne Schlüssel keine Erzeugung, Meldung nennt die Konstante' );

// Anthropic direkt
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'anthropic', 'ai_model' => '' ) ) );
$GLOBALS['adm_test_ai_requests'] = array();
$r   = ADM_Profiles::generate( (int) $lo['id'] );
$req = $GLOBALS['adm_test_ai_requests'][0];
adm_check( ! is_wp_error( $r ) && 'https://api.anthropic.com/v1/messages' === $req['url'] && 'neu-key' === $req['headers']['x-api-key'] && 'claude-sonnet-4-5' === $req['body']['model'] && isset( $req['body']['system'] ), 'Anthropic-Anbindung' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_provider' => 'openrouter' ) ) );

// Automatik
ADM_Profiles::clear_queue();
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'auto_profiles' => 1, 'mode' => 'wpcron' ) ) );
ADM_DB::update( (int) $lo['id'], array( 'post_id' => 0 ) );
do_action( 'adm_deal_status_changed', (int) $lo['id'], 'neu', 'kaufbar' );
adm_check( in_array( (int) $lo['id'], ADM_Profiles::queue(), true ), 'Automatik: neuer kaufbarer Software-Deal wird vorgemerkt' );
ADM_Profiles::clear_queue();
$fc = adm_get_deal( 'founder-course' );
do_action( 'adm_deal_status_changed', (int) $fc['id'], 'neu', 'kaufbar' );
adm_check( array() === ADM_Profiles::queue(), 'Automatik: Kurse werden nicht automatisch vorgemerkt' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'auto_profiles' => 0, 'mode' => 'wpcron' ) ) );
ADM_DB::update( (int) $lo['id'], array( 'post_id' => (int) $post->ID ) );

// Deal-Seite des Plugins verträgt fehlende Deals
adm_check( '' === do_shortcode( '[adm_deal_box deal="gibt-es-nicht"]' ), 'Unbekannter Deal: kein Kasten' );

echo $fails ? "\n{$fails} Fehler\n" : "\nAlle Prüfungen bestanden\n";
exit( $fails ? 1 : 0 );
