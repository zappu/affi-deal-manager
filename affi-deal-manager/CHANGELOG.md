# Changelog Thoka Affi Deal Manager

## 0.8.3 (07.11.2026)

- Screenshots: mindestens 5 Minuten zwischen zwei Abrufen je Profil (mShots braucht Zeit zum Erzeugen, vorher wurden die Versuche zu schnell verbraucht), bis zu 12 Versuche, Abruf immer über https. Zu früh abgebrochene Screenshots werden einmalig neu versucht

## 0.8.2 (07.11.2026)

- Knopf „Veröffentlichte Profile neu schreiben (N)“ mit Rückfrage: schreibt veröffentlichte Profile über die Warteschlange neu. Sie bleiben online, der neue Text ersetzt den alten, der alte bleibt als Revision erhalten. Die Freigabe gilt je Profil genau einmal (Option `adm_profile_force`); ohne diesen Klick werden veröffentlichte Profile weiterhin nie überschrieben

## 0.8.1 (07.11.2026)

- Bewertungen richtig gewichten: Der Abgleich liest die Verteilung der Tacos (1 bis 5) von der AppSumo-Seite. „Auf einen Blick“ zeigt sie live als Balken mit dem Satz „X % der N Bewertungen vergeben 4 oder 5 Tacos“, die Spalten heißen „Was Käufer loben“ und „Kritik aus den N schwächeren Bewertungen (X %)“. Gilt sofort für alle Profile, der gespeicherte Text bleibt unverändert. Shortcode `[adm_ratings]`
- Dazu Stufe bei AppSumo (Select, LaunchPad, Radar), Platzierung in den AppSumo-Charts und Zahl der Käufe: als Chips in „Auf einen Blick“, Käufe auch im Kopf, alles in den Deal-Details
- Prompt: bekommt Verteilung, Stufe, Käufe und Platzierung und ordnet Kritik nach ihrem Anteil ein; bei Radar und LaunchPad Hinweis, dass das Tool jung ist
- Datenbank-Version 7 (Spalten rating_dist, purchases, listing_type, ranks), Deals mit Profil werden nach dem Update neu gelesen

## 0.8.0 (07.11.2026)

- Website des Herstellers: der Abgleich liest sie aus den Seitendaten von AppSumo (`product_url`, neue Spalte `website`, ohne Parameter wie `?ref=`), im Profil überschreibbar. Link „Website des Herstellers“ im Deal-Kasten und Zeile „Website“ in den Details, beides öffnet in einem neuen Fenster
- Screenshot der Startseite des Herstellers als Produktbild: entsteht im Hintergrund beim Dienst mShots von WordPress.com (höchstens drei pro Abgleichschritt, Platzhalter werden beim nächsten Schritt erneut abgefragt), liegt in der Mediathek mit Quellenangabe und wird Beitragsbild statt des Rubrikbilds. Erscheint im Kopf des Profils (mit „Screenshot: <Domain>“) und auf den Karten der Übersichten. Einstellung „Screenshots“, im Profil „Screenshot neu aufnehmen“
- Handy: Leiste mit Name, Preis und Kauf-Button am unteren Rand, sobald der Kasten oben aus dem Bild gescrollt ist; verschwindet vor der Fußzeile (Filter `adm_sticky_bar`)
- Datenbank-Version 6: Deals mit Profil werden nach dem Update gleich neu gelesen

## 0.7.4 (07.11.2026)

- Bausteine für die Startseite: `[adm_rubrics]` zeigt alle Rubriken als Kacheln mit Bild und Zahl der Profile (auf dem Handy als kompakte Liste), `[adm_tools limit="6" heading="…"]` die veröffentlichten Tool-Profile als Karten, kaufbare und meistbewertete zuerst; ohne veröffentlichte Profile bleibt der Abschnitt leer
- Gestaltung für Seiten mit einer Block-Gruppe der Klasse `adm-home` (Startseite): Kopfbereich `adm-hero`, Kacheln, Teaser `adm-teasers`, Hinweis `adm-note`, grauer Grund
- Stylesheet lädt im Kopf der Seite, sobald der Inhalt Bausteine des Plugins enthält

## 0.7.3 (07.11.2026)

- Werbelinks, die nicht aus dem Plugin kommen (Elementor-Widgets in Kopf- und Fußzeile, Links im Editor), bekommen automatisch `rel="sponsored nofollow"`, wenn sie zu AppSumo oder zum Tracking-Host führen. Vorhandene rel-Werte bleiben, weitere Hosts per Filter `adm_ad_hosts`

## 0.7.2 (07.11.2026)

- Neuer Shortcode `[adm_deals]`: kaufbare Deals als Karten mit Preis, Bewertung und Werbelink, z. B. `[adm_deals plus="only"]` für die AppSumo-Plus-Seite. Die Plus-Mitgliedschaft selbst erscheint nicht in der Liste. `desc="0"` blendet die englische Kurzbeschreibung von AppSumo aus
- `[adm_link url="https://appsumo.com/…"]` verlinkt auch AppSumo-Seiten, die nicht in der Deal-Tabelle stehen (z. B. die Plus-Mitgliedschaft)
- Rubrikseiten: Rubrikbild als Vorschaubild beim Teilen (Rank Math)

## 0.7.1 (07.11.2026)

- Seitentitel und Meta-Beschreibung der Übersichten: /tools/ heißt „Software ohne Abo: alle Tool-Profile“, Rubriken „<Rubrik>: Software ohne Abo kaufen“, ab Seite 2 mit Seitenzahl. Beschreibung aus der Einleitung bzw. der Rubrik-Beschreibung. Gilt für Rank Math und ohne SEO-Plugin, anpassbar per Filter `adm_archive_meta_title`

## 0.7.0 (07.11.2026)

- Übersichtsseiten im Stil der Profile: /tools/ zeigt alle veröffentlichten Tool-Profile, /rubrik/<slug>/ die Profile einer Rubrik. Kopf mit Titel, Einleitung (Beschreibung der Rubrik) und Rubrikbild, Leiste mit allen Rubriken, Karten mit Preis, Bewertung und Status aus der Deal-Tabelle
- Sortierung: kaufbare zuerst, dann nach Zahl der Bewertungen; 24 Profile pro Seite mit Seitenzahlen
- Leere Übersichten bekommen noindex
- Aktiv mit dem Profil-Layout; eine passende Archiv-Vorlage in Elementor Pro hat Vorrang, abschaltbar per Filter `adm_archive_layout`, Titel und Einleitung per `adm_archive_title` und `adm_archive_lead`

## 0.6.3 (06.11.2026)

- Videotexte: „die sprechende Person“ wird zu „die Person im Video“, der Gemini-Prompt schreibt über das Video statt über die sprechende Person

## 0.6.2 (06.11.2026)

- YouTube-API: Anfragen senden die Adresse der eigenen Website als Referer. Schlüssel mit Website-Einschränkung („HTTP-Verweis-URLs“) funktionieren damit auch bei Abrufen vom Server (vorher HTTP 403). Anpassbar über den Filter `adm_youtube_referer`
- Fehlermeldung der YouTube-Suche enthält Googles Begründung

## 0.6.1 (06.11.2026)

- Videos nachtragen: Knopf „Videos nachtragen (N Entwürfe ohne Video)“ und Zeilenaktion „Videos neu suchen“. Sucht nur die Videos neu und lässt Gemini sie zusammenfassen, der Text bleibt (keine Kosten für die Haupt-KI). Läuft über die Warteschlange und den Server-Cron
- Fehler der YouTube-Suche erscheinen im Protokoll, insbesondere ein erschöpftes Tageskontingent der YouTube-API
- Stilprüfung: „SEO-Experten“ gilt nicht mehr als unbestimmte Quelle, nur Wendungen wie „Experten empfehlen“

## 0.6.0 (06.11.2026)

- AppSumo Plus: Deals „Plus exclusive“ und die Plus-Mitgliedschaft selbst (`plus-…`) gelten als Plus-Deals. Sie bekommen keine Tool-Profile, erscheinen nicht beim Sammelknopf und nicht bei „Alle Entwürfe neu erzeugen“
- `adm_get_deals()` lässt Plus-Deals standardmäßig weg; `plus => 'only'` liefert nur Plus-Deals (für die geplante Plus-Seite), `plus => 'all'` alle
- Deal-Liste: Kennzeichen „AppSumo Plus, kein Profil“ und Filter „Nur AppSumo Plus“

## 0.5.11 (06.11.2026)

- Deal-Kasten: Werbehinweis entfällt (steht im Footer der Seite), das Sternchen am Kauf-Button bleibt und ist für Screenreader als „Werbelink“ benannt; Hinweis bei Bedarf über den Filter `adm_box_note`

## 0.5.10 (06.11.2026)

- Kopfbereich: unter den Eckdaten steht nur noch „Stand <Datum>“

## 0.5.9 (06.11.2026)

- „Auf einen Blick“ ohne Quellenzeile (die Quelle steht unter „Was Käufer sagen“)

## 0.5.8 (06.11.2026)

- Videos: Zusatz „Zusammenfassung automatisch aus dem Video erstellt“ entfällt (gilt für neu erzeugte Texte)

## 0.5.7 (06.11.2026)

- Quellenzeile unter „Was Käufer sagen“ endet mit „Die Bewertungen stammen von AppSumo.“ (gilt für neu erzeugte Texte)

## 0.5.6 (06.11.2026)

- Kopfbereich: Zusatz „nicht selbst getestet“ entfällt; die Arbeitsweise steht künftig zentral auf der Seite „Über uns“

## 0.5.5 (06.11.2026)

- Videozusammenfassung: Gemini meldet die im Video gesprochene Sprache (vorher kam die Sprache der Zusammenfassung zurück, englische Videos standen als „Deutsch“ da); alter Zwischenspeicher wird nicht mehr verwendet
- Standardmodell `gemini-3.6-flash`, Ausweichmodell `gemini-3.5-flash-lite` (die „-latest“-Aliasse waren im Gratis-Kontingent dauerhaft überlastet)

## 0.5.4 (06.11.2026)

- Videozusammenfassung: Ist Gemini überlastet (HTTP 429, 500, 503), wartet das Plugin kurz und versucht es erneut, danach mit dem kleineren Modell `gemini-flash-lite-latest`

## 0.5.3 (06.11.2026)

- Bausteine nutzen eigene Schriftvariablen (`--adm-body-font` Inter, `--adm-head-font` Space Grotesk) statt der Standardschrift des Kits
- Kleinere Innenabstände auf dem Handy
- Prompt: keine Bewertungsnoten und keine Anzahl von Bewertungen im Fließtext (stehen live im Kasten)

## 0.5.2 (06.11.2026)

- Videozusammenfassung: nur Videos bis 25 Minuten, Zeitlimit 100 Sekunden, nach einer Zeitüberschreitung keine zweite Anfrage im selben Lauf
- Ungültiges oder abgeschnittenes JSON der KI: ein zweiter Versuch mit mehr Platz
- Sperre der Profil-Erzeugung 15 statt 5 Minuten, damit sich lange Läufe nicht überschneiden; ein Lauf, den der Server abbricht, erscheint als „Abgebrochen“ im Protokoll
- Protokoll trennt Stilhinweise und Video-Hinweise
- Hinweis „kein Aufruf vom Server“ nur noch, wenn der Server-Cron seit über einer Stunde nicht kam (ein manueller Schritt löste ihn fälschlich aus)

## 0.5.1 (06.11.2026)

- Profil-Layout aus dem Plugin: Bei der Einstellung „Profil-Layout“ legt das Plugin Kopfbereich und Seitenleiste mit Deal-Kasten selbst um den Beitragsinhalt. Theme oder Elementor-Vorlage geben nur noch den Inhalt aus (Elementor kann Shortcode-Widgets über seine Schnittstelle nicht setzen)
- Hello Elementor: Theme-Titel entfällt im Profil-Layout, die H1 kommt aus dem Kopfbereich
- Grauer Seitenhintergrund und mitlaufende Seitenleiste per Stylesheet

## 0.5.0 (06.11.2026)

- Neue Gestaltung „Verzeichnis“: Abschnitte als Karten, Farben und Schriften über CSS-Variablen (`--adm-*`), eigenes Stylesheet `assets/adm-frontend.css`
- Neuer Baustein `[adm_deal_header]`: Brotkrumen, Titel, Kurzbeschreibung, Eckdaten als Chips (Preis, Bewertung, Rückgabe, Rubrik), Bild und Quellenzeile, für das Elementor-Layout
- `[adm_deal_box placement="sidebar"]` für die Seitenleiste; Einstellung „Deal-Kasten in der Seitenleiste“: der Kasten im Text erscheint dann nur auf dem Handy, die Seite bekommt die Klasse `adm-layout`
- Neuer Baustein `[adm_toc]`: Sprungmenü aus den Abschnitten
- Profiltexte: „Auf einen Blick“ mit Stärken und Schwächen ganz oben, Abschnitte mit Sprungzielen, Fragen zum Aufklappen, Funktionen als Kacheln
- Videos mit Zwei-Klick-Lösung: YouTube wird erst nach Klick geladen, mit Hinweis auf die Datenschutzerklärung
- Rubriken bekommen ein Bild (Mediathek); es wird Beitragsbild der Profile ohne eigenes Bild

## 0.4.5 (06.11.2026)

- Drei weitere Rubriken aus den AppSumo-Bereichen unter „Build & Code“: Entwickler-Tools, Formulare und App-Baukästen, Sicherheit und Datenschutz (dorthin auch Cookie-Banner); damit hat jeder kaufbare Deal eine Rubrik
- Knopf „Alle Entwürfe neu erzeugen“: merkt alle Profil-Entwürfe zum Neuschreiben vor, veröffentlichte Profile bleiben unangetastet
- Protokoll zeigt „Entwurf erstellt“ auch bei Stilhinweisen

## 0.4.4 (06.11.2026)

- Anzeigename: Zusätze aus AppSumo-Namen fallen weg („WP Reset Pro Plan“ → „WP Reset Pro“, „Screpy - Plus Exclusive“ → „Screpy“, bei Software auch der Werbezusatz nach Bindestrich oder Doppelpunkt); eigener Anzeigename im Kasten „Deal bei AppSumo“ am Profil; Datenbank Version 5 (Spalte `display_name`)
- Rubriken: Taxonomie „Rubrik“ (`/rubrik/<name>/`) mit den 10 Rubriken aus dem Projektplan; AppSumo-Kategorien werden beim Erzeugen zugeordnet, von Hand gesetzte Rubriken bleiben; Deal-Details zeigen die Rubrik statt der englischen AppSumo-Kategorie; Deal-Liste zeigt Rubrik und Kategorie
- Alternativen kommen aus derselben Rubrik; ohne Alternativen bleibt der Abschnitt samt Überschrift weg, bei ausgelaufenen Deals führt der Knopf dann direkt zur Warteliste
- Videos: Abschnitt „Videos zu <Name>“ statt „Was Testvideos zeigen“; Auswahl nach Punkten (deutsch, Test oder Anleitung, Länge, Alter), Werbevideos nach hinten, Shorts fallen weg; Dauer und Sprache über die YouTube-API
- Videozusammenfassung mit Gemini (optional, `THOKA_GEMINI_API_KEY`): Gemini sieht das Video an, die Zusammenfassung ist gekennzeichnet und fließt in die Funktionen ein; ohne Schlüssel kein Text zum Videoinhalt; Ergebnisse 60 Tage zwischengespeichert
- Prompt: kleines „du“, Aussagen aus Bewertungen immer mit Gruppe, „Für wen es passt“ nur aus Herstellerangaben oder mehrfach genannten Punkten, weitere Regeln aus „vermenschlichen“
- Stilprüfung nach jeder Erzeugung (Gedankenstriche, „nicht nur … sondern“, großes „Du“, Floskeln, unbestimmte Quellen, behauptete Tests); Hinweise im Protokoll und am Profil, großes „Du“ mitten im Satz wird automatisch korrigiert

## 0.4.3 (06.11.2026)

- Fehler behoben: Claude Sonnet 5.5 lehnt den Parameter `temperature` ab (HTTP 400). Die Anfragen kommen jetzt bei allen Anbietern ohne ihn aus

## 0.4.2 (06.11.2026)

- Fehler behoben: Modellnamen mit Tilde (OpenRouter-Aliasse wie `~anthropic/claude-sonnet-latest`) wurden beim Speichern verstümmelt
- Standardmodelle: OpenRouter `anthropic/claude-sonnet-5.5`, Anthropic `claude-sonnet-5-5`; Vorschlagsliste im Modellfeld
- Warteschlange hält bei Einstellungsfehlern (falsches Modell, fehlender oder ungültiger Schlüssel, HTTP 400/401/403/404) an, statt Aufträge zu verbrauchen; weiter nach dem Speichern der Einstellungen oder mit „Nächstes jetzt erzeugen“
- Server-Cron: angezeigter Befehl mit `-k` (fehlende Zertifikatsdatei in Plesk), `-d ""` (ModSecurity) und eigener Kennung, Hinweis auf das `%`-Zeichen

## 0.4.1 (06.11.2026)

- API-Schlüssel nur noch in der `wp-config.php`: `THOKA_OPENROUTER_API_KEY`, `THOKA_OPENAI_API_KEY`, `THOKA_ANTHROPIC_API_KEY` und optional `THOKA_YOUTUBE_API_KEY`. Die Namen sind für alle Thoka-Plugins gedacht
- Kein Schlüsselfeld mehr in den Einstellungen, stattdessen Status je Schlüssel (gesetzt oder fehlt) und die fertigen Zeilen für die `wp-config.php`
- Beim Update werden in 0.4.0 gespeicherte Schlüssel aus der Datenbank gelöscht
- Fehlermeldungen nennen die fehlende Konstante

## 0.4.0 (06.11.2026)

- Tool-Profile: eigener Beitragstyp unter `/tools/<name>/`, verknüpft mit dem Deal, Reiter „Tool-Profile“ in der Thoka-Leiste
- Erzeugung per KI (OpenRouter, OpenAI oder Anthropic, Schlüssel und Modell in den Einstellungen) als Entwurf; veröffentlichte Profile werden nie überschrieben
- Käuferstimmen: die neuesten und die kritischsten Bewertungen bei AppSumo werden getrennt ausgewertet, ohne Nutzernamen, ohne wörtliche Zitate, ohne vergütete Bewertungen und Antworten; Quelle und Stand stehen im Text
- Testvideos: mit YouTube-Schlüssel eigene Suche, deutsche Videos zuerst, sonst die Liste von AppSumo (nie das AppSumo-eigene Video); Einbettung über youtube-nocookie.com
- Abschnitte: Kurz erklärt, Für wen es passt und für wen nicht, Funktionen (bei Kursen und E-Books Inhalt), Was Käufer sagen, Was Testvideos zeigen, Deal-Details, Häufige Fragen, Alternativen
- Live-Bausteine `[adm_deal_box]`, `[adm_deal_details]`, `[adm_video]`, `[adm_alternatives]`: Preis, Status mit Grund und Kauf-Button kommen immer aktuell aus der Datenbank; bei ausgelaufenen Deals Alternativen und Warteliste
- Seitentitel, SEO-Titel, Meta-Beschreibung und Fokus-Keyword für Rank Math nach einstellbaren Vorlagen
- Warteschlange mit Sammelknopf „Profile für die besten Software-Deals“, „Nächstes jetzt erzeugen“ und Protokoll; optional automatische Entwürfe für neue kaufbare Software-Deals
- Server-Cron arbeitet die Profil-Warteschlange mit ab
- Deal-Nummer bei AppSumo (`appsumo_id`) wird gespeichert; Datenbank Version 4
- Stilregeln im Prompt nach dem Skill „vermenschlichen“; Schutz gegen Anweisungen in fremden Daten; Script- und Style-Blöcke aus KI-Antworten werden samt Inhalt entfernt

## 0.3.0 (06.11.2026)

- Deal-Typ: Software, Kurs, E-Book oder Vorlage. Grundlage ist der Deal-Typ von AppSumo (Link oder eingebettete Seitendaten); Lern-Deals werden über Stichwörter in Name und Beschreibung in Kurs oder E-Book geteilt, im Zweifel Kurs
- Typ im Backend per Klick korrigierbar; eine manuelle Korrektur übersteht den Abgleich, „automatisch“ gibt die Erkennung wieder frei
- Spalte „Typ“, Filter nach Typ und „Nur kostenlose“, Übersicht „Kaufbar nach Typ“ mit Anzahl kostenloser Deals, Preis 0 erscheint als „kostenlos“
- Neue Funktion `adm_get_deals()` für Bereiche der Website, z. B. kostenlose E-Books
- Enddatum: AppSumo setzt bei laufenden Deals einen Platzhalter etwa ein Jahr ab Abruf; solche Daten werden verworfen, nur echte Enddaten bleiben
- Update: Datenbank erhält die Spalten `deal_type`, `deal_type_source`, `appsumo_type`; Platzhalter-Enddaten werden entfernt, kaufbare Deals sofort neu geprüft

## 0.2.0 (06.11.2026)

- Zeitplan frei einstellbar: Modus (WP-Cron, Server-Cron, aus), Takt von 5 Minuten bis einmal am Tag, Zeitfenster (auch über Mitternacht), Uhrzeit für das Lesen der Sitemap
- Server-Cron für Plesk über `/wp-json/affi-deal-manager/v1/tick` mit geheimem Schlüssel (Header `X-ADM-Key` oder Parameter `key`), fertiger Befehl in den Einstellungen
- Aktive Deals zuerst: die Sitemap-Priorität (0.5 aktiv, 0.25 alt) wird gespeichert und bestimmt die Reihenfolge der Prüfung
- Bis zu 100 Deals pro Schritt, jeder Schritt endet nach spätestens etwa 3 Minuten
- Grund zum Status in der Deal-Liste (z. B. „Ausverkauft“, „Nicht mehr in der AppSumo-Sitemap“, „Seite bei AppSumo entfernt“, „Angebot läuft bis …“), auch als Feld `reason` in `adm_get_deal()`
- Durchsatz-Schätzung in den Einstellungen, Zeitplan und letzter Schritt auf der Deal-Seite
- Sitemap-Fehler: neuer Versuch nach einer Stunde, ohne den Tagesrhythmus zu verschieben
- Update von 0.1.0: Einstellungen und Daten werden übernommen, Datenbank erhält die Spalte `sitemap_priority`

## 0.1.0 (05.11.2026)

- Erste Version
- Täglicher Abgleich mit der AppSumo-Sitemap: neue Produkte anlegen, geänderte Seiten (lastmod) sofort prüfen, verschwundene kaufbare Deals auf „ausgelaufen“ setzen
- Abruf der Produktseiten in kleinen Schritten alle 5 Minuten (Anzahl und Pause einstellbar), Übernahme der schema.org-Daten: Name, Marke, Kurzbeschreibung, Preis, Verfügbarkeit, Bewertung, Kategorie, FAQ, Rückgabefrist, Testvideos
- Status kaufbar, ausgelaufen, ungeprüft, ignoriert; Hook `adm_deal_status_changed` für spätere Module
- Impact-Deep-Links mit subId1, Shortcode `[adm_link]` mit `rel="sponsored nofollow"` und Kennzeichnung
- Backend im Menü „Thoka“: Deal-Liste mit Filtern, Suche und Sortierung, Statusübersicht, Knöpfe für sofortigen Abgleich
- E-Mail bei Störungen (höchstens einmal am Tag), Schutz gegen unvollständige Sitemaps
- Updates über update.json mit SHA-256-Prüfung, bringt thoka-menu mit
