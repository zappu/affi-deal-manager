# Changelog Thoka Affi Deal Manager

## 0.1.0 (05.11.2026)

- Erste Version
- Täglicher Abgleich mit der AppSumo-Sitemap: neue Produkte anlegen, geänderte Seiten (lastmod) sofort prüfen, verschwundene kaufbare Deals auf „ausgelaufen“ setzen
- Abruf der Produktseiten in kleinen Schritten alle 5 Minuten (Anzahl und Pause einstellbar), Übernahme der schema.org-Daten: Name, Marke, Kurzbeschreibung, Preis, Verfügbarkeit, Bewertung, Kategorie, FAQ, Rückgabefrist, Testvideos
- Status kaufbar, ausgelaufen, ungeprüft, ignoriert; Hook `adm_deal_status_changed` für spätere Module
- Impact-Deep-Links mit subId1, Shortcode `[adm_link]` mit `rel="sponsored nofollow"` und Kennzeichnung
- Backend im Menü „Thoka“: Deal-Liste mit Filtern, Suche und Sortierung, Statusübersicht, Knöpfe für sofortigen Abgleich
- E-Mail bei Störungen (höchstens einmal am Tag), Schutz gegen unvollständige Sitemaps
- Updates über update.json mit SHA-256-Prüfung, bringt thoka-menu mit
