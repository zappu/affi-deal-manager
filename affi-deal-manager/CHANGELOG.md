# Changelog Thoka Affi Deal Manager

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
