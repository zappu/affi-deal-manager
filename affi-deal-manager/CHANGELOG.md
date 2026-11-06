# Changelog Thoka Affi Deal Manager

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
