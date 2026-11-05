# thoka-menu

Gemeinsamer Admin-Menüpunkt **Thoka** für alle Thoka-Plugins. Jedes Plugin bringt eine Kopie dieses Ordners mit. Geladen wird immer die höchste vorhandene Version, egal aus welchem Plugin.

Maßgebliche Quelle: Repo `zappu/thoka-plugin-installer`, Ordner `thoka-plugin-installer/thoka-menu/`. Änderungen dort machen, Version in `loader.php` und `class-thoka-admin-menu.php` erhöhen und den Ordner danach in die Plugins kopieren.

## Einbinden

Im Hauptdatei des Plugins:

```php
require_once __DIR__ . '/thoka-menu/loader.php';
```

Im eigenen `admin_menu`-Callback:

```php
if ( function_exists( 'thoka_menu_load' ) && thoka_menu_load() ) {
	$hook = Thoka_Admin_Menu::add_page( array(
		'group'       => 'mein-plugin',            // Schlüssel des Plugins
		'group_label' => 'Mein Plugin',            // Name im Menü „Thoka“ (ohne „Thoka“)
		'description' => 'Ein Satz für die Übersicht ohne Installer.',
		'slug'        => 'mein-plugin',
		'page_title'  => 'Thoka Mein Plugin',
		'menu_title'  => 'Übersicht',              // Beschriftung des Reiters
		'capability'  => 'manage_options',
		'callback'    => array( $this, 'render' ),
		'main'        => true,                     // sichtbarer Menüeintrag, zuerst registrieren
	) );

	// Weitere Seiten: ohne 'main', erscheinen als Reiter in der Thoka-Leiste.
	Thoka_Admin_Menu::add_page( array( 'group' => 'mein-plugin', 'slug' => 'mein-plugin-settings', 'page_title' => 'Thoka Mein Plugin: Einstellungen', 'menu_title' => 'Einstellungen', 'callback' => array( $this, 'render_settings' ) ) );

	// Alte Adressen umleiten (Lesezeichen, Links aus Mails, andere Plugins).
	Thoka_Admin_Menu::add_legacy_url( 'options-general.php', 'mein-plugin' );
}
```

## Regeln

- Seiten-Hooks heißen `thoka_page_<slug>`, die Startseite `toplevel_page_thoka`. Den Rückgabewert von `add_page()` nutzen, nicht `toplevel_page_…` oder `settings_page_…` fest eintragen.
- Links immer als `admin.php?page=<slug>` oder `Thoka_Admin_Menu::page_url( $slug, $args )` bauen, nie über `options-general.php`, `tools.php` oder `edit.php?post_type=…&page=…`.
- Eigener Beitragstyp: `'show_in_menu' => Thoka_Admin_Menu::PARENT` und die Liste mit `Thoka_Admin_Menu::add_tab_link()` als Reiter (und ggf. `'main' => true`) eintragen.
- Plugin-Name im Header beginnt mit „Thoka “, im Menü steht er ohne „Thoka“.

## API

| Methode | Zweck |
|---|---|
| `add_page( $args )` | Seite unter „Thoka“; Haupteintrag (`main`) oder Reiter |
| `add_tab_link( $args )` | Reiter auf eine andere Admin-Seite, z. B. `edit.php?post_type=x` (`screen` = Screen-ID) |
| `add_legacy_url( $datei, $alter_slug, $neuer_slug = null )` | Umleitung alter Adressen |
| `page_url( $slug, $args )` | URL einer Thoka-Seite |
| `hook_suffix( $slug )` | Hook-Name einer Thoka-Seite |
| `is_thoka_screen( $group = '' )` | Aktuelle Seite gehört zu Thoka (bzw. zu einer Gruppe) |
| `set_overview( $args )`, `set_parent_badge( $n )` | nur Thoka Plugin Installer |
