<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deal-Tabelle {prefix}adm_deals.
 *
 * Status:
 * - neu         in der Sitemap gefunden, noch nicht abgerufen
 * - kaufbar     Deal kann bei AppSumo gekauft werden
 * - ausgelaufen war kaufbar oder hat ein Tool-Profil, ist es aber nicht mehr
 * - ignoriert   nie als kaufbar gesehen (alte Deals aus der Sitemap)
 *
 * Deal-Typ (deal_type): software, kurs, ebook, vorlage oder leer (noch unbekannt).
 * deal_type_source „manuell“ schützt eine Korrektur im Backend vor dem Abgleich.
 */
class ADM_DB {

	const STATUSES = array( 'neu', 'kaufbar', 'ausgelaufen', 'ignoriert' );
	const TYPES    = array(
		'software' => 'Software',
		'kurs'     => 'Kurs',
		'ebook'    => 'E-Book',
		'vorlage'  => 'Vorlage',
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'adm_deals';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(255) NOT NULL,
			slug varchar(191) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			display_name varchar(255) NOT NULL DEFAULT '',
			brand varchar(255) NOT NULL DEFAULT '',
			website varchar(255) NOT NULL DEFAULT '',
			description text NULL,
			price decimal(10,2) NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			availability varchar(40) NOT NULL DEFAULT '',
			available_until datetime NULL,
			rating decimal(3,2) NULL,
			review_count int(10) unsigned NOT NULL DEFAULT 0,
			rating_dist varchar(60) NOT NULL DEFAULT '',
			purchases int(10) unsigned NOT NULL DEFAULT 0,
			listing_type varchar(20) NOT NULL DEFAULT '',
			ranks varchar(400) NOT NULL DEFAULT '',
			category varchar(255) NOT NULL DEFAULT '',
			category_url varchar(255) NOT NULL DEFAULT '',
			return_days smallint(5) unsigned NULL,
			videos longtext NULL,
			faq longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'neu',
			was_available tinyint(1) NOT NULL DEFAULT 0,
			in_sitemap tinyint(1) NOT NULL DEFAULT 1,
			sitemap_lastmod date NULL,
			sitemap_priority decimal(3,2) NULL,
			deal_type varchar(20) NOT NULL DEFAULT '',
			deal_type_source varchar(10) NOT NULL DEFAULT '',
			appsumo_type varchar(60) NOT NULL DEFAULT '',
			appsumo_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_checked datetime NULL,
			last_changed datetime NULL,
			next_check datetime NULL,
			fail_count smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY url (url),
			KEY slug (slug),
			KEY status (status),
			KEY deal_type (deal_type),
			KEY next_check (next_check)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'adm_db_version', ADM_DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		$old = get_option( 'adm_db_version' );
		if ( $old === ADM_DB_VERSION ) {
			return;
		}
		self::install();
		if ( $old && version_compare( (string) $old, '2', '<' ) ) {
			// Neue Spalte sitemap_priority: Sitemap beim nächsten Schritt neu lesen.
			delete_option( 'adm_sitemap_read' );
		}
		// Version 5: Spalte display_name (Anzeigename) kommt über dbDelta, keine Datenänderung nötig.
		if ( $old && version_compare( (string) $old, '7', '<' ) ) {
			// Neue Spalten website (6), rating_dist, purchases, listing_type, ranks (7): Deals mit Profil gleich neu lesen.
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET next_check = %s WHERE post_id > 0 AND status IN ('kaufbar','ausgelaufen')", self::now() ) ); // phpcs:ignore
		}
		if ( $old && version_compare( (string) $old, '3', '<' ) ) {
			global $wpdb;
			$t = self::table();
			// Platzhalter-Enddaten (etwa ein Jahr ab Abruf) entfernen.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET available_until = NULL WHERE available_until > %s", gmdate( 'Y-m-d H:i:s', time() + ADM_Parser::PLACEHOLDER_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore
			// Neue Spalte deal_type: kaufbare Deals gleich neu prüfen, damit sie ihren Typ bekommen.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET next_check = %s WHERE status = 'kaufbar'", self::now() ) ); // phpcs:ignore
		}
	}

	/** Deal zu einem Tool-Profil. */
	public static function get_by_post( $post_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE post_id = %d LIMIT 1', (int) $post_id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function type_label( $type ) {
		return isset( self::TYPES[ $type ] ) ? self::TYPES[ $type ] : 'Unbekannt';
	}

	/** Manuelle Korrektur des Deal-Typs; leerer Typ gibt die automatische Erkennung wieder frei. */
	public static function set_type( $id, $type ) {
		$type = sanitize_key( $type );
		if ( '' !== $type && ! isset( self::TYPES[ $type ] ) ) {
			return false;
		}
		if ( '' === $type ) {
			return self::update( $id, array( 'deal_type_source' => '', 'next_check' => self::now() ) );
		}
		return self::update( $id, array( 'deal_type' => $type, 'deal_type_source' => 'manuell' ) );
	}

	/**
	 * Deals für Vorlagen und Bereiche der Website.
	 *
	 * @param array $args status (Standard kaufbar), type (software, kurs, ebook, vorlage),
	 *                    free (true = nur kostenlose), limit (1 bis 100), orderby (reviews, rating, price, newest),
	 *                    plus (exclude = ohne Plus-Deals, Standard; only = nur Plus-Deals; all = alle).
	 * @return array Liste von Deals.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'status' => 'kaufbar', 'type' => '', 'free' => false, 'limit' => 12, 'orderby' => 'reviews', 'plus' => 'exclude' ) );
		$where = array( 'status = %s' );
		$vals  = array( in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : 'kaufbar' );
		if ( isset( self::TYPES[ $args['type'] ] ) ) {
			$where[] = 'deal_type = %s';
			$vals[]  = $args['type'];
		}
		if ( $args['free'] ) {
			$where[] = 'price IS NOT NULL AND price = 0';
		}
		if ( 'only' === $args['plus'] ) {
			$where[] = self::PLUS_SQL;
		} elseif ( 'all' !== $args['plus'] ) {
			$where[] = 'NOT ' . self::PLUS_SQL;
		}
		$orders = array(
			'reviews' => 'review_count DESC, rating DESC',
			'rating'  => 'rating DESC, review_count DESC',
			'price'   => 'price ASC, review_count DESC',
			'newest'  => 'first_seen DESC',
		);
		$order  = isset( $orders[ $args['orderby'] ] ) ? $orders[ $args['orderby'] ] : $orders['reviews'];
		$vals[] = max( 1, min( 100, (int) $args['limit'] ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . " ORDER BY {$order}, id ASC LIMIT %d", $vals ), ARRAY_A ); // phpcs:ignore
		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/** Anzahl kaufbarer Deals je Typ. */
	public static function type_counts( $status = 'kaufbar' ) {
		global $wpdb;
		$out  = array_fill_keys( array_keys( self::TYPES ), 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT deal_type, COUNT(*) AS n FROM ' . self::table() . ' WHERE status = %s GROUP BY deal_type', $status ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ $r['deal_type'] ] ) ) {
				$out[ $r['deal_type'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	public static function now() {
		return current_time( 'mysql', true );
	}

	/** Slug aus einer AppSumo-Produktadresse, z. B. https://appsumo.com/products/neuronwriter/ → neuronwriter. */
	public static function slug_from_url( $url ) {
		return preg_match( '#/products/([a-z0-9][a-z0-9\-_.]*)/?$#i', (string) $url, $m ) ? strtolower( $m[1] ) : '';
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function get_by_slug( $slug ) {
		global $wpdb;
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s ORDER BY id ASC LIMIT 1', $slug ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function decode( array $row ) {
		foreach ( array( 'videos', 'faq' ) as $k ) {
			$v         = isset( $row[ $k ] ) ? json_decode( (string) $row[ $k ], true ) : null;
			$row[ $k ] = is_array( $v ) ? $v : array();
		}
		$row['dist']   = self::dist( isset( $row['rating_dist'] ) ? $row['rating_dist'] : '' );
		$rk            = isset( $row['ranks'] ) ? json_decode( (string) $row['ranks'], true ) : null;
		$row['ranks']  = is_array( $rk ) ? $rk : array();
		$row['reason'] = self::reason( $row );
		$row['title']  = self::display_name( $row );
		$row['plus']   = self::is_plus( $row );
		return $row;
	}

	/**
	 * Verteilung der Bewertungen bei AppSumo (Tacos 1 bis 5) aus der Spalte rating_dist („1er,2er,3er,4er,5er“).
	 *
	 * @return array array( 1 => n, …, 5 => n ) oder leer.
	 */
	public static function dist( $raw ) {
		$p = array_map( 'intval', explode( ',', (string) $raw ) );
		if ( 5 !== count( $p ) || array_sum( $p ) <= 0 ) {
			return array();
		}
		return array_combine( array( 1, 2, 3, 4, 5 ), $p );
	}

	/** SQL-Bedingung für Deals nur für Mitglieder von AppSumo Plus (und die Mitgliedschaft selbst). */
	const PLUS_SQL = "(INSTR(LOWER(name), 'plus exclusive') > 0 OR SUBSTR(slug, 1, 5) = 'plus-' OR SUBSTR(LOWER(name), 1, 12) = 'appsumo plus')"; // ohne %, damit es in $wpdb->prepare() passt

	/** Zusatzpakete eines anderen Deals (AppSumo-Slug enthält -add-on, etwa skillplate-add-on-2). */
	const ADDON_SQL = "(INSTR(slug, '-add-on') > 0 OR INSTR(slug, '-addon') > 0)"; // ohne %, damit es in $wpdb->prepare() passt

	/** Zusatzpaket (Add-on) eines anderen Deals: bekommt kein eigenes Tool-Profil. */
	public static function is_addon( array $row ) {
		$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		return false !== strpos( $slug, '-add-on' ) || false !== strpos( $slug, '-addon' );
	}

	/**
	 * Deal nur für Mitglieder von AppSumo Plus („Plus exclusive“) oder die Plus-Mitgliedschaft selbst.
	 * Plus ist ein Jahresabo: Solche Deals bekommen keine Tool-Profile, sie gehören auf eine eigene Plus-Seite.
	 */
	public static function is_plus( array $row ) {
		$name = isset( $row['name'] ) ? (string) $row['name'] : '';
		$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		return (bool) preg_match( '/\bplus\s+exclusive\b/i', $name ) || 0 === strpos( $slug, 'plus-' ) || (bool) preg_match( '/^appsumo\s+plus\b/i', $name );
	}

	/**
	 * Name für die Website: eigener Anzeigename oder der bereinigte Name von AppSumo.
	 *
	 * @param array $row Zeile der Deal-Tabelle.
	 * @return string
	 */
	public static function display_name( array $row ) {
		$own = isset( $row['display_name'] ) ? trim( (string) $row['display_name'] ) : '';
		if ( '' !== $own ) {
			return $own;
		}
		$type = isset( $row['deal_type'] ) ? (string) $row['deal_type'] : '';
		return self::clean_name( isset( $row['name'] ) ? (string) $row['name'] : '', in_array( $type, array( 'software', '' ), true ) );
	}

	/**
	 * Entfernt Zusätze aus AppSumo-Namen: „Plus exclusive“, „Lifetime Deal“, „(fka …)“, „Plan“ am Ende
	 * und bei Software den Werbezusatz nach Bindestrich oder Doppelpunkt („Writeseed - AI Content Writer“ → „Writeseed“).
	 * Bei Kursen und E-Books bleibt der Titel stehen, nur die Zusätze fallen weg.
	 *
	 * @param string $name     Name von AppSumo.
	 * @param bool   $software Werbezusatz abtrennen.
	 * @return string
	 */
	public static function clean_name( $name, $software = true ) {
		$orig = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		$n    = $orig;
		$n    = preg_replace( '/\s*\((?:fka|formerly|früher|ehemals)\b[^)]*\)/iu', '', $n );
		$n    = preg_replace( '/\s*[-–—|:]\s*plus\s+exclusive\s*$/iu', '', $n );
		$n    = preg_replace( '/(?:\s+|\s*[-–—|:(]\s*)(?:lifetime\s+deal|ltd)\)?\s*$/iu', '', $n );
		if ( $software ) {
			$parts = preg_split( '/\s+[-–—|]\s+|:\s+/u', $n, 2 );
			if ( 2 === count( $parts ) && mb_strlen( trim( $parts[0] ) ) >= 2 ) {
				$n = $parts[0];
			}
			$n = preg_replace( '/\s+plans?$/iu', '', $n );
		}
		$n = trim( preg_replace( '/^[\s\-–—|:]+|[\s\-–—|:]+$/u', '', $n ) );
		return mb_strlen( $n ) >= 2 ? $n : $orig;
	}

	/**
	 * Grund für den Status in Klartext, abgeleitet aus Verfügbarkeit, Sitemap, Angebotsende und Verlauf.
	 *
	 * @param array $row Zeile der Deal-Tabelle.
	 * @return string
	 */
	public static function reason( array $row ) {
		$status = isset( $row['status'] ) ? $row['status'] : '';
		$avail  = isset( $row['availability'] ) ? (string) $row['availability'] : '';
		$until  = ! empty( $row['available_until'] ) ? strtotime( $row['available_until'] . ' UTC' ) : 0;
		$date   = function ( $ts ) {
			return wp_date( 'd.m.Y', (int) $ts );
		};

		if ( 'neu' === $status ) {
			return 'Noch nicht abgerufen';
		}
		if ( 'kaufbar' === $status ) {
			$labels = array(
				'PreOrder'            => 'Vorbestellung bei AppSumo',
				'PreSale'             => 'Vorverkauf bei AppSumo',
				'LimitedAvailability' => 'Bei AppSumo erhältlich, nur noch begrenzt',
				'BackOrder'           => 'Bei AppSumo bestellbar, Lieferung später',
			);
			$text = isset( $labels[ $avail ] ) ? $labels[ $avail ] : 'Bei AppSumo erhältlich';
			if ( $until && $until > time() ) {
				$text .= ', Angebot läuft bis ' . $date( $until );
			}
			return $text;
		}

		// ausgelaufen oder ignoriert
		if ( 'Gone' === $avail ) {
			$why = 'Seite bei AppSumo entfernt';
		} elseif ( isset( $row['in_sitemap'] ) && ! (int) $row['in_sitemap'] ) {
			$why = 'Nicht mehr in der AppSumo-Sitemap';
		} elseif ( $until && $until <= time() ) {
			$why = 'Angebot endete am ' . $date( $until );
		} else {
			$labels = array(
				'SoldOut'      => 'Ausverkauft',
				'OutOfStock'   => 'Nicht verfügbar',
				'Discontinued' => 'Vom Anbieter eingestellt',
			);
			$why = isset( $labels[ $avail ] ) ? $labels[ $avail ] : ( '' !== $avail ? 'Nicht kaufbar (' . $avail . ')' : 'Nicht kaufbar' );
		}
		if ( 'ignoriert' === $status ) {
			return $why . ', nie kaufbar gesehen';
		}
		if ( ! empty( $row['last_changed'] ) ) {
			$why .= ', ausgelaufen seit ' . $date( strtotime( $row['last_changed'] . ' UTC' ) );
		}
		return $why;
	}

	/** @return array url => array(id, lastmod, priority, status, in_sitemap) */
	public static function url_map() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, url, sitemap_lastmod, sitemap_priority, status, in_sitemap FROM ' . self::table(), ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$map[ $r['url'] ] = $r;
		}
		return $map;
	}

	/**
	 * Legt neue Sitemap-Einträge an.
	 *
	 * @param array $items Liste aus array( url, lastmod, priority ).
	 */
	public static function insert_new( array $items ) {
		global $wpdb;
		$now = self::now();
		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $it ) {
				$values[] = '(%s, %s, %s, %s, %s, %s, %s)';
				array_push( $args, $it['url'], self::slug_from_url( $it['url'] ), 'neu', $it['lastmod'] ? $it['lastmod'] : null, isset( $it['priority'] ) ? $it['priority'] : null, $now, $now );
			}
			$sql = 'INSERT INTO ' . self::table() . ' (url, slug, status, sitemap_lastmod, sitemap_priority, first_seen, next_check) VALUES ' . implode( ', ', $values );
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		foreach ( array( 'videos', 'faq' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				$data[ $k ] = wp_json_encode( $data[ $k ] );
			}
		}
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Fällige Einträge: kaufbare zuerst, dann hohe Sitemap-Priorität (aktive Deals),
	 * dann ungeprüfte, dann frisch geänderte, dann nach Fälligkeit.
	 */
	public static function due( $limit ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE next_check IS NOT NULL AND next_check <= %s ORDER BY (status = 'kaufbar') DESC, sitemap_priority DESC, (status = 'neu') DESC, sitemap_lastmod DESC, next_check ASC LIMIT %d",
				self::now(),
				(int) $limit
			),
			ARRAY_A
		);
	}

	public static function count_due() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE next_check IS NOT NULL AND next_check <= %s', self::now() ) );
	}

	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}
}
