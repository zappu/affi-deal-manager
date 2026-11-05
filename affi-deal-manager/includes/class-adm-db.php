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
 */
class ADM_DB {

	const STATUSES = array( 'neu', 'kaufbar', 'ausgelaufen', 'ignoriert' );

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
			brand varchar(255) NOT NULL DEFAULT '',
			description text NULL,
			price decimal(10,2) NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			availability varchar(40) NOT NULL DEFAULT '',
			available_until datetime NULL,
			rating decimal(3,2) NULL,
			review_count int(10) unsigned NOT NULL DEFAULT 0,
			category varchar(255) NOT NULL DEFAULT '',
			category_url varchar(255) NOT NULL DEFAULT '',
			return_days smallint(5) unsigned NULL,
			videos longtext NULL,
			faq longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'neu',
			was_available tinyint(1) NOT NULL DEFAULT 0,
			in_sitemap tinyint(1) NOT NULL DEFAULT 1,
			sitemap_lastmod date NULL,
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
			KEY next_check (next_check)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'adm_db_version', ADM_DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'adm_db_version' ) !== ADM_DB_VERSION ) {
			self::install();
		}
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

	private static function decode( array $row ) {
		foreach ( array( 'videos', 'faq' ) as $k ) {
			$v         = isset( $row[ $k ] ) ? json_decode( (string) $row[ $k ], true ) : null;
			$row[ $k ] = is_array( $v ) ? $v : array();
		}
		return $row;
	}

	/** @return array url => array(id, lastmod, status, in_sitemap) */
	public static function url_map() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, url, sitemap_lastmod, status, in_sitemap FROM ' . self::table(), ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$map[ $r['url'] ] = $r;
		}
		return $map;
	}

	/**
	 * Legt neue Sitemap-Einträge an.
	 *
	 * @param array $items Liste aus array( url, lastmod ).
	 */
	public static function insert_new( array $items ) {
		global $wpdb;
		$now = self::now();
		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $it ) {
				$values[] = '(%s, %s, %s, %s, %s, %s)';
				array_push( $args, $it['url'], self::slug_from_url( $it['url'] ), 'neu', $it['lastmod'] ? $it['lastmod'] : null, $now, $now );
			}
			$sql = 'INSERT INTO ' . self::table() . ' (url, slug, status, sitemap_lastmod, first_seen, next_check) VALUES ' . implode( ', ', $values );
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

	/** Fällige Einträge: kaufbare zuerst, dann nach Fälligkeit. */
	public static function due( $limit ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE next_check IS NOT NULL AND next_check <= %s ORDER BY (status = 'kaufbar') DESC, (status = 'neu') DESC, next_check ASC LIMIT %d",
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
