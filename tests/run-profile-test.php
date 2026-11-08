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
adm_check( taxonomy_exists( 'adm_rubrik' ) && 13 === count( get_terms( array( 'taxonomy' => 'adm_rubrik', 'hide_empty' => false ) ) ), 'Taxonomie Rubrik mit 13 Rubriken' );
adm_check( 'seo' === ADM_Rubrics::for_category( 'Marketing > SEO & Analytics > Website Analytics' ) && 'wordpress-plugins' === ADM_Rubrics::for_category( 'Build & Code > Website Builders & WordPress > WordPress' ) && 'seo' === ADM_Rubrics::for_category( 'Build & Code > Website Builders & WordPress > SEO' ) && 'marketing-und-werbung' === ADM_Rubrics::for_category( 'Marketing > Social Media > Social Proof' ) && 'social-media' === ADM_Rubrics::for_category( 'Marketing > Social Media > Scheduling' ) && 'entwickler-tools' === ADM_Rubrics::for_category( 'Build & Code > Developer Tools > Dev Utilities' ) && 'sicherheit-und-datenschutz' === ADM_Rubrics::for_category( 'Build & Code > Security & Compliance > Cookie Consent' ) && 'formulare-und-app-baukaesten' === ADM_Rubrics::for_category( 'Build & Code > Forms & App Builders > Form Builders' ) && '' === ADM_Rubrics::for_category( 'Something > Unknown' ), 'Zuordnung AppSumo-Kategorie zu Rubrik' );
$names = array( 'WP Reset Pro Plan' => 'WP Reset Pro', 'Screpy - Plus Exclusive' => 'Screpy', 'Writeseed - AI Content Writer' => 'Writeseed', 'Sonny: White-Label Helpdesk' => 'Sonny', 'AutoScaled (fka SlideFill)' => 'AutoScaled', 'testimonials.ltd' => 'testimonials.ltd', 'Reoon Lead Scraper (YellowPages)' => 'Reoon Lead Scraper (YellowPages)', 'NeuronWriter' => 'NeuronWriter' );
$bad   = array();
foreach ( $names as $in => $want ) {
	if ( ADM_DB::clean_name( $in ) !== $want ) {
		$bad[] = $in . ' → ' . ADM_DB::clean_name( $in );
	}
}
adm_check( ! $bad && 'How to Start: A Beginner Guide' === ADM_DB::clean_name( 'How to Start: A Beginner Guide - Plus exclusive', false ), 'Namen bereinigt, Kurstitel bleiben ' . implode( ', ', $bad ) );
adm_check( 'Du sagst, du kannst.' === ADM_Style::fix( 'Du sagst, Du kannst.' ) && array() === ADM_Style::check( '<p>Das Tool ist schnell.</p>' ), 'Stil: großes Du mitten im Satz korrigiert, sauberer Text ohne Hinweise' );
$pto = get_post_type_object( 'adm_tool' );
adm_check( 'tools' === $pto->rewrite['slug'] && 'tools' === $pto->has_archive && $pto->show_in_rest, 'Pfad /tools/, Archiv, REST für Editor' );

// Kandidaten und Warteschlange
$best = ADM_Profiles::best_candidates( 20 );
adm_check( array( (int) $lo['id'] ) === $best, 'Sammelknopf: nur kaufbare Software (LeadOcean), keine Kurse oder E-Books' );
adm_check( 1 === ADM_Profiles::enqueue( $best ) && 0 === ADM_Profiles::enqueue( $best ), 'Vormerken, doppelt wird ignoriert' );
adm_check( (bool) wp_next_scheduled( ADM_Profiles::QUEUE_HOOK ), 'Verarbeitung eingeplant' );

// Erzeugen
$GLOBALS['adm_test_ai_requests']     = array();
$GLOBALS['adm_test_gemini_requests'] = array();
$GLOBALS['adm_test_yt_requests']     = array();
$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '%adm\\_vsum%'" );
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
adm_check( 0 === strpos( $c, '[adm_deal_box placement="content"]' ) && false !== strpos( $c, '[adm_toc]' ) && false !== strpos( $c, '[adm_deal_details]' ) && false !== strpos( $c, '[adm_alternatives ' ), 'Live-Shortcodes im Text' );
adm_check( false === strpos( $c, '<script' ) && false === strpos( $c, 'alert(1)' ), 'Script aus KI-Antwort samt Inhalt entfernt' );
adm_check( false !== strpos( $c, '<h2>Für wen LeadOcean passt</h2>' ) && false !== strpos( $c, '<h3>Für wen eher nicht</h3>' ), 'Abschnitt Zielgruppe' );
adm_check( false === strpos( $c, 'nicht selbst geprüft' ) && false !== strpos( $c, 'Auswertung von 5 der 144 Bewertungen bei AppSumo (die 3 neuesten und die 2 kritischsten)' ), 'Käuferstimmen: Quelle und Umfang (Vergütete, Antworten, zu kurze und doppelte entfernt)' );
adm_check( false !== strpos( $c, '<section class="adm-glance" id="auf-einen-blick"><h2>Auf einen Blick</h2>' ) && false !== strpos( $c, 'Stärken</p><ul><li>Große Datenbasis' ) && false !== strpos( $c, 'Schwächen</p><ul><li>Limits werden schnell erreicht' ) && strpos( $c, 'auf-einen-blick' ) < strpos( $c, 'kurz-erklaert' ), 'Auf einen Blick: Stärken und Schwächen oben' );
adm_check( 6 <= substr_count( $c, '<section class="adm-sec" id="' ) && false === strpos( $c, '<h3>Häufig gelobt</h3>' ), 'Abschnitte als Karten mit Sprungzielen, keine doppelten Lob-Listen' );
adm_check( strpos( $c, '[adm_video id="DEvideo0001"' ) < strpos( $c, '[adm_video id="ENvideo0002"' ) && false === strpos( $c, 'XXunrelated' ), 'Videos: deutsches zuerst, fremdes Thema verworfen' );
adm_check( false === strpos( $c, 'PROMOvideo3' ) && false === strpos( $c, 'SHORTvideo4' ), 'Werbevideo nach hinten, Short verworfen' );
adm_check( false !== strpos( $c, '<h2>Videos zu LeadOcean</h2>' ) && false === strpos( $c, 'Testvideos' ) && false === strpos( $c, '🚀' ), 'Abschnitt „Videos zu …“, Emoji aus dem Titel entfernt' );
adm_check( false !== strpos( $c, 'Das Video zeigt die Einrichtung auf Deutsch.' ) && false !== strpos( $c, 'Kanal: Kanal DE' ) && false !== strpos( $c, 'Sprache: Deutsch' ) && false !== strpos( $c, 'Länge: 8 Minuten' ) && false === strpos( $c, 'Zusammenfassung automatisch' ), 'Video: Zusammenfassung mit Kanal, Sprache und Länge' );
adm_check( false !== strpos( $c, 'Sprache: Englisch' ) && 1 === substr_count( $c, 'Das Video wirbt für ein Angebot' ), 'Werbevideo laut Gemini gekennzeichnet' );
adm_check( false === strpos( $c, 'sprechende Person' ), 'Videotext ohne „die sprechende Person“' );
$gem = $GLOBALS['adm_test_gemini_requests'];
adm_check( 2 === count( $gem ) && 'gem-key' === $gem[0]['headers']['x-goog-api-key'] && 'https://www.youtube.com/watch?v=DEvideo0001' === $gem[0]['body']['contents'][0]['parts'][0]['file_data']['file_uri'] && 'MEDIA_RESOLUTION_LOW' === $gem[0]['body']['generationConfig']['mediaResolution'] && false !== strpos( $gem[0]['url'], 'gemini-3.6-flash:generateContent' ) && false === strpos( $gem[0]['url'], 'gem-key' ), 'Gemini: YouTube-Adresse, niedrige Auflösung, Schlüssel im Header' );
adm_check( false !== strpos( implode( ' ', $GLOBALS['adm_test_yt_requests'] ), 'youtube/v3/videos' ), 'YouTube: Details (Dauer, Sprache) abgefragt' );
adm_check( home_url( '/' ) === $GLOBALS['adm_test_yt_referer'], 'YouTube: Referer der eigenen Website (Schlüssel mit Website-Einschränkung)' );
adm_check( false !== strpos( $c, 'für die du Daten' ) && false === strpos( $c, 'für die Du' ), 'Großes „Du“ mitten im Satz klein geschrieben' );
$hints = get_post_meta( $post->ID, '_adm_style_hints', true );
adm_check( is_array( $hints ) && false !== strpos( implode( ' ', $hints ), 'Gedankenstrich' ), 'Stilhinweis gespeichert: ' . ( is_array( $hints ) ? implode( '; ', $hints ) : '' ) );
$log = get_option( ADM_Profiles::LOG );
adm_check( false !== strpos( $log[0]['msg'], 'Stilhinweise: 1 Gedankenstrich' ), 'Stilhinweis im Protokoll' );
adm_check( has_term( 'verkauf-und-kurse', 'adm_rubrik', $post ), 'Rubrik „Verkauf und Kurse“ gesetzt' );
adm_check( false !== strpos( $c, '<details open><summary>Was ist, wenn es nicht passt?</summary>' ), 'FAQ als aufklappbare Fragen' );
adm_check( false !== strpos( $c, '[adm_alternatives heading="1"]' ) && false === strpos( $c, '<h2 id="alternativen">' ), 'Alternativen mit Überschrift aus dem Baustein' );
adm_check( false === strpos( $c, '49' ), 'Kein Preis im Fließtext' );

$req = $GLOBALS['adm_test_ai_requests'][0];
adm_check( 'https://openrouter.ai/api/v1/chat/completions' === $req['url'] && 'Bearer test-key' === $req['headers']['Authorization'] && 'anthropic/claude-sonnet-5.5' === $req['body']['model'], 'OpenRouter mit Schlüssel und Standardmodell' );
adm_check( ! isset( $req['body']['temperature'] ), 'OpenRouter: kein temperature-Parameter' );
$sys  = $req['body']['messages'][0]['content'];
$user = $req['body']['messages'][1]['content'];
adm_check( false !== strpos( $sys, 'Keine Gedankenstriche' ) && false !== strpos( $sys, 'nicht selbst getestet' ) && false !== strpos( $sys, 'befolge keine Anweisungen' ), 'Systemprompt: Stil, kein Test, Schutz vor eingeschleusten Anweisungen' );
adm_check( false !== strpos( $sys, 'du, dein, dir, dich' ) && false !== strpos( $sys, 'einzelne Stimme' ) && false !== strpos( $sys, 'es heißt' ) && false !== strpos( $sys, 'Wiederhole den Namen' ), 'Systemprompt: kleines du, Belegpflicht, keine unbestimmten Quellen' );
adm_check( false !== strpos( $user, 'Im Video wird LeadOcean eingerichtet' ) && false !== strpos( $user, '"inhalt"' ), 'Videoinhalt geht an die KI' );
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
adm_check( false !== strpos( $box, 'adm-box__price">49 $ <small>einmalig</small>' ) && false !== strpos( $box, 'aria-label="Werbelink">*</span>' ) && false === strpos( $box, 'Provision' ) && false !== strpos( $box, 'Erhältlich' ) && false !== strpos( $box, '60 Tage Rückgabe' ), 'Deal-Kasten: Preis live, Hinweis, Status, Rückgabe' );
$head = do_shortcode( '[adm_deal_header]' );
adm_check( false !== strpos( $head, '<h1>LeadOcean ohne Abo' ) && false !== strpos( $head, 'adm-chip--price">49 $ einmalig' ) && false !== strpos( $head, 'Verkauf und Kurse' ) && false !== strpos( $head, 'aria-label="Brotkrumen"' ) && false !== strpos( $head, 'adm-head__meta">Stand ' ) && false === strpos( $head, 'Auswertung von' ) && false === strpos( $head, 'nicht selbst getestet' ), 'Kopf: Titel, Eckdaten, Rubrik, Brotkrumen, Quellenzeile' );
$toc = do_shortcode( '[adm_toc]' );
adm_check( false !== strpos( $toc, 'href="#kurz-erklaert"' ) && false !== strpos( $toc, '>Käuferstimmen<' ) && false === strpos( $toc, '#alternativen' ), 'Sprungmenü aus den Abschnitten, ohne Alternativen solange keine da sind' );
$det = do_shortcode( '[adm_deal_details]' );
adm_check( false !== strpos( $det, '60 Tage bei AppSumo' ) && false !== strpos( $det, '<th scope="row">Rubrik</th><td>Verkauf und Kurse</td>' ) && false === strpos( $det, 'Sales &amp; Leads' ), 'Deal-Details: Rückgabefrist und Rubrik statt AppSumo-Kategorie' );
$vid = do_shortcode( '[adm_video id="DEvideo0001" title="Test"]' ) . do_shortcode( '[adm_video id="<script>"]' );
adm_check( false !== strpos( $vid, 'data-id="DEvideo0001"' ) && false === strpos( $vid, '<iframe' ) && false !== strpos( $vid, 'Video von YouTube laden' ) && false === strpos( $vid, '<script' ), 'Video: Zwei-Klick, lädt nichts von YouTube vor dem Klick, ungültige ID verworfen' );
global $wp_scripts;
adm_check( wp_script_is( 'adm-video', 'enqueued' ) && false !== strpos( implode( '', (array) $wp_scripts->get_data( 'adm-video', 'after' ) ), 'youtube-nocookie.com/embed/' ), 'Video-Skript lädt erst beim Klick von youtube-nocookie.com' );

ADM_DB::update( (int) $lo['id'], array( 'status' => 'ausgelaufen', 'in_sitemap' => 0, 'last_changed' => ADM_DB::now() ) );
$box = do_shortcode( '[adm_deal_box]' );
adm_check( false === strpos( $box, 'Kaufbare Alternativen ansehen' ) && false !== strpos( $box, 'class="adm-btn" href="https://appsumo.8odi.net' ) && false !== strpos( $box, 'Zur Warteliste bei AppSumo' ) && false !== strpos( $box, 'Ausgelaufen' ), 'Ausgelaufen ohne Alternativen: nur Warteliste als Knopf' );
adm_check( '' === do_shortcode( '[adm_alternatives heading="1"]' ), 'Keine Alternativen: Abschnitt bleibt ganz leer' );

// Alternativen: veröffentlichtes Profil aus derselben Kategorie
global $wpdb;
// Kategorie passt nicht, die Rubrik ist von Hand gesetzt: gefunden wird es über die Rubrik.
$wpdb->delete( ADM_DB::table(), array( 'slug' => 'other-leads' ) );
$wpdb->insert( ADM_DB::table(), array( 'url' => 'https://appsumo.com/products/other-leads/', 'slug' => 'other-leads', 'name' => 'Other Leads - Plus exclusive', 'description' => 'Lead lists for sales teams', 'price' => 39, 'currency' => 'USD', 'status' => 'kaufbar', 'deal_type' => 'software', 'category' => 'Operations > Something Else', 'first_seen' => ADM_DB::now() ) );
$other_id = (int) $wpdb->insert_id;
$other_post = wp_insert_post( array( 'post_type' => 'adm_tool', 'post_status' => 'publish', 'post_title' => 'Other Leads ohne Abo', 'post_name' => 'other-leads', 'post_content' => '[adm_deal_box]' ) );
wp_set_object_terms( $other_post, array( 'verkauf-und-kurse' ), 'adm_rubrik' );
ADM_DB::update( $other_id, array( 'post_id' => $other_post ) );
$alt = do_shortcode( '[adm_alternatives heading="1"]' );
adm_check( false !== strpos( $alt, 'adm-alt__name">Other Leads</p>' ) && false === strpos( $alt, 'Plus exclusive' ) && false !== strpos( $alt, '39 $ einmalig' ) && 0 === strpos( $alt, '<section class="adm-sec" id="alternativen"><h2>Alternativen</h2>' ), 'Alternativen: über die Rubrik, bereinigter Name, Live-Preis, Karte mit Überschrift' );
adm_check( false !== strpos( do_shortcode( '[adm_toc]' ), '#alternativen' ), 'Sprungmenü nennt Alternativen, sobald es welche gibt' );
$box = do_shortcode( '[adm_deal_box]' );
adm_check( false !== strpos( $box, 'Kaufbare Alternativen ansehen' ) && false !== strpos( $box, 'Zur Warteliste bei AppSumo' ), 'Ausgelaufen mit Alternativen: Knopf zu den Alternativen und Warteliste' );
ADM_DB::update( (int) $lo['id'], array( 'status' => 'kaufbar', 'in_sitemap' => 1 ) );
adm_check( false !== strpos( do_shortcode( '[adm_deal_box deal="other-leads"]' ), 'adm-box__name">Other Leads</p>' ), 'Deal-Kasten zeigt den bereinigten Namen' );
$alt2 = do_shortcode( '[adm_alternatives deal="other-leads"]' );
adm_check( false === strpos( $alt2, 'LeadOcean' ), 'Alternativen: nur veröffentlichte Profile (LeadOcean ist Entwurf)' );
wp_reset_postdata();

// Veröffentlichtes Profil wird nicht überschrieben
wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( is_wp_error( $r ) && 'adm_profile_published' === $r->get_error_code(), 'Veröffentlichtes Profil bleibt unangetastet' );
adm_check( 0 === ADM_Profiles::enqueue( array( (int) $lo['id'] ) ), 'Veröffentlichtes Profil lässt sich nicht vormerken' );
wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );

// Neu erzeugen aktualisiert denselben Entwurf, Videozusammenfassungen kommen aus dem Zwischenspeicher
$GLOBALS['adm_test_gemini_requests'] = array();
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( array() === $GLOBALS['adm_test_gemini_requests'], 'Videozusammenfassung zwischengespeichert, keine zweite Gemini-Anfrage' );
adm_check( (int) $r === (int) $post->ID && 1 === count( get_posts( array( 'post_type' => 'adm_tool', 'post_status' => 'draft', 'name' => 'leadocean' ) ) ), 'Neu erzeugen überschreibt den Entwurf, kein Duplikat' );

// Anzeigename im Profil-Kasten speichern
wp_set_current_user( 1 );
$_POST = array( 'adm_display_name' => 'Lead Ocean', 'adm_display_name_nonce' => wp_create_nonce( 'adm_display_name' ) );
ADM_Profiles::save_meta_box( $post->ID, get_post( $post->ID ) );
$_POST = array();
$lo    = adm_get_deal( 'leadocean' );
adm_check( 'Lead Ocean' === $lo['display_name'] && 'Lead Ocean' === $lo['title'] && 'LeadOcean' === $lo['name'], 'Anzeigename gespeichert, AppSumo-Name bleibt' );

// Ohne Videozusammenfassung: keine Inhaltsangabe, auch wenn die KI eine liefert
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'video_summaries' => 0, 'mode' => 'wpcron' ) ) );
$GLOBALS['adm_test_ai_requests'] = array();
$GLOBALS['adm_test_gemini_requests'] = array();
$r  = ADM_Profiles::generate( (int) $lo['id'] );
$c2 = get_post( $post->ID )->post_content;
$u2 = $GLOBALS['adm_test_ai_requests'][0]['body']['messages'][1]['content'];
adm_check( ! is_wp_error( $r ) && array() === $GLOBALS['adm_test_gemini_requests'] && false === strpos( $c2, 'Das Video zeigt die Einrichtung' ) && false === strpos( $c2, 'Zusammenfassung automatisch' ) && false !== strpos( $c2, '[adm_video id="DEvideo0001"' ), 'Ohne Zusammenfassung: Video ohne Inhaltsangabe' );
adm_check( false !== strpos( $c2, '<h2>Videos zu Lead Ocean</h2>' ) && false !== strpos( $u2, 'leeres Array' ), 'Neuer Anzeigename im Text, KI soll keine Videotexte liefern' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'video_summaries' => 1, 'mode' => 'wpcron' ) ) );
ADM_DB::update( (int) $lo['id'], array( 'display_name' => '' ) );

// Alle Entwürfe neu erzeugen: nur Entwürfe, veröffentlichte nicht
ADM_Profiles::clear_queue();
adm_check( array( (int) $lo['id'] ) === ADM_Profiles::draft_deals() && 1 === ADM_Profiles::enqueue( ADM_Profiles::draft_deals() ), 'Entwürfe neu vormerken (Other Leads ist veröffentlicht und fehlt)' );
ADM_Profiles::clear_queue();

// Gemini überlastet: zweimal das eingestellte Modell, dann das Ausweichmodell
update_option( 'adm_test_gemini', 'overloaded' );
delete_transient( 'adm_vsum2_DEvideo0001' );
$GLOBALS['adm_test_gemini_requests'] = array();
$sum = ADM_AI::video_summary( 'DEvideo0001', 'LeadOcean' );
$urls = wp_list_pluck( $GLOBALS['adm_test_gemini_requests'], 'url' );
adm_check( ! is_wp_error( $sum ) && 3 === count( $urls ) && false !== strpos( $urls[2], 'gemini-3.5-flash-lite' ), 'Gemini überlastet: Wiederholung, dann Ausweichmodell' );
delete_option( 'adm_test_gemini' );

// AppSumo Plus: kein Profil, eigene Abfrage für die Plus-Seite
$wpdb->delete( ADM_DB::table(), array( 'slug' => 'screpy-plus' ) );
$wpdb->delete( ADM_DB::table(), array( 'slug' => 'plus-2019' ) );
$wpdb->insert( ADM_DB::table(), array( 'url' => 'https://appsumo.com/products/screpy-plus/', 'slug' => 'screpy-plus', 'name' => 'Screpy - Plus Exclusive', 'price' => 49, 'currency' => 'USD', 'status' => 'kaufbar', 'deal_type' => 'software', 'review_count' => 99999, 'category' => 'Marketing > SEO & Analytics > SEO', 'first_seen' => ADM_DB::now() ) );
$plus_id = (int) $wpdb->insert_id;
$wpdb->insert( ADM_DB::table(), array( 'url' => 'https://appsumo.com/products/plus-2019/', 'slug' => 'plus-2019', 'name' => 'AppSumo Plus', 'price' => 99, 'currency' => 'USD', 'status' => 'kaufbar', 'deal_type' => 'software', 'review_count' => 88888, 'first_seen' => ADM_DB::now() ) );
$plus_deal = ADM_DB::get( $plus_id );
adm_check( $plus_deal['plus'] && 'Screpy' === $plus_deal['title'] && ADM_DB::is_plus( adm_get_deal( 'plus-2019' ) ) && ! ADM_DB::is_plus( adm_get_deal( 'leadocean' ) ), 'Plus-Deals erkannt (Plus exclusive und die Mitgliedschaft selbst)' );
adm_check( ! in_array( $plus_id, ADM_Profiles::best_candidates( 50 ), true ) && 0 === ADM_Profiles::enqueue( array( $plus_id ) ), 'Plus-Deals: nicht beim Sammelknopf, nicht vormerkbar' );
$r = ADM_Profiles::generate( $plus_id );
adm_check( is_wp_error( $r ) && 'adm_profile_plus' === $r->get_error_code(), 'Plus-Deal: keine Erzeugung' );
$all_slugs  = wp_list_pluck( adm_get_deals( array( 'limit' => 100 ) ), 'slug' );
$plus_slugs = wp_list_pluck( adm_get_deals( array( 'limit' => 100, 'plus' => 'only' ) ), 'slug' );
adm_check( ! in_array( 'screpy-plus', $all_slugs, true ) && ! in_array( 'plus-2019', $all_slugs, true ) && in_array( 'screpy-plus', $plus_slugs, true ) && ! in_array( 'leadocean', $plus_slugs, true ), 'adm_get_deals: ohne Plus als Standard, plus=only für die Plus-Seite' );
$wpdb->delete( ADM_DB::table(), array( 'id' => $plus_id ) );
$wpdb->delete( ADM_DB::table(), array( 'slug' => 'plus-2019' ) );

// Videos nachtragen, ohne den Text neu zu schreiben
ADM_Profiles::clear_queue();
update_option( ADM_Profiles::VQUEUE, array() );
$pid_lo = (int) adm_get_deal( 'leadocean' )['post_id'];
$c_old  = preg_replace( '#\n*<section class="adm-sec" id="videos">.*?</section>#s', '', get_post( $pid_lo )->post_content );
wp_update_post( wp_slash( array( 'ID' => $pid_lo, 'post_content' => $c_old ) ) );
adm_check( in_array( (int) $lo['id'], ADM_Profiles::drafts_without_videos(), true ), 'Entwurf ohne Videos wird erkannt' );
update_option( 'adm_test_yt', 'quota' );
adm_check( 1 === ADM_Profiles::enqueue_videos( ADM_Profiles::drafts_without_videos() ), 'Videos nachtragen vorgemerkt' );
$GLOBALS['adm_test_ai_requests'] = array();
delete_transient( ADM_Profiles::LOCK );
ADM_Profiles::process_queue();
$log = get_option( ADM_Profiles::LOG );
adm_check( array() === $GLOBALS['adm_test_ai_requests'] && array() === ADM_Profiles::video_queue() && false !== strpos( $log[0]['msg'], 'Tageskontingent der YouTube-API' ), 'Videos nachtragen: keine KI für den Text, Hinweis auf YouTube-Kontingent: ' . $log[0]['msg'] );
delete_option( 'adm_test_yt' );
ADM_Profiles::enqueue_videos( array( (int) $lo['id'] ) );
delete_transient( ADM_Profiles::LOCK );
ADM_Profiles::process_queue();
$c_new = get_post( $pid_lo )->post_content;
adm_check( 1 === substr_count( $c_new, 'id="videos"' ) && strpos( $c_new, 'id="videos"' ) < strpos( $c_new, 'id="details"' ) && false !== strpos( $c_new, 'Im Video wird LeadOcean eingerichtet' ) && array() === $GLOBALS['adm_test_ai_requests'], 'Videos nachgetragen vor den Deal-Details, Zusammenfassung aus Gemini, Text unverändert' );
adm_check( array() === ADM_Style::check( '<p>Für SEO-Experten und Entwickler.</p>' ) && 1 === count( ADM_Style::check( '<p>Experten empfehlen das Tool.</p>' ) ), 'Stilprüfung: „SEO-Experten“ kein Fehlalarm, „Experten empfehlen“ schon' );
adm_check( 'Die Person im Video lobt den Export, der Person im Video fehlt eine App.' === ADM_Style::video_wording( 'Die sprechende Person lobt den Export, der sprechenden Person fehlt eine App.' ), 'Videotext: „die sprechende Person“ wird zu „die Person im Video“' );

// Abgebrochener Lauf (Zeitlimit) wird gemeldet
ADM_Profiles::clear_queue();
update_option( ADM_Profiles::CURRENT, array( 'id' => (int) $lo['id'], 'started' => time() - 1200 ) );
delete_transient( ADM_Profiles::LOCK );
ADM_Profiles::process_queue();
$log = get_option( ADM_Profiles::LOG );
adm_check( false === get_option( ADM_Profiles::CURRENT ) && false !== strpos( $log[0]['msg'], 'Zeitlimit' ), 'Abgebrochener Lauf erscheint im Protokoll' );

// Abgeschnittene KI-Antwort: zweiter Versuch mit mehr Platz
update_option( 'adm_test_ai', 'truncated_once' );
$GLOBALS['adm_test_ai_requests'] = array();
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( ! is_wp_error( $r ) && 2 === count( $GLOBALS['adm_test_ai_requests'] ) && 12000 === $GLOBALS['adm_test_ai_requests'][1]['body']['max_tokens'], 'Abgeschnittenes JSON: zweiter Versuch mit mehr Platz' );
update_option( 'adm_test_ai', 'ok' );

// Fehlerfälle
update_option( 'adm_test_ai', 'error' );
$r = ADM_Profiles::generate( (int) $lo['id'] );
adm_check( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'HTTP 401' ) && false !== strpos( $r->get_error_message(), 'Invalid API key' ), 'KI-Fehler wird verständlich gemeldet: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
// Einstellungsfehler: Warteschlange hält an, Auftrag bleibt erhalten
ADM_Profiles::clear_queue();
ADM_Profiles::enqueue( array( (int) $lo['id'] ) );
ADM_Profiles::process_queue();
adm_check( array( (int) $lo['id'] ) === ADM_Profiles::queue() && false !== strpos( ADM_Profiles::paused(), 'HTTP 401' ), 'Einstellungsfehler: Auftrag bleibt, Warteschlange angehalten' );
$GLOBALS['adm_test_ai_requests'] = array();
ADM_Profiles::process_queue();
adm_check( array() === $GLOBALS['adm_test_ai_requests'], 'Angehalten: keine weiteren KI-Anfragen' );
update_option( 'adm_test_ai', 'ok' );
ADM_Profiles::resume();
adm_check( '' === ADM_Profiles::paused() && (bool) wp_next_scheduled( ADM_Profiles::QUEUE_HOOK ), 'Fortsetzen hebt die Pause auf und plant neu' );
ADM_Profiles::process_queue();
adm_check( array() === ADM_Profiles::queue(), 'Nach dem Fortsetzen abgearbeitet' );

// Modellnamen mit Tilde (OpenRouter-Aliasse) bleiben erhalten
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_model' => '~anthropic/claude-sonnet-latest' ) ) );
adm_check( '~anthropic/claude-sonnet-latest' === ADM_Settings::ai_model(), 'Modell mit Tilde wird gespeichert' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'ai_model' => '' ) ) );

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
adm_check( ! isset( $req['body']['temperature'] ), 'Kein temperature-Parameter (Claude 5.5 lehnt ihn ab)' );
adm_check( ! is_wp_error( $r ) && 'https://api.anthropic.com/v1/messages' === $req['url'] && 'neu-key' === $req['headers']['x-api-key'] && 'claude-sonnet-5-5' === $req['body']['model'] && isset( $req['body']['system'] ), 'Anthropic-Anbindung' );
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

// Einstellung Deal-Kasten
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'sidebar', 'mode' => 'wpcron' ) ) );
adm_check( 'sidebar' === ADM_Settings::get( 'box_placement' ), 'Einstellung Deal-Kasten in der Seitenleiste' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'quatsch', 'mode' => 'wpcron' ) ) );
adm_check( 'sidebar' === ADM_Settings::get( 'box_placement' ), 'Ungültiger Wert wird verworfen' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'content', 'mode' => 'wpcron' ) ) );
adm_check( file_exists( ADM_DIR . 'assets/adm-frontend.css' ) && '' === ADM_Rubrics::image_html( 999999 ), 'Stylesheet vorhanden, Rubrik ohne Bild liefert kein Bild' );

// Profil-Layout über the_content (Aufruf wie auf der Profilseite)
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'sidebar', 'mode' => 'wpcron' ) ) );
$lo2 = adm_get_deal( 'leadocean' );
wp_update_post( array( 'ID' => (int) $lo2['post_id'], 'post_status' => 'publish' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => (int) $lo2['post_id'], 'post_type' => 'adm_tool' ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();
$html = apply_filters( 'the_content', get_post( (int) $lo2['post_id'] )->post_content );
adm_check( 1 === substr_count( $html, 'adm-page__cols' ) && false !== strpos( $html, '<header class="adm adm-head">' ) && false !== strpos( $html, 'adm-box--side' ) && false !== strpos( $html, 'adm-box--inline adm-only-mobile' ) && false === apply_filters( 'hello_elementor_page_title', true ), 'Profil-Layout: Kopf, Seitenleiste, Kasten im Text nur mobil, Theme-Titel aus' );
adm_check( in_array( 'adm-layout', get_body_class(), true ), 'Seite bekommt die Klasse adm-layout' );
wp_reset_postdata();
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'content', 'mode' => 'wpcron' ) ) );
wp_update_post( array( 'ID' => (int) $lo2['post_id'], 'post_status' => 'draft' ) );

// Übersicht /tools/: Karte und Sortierung (kaufbare zuerst)
$lo3 = adm_get_deal( 'leadocean' );
wp_update_post( array( 'ID' => (int) $lo3['post_id'], 'post_status' => 'publish' ) );
$card = ADM_Archive::card( get_post( (int) $lo3['post_id'] ) );
adm_check( false !== strpos( $card, 'class="adm-card"' ) && false !== strpos( $card, get_permalink( (int) $lo3['post_id'] ) ) && false !== strpos( $card, 'adm-chip--price' ) && false !== strpos( $card, 'Bewertungen auf AppSumo' ), 'Übersicht: Karte mit Link, Preis und Bewertung' );
$other = wp_insert_post( array( 'post_type' => 'adm_tool', 'post_status' => 'publish', 'post_title' => 'AAA ausgelaufen' ) );
$GLOBALS['wpdb']->insert( ADM_DB::table(), array( 'slug' => 'aaa-test-alt', 'name' => 'AAA', 'url' => 'https://appsumo.com/products/aaa-test-alt/', 'status' => 'ausgelaufen', 'review_count' => 9999, 'post_id' => $other ) );
$q = new WP_Query( array( 'post_type' => 'adm_tool', 'adm_sort' => 1, 'posts_per_page' => 10, 'post__in' => array( $other, (int) $lo3['post_id'] ) ) );
adm_check( 2 === count( $q->posts ) && (int) $q->posts[0]->ID === (int) $lo3['post_id'], 'Übersicht: kaufbare vor ausgelaufenen, auch bei mehr Bewertungen' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'sidebar', 'mode' => 'wpcron' ) ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'adm_tool' ) );
$GLOBALS['wp_query']->is_post_type_archive = true;
$GLOBALS['wp_query']->is_archive = true;
$GLOBALS['wp_query']->set( 'post_type', 'adm_tool' );
adm_check( ADM_DIR . 'templates/archive.php' === apply_filters( 'template_include', 'index.php' ) && in_array( 'adm-overview', get_body_class(), true ), 'Übersicht: Plugin-Vorlage mit Profil-Layout' );
adm_check( 'Software ohne Abo: alle Tool-Profile' === apply_filters( 'pre_get_document_title', '' ) && 0 === strpos( apply_filters( 'rank_math/frontend/description', 'x' ), 'Tools, die du bei AppSumo' ), 'Übersicht: Seitentitel und Beschreibung' );
$page = ADM_Archive::render();
adm_check( false !== strpos( $page, '<h1>Software ohne Abo: alle Tool-Profile</h1>' ) && substr_count( $page, 'class="adm-card"' ) >= 2, 'Übersicht: Titel und Karten' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'content', 'mode' => 'wpcron' ) ) );
adm_check( 'index.php' === apply_filters( 'template_include', 'index.php' ), 'Übersicht: ohne Profil-Layout bleibt die Vorlage des Themes' );
wp_reset_query();
adm_check( 'Alt' === apply_filters( 'pre_get_document_title', 'Alt' ), 'Seitentitel anderer Seiten bleibt' );
$GLOBALS['wpdb']->delete( ADM_DB::table(), array( 'slug' => 'aaa-test-alt' ) );
wp_delete_post( $other, true );
wp_update_post( array( 'ID' => (int) $lo3['post_id'], 'post_status' => 'draft' ) );
wp_reset_query();

// Deal-Liste für die Plus-Seite
$GLOBALS['wpdb']->insert( ADM_DB::table(), array( 'slug' => 'plus-test-tool', 'name' => 'Test Tool - Plus exclusive', 'url' => 'https://appsumo.com/products/plus-test-tool/', 'status' => 'kaufbar', 'price' => 49, 'currency' => 'USD', 'rating' => 4.5, 'review_count' => 12, 'description' => 'Ein Testwerkzeug nur für Plus.' ) );
$GLOBALS['wpdb']->insert( ADM_DB::table(), array( 'slug' => 'plus-2019', 'name' => 'AppSumo Plus Yearly Plan', 'url' => 'https://appsumo.com/products/plus-2019/', 'status' => 'kaufbar', 'price' => 99, 'currency' => 'USD' ) );
$list = do_shortcode( '[adm_deals plus="only"]' );
adm_check( false !== strpos( $list, '<h3 class="adm-card__name">Test Tool</h3>' ) && false === strpos( $list, 'Yearly Plan' ) && false !== strpos( $list, 'rel="sponsored nofollow noopener"' ) && false !== strpos( $list, 'aria-label="Werbelink"' ), 'Plus-Liste: Plus-Deal mit Werbelink, ohne die Mitgliedschaft' );
adm_check( false === strpos( do_shortcode( '[adm_deals]' ), 'Test Tool' ), 'Normale Liste ohne Plus-Deals' );
adm_check( false === strpos( do_shortcode( '[adm_deals plus="only" desc="0"]' ), 'Ein Testwerkzeug' ), 'Plus-Liste ohne Kurzbeschreibung mit desc="0"' );
$pl = do_shortcode( '[adm_link url="https://appsumo.com/products/plus-2019/" subid="appsumo-plus"]AppSumo Plus[/adm_link]' );
adm_check( false !== strpos( $pl, 'subId1=appsumo-plus' ) && false !== strpos( $pl, 'sponsored nofollow' ) && 'X' === do_shortcode( '[adm_link url="https://example.com/"]X[/adm_link]' ), 'adm_link mit url: Werbelink nur für appsumo.com' );
$GLOBALS['wpdb']->query( "DELETE FROM " . ADM_DB::table() . " WHERE slug IN ('plus-test-tool','plus-2019')" );
adm_check( false !== strpos( do_shortcode( '[adm_deals type="vorlage"]' ), 'keine kaufbaren Angebote' ) && ADM_Shortcodes::is_membership( array( 'name' => 'AppSumo Plus Monthly', 'slug' => 'x' ) ), 'Leere Liste: Hinweis; Mitgliedschaft erkannt' );

// Werbelinks aus Elementor oder dem Editor bekommen rel="sponsored nofollow"
$mk = ADM_Links::mark_links( '<p><a href="https://appsumo.8odi.net/c/1/2/3?u=x" class="b">A</a> <a href="https://appsumo.com/products/x/" rel="noopener" target="_blank">B</a> <a href="https://example.com/">C</a></p>' );
adm_check( false !== strpos( $mk, '<a rel="sponsored nofollow" href="https://appsumo.8odi.net' ) && false !== strpos( $mk, 'rel="noopener sponsored nofollow"' ) && false !== strpos( $mk, '<a href="https://example.com/">' ), 'Werbelinks: rel ergänzt, fremde Links unverändert' );
adm_check( false !== strpos( apply_filters( 'elementor/frontend/the_content', '<a href="https://appsumo.com/products/plus-2019/">P</a>' ), 'sponsored nofollow' ), 'Werbelinks auch in Elementor-Inhalten' );

// Startseite: Rubriken-Kacheln und Tool-Karten
$rg = do_shortcode( '[adm_rubrics]' );
adm_check( substr_count( $rg, 'class="adm-rub"' ) >= 13 && false !== strpos( $rg, '/rubrik/seo/' ), 'Startseite: Kacheln aller Rubriken' );
$pub = get_posts( array( 'post_type' => 'adm_tool', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
foreach ( $pub as $pid ) {
	wp_update_post( array( 'ID' => $pid, 'post_status' => 'draft' ) );
}
adm_check( '' === do_shortcode( '[adm_tools heading="Beliebte Tool-Profile"]' ), 'Startseite: ohne veröffentlichte Profile kein Abschnitt' );
$lo4 = adm_get_deal( 'leadocean' );
wp_update_post( array( 'ID' => (int) $lo4['post_id'], 'post_status' => 'publish' ) );
$tl = do_shortcode( '[adm_tools heading="Beliebte Tool-Profile"]' );
adm_check( false !== strpos( $tl, '<h2>Beliebte Tool-Profile</h2>' ) && 1 === substr_count( $tl, 'class="adm-card"' ), 'Startseite: Abschnitt mit veröffentlichtem Profil' );
$tr = do_shortcode( '[adm_tools rows="2" heading="Beliebte Tool-Profile"]' );
adm_check( false !== strpos( $tr, '<div class="adm-fit"><div class="adm adm-cards adm-cards--rows2">' ), 'Startseite: volle Reihen per rows="2"' );
wp_update_post( array( 'ID' => (int) $lo4['post_id'], 'post_status' => 'draft' ) );
foreach ( $pub as $pid ) {
	wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ) );
}

// Website des Herstellers und Screenshot
$lo5 = adm_get_deal( 'leadocean' );
adm_check( 'https://www.leadocean.io/' === $lo5['website'] && 'https://www.leadocean.io/' === ADM_Shots::website( $lo5 ), 'Website aus den Seitendaten, ohne ref-Parameter' );
adm_check( '' === ADM_Shots::clean_url( 'https://appsumo.com/x/' ) && '' === ADM_Shots::clean_url( 'javascript:alert(1)' ) && 'leadocean.io' === ADM_Shots::host( 'https://www.leadocean.io/' ), 'Website: nur fremde http(s)-Adressen, Host ohne www' );
$pid5 = (int) $lo5['post_id'];
ADM_Shots::reset( $pid5 );
delete_option( 'adm_test_shot_calls' );
adm_check( in_array( $pid5, ADM_Shots::pending(), true ) && 'waiting' === ADM_Shots::fetch( $pid5 ), 'Screenshot: erster Abruf wartet auf den Dienst' );
adm_check( ! in_array( $pid5, ADM_Shots::pending(), true ) && false !== strpos( ADM_Shots::service_url( 'http://tidycal.com/' ), rawurlencode( 'https://tidycal.com/' ) ), 'Screenshot: Pause bis zum nächsten Versuch, Abruf über https' );
$att5 = ADM_Shots::fetch( $pid5 );
adm_check( is_int( $att5 ) && $att5 > 0 && ADM_Shots::image_id( $pid5 ) === $att5 && (int) get_post_thumbnail_id( $pid5 ) === $att5 && ! in_array( $pid5, ADM_Shots::pending(), true ), 'Screenshot gespeichert und als Beitragsbild gesetzt' );
adm_check( 'Screenshot: leadocean.io' === get_post_field( 'post_excerpt', $att5 ), 'Screenshot mit Quellenangabe' );
wp_update_post( array( 'ID' => $pid5, 'post_status' => 'publish' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $pid5, 'post_type' => 'adm_tool' ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();
$hd = do_shortcode( '[adm_deal_header]' );
$bx = do_shortcode( '[adm_deal_box]' );
$dt = do_shortcode( '[adm_deal_details]' );
adm_check( false !== strpos( $hd, 'adm-head__img--shot' ) && false !== strpos( $hd, 'Screenshot: <a href="https://www.leadocean.io/" target="_blank" rel="noopener">leadocean.io</a>' ), 'Kopf: Screenshot mit Quelle' );
adm_check( false !== strpos( $bx, 'class="adm-box__site" href="https://www.leadocean.io/" target="_blank" rel="noopener"' ) && false !== strpos( $dt, '<th scope="row">Website</th>' ), 'Website-Link im Kasten (neues Fenster) und in den Details' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'sidebar', 'mode' => 'wpcron' ) ) );
$pg = apply_filters( 'the_content', get_post( $pid5 )->post_content );
adm_check( false !== strpos( $pg, 'class="adm adm-sticky" hidden' ) && false !== strpos( $pg, 'subId1=' ) && false !== strpos( $pg, 'Zum Angebot' ), 'Handy-Leiste mit Kauf-Button' );
adm_check( false !== strpos( ADM_Archive::card( get_post( $pid5 ) ), 'adm-card__img--shot' ), 'Karte mit Screenshot' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'box_placement' => 'content', 'mode' => 'wpcron' ) ) );
wp_reset_postdata();
wp_update_post( array( 'ID' => $pid5, 'post_status' => 'draft' ) );

// Verteilung der Bewertungen
$lo6 = adm_get_deal( 'leadocean' );
adm_check( '3,1,2,10,125' === $lo6['rating_dist'] && 125 === $lo6['dist'][5], 'Verteilung der Tacos aus den Seitendaten' );
adm_check( 1234 === (int) $lo6['purchases'] && 'launchpad' === $lo6['listing_type'] && 'Platz 3 in Lead & Data' === ADM_Shortcodes::rank_text( $lo6['ranks'][0] ), 'Käufe, Stufe und Platzierung aus den Seitendaten' );
$st6 = ADM_Shortcodes::dist_stats( $lo6 );
adm_check( 141 === $st6['all'] && 96 === $st6['pgood'] && 4 === $st6['plow'] && 6 === $st6['low'], 'Anteile 4 bis 5 und 1 bis 3 Tacos' );
$up6 = ADM_Profiles::user_prompt( $lo6, array( 'newest' => array(), 'critical' => array() ), array() );
adm_check( false !== strpos( $up6, '"anteil_4_oder_5_tacos": "96 %"' ) && false !== strpos( ADM_Profiles::system_prompt(), 'bewertungsverteilung' ), 'Prompt mit Verteilung und Regel zur Gewichtung' );
wp_update_post( array( 'ID' => (int) $lo6['post_id'], 'post_status' => 'publish' ) );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => (int) $lo6['post_id'], 'post_type' => 'adm_tool' ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$GLOBALS['wp_query']->the_post();
$gl = apply_filters( 'the_content', get_post( (int) $lo6['post_id'] )->post_content );
adm_check( 1 === substr_count( $gl, 'class="adm-dist"' ) && false !== strpos( $gl, '<strong>96 %</strong> der 141 Bewertungen' ) && false !== strpos( $gl, 'Was Käufer loben' ) && false !== strpos( $gl, 'Was 4 % der Käufer bemängeln' ) && preg_match( '#adm-glance__col--con">.*?<ul>(.*?)</ul>#s', $gl, $cm ) && substr_count( $cm[1], '<li>' ) <= 3 && false === strpos( $gl, '>Schwächen<' ), 'Auf einen Blick mit Verteilung und eingeordneter Kritik' );
adm_check( false !== strpos( $gl, 'LaunchPad: Tool mit ersten Verkäufen' ) && false !== strpos( $gl, 'Platz 3 in Lead &amp; Data' ) && false !== strpos( $gl, '1.234-mal gekauft' ) || false !== strpos( $gl, '1,234-mal gekauft' ), 'Auf einen Blick mit Stufe, Platz und Käufen' );
adm_check( false !== strpos( $up6, '"stufe_bei_appsumo": "LaunchPad' ) && false !== strpos( $up6, 'kaeufe_bei_appsumo' ), 'Prompt mit Stufe und Käufen' );
wp_reset_postdata();
wp_update_post( array( 'ID' => (int) $lo6['post_id'], 'post_status' => 'draft' ) );

// Veröffentlichte Profile nur auf ausdrücklichen Wunsch neu schreiben
$lo7 = adm_get_deal( 'leadocean' );
$p7  = (int) $lo7['post_id'];
wp_update_post( array( 'ID' => $p7, 'post_status' => 'publish', 'post_content' => '<p>Alter Text</p>' ) );
ADM_Profiles::clear_queue();
adm_check( 0 === ADM_Profiles::enqueue( array( (int) $lo7['id'] ) ) && is_wp_error( ADM_Profiles::generate( (int) $lo7['id'] ) ), 'Veröffentlichtes Profil wird ohne Freigabe nicht überschrieben' );
adm_check( in_array( (int) $lo7['id'], ADM_Profiles::published_deals(), true ) && 1 === ADM_Profiles::enqueue_published( array( (int) $lo7['id'] ) ), 'Neuschreiben veröffentlichter Profile vorgemerkt' );
$r7 = ADM_Profiles::generate( (int) $lo7['id'] );
adm_check( $p7 === $r7 && 'publish' === get_post_status( $p7 ) && false === strpos( get_post_field( 'post_content', $p7 ), 'Alter Text' ) && ! in_array( (int) $lo7['id'], (array) get_option( ADM_Profiles::FORCE, array() ), true ), 'Neu geschrieben, bleibt veröffentlicht, Freigabe verbraucht' );
adm_check( is_wp_error( ADM_Profiles::generate( (int) $lo7['id'] ) ), 'Danach wieder geschützt' );
ADM_Profiles::clear_queue();
wp_update_post( array( 'ID' => $p7, 'post_status' => 'draft' ) );

// Bestenliste
$lo8 = adm_get_deal( 'leadocean' );
$p8  = (int) $lo8['post_id'];
adm_check( '' === do_shortcode( '[adm_bestenliste]' ) && '' === do_shortcode( '[adm_bestenliste tools="' . get_post_field( 'post_name', $p8 ) . '"]' ), 'Bestenliste: ohne Auswahl oder ohne veröffentlichte Profile leer' );
wp_update_post( array( 'ID' => $p8, 'post_status' => 'publish' ) );
$bl = do_shortcode( '[adm_bestenliste tools="' . get_post_field( 'post_name', $p8 ) . '"]' );
adm_check( 1 === substr_count( $bl, 'class="adm-best__item"' ) && false !== strpos( $bl, 'aria-label="Platz 1"' ) && false !== strpos( $bl, "96\xc2\xa0% zufrieden (141 Bewertungen)" ), 'Bestenliste: Eintrag mit Platz und Anteil zufriedener Käufer' );
adm_check( false !== strpos( $bl, 'rel="sponsored nofollow noopener"' ) && false !== strpos( $bl, 'subId1=beste-leadocean' ) && false !== strpos( $bl, get_permalink( $p8 ) ) && false !== strpos( $bl, 'Reihenfolge:' ), 'Bestenliste: Werbelink, Profil-Link und Erklärung der Reihenfolge' );
$tt8 = get_the_terms( $p8, ADM_Rubrics::TAX );
$br8 = do_shortcode( '[adm_bestenliste rubrik="' . $tt8[0]->slug . '"]' );
$bx8 = do_shortcode( '[adm_bestenliste rubrik="' . $tt8[0]->slug . '" exclude="leadocean"]' );
adm_check( $tt8 && false !== strpos( $br8, get_permalink( $p8 ) ) && false === strpos( $bx8, get_permalink( $p8 ) ) && substr_count( $bx8, 'class="adm-best__item"' ) === substr_count( $br8, 'class="adm-best__item"' ) - 1, 'Bestenliste nach Rubrik, mit Ausschluss' );
adm_check( false !== strpos( $br8, '"@type":"ItemList"' ) && false !== strpos( $br8, '"position":1' ), 'Bestenliste mit strukturierten Daten' );
$few  = array( 'dist' => array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 5 ), 'listing_type' => '' );
$many = array( 'dist' => array( 1 => 2, 2 => 1, 3 => 2, 4 => 20, 5 => 175 ), 'listing_type' => '' );
adm_check( ADM_Shortcodes::best_score( $many ) > ADM_Shortcodes::best_score( $few ) && ADM_Shortcodes::best_score( array_merge( $many, array( 'listing_type' => 'select' ) ) ) > ADM_Shortcodes::best_score( $many ) && 0.0 === ADM_Shortcodes::best_score( array( 'dist' => array() ) ), 'Bestenliste: wenige Bewertungen zählen weniger, Select bekommt Vorsprung' );
$par8 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bestenlisten', 'post_content' => '[adm_bestenlisten]' ) );
$kid8 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $par8, 'post_title' => 'Beste Lead-Tools', 'post_content' => '<p>Erster Absatz der Liste.</p>[adm_bestenliste tools="' . get_post_field( 'post_name', $p8 ) . '"]' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $par8, 'post_title' => 'Ohne Liste', 'post_content' => '<p>Nichts</p>' ) );
$ov8 = do_shortcode( '[adm_bestenlisten parent="' . $par8 . '"]' );
adm_check( false !== strpos( $ov8, 'Beste Lead-Tools</a></h3>' ) && false !== strpos( $ov8, 'Erster Absatz der Liste.' ) && false !== strpos( $ov8, 'adm-top__label">Top-Empfehlung' ) && false !== strpos( $ov8, 'Alle 1 Tools der Liste' ) && false === strpos( $ov8, 'Ohne Liste' ) && false !== strpos( $ov8, get_permalink( $kid8 ) ), 'Übersicht der Bestenlisten mit Top-Empfehlung' );
adm_check( false === strpos( $ov8, 'Kritisiert:' ) && false === strpos( $ov8, ' $' ) && false !== strpos( $ov8, 'Gelobt:' ) && false !== strpos( $ov8, "96\xc2\xa0% zufrieden (141 Bewertungen)" ) && false !== strpos( $ov8, 'adm-ico--trophy' ) && false !== strpos( $bl, 'Kritisiert:' ), 'Top-Empfehlung kurz und positiv: Zufriedenheit, Lob, Pokal, ohne Kritik und Preis' );
$tp8 = do_shortcode( '[adm_best_top page="' . get_page_uri( $kid8 ) . '"]' );
adm_check( false !== strpos( $tp8, 'adm-top__name' ) && $tp8 === do_shortcode( '[adm_best_top page="' . $kid8 . '"]' ) && '' === do_shortcode( '[adm_best_top page="gibt-es-nicht"]' ), 'Teaser einzeln per Pfad oder ID' );
wp_delete_post( $kid8, true );
wp_delete_post( $par8, true );
wp_update_post( array( 'ID' => $p8, 'post_status' => 'draft' ) );

// Videos: allgemeine Namen brauchen den ganzen Namen
adm_check( ! ADM_Sources::mentions( 'Surfer SEO Tutorial (2026): bestes SEO KI Tool AppSumo', 'SEO Generator' ) && ADM_Sources::mentions( 'SEO Generator Review: AppSumo Lifetime Deal', 'SEO Generator' ) && ADM_Sources::mentions( 'walkthrough of seogenerator.io', 'SEO Generator', 'https://seogenerator.io/' ), 'Videosuche: SEO Generator nur mit vollem Namen und Bezug zum Deal' );
adm_check( ADM_Sources::mentions( 'Nuwtonic Review 2026', 'Nuwtonic SEO' ) && ! ADM_Sources::mentions( 'Google Flow AI 2026: Das neue Update', 'Flow AI', 'https://www.flowai.com/' ) && ADM_Sources::mentions( 'Flow AI lifetime deal review', 'Flow AI' ) && ADM_Sources::mentions( 'The Link Chest review, appsumo', 'The Link Chest' ), 'Videosuche: unverwechselbares erstes Wort reicht, Google Flow passt nicht zu Flow AI' );

// Seiten-Cache leeren (FlyingPress)
if ( ! class_exists( '\\FlyingPress\\Purge' ) ) {
	eval( 'namespace FlyingPress; class Purge { public static $got = array(); public static function purge_urls( $u ) { self::$got = $u; } }' ); // phpcs:ignore Squiz.PHP.Eval
}
$lo9 = adm_get_deal( 'leadocean' );
$p9  = (int) $lo9['post_id'];
ADM_Cache::flush();
\FlyingPress\Purge::$got = array();
$pg9 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Liste Cache', 'post_content' => '[adm_bestenliste tools="x"]' ) );
wp_update_post( array( 'ID' => $p9, 'post_status' => 'publish' ) );
$n9  = ADM_Cache::flush();
$got = \FlyingPress\Purge::$got;
adm_check( $n9 > 0 && in_array( get_permalink( $p9 ), $got, true ) && in_array( get_post_type_archive_link( 'adm_tool' ), $got, true ) && in_array( get_permalink( $pg9 ), $got, true ) && in_array( home_url( '/' ), $got, true ), 'Cache: Profil veröffentlicht, Profil, /tools/, Startseite und Seiten mit Bausteinen geleert' );
adm_check( 0 === ADM_Cache::flush(), 'Cache: nichts vorgemerkt, nichts geleert' );
adm_check( ADM_Cache::changed( $lo9, array( 'price' => 59 ), $lo9['status'] ) && ADM_Cache::changed( $lo9, array(), 'ausgelaufen' ) && ! ADM_Cache::changed( $lo9, array( 'price' => (float) $lo9['price'], 'rating' => 1.0 ), $lo9['status'] ), 'Cache: Preis oder Status geändert, Bewertung allein nicht' );
wp_delete_post( $pg9, true );
wp_update_post( array( 'ID' => $p9, 'post_status' => 'draft' ) );
ADM_Cache::flush();

// Impact-API (Verbindungstest, nur lesend)
adm_check( ! ADM_Impact::configured() || defined( 'THOKA_IMPACT_ACCOUNT_SID' ), 'Impact: ohne Zugangsdaten nicht eingerichtet' );
if ( ! defined( 'THOKA_IMPACT_ACCOUNT_SID' ) ) {
	define( 'THOKA_IMPACT_ACCOUNT_SID', 'IRtestSID123' );
	define( 'THOKA_IMPACT_AUTH_TOKEN', 'geheimTOKEN456' );
}
$imp_auth = '';
$imp_mock = function ( $pre, $args, $url ) use ( &$imp_auth ) {
	if ( 0 !== strpos( $url, 'https://api.impact.com/' ) ) {
		return $pre;
	}
	$imp_auth = isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';
	$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( false !== strpos( $path, '/Campaigns' ) ) {
		$body = array( '@total' => 1, 'Campaigns' => array( array( 'CampaignId' => '7443', 'CampaignName' => 'AppSumo' ) ) );
	} elseif ( preg_match( '#/Catalogs/ItemSearch#', $path ) || preg_match( '#/Catalogs/\d+/Items#', $path ) ) {
		$body = array( 'Items' => array( array( 'Name' => 'DocPro', 'CurrentPrice' => '179.00', 'StockAvailability' => 'InStock', 'Url' => 'https://appsumo.8odi.net/c/x', 'Labels' => array() ) ) );
	} elseif ( false !== strpos( $path, '/Catalogs' ) ) {
		$body = array( 'Catalogs' => array( array( 'Id' => '9001', 'Name' => 'AppSumo Product Feed', 'CampaignId' => '7443', 'NumberOfItems' => '1096' ) ) );
	} else {
		return array( 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'body' => '{"Message":"Not found for IRtestSID123"}', 'headers' => array(), 'cookies' => array() );
	}
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $imp_mock, 5, 3 );
$imp = ADM_Impact::test();
remove_filter( 'pre_http_request', $imp_mock, 5 );
$imp_json = wp_json_encode( $imp );
adm_check( 'AppSumo' === $imp['Campaigns']['appsumo']['CampaignName'] && '9001' === $imp['Catalogs']['kataloge'][0]['Id'] && '179.00' === $imp['ItemSearch']['beispiel']['CurrentPrice'] && '[array]' === $imp['CatalogItems']['beispiel']['Labels'], 'Impact: Programme, Kataloge und Einträge im Test sichtbar' );
adm_check( 'Basic ' . base64_encode( 'IRtestSID123:geheimTOKEN456' ) === $imp_auth && false === strpos( $imp_json, 'geheimTOKEN456' ) && false === strpos( $imp_json, 'IRtestSID123' ) && 404 === $imp['Pfad Products']['http'], 'Impact: Basic-Auth, Zugangsdaten nie in der Ausgabe' );
ob_start();
ADM_Impact::settings_row();
$imp_row = ob_get_clean();
adm_check( false !== strpos( $imp_row, 'gesetzt' ) && false === strpos( $imp_row, 'geheimTOKEN456' ) && false !== strpos( $imp_row, 'Verbindung testen' ), 'Impact: Einstellungen zeigen Status, nie den Token' );
delete_option( ADM_Impact::TEST_OPTION );
add_filter( 'pre_http_request', $imp_mock, 5, 3 );
$q1 = ADM_Impact::query( 'Catalogs/ItemSearch?Keyword=DocPro&PageSize=2' );
$q2 = ADM_Impact::query( '../Admin' );
$q3 = ADM_Impact::query( 'https://evil.example/x' );
$q4 = ADM_Impact::query( 'Gibt/Es/Nicht' );
remove_filter( 'pre_http_request', $imp_mock, 5 );
adm_check( 200 === $q1['http'] && 'DocPro' === $q1['antwort']['Items'][0]['Name'] && false !== strpos( $q1['abfrage'], 'Keyword=DocPro' ), 'Impact-Abfrage: Pfad mit Parametern' );
adm_check( 'Ungültiger Pfad' === $q2['fehler'] && 'Ungültiger Pfad' === $q3['fehler'] && 404 === $q4['http'] && false === strpos( wp_json_encode( $q4 ), 'IRtestSID123' ), 'Impact-Abfrage: nur einfache Pfade, Zugangsdaten maskiert' );

// Einnahmen je subId1 und Aktionen von AppSumo (Impact)
$stat_mock = function ( $pre, $args, $url ) {
	if ( 0 !== strpos( $url, 'https://api.impact.com/' ) ) {
		return $pre;
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( false !== strpos( $path, '/Reports/partner_performance_by_subid' ) ) {
		$body = array( '@numpages' => '1', 'Records' => array(
			array( 'pubsubid1_' => 'leadocean', 'Campaign' => 'AppSumo', 'Clicks' => '40', 'raw_clicks' => '44', 'Actions' => '2', 'sale_amount' => '98.0', 'Earnings' => '98.0' ),
			array( 'pubsubid1_' => 'beste-leadocean', 'Campaign' => 'AppSumo', 'Clicks' => '10', 'raw_clicks' => '10', 'Actions' => '0', 'sale_amount' => '0', 'Earnings' => '0' ),
			array( 'pubsubid1_' => '', 'Campaign' => 'Envato Placeit', 'Clicks' => '999', 'raw_clicks' => '999', 'Actions' => '5', 'sale_amount' => '5', 'Earnings' => '5' ),
		) );
	} elseif ( preg_match( '#/Campaigns/7443$#', $path ) ) {
		$body = array( 'CampaignId' => '7443', 'CampaignName' => 'AppSumo' );
	} elseif ( false !== strpos( $path, '/Deals' ) ) {
		$body = array( 'Deals' => array(
			array( 'Id' => '11', 'Name' => 'Black Friday', 'State' => 'ACTIVE', 'StartDate' => gmdate( 'c', time() - 3600 ), 'EndDate' => gmdate( 'c', time() + 5 * DAY_IN_SECONDS ), 'DefaultPromoCode' => 'BF26', 'DiscountPercent' => '10', 'CampaignId' => '7443' ),
			array( 'Id' => '12', 'Name' => 'Nur LeadOcean', 'State' => 'ACTIVE', 'StartDate' => gmdate( 'c', time() - 3600 ), 'EndDate' => gmdate( 'c', time() + DAY_IN_SECONDS ), 'Products' => array( 'https://appsumo.com/products/other-tool/' ), 'CampaignId' => '7443' ),
			array( 'Id' => '13', 'Name' => 'Cyber Monday', 'State' => 'PENDING', 'StartDate' => gmdate( 'c', time() + 10 * DAY_IN_SECONDS ), 'EndDate' => gmdate( 'c', time() + 12 * DAY_IN_SECONDS ), 'CampaignId' => '7443' ),
			array( 'Id' => '14', 'Name' => 'Alt', 'State' => 'EXPIRED', 'CampaignId' => '7443' ),
		) );
	} elseif ( false !== strpos( $path, '/PromoCodes' ) ) {
		return array( 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'body' => '{"status":403,"title":"Access Denied"}', 'headers' => array(), 'cookies' => array() );
	} else {
		$body = array( 'Promotions' => array() );
	}
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $stat_mock, 5, 3 );
$mails = array();
$mail_cb = function ( $null, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $mail_cb, 10, 2 );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'notify_email' => 'info@example.org' ) ) );
delete_option( ADM_Promos::SEEN );
$st = ADM_Stats::refresh();
adm_check( '' === $st['error'] && 2 === count( $st['ranges'][30] ) && 98.0 === $st['ranges'][30]['leadocean']['earnings'] && ! isset( $st['ranges'][30][''] ), 'Einnahmen: nur AppSumo, je subId1' );
$lo10 = adm_get_deal( 'leadocean' );
wp_update_post( array( 'ID' => (int) $lo10['post_id'], 'post_status' => 'publish' ) );
$lb1 = ADM_Stats::label( get_post_field( 'post_name', (int) $lo10['post_id'] ) );
$lb2 = ADM_Stats::label( 'beste-leadocean' );
adm_check( 0 === strpos( $lb1[0], 'Profil: ' ) && 'Bestenliste: LeadOcean' === $lb2[0] && 'Fußzeile: AppSumo Plus' === ADM_Stats::label( 'footer-plus' )[0], 'Einnahmen: lesbare Namen zu subId1' );
$items = ADM_Promos::refresh();
adm_check( 3 === count( $items ) && isset( $items['deal:11'] ) && 'BF26' === $items['deal:11']['code'] && '10 % Rabatt' === $items['deal:11']['discount'] && ! isset( $items['deal:14'] ) && isset( ADM_Promos::data()['errors']['code'] ), 'Aktionen: Deals gelesen, abgelaufene weg, 403 vermerkt' );
adm_check( 1 === count( $mails ) && 'info@example.org' === $mails[0]['to'] && false !== strpos( $mails[0]['message'], 'Code: BF26' ) && false !== strpos( $mails[0]['message'], 'Cyber Monday' ), 'Aktionen: E-Mail mit allen neuen Aktionen' );
ADM_Promos::refresh();
adm_check( 1 === count( $mails ), 'Aktionen: keine zweite E-Mail für dieselben Aktionen' );
$act = ADM_Promos::active();
$fd  = ADM_Promos::for_deal( $lo10 );
adm_check( 2 === count( $act ) && isset( $fd['deal:11'] ) && ! isset( $fd['deal:12'] ) && ! isset( $act['deal:13'] ), 'Aktionen: laufend, nur passende Produkte, geplante noch nicht' );
$bx10 = do_shortcode( '[adm_deal_box deal="leadocean"]' );
$pr10 = do_shortcode( '[adm_promos]' );
adm_check( false !== strpos( $bx10, 'adm-promo' ) && false !== strpos( $bx10, 'BF26' ) && false !== strpos( $pr10, 'Black Friday' ) && false === strpos( $pr10, 'Nur LeadOcean' ) && false !== strpos( $pr10, 'subId1=aktion' ), 'Aktionen: Hinweis im Kasten und Leiste mit Werbelink' );
ob_start();
ADM_Stats::page();
$sp = ob_get_clean();
adm_check( false !== strpos( $sp, 'Bestenliste: LeadOcean' ) && false !== strpos( $sp, 'Summe' ) && false !== strpos( $sp, 'Black Friday' ), 'Einnahmen: Seite mit Tabelle und Aktionen' );
remove_filter( 'pre_http_request', $stat_mock, 5 );
remove_filter( 'pre_wp_mail', $mail_cb, 10 );
delete_option( ADM_Promos::OPTION );
delete_option( ADM_Stats::OPTION );
wp_update_post( array( 'ID' => (int) $lo10['post_id'], 'post_status' => 'draft' ) );

// Aktionsbanner (Impact Ads), nur nach Freigabe
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'banners' => 1 ) ) );
$png = ( function () {
	$im = imagecreatetruecolor( 1216, 381 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 20, 20, 20 ) );
	ob_start();
	imagepng( $im );
	return ob_get_clean();
} )();
$ban_mock = function ( $pre, $args, $url ) use ( $png ) {
	if ( 0 === strpos( $url, 'https://a.impactradius-go.com/' ) ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $png, 'headers' => array( 'content-type' => 'image/png' ), 'cookies' => array() );
	}
	if ( 0 !== strpos( $url, 'https://api.impact.com/' ) ) {
		return $pre;
	}
	$now  = time();
	$body = array( '@numpages' => '1', 'Ads' => array(
		array( 'Id' => '4131673', 'Name' => 'as-bf26-thematic-desktop.png', 'Type' => 'BANNER', 'Width' => '1216', 'Height' => '381', 'StartDate' => gmdate( 'c', $now - DAY_IN_SECONDS ), 'EndDate' => '', 'LandingPageUrl' => 'https://appsumo.com', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-4131673' ),
		array( 'Id' => '4131675', 'Name' => 'as-bf26-thematic-drop4-feature-img.png', 'Type' => 'BANNER', 'Width' => '500', 'Height' => '285', 'StartDate' => gmdate( 'c', $now - DAY_IN_SECONDS ), 'EndDate' => '', 'LandingPageUrl' => 'https://appsumo.com', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-4131675' ),
		array( 'Id' => '1480856', 'Name' => 'Voxpopme Black Friday', 'Type' => 'BANNER', 'Width' => '300', 'Height' => '250', 'StartDate' => gmdate( 'c', $now - DAY_IN_SECONDS ), 'LandingPageUrl' => 'https://appsumo.com/products/voxpopme', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-1480856' ),
		array( 'Id' => '900', 'Name' => 'Black Friday 2024', 'Type' => 'BANNER', 'Width' => '1216', 'Height' => '381', 'StartDate' => '2024-11-01T00:00:00+01:00', 'LandingPageUrl' => 'https://appsumo.com', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-900' ),
		array( 'Id' => '4131680', 'Name' => 'as-bf26-thematic-drop1-logo.png', 'Type' => 'BANNER', 'Width' => '420', 'Height' => '116', 'StartDate' => gmdate( 'c', $now - DAY_IN_SECONDS ), 'EndDate' => '', 'LandingPageUrl' => 'https://appsumo.com', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-4131680' ),
		array( 'Id' => '901', 'Name' => 'Generic logo', 'Type' => 'BANNER', 'Width' => '420', 'Height' => '116', 'StartDate' => gmdate( 'c', $now ), 'LandingPageUrl' => 'https://appsumo.com', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-901' ),
	) );
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $ban_mock, 5, 3 );
$bmails = array();
$bmail_cb = function ( $null, $atts ) use ( &$bmails ) {
	$bmails[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $bmail_cb, 10, 2 );
delete_option( ADM_Banners::OPTION );
$bnew = ADM_Banners::refresh();
adm_check( array( '4131673', '4131675', '4131680' ) === $bnew && 1 === count( $bmails ) && false !== strpos( $bmails[0]['message'], 'as-bf26-thematic-desktop.png' ) && false !== strpos( $bmails[0]['message'], 'Startseite' ), 'Banner: nur neue Aktionsbanner für den ganzen Shop, eine E-Mail' );
adm_check( '' === ADM_Banners::html( 'home' ) && '' === do_shortcode( '[adm_promos]' ), 'Banner: ohne Freigabe nichts auf der Seite' );
ADM_Banners::refresh();
adm_check( 1 === count( $bmails ), 'Banner: keine zweite E-Mail' );
$att = ADM_Banners::approve( '4131673' );
$ADM_home = do_shortcode( '[adm_promos]' );
adm_check( is_int( $att ) && $att > 0 && false !== strpos( $ADM_home, 'adm-banner--home' ) && false !== strpos( $ADM_home, '/c/' ) && false !== strpos( $ADM_home, '/4131673/' ) && false !== strpos( $ADM_home, 'subId1=banner-4131673' ) && false !== strpos( $ADM_home, 'sponsored nofollow' ) && false === strpos( $ADM_home, 'impactradius' ), 'Banner: nach Freigabe auf der Startseite, Bild lokal, Werbelink' );
ADM_Banners::approve( '4131675' );
$bx11 = do_shortcode( '[adm_deal_box deal="leadocean"]' );
adm_check( false !== strpos( $bx11, 'adm-banner--side' ) && 'home' === ADM_Banners::slot( array( 'w' => 1216, 'h' => 381 ) ) && '' === ADM_Banners::slot( array( 'w' => 390, 'h' => 570 ) ), 'Banner: kompaktes Format unter dem Deal-Kasten' );
adm_check( 'list' === ADM_Banners::slot( array( 'w' => 440, 'h' => 116 ) ) && 'side' === ADM_Banners::slot( array( 'w' => 500, 'h' => 285 ) ) && 'side' === ADM_Banners::slot( array( 'w' => 300, 'h' => 250 ) ) && '' === ADM_Banners::slot( array( 'w' => 160, 'h' => 600 ) ), 'Banner: Streifen über die Bestenlisten, 16:9 unter den Deal-Kasten' );
adm_check( '' === ADM_Banners::html( 'list' ) && false === strpos( $bx11, 'drop1-logo' ), 'Banner: Streifen ohne Freigabe nirgends' );
ADM_Banners::approve( '4131680' );
adm_check( false !== strpos( ADM_Banners::html( 'list' ), 'adm-banner--list' ) && false !== strpos( ADM_Banners::html( 'list' ), 'subId1=banner-4131680' ), 'Banner: Streifen nach Freigabe über den Bestenlisten' );
adm_check( 0 === strpos( ADM_Stats::label( 'banner-4131673' )[0], 'Aktionsbanner: as-bf26' ), 'Banner: eigene Zeile in den Einnahmen' );
ADM_Banners::set_status( '4131673', 'beendet' );
adm_check( '' === ADM_Banners::html( 'home' ), 'Banner: beendet verschwindet' );
remove_filter( 'pre_http_request', $ban_mock, 5 );
remove_filter( 'pre_wp_mail', $bmail_cb, 10 );
delete_option( ADM_Banners::OPTION );

ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'banners' => 0 ) ) );
adm_check( ! ADM_Banners::enabled() && '' === ADM_Banners::html( 'home' ), 'Banner: Schalter aus, nichts auf der Seite' );

// Eigene Aktion (Tipp-Kasten)
$lo12 = adm_get_deal( 'leadocean' );
$nw12 = adm_get_deal( 'neuronwriter' );
wp_update_post( array( 'ID' => (int) $lo12['post_id'], 'post_status' => 'publish' ) );
$rub12 = ADM_Rubrics::for_deal( $lo12 );
$spot_mock = function ( $pre, $args, $url ) use ( $png ) {
	if ( 0 === strpos( $url, 'https://a.impactradius-go.com/' ) ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $png, 'headers' => array( 'content-type' => 'image/png' ), 'cookies' => array() );
	}
	if ( 0 !== strpos( $url, 'https://api.impact.com/' ) ) {
		return $pre;
	}
	$camp = false !== strpos( $url, '/Ads/556' ) ? '9999' : '7443';
	$body = array( 'Id' => false !== strpos( $url, '/Ads/556' ) ? '556' : '555', 'Name' => 'LeadOcean', 'CampaignId' => $camp, 'Type' => 'BANNER', 'Width' => '300', 'Height' => '250', 'LandingPageUrl' => 'https://appsumo.com/leadocean/', 'CreativeUrl' => '//a.impactradius-go.com/display-ad/7443-555' );
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $spot_mock, 5, 3 );
adm_check( '' === ADM_Spotlight::html( 'home' ) && '' === do_shortcode( '[adm_spotlight]' ), 'Eigene Aktion: ohne Einträge nichts' );
$sp12 = ADM_Spotlight::save( array( 'label' => 'Tipp', 'until' => wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ), 'items' => array(
	array( 'deal' => 'leadocean', 'instead' => 'Apollo', 'text' => 'Leads finden ohne Monatsabo.', 'code' => 'TEST1', 'guide' => '' ),
	array( 'deal' => 'gibt-es-nicht', 'instead' => 'X' ),
) ) );
$h12 = ADM_Spotlight::html( 'home' );
adm_check( 1 === count( $sp12['items'] ) && false !== strpos( $h12, 'adm-spot' ) && false !== strpos( $h12, 'statt Apollo' ) && false !== strpos( $h12, 'subId1=tipp-leadocean' ) && false !== strpos( $h12, 'TEST1' ) && false !== strpos( $h12, 'sponsored nofollow' ) && false !== strpos( $h12, 'zufrieden' ) && false === strpos( $h12, 'adm-spot__img' ), 'Eigene Aktion: Karte mit Alternative, Code, Werbelink, ohne Bild' );
adm_check( false !== strpos( do_shortcode( '[adm_promos]' ), 'adm-spot' ), 'Eigene Aktion: oben auf der Startseite über [adm_promos]' );
$bx12 = do_shortcode( '[adm_deal_box deal="leadocean"]' );
adm_check( false !== strpos( $bx12, 'TEST1' ) && false === strpos( $bx12, 'adm-spot--side' ), 'Eigene Aktion: im eigenen Profil nur der Code' );
adm_check( '' !== $rub12 && false !== strpos( ADM_Spotlight::html( 'rubric', $rub12 ), 'adm-spot--rubric' ) && '' === ADM_Spotlight::html( 'rubric', 'gibt-es-nicht' ), 'Eigene Aktion: in der Übersicht der eigenen Rubrik' );
adm_check( false !== strpos( ADM_Spotlight::html( 'side', $rub12, (int) $nw12['id'] ), 'adm-spot--side' ) && '' === ADM_Spotlight::html( 'side', $rub12, (int) $lo12['id'] ), 'Eigene Aktion: im Deal-Kasten anderer Profile der Rubrik' );
adm_check( 0 === strpos( ADM_Stats::label( 'tipp-leadocean' )[0], 'Eigene Aktion: LeadOcean' ), 'Eigene Aktion: eigene Zeile in den Einnahmen' );
$sp12b = ADM_Spotlight::save( array( 'until' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ), 'items' => array( array( 'deal' => 'leadocean', 'banner' => '555' ), array( 'deal' => 'neuronwriter', 'banner' => '556' ) ) ) );
$h12b = ADM_Spotlight::html( 'home' );
adm_check( $sp12b['items'][0]['att'] > 0 && '' === $sp12b['items'][0]['berr'] && false !== strpos( $h12b, 'adm-spot__img' ) && false !== strpos( $h12b, 'Anzeige' ) && false !== strpos( $h12b, '/555/7443?u=' ) && false !== strpos( $h12b, 'subId1=tipp-leadocean' ) && false === strpos( $h12b, 'impactradius' ), 'Eigene Aktion: Banner aus Impact, Bild lokal, Klick über den Banner' );
adm_check( 0 === (int) $sp12b['items'][1]['att'] && false !== strpos( $sp12b['items'][1]['berr'], 'kein Banner des AppSumo-Programms' ), 'Eigene Aktion: fremder Banner abgelehnt' );
$att12 = $sp12b['items'][0]['att'];
$sp12c = ADM_Spotlight::save( array( 'until' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ), 'items' => array( array( 'deal' => 'leadocean', 'banner' => '555' ) ) ) );
adm_check( $att12 === $sp12c['items'][0]['att'], 'Eigene Aktion: gleicher Banner wird nicht erneut geladen' );
ob_start();
ADM_Stats::page();
$sp12p = ob_get_clean();
adm_check( false !== strpos( $sp12p, 'Eigene Aktion' ) && false !== strpos( $sp12p, 'name="spot[items][0][banner]"' ) && false !== strpos( $sp12p, 'Ausgeschaltet' ), 'Eigene Aktion: Formular im Reiter Einnahmen, Banner aus' );
ADM_Spotlight::save( array( 'until' => wp_date( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), 'items' => array( array( 'deal' => 'leadocean' ) ) ) );
adm_check( '' === ADM_Spotlight::html( 'home' ) && '' === ADM_Spotlight::code_html( $lo12 ), 'Eigene Aktion: nach dem Ende verschwunden' );
remove_filter( 'pre_http_request', $spot_mock, 5 );
delete_option( ADM_Spotlight::OPTION );
wp_update_post( array( 'ID' => (int) $lo12['post_id'], 'post_status' => 'draft' ) );

// Newsletter (SendFox)
adm_check( '' === do_shortcode( '[adm_newsletter]' ), 'Newsletter: ohne Schlüssel und Liste kein Kasten' );
if ( ! defined( 'THOKA_SENDFOX_API_KEY' ) ) {
	define( 'THOKA_SENDFOX_API_KEY', 'sf-test-key' );
}
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'newsletter_list' => '684361', 'newsletter_auto' => 0 ) ) );
$nl_calls = array();
$nl_fail  = false;
$nl_mock  = function ( $pre, $args, $url ) use ( &$nl_calls, &$nl_fail ) {
	if ( 0 !== strpos( $url, 'https://api.sendfox.com/' ) ) {
		return $pre;
	}
	$nl_calls[] = array( 'url' => $url, 'args' => $args );
	if ( false !== strpos( $url, '/lists/' ) ) {
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'id' => 684361, 'name' => 'Software ohne Abo' ) ), 'headers' => array(), 'cookies' => array() );
	}
	if ( $nl_fail ) {
		return array( 'response' => array( 'code' => 422, 'message' => 'x' ), 'body' => wp_json_encode( array( 'message' => 'The email field is invalid.' ) ), 'headers' => array(), 'cookies' => array() );
	}
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'id' => 1, 'created' => true ) ), 'headers' => array(), 'cookies' => array() );
};
add_filter( 'pre_http_request', $nl_mock, 5, 3 );
$nlh = do_shortcode( '[adm_newsletter]' );
adm_check( false !== strpos( $nlh, 'adm-nl__form' ) && false !== strpos( $nlh, 'name="website"' ) && false !== strpos( $nlh, 'zur Bestätigung' ) && false === strpos( $nlh, 'SendFox' ) && false !== strpos( $nlh, 'admin-post.php' ) && false === strpos( $nlh, 'sendfox.com/js' ), 'Newsletter: eigenes Formular ohne fremdes Skript, mit Hinweis' );
$sign = new ReflectionMethod( 'ADM_Newsletter', 'sign' );
$sign->setAccessible( true );
$old  = time() - 30;
$good = array( 'email' => 'leser@example.org', 'ts' => $old, 'sig' => $sign->invoke( null, $old ), 'website' => '' );
$r1 = ADM_Newsletter::subscribe( array_merge( $good, array( 'email' => 'kaputt' ) ), '203.0.113.5' );
$r2 = ADM_Newsletter::subscribe( array_merge( $good, array( 'website' => 'spam' ) ), '203.0.113.5' );
$r3 = ADM_Newsletter::subscribe( array_merge( $good, array( 'ts' => time(), 'sig' => $sign->invoke( null, time() ) ) ), '203.0.113.5' );
adm_check( ! $r1['ok'] && $r2['ok'] && $r3['ok'] && 0 === count( $nl_calls ), 'Newsletter: ungültige Adresse abgelehnt, Bots und zu schnelle Absender ohne Abruf' );
$r4   = ADM_Newsletter::subscribe( $good, '203.0.113.5' );
$sent = $nl_calls ? json_decode( $nl_calls[0]['args']['body'], true ) : array();
adm_check( $r4['ok'] && 1 === count( $nl_calls ) && 'https://api.sendfox.com/contacts' === $nl_calls[0]['url'] && 'Bearer sf-test-key' === $nl_calls[0]['args']['headers']['Authorization'] && array( 684361 ) === $sent['lists'] && 'leser@example.org' === $sent['email'] && '203.0.113.5' === $sent['ip_address'] && false !== strpos( $r4['msg'], 'bestätige' ), 'Newsletter: Anmeldung über die API in die Liste, mit IP für die Einwilligung' );
$nl_fail = true;
$r5 = ADM_Newsletter::subscribe( $good, '203.0.113.6' );
$nl_fail = false;
adm_check( ! $r5['ok'] && 'The email field is invalid.' === get_option( 'adm_newsletter_error' )['error'], 'Newsletter: Fehler von SendFox wird vermerkt' );
for ( $i = 0; $i < 6; $i++ ) {
	$r6 = ADM_Newsletter::subscribe( $good, '203.0.113.7' );
}
adm_check( ! $r6['ok'] && false !== strpos( $r6['msg'], 'Zu viele' ), 'Newsletter: höchstens fünf Anmeldungen pro Stunde und IP' );
adm_check( '' === ADM_Newsletter::auto_html(), 'Newsletter: automatische Platzierung aus' );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'newsletter_auto' => 1 ) ) );
adm_check( false !== strpos( ADM_Newsletter::auto_html(), 'adm-nl' ), 'Newsletter: automatische Platzierung an' );
ob_start();
ADM_Newsletter::settings_rows( ADM_Settings::all() );
$nlrow = ob_get_clean();
adm_check( false !== strpos( $nlrow, 'gesetzt' ) && false !== strpos( $nlrow, 'Software ohne Abo' ) && false === strpos( $nlrow, 'sf-test-key' ), 'Newsletter: Verbindung in den Einstellungen, Schlüssel nicht sichtbar' );
remove_filter( 'pre_http_request', $nl_mock, 5 );
ADM_Settings::save( array_merge( ADM_Settings::all(), array( 'newsletter_list' => '', 'newsletter_auto' => 0 ) ) );
delete_option( 'adm_newsletter_error' );

// Deal-Seite des Plugins verträgt fehlende Deals
adm_check( '' === do_shortcode( '[adm_deal_box deal="gibt-es-nicht"]' ), 'Unbekannter Deal: kein Kasten' );

echo $fails ? "\n{$fails} Fehler\n" : "\nAlle Prüfungen bestanden\n";
exit( $fails ? 1 : 0 );
