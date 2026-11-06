<?php
/**
 * Test-Mu-Plugin: beantwortet Anfragen an appsumo.com mit Testdaten (kein Netz nötig).
 * Szenario über die Option adm_test_scenario: 1 = Grundstand, 2 = LeadOcean aus der Sitemap entfernt, 3 = Sitemap kaputt.
 */
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'appsumo.com' ) ) {
		return $pre;
	}
	$fx       = __DIR__ . '/adm-fixtures/';
	$scenario = (int) get_option( 'adm_test_scenario', 1 );
	$GLOBALS['adm_test_requests'][] = array( 'url' => $url, 'ua' => isset( $args['user-agent'] ) ? $args['user-agent'] : '' );
	$ok = function ( $body ) {
		return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	if ( preg_match( '#/sitemap\.xml$#', $url ) ) {
		if ( 3 === $scenario ) {
			return $ok( '<urlset><url><loc>https://appsumo.com/</loc></url></urlset>' );
		}
		// Alte Deals stehen vorn (Priorität 0.25), aktive hinten (0.5): der Abgleich muss die aktiven zuerst prüfen.
		$urls = array( 'gone-deal' => '0.25' );
		for ( $i = 1; $i <= 58; $i++ ) {
			$urls[ 'old-deal-' . $i ] = '0.25';
		}
		$urls['neuronwriter'] = '0.5';
		if ( 2 !== $scenario ) {
			$urls['leadocean'] = '0.5';
		}
		$xml = '<?xml version="1.0"?><urlset><url><loc>https://appsumo.com/</loc></url><url><loc>https://appsumo.com/marketing/</loc></url>';
		foreach ( $urls as $u => $prio ) {
			$xml .= '<url><loc>https://appsumo.com/products/' . $u . '/</loc><lastmod>2026-11-01</lastmod><priority>' . $prio . '</priority></url>';
		}
		return $ok( $xml . '</urlset>' );
	}
	if ( preg_match( '#/products/([a-z0-9\-]+)/#', $url, $m ) ) {
		$slug = $m[1];
		if ( file_exists( $fx . $slug . '.html' ) ) {
			return $ok( file_get_contents( $fx . $slug . '.html' ) );
		}
		if ( 'gone-deal' === $slug ) {
			return array( 'headers' => array(), 'body' => 'not found', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		$name = ucwords( str_replace( '-', ' ', $slug ) );
		return $ok( '<script type="application/ld+json">{"@type":"Product","name":"' . $name . '","offers":{"price":"59","priceCurrency":"USD","availability":"http://schema.org/SoldOut"},"aggregateRating":{"ratingValue":"4.1","reviewCount":"12"}}</script>' );
	}
	return $pre;
}, 10, 3 );
