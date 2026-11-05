<?php
/**
 * Thoka Admin-Menü: gemeinsamer Menüpunkt „Thoka“ für alle Thoka-Plugins.
 *
 * Jedes Plugin erscheint dort genau einmal. Weitere Seiten eines Plugins sind
 * registriert, aber im Menü ausgeblendet und werden als Reiter in der
 * Thoka-Leiste oben auf jeder Plugin-Seite angezeigt.
 *
 * Nicht direkt einbinden, sondern über thoka-menu/loader.php.
 *
 * Kurzanleitung für ein Plugin (im eigenen admin_menu-Callback):
 *
 *   if ( function_exists( 'thoka_menu_load' ) && thoka_menu_load() ) {
 *       $hook = Thoka_Admin_Menu::add_page( array(
 *           'group'      => 'mein-plugin',          // Schlüssel des Plugins
 *           'group_label'=> 'Mein Plugin',          // Name im Menü „Thoka“ (ohne „Thoka“)
 *           'slug'       => 'mein-plugin',
 *           'page_title' => 'Thoka Mein Plugin',
 *           'menu_title' => 'Übersicht',            // Beschriftung des Reiters
 *           'capability' => 'manage_options',
 *           'callback'   => array( $this, 'render' ),
 *           'main'       => true,                   // sichtbarer Menüeintrag
 *       ) );
 *   }
 *
 * Seiten-Hooks lauten immer „thoka_page_<slug>“, die Übersicht selbst
 * „toplevel_page_thoka“. Plugins sollten den Rückgabewert von add_page()
 * verwenden statt den Hook-Namen fest einzutragen.
 *
 * @package Thoka
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Thoka_Admin_Menu', false ) ) {
	return;
}

final class Thoka_Admin_Menu {

	const VERSION     = '1.0.0';
	const PARENT      = 'thoka';
	const HOOK_PREFIX = 'thoka_page_';
	const POSITION    = 3;

	/** @var bool */
	private static $initialized = false;

	/** @var array<string,mixed>|null Übersichtsseite (vom Thoka Plugin Installer gesetzt). */
	private static $overview = null;

	/** @var string */
	private static $overview_hook = '';

	/** @var int */
	private static $parent_badge = 0;

	/**
	 * Gruppen = Plugins. group => [label, description, main, items[]]
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $groups = array();

	/** @var array<string,string> slug => group */
	private static $page_group = array();

	/** @var array<string,string> screen id => group */
	private static $screen_group = array();

	/** @var array<string,bool> slug => true für ausgeblendete Seiten */
	private static $hidden = array();

	/** @var array<string,array<string,string>> alte Datei => [alter Slug => neuer Slug] */
	private static $legacy = array();

	/** @var int */
	private static $counter = 0;

	/**
	 * Wird vom Loader einmal aufgerufen.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'admin_menu', array( __CLASS__, 'register_parent' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'finalize_menu' ), PHP_INT_MAX );
		// Alte Adressen: admin_page_access_denied greift, wenn WordPress die Seite
		// unter der alten Datei nicht mehr kennt (z. B. options-general.php),
		// admin_init in allen anderen Fällen (z. B. edit.php?post_type=…).
		add_action( 'admin_page_access_denied', array( __CLASS__, 'legacy_redirect' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'legacy_redirect' ), 0 );
		add_filter( 'submenu_file', array( __CLASS__, 'filter_submenu_file' ), 10, 2 );
		add_action( 'in_admin_header', array( __CLASS__, 'render_bar' ), 20 );
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/* ---------------------------------------------------------------------
	 * Öffentliche API
	 * ------------------------------------------------------------------- */

	/**
	 * Legt fest, was die Startseite „Thoka“ zeigt. Der Thoka Plugin Installer
	 * nutzt das für seine Plugin-Übersicht. Muss vor admin_menu (Priorität 0)
	 * aufgerufen werden.
	 *
	 * @param array<string,mixed> $args callback, capability, page_title, menu_title, load (optional).
	 */
	public static function set_overview( array $args ) {
		self::$overview = wp_parse_args(
			$args,
			array(
				'callback'   => array( __CLASS__, 'render_fallback_overview' ),
				'capability' => 'manage_options',
				'page_title' => 'Thoka',
				'menu_title' => 'Übersicht',
				'load'       => null,
			)
		);
	}

	/**
	 * Zahl, die als Hinweis-Kreis am Menüpunkt „Thoka“ erscheint (z. B. Updates).
	 *
	 * @param int $count
	 */
	public static function set_parent_badge( $count ) {
		self::$parent_badge = max( 0, (int) $count );
	}

	/**
	 * Registriert eine Plugin-Seite unter „Thoka“. Im admin_menu-Hook aufrufen.
	 *
	 * @param array<string,mixed> $args
	 * @return string|false Seiten-Hook („thoka_page_<slug>“) oder false ohne Berechtigung.
	 */
	public static function add_page( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'group'       => '',
				'group_label' => '',
				'description' => '',
				'slug'        => '',
				'page_title'  => '',
				'menu_title'  => '',
				'capability'  => 'manage_options',
				'callback'    => '',
				'main'        => false,
				'position'    => null,
			)
		);

		$slug  = (string) $args['slug'];
		$group = '' !== $args['group'] ? sanitize_key( $args['group'] ) : sanitize_key( $slug );
		if ( '' === $slug ) {
			return false;
		}

		self::ensure_parent();
		self::add_group( $group, $args );

		$is_main    = (bool) $args['main'] || empty( self::$groups[ $group ]['main'] );
		$tab_label  = '' !== $args['menu_title'] ? $args['menu_title'] : $args['page_title'];
		$menu_label = $is_main && '' !== self::$groups[ $group ]['label'] ? self::$groups[ $group ]['label'] : $tab_label;

		$hook = add_submenu_page(
			self::PARENT,
			'' !== $args['page_title'] ? $args['page_title'] : wp_strip_all_tags( $tab_label ),
			$menu_label,
			$args['capability'],
			$slug,
			$args['callback']
		);

		self::$page_group[ $slug ] = $group;
		self::add_item(
			$group,
			array(
				'type'       => 'page',
				'slug'       => $slug,
				'label'      => $tab_label,
				'capability' => $args['capability'],
				'url'        => self::page_url( $slug ),
				'position'   => $args['position'],
			)
		);

		if ( $is_main && empty( self::$groups[ $group ]['main'] ) ) {
			self::$groups[ $group ]['main'] = $slug;
		} elseif ( self::$groups[ $group ]['main'] !== $slug ) {
			self::$hidden[ $slug ] = true;
		}

		return $hook;
	}

	/**
	 * Fügt einer Gruppe einen Reiter hinzu, der keine eigene Plugin-Seite ist,
	 * z. B. die Liste eines eigenen Beitragstyps (edit.php?post_type=…).
	 *
	 * Ist 'main' gesetzt, gilt der Link als Haupteintrag der Gruppe. Der
	 * sichtbare Menüeintrag kommt dann vom Plugin selbst (z. B. per
	 * register_post_type( … 'show_in_menu' => Thoka_Admin_Menu::PARENT )).
	 *
	 * @param array<string,mixed> $args group, group_label, label, url, screen, capability, main, position.
	 */
	public static function add_tab_link( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'group'       => '',
				'group_label' => '',
				'description' => '',
				'label'       => '',
				'url'         => '',
				'screen'      => '',
				'menu_slug'   => '',
				'capability'  => 'read',
				'main'        => false,
				'position'    => null,
			)
		);

		$group = sanitize_key( $args['group'] );
		if ( '' === $group || '' === $args['url'] ) {
			return;
		}

		self::add_group( $group, $args );
		self::add_item(
			$group,
			array(
				'type'       => 'link',
				'slug'       => (string) $args['menu_slug'],
				'screen'     => (string) $args['screen'],
				'label'      => $args['label'],
				'capability' => $args['capability'],
				'url'        => $args['url'],
				'position'   => $args['position'],
			)
		);

		foreach ( (array) $args['screen'] as $screen ) {
			if ( '' !== $screen ) {
				self::$screen_group[ $screen ] = $group;
			}
		}

		if ( $args['main'] && empty( self::$groups[ $group ]['main'] ) ) {
			self::$groups[ $group ]['main']     = '' !== $args['menu_slug'] ? $args['menu_slug'] : $args['url'];
			self::$groups[ $group ]['main_url'] = $args['url'];
		}
	}

	/**
	 * Leitet eine alte Adresse (z. B. options-general.php?page=x) auf die neue
	 * Seite unter „Thoka“ um, damit Lesezeichen und alte Links weiter gehen.
	 *
	 * @param string      $file     Alte Admin-Datei, z. B. 'options-general.php' oder 'edit.php'.
	 * @param string      $old_page Alter Wert von ?page=.
	 * @param string|null $new_page Neuer Slug, Standard: gleich.
	 */
	public static function add_legacy_url( $file, $old_page, $new_page = null ) {
		self::$legacy[ (string) $file ][ (string) $old_page ] = null === $new_page ? (string) $old_page : (string) $new_page;
	}

	/**
	 * URL einer Seite unter „Thoka“.
	 *
	 * @param string              $slug
	 * @param array<string,mixed> $args Weitere Query-Parameter.
	 * @return string
	 */
	public static function page_url( $slug, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), self_admin_url( 'admin.php' ) );
	}

	/**
	 * Hook-Name einer Seite unter „Thoka“, z. B. für admin_enqueue_scripts.
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function hook_suffix( $slug ) {
		return self::PARENT === $slug ? 'toplevel_page_' . self::PARENT : self::HOOK_PREFIX . $slug;
	}

	/**
	 * Gehört die aktuelle Admin-Seite zu „Thoka“ (optional: zu einer Gruppe)?
	 *
	 * @param string $group
	 * @return bool
	 */
	public static function is_thoka_screen( $group = '' ) {
		$current = self::current_group();
		if ( null === $current ) {
			return false;
		}
		return '' === $group || $current === sanitize_key( $group );
	}

	/**
	 * Seiten-Hook der Startseite „Thoka“.
	 *
	 * @return string
	 */
	public static function overview_hook() {
		return self::$overview_hook;
	}

	/* ---------------------------------------------------------------------
	 * Menü aufbauen
	 * ------------------------------------------------------------------- */

	/**
	 * admin_menu, Priorität 0: Hauptmenü „Thoka“ anlegen.
	 */
	public static function register_parent() {
		self::ensure_parent();
	}

	private static function ensure_parent() {
		global $admin_page_hooks;

		if ( ! empty( $admin_page_hooks[ self::PARENT ] ) ) {
			return;
		}

		if ( null === self::$overview ) {
			self::set_overview( array() );
		}
		$o = self::$overview;

		add_menu_page(
			'Thoka',
			'Thoka',
			$o['capability'],
			self::PARENT,
			$o['callback'],
			self::icon(),
			self::POSITION
		);

		self::$overview_hook = (string) add_submenu_page(
			self::PARENT,
			$o['page_title'],
			$o['menu_title'],
			$o['capability'],
			self::PARENT,
			$o['callback']
		);

		if ( '' === self::$overview_hook ) {
			self::$overview_hook = self::hook_suffix( self::PARENT );
		}
		if ( ! empty( $o['load'] ) && is_callable( $o['load'] ) ) {
			add_action( 'load-' . self::$overview_hook, $o['load'] );
		}
	}

	/**
	 * admin_menu, ganz zum Schluss: Untermenü sortieren, Unterseiten
	 * ausblenden, Hinweis-Kreis setzen.
	 */
	public static function finalize_menu() {
		global $submenu, $menu;

		if ( self::$parent_badge > 0 && is_array( $menu ) ) {
			foreach ( $menu as $i => $item ) {
				if ( isset( $item[2] ) && self::PARENT === $item[2] ) {
					$menu[ $i ][0] .= ' <span class="update-plugins count-' . self::$parent_badge . '"><span class="plugin-count">' . self::$parent_badge . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					break;
				}
			}
		}

		if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
			return;
		}

		$first   = array();
		$visible = array();
		$hidden  = array();

		foreach ( $submenu[ self::PARENT ] as $item ) {
			$slug = isset( $item[2] ) ? $item[2] : '';
			if ( self::PARENT === $slug ) {
				$first[] = $item;
			} elseif ( isset( self::$hidden[ $slug ] ) ) {
				$item[4] = trim( ( isset( $item[4] ) ? $item[4] : '' ) . ' hidden thoka-menu-hidden' );
				$hidden[] = $item;
			} else {
				$visible[] = $item;
			}
		}

		usort(
			$visible,
			static function ( $a, $b ) {
				return strnatcasecmp( wp_strip_all_tags( $a[0] ), wp_strip_all_tags( $b[0] ) );
			}
		);

		$submenu[ self::PARENT ] = array_merge( $first, $visible, $hidden ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Ausgeblendete Unterseiten: im Menü den Haupteintrag des Plugins markieren.
	 *
	 * @param string|null $submenu_file
	 * @param string      $parent_file
	 * @return string|null
	 */
	public static function filter_submenu_file( $submenu_file, $parent_file = '' ) {
		global $plugin_page;

		if ( self::PARENT !== $parent_file ) {
			return $submenu_file;
		}

		$page = is_string( $plugin_page ) ? $plugin_page : '';
		if ( '' !== $page && isset( self::$hidden[ $page ], self::$page_group[ $page ] ) ) {
			$group = self::$page_group[ $page ];
			if ( ! empty( self::$groups[ $group ]['main'] ) ) {
				return self::$groups[ $group ]['main'];
			}
		}

		return $submenu_file;
	}

	/**
	 * admin_init: alte Adressen umleiten.
	 */
	public static function legacy_redirect() {
		global $pagenow;

		if ( empty( self::$legacy ) || ! isset( $_GET['page'] ) || ( wp_doing_ajax() ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$file = is_string( $pagenow ) ? $pagenow : '';
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::$legacy[ $file ][ $page ] ) ) {
			return;
		}

		$target = self::$legacy[ $file ][ $page ];
		if ( 'admin.php' === $file && $target === $page ) {
			return;
		}

		$args = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $args['page'] );
		if ( 'edit.php' === $file ) {
			unset( $args['post_type'] );
		}

		wp_safe_redirect( self::page_url( $target, rawurlencode_deep( $args ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Thoka-Leiste mit Reitern
	 * ------------------------------------------------------------------- */

	/**
	 * in_admin_header: Leiste „Thoka › Plugin“ mit den Reitern des Plugins.
	 */
	public static function render_bar() {
		$group = self::current_group();
		if ( null === $group ) {
			return;
		}

		$items   = self::accessible_items( $group );
		$current = self::current_item_key( $group );
		$label   = isset( self::$groups[ $group ] ) ? self::$groups[ $group ]['label'] : '';
		$home    = current_user_can( self::$overview ? self::$overview['capability'] : 'manage_options' ) ? self::page_url( self::PARENT ) : '';

		echo '<div class="thoka-bar" role="navigation" aria-label="Thoka">';
		echo '<div class="thoka-bar__title">';
		if ( '' !== $home ) {
			echo '<a class="thoka-bar__brand" href="' . esc_url( $home ) . '">Thoka</a>';
		} else {
			echo '<span class="thoka-bar__brand">Thoka</span>';
		}
		if ( '' !== $label ) {
			echo '<span class="thoka-bar__sep" aria-hidden="true">›</span><span class="thoka-bar__plugin">' . esc_html( $label ) . '</span>';
		}
		echo '</div>';

		if ( count( $items ) > 1 ) {
			echo '<nav class="thoka-bar__tabs">';
			foreach ( $items as $key => $item ) {
				$is_current = $key === $current;
				printf(
					'<a href="%1$s"%2$s>%3$s</a>',
					esc_url( $item['url'] ),
					$is_current ? ' class="is-current" aria-current="page"' : '',
					wp_kses( $item['label'], array( 'span' => array( 'class' => true ) ) )
				);
			}
			echo '</nav>';
		}

		echo '</div>';
	}

	/**
	 * admin_head: Stile für Leiste und ausgeblendete Menüeinträge.
	 */
	public static function print_styles() {
		$css = '#adminmenu .thoka-menu-hidden{display:none!important}';

		if ( null !== self::current_group() ) {
			$css .= '.thoka-bar{display:flex;flex-wrap:wrap;align-items:stretch;gap:0 24px;margin:0 0 0 -20px;padding:0 20px;background:#fff;border-bottom:1px solid #dcdcde;min-height:48px;box-sizing:border-box}'
				. '.thoka-bar__title{display:flex;align-items:center;gap:8px;font-size:14px;line-height:1.4;padding:12px 0;color:#1d2327}'
				. '.thoka-bar__brand{color:#646970;text-decoration:none;font-weight:400}'
				. 'a.thoka-bar__brand:hover,a.thoka-bar__brand:focus{color:#2271b1}'
				. '.thoka-bar__sep{color:#a7aaad}'
				. '.thoka-bar__plugin{font-weight:600}'
				. '.thoka-bar__tabs{display:flex;flex-wrap:wrap;align-items:stretch}'
				. '.thoka-bar__tabs a{display:flex;align-items:center;padding:0 12px;min-height:46px;color:#50575e;text-decoration:none;font-size:13px;border-bottom:2px solid transparent;margin-bottom:-1px}'
				. '.thoka-bar__tabs a:hover{color:#2271b1}'
				. '.thoka-bar__tabs a:focus{box-shadow:inset 0 0 0 2px #2271b1;outline:none}'
				. '.thoka-bar__tabs a.is-current{color:#1d2327;font-weight:600;border-bottom-color:#2271b1}'
				. '@media screen and (max-width:782px){.thoka-bar{margin-left:-10px;padding:0 10px}.thoka-bar__tabs a{padding:0 10px}}'
				. '.thoka-fallback-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-top:16px}'
				. '.thoka-fallback-list .card{margin:0;max-width:none}';
		}

		echo '<style id="thoka-admin-menu">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @param string $classes
	 * @return string
	 */
	public static function body_class( $classes ) {
		if ( null !== self::current_group() ) {
			$classes .= ' thoka-admin';
		}
		return $classes;
	}

	/* ---------------------------------------------------------------------
	 * Startseite ohne Thoka Plugin Installer
	 * ------------------------------------------------------------------- */

	public static function render_fallback_overview() {
		echo '<div class="wrap"><h1>Thoka</h1>';
		echo '<p>Alle installierten Thoka-Plugins auf dieser Website.</p>';

		$cards = array();
		foreach ( self::$groups as $group => $data ) {
			$items = self::accessible_items( $group );
			if ( empty( $items ) ) {
				continue;
			}
			$first   = reset( $items );
			$cards[] = array(
				'label'       => '' !== $data['label'] ? $data['label'] : $first['label'],
				'description' => $data['description'],
				'url'         => ! empty( $data['main_url'] ) ? $data['main_url'] : $first['url'],
			);
		}

		usort(
			$cards,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['label'], $b['label'] );
			}
		);

		if ( empty( $cards ) ) {
			echo '<p>Es ist noch kein Thoka-Plugin mit eigener Seite aktiv.</p>';
		} else {
			echo '<div class="thoka-fallback-list">';
			foreach ( $cards as $card ) {
				echo '<div class="card"><h2 class="title"><a href="' . esc_url( $card['url'] ) . '">' . esc_html( $card['label'] ) . '</a></h2>';
				if ( '' !== $card['description'] ) {
					echo '<p>' . esc_html( $card['description'] ) . '</p>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		if ( current_user_can( 'install_plugins' ) && ! defined( 'TPI_VERSION' ) ) {
			echo '<p class="description" style="margin-top:24px">Mit dem Thoka Plugin Installer siehst du hier alle verfügbaren Thoka-Plugins und kannst sie installieren und aktualisieren.</p>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Intern
	 * ------------------------------------------------------------------- */

	/**
	 * @param string              $group
	 * @param array<string,mixed> $args
	 */
	private static function add_group( $group, array $args ) {
		if ( ! isset( self::$groups[ $group ] ) ) {
			self::$groups[ $group ] = array(
				'label'       => '',
				'description' => '',
				'main'        => '',
				'main_url'    => '',
				'items'       => array(),
			);
		}
		if ( '' === self::$groups[ $group ]['label'] && ! empty( $args['group_label'] ) ) {
			self::$groups[ $group ]['label'] = (string) $args['group_label'];
		}
		if ( '' === self::$groups[ $group ]['description'] && ! empty( $args['description'] ) ) {
			self::$groups[ $group ]['description'] = (string) $args['description'];
		}
	}

	/**
	 * @param string              $group
	 * @param array<string,mixed> $item
	 */
	private static function add_item( $group, array $item ) {
		$item['order'] = null === $item['position'] ? 1000 + ( ++self::$counter ) : (float) $item['position'];
		$key           = 'page' === $item['type'] ? 'page:' . $item['slug'] : 'link:' . $item['url'];

		self::$groups[ $group ]['items'][ $key ] = $item;

		uasort(
			self::$groups[ $group ]['items'],
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);
	}

	/**
	 * @param string $group
	 * @return array<string,array<string,mixed>>
	 */
	private static function accessible_items( $group ) {
		if ( empty( self::$groups[ $group ]['items'] ) ) {
			return array();
		}
		$out = array();
		foreach ( self::$groups[ $group ]['items'] as $key => $item ) {
			if ( current_user_can( $item['capability'] ) ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}

	/**
	 * Gruppe der aktuellen Admin-Seite oder null.
	 *
	 * @return string|null
	 */
	private static function current_group() {
		global $plugin_page;

		if ( is_string( $plugin_page ) && isset( self::$page_group[ $plugin_page ] ) ) {
			return self::$page_group[ $plugin_page ];
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( self::$screen_group[ $screen->id ] ) ) {
				return self::$screen_group[ $screen->id ];
			}
		}

		return null;
	}

	/**
	 * @param string $group
	 * @return string
	 */
	private static function current_item_key( $group ) {
		global $plugin_page;

		if ( is_string( $plugin_page ) && isset( self::$groups[ $group ]['items'][ 'page:' . $plugin_page ] ) ) {
			return 'page:' . $plugin_page;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				foreach ( self::$groups[ $group ]['items'] as $key => $item ) {
					if ( 'link' === $item['type'] && in_array( $screen->id, (array) $item['screen'], true ) ) {
						return $key;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Menü-Icon „T“ als SVG (wird von WordPress an das Farbschema angepasst).
	 *
	 * @return string
	 */
	public static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M4 2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm1.5 3.5v2.25h3.375V15h2.25V7.75H14.5V5.5z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
