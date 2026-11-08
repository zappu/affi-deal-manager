<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eigene Aktion: bis zu vier Tools, die bis zu einem Enddatum hervorgehoben werden
 * (z. B. „TidyCal statt Calendly“). Ohne Bilder von AppSumo, Preis und Bewertungen live aus der Deal-Tabelle.
 *
 * Plätze: Startseite über [adm_promos] (oder [adm_spotlight]), Übersicht der Rubrik des Tools,
 * Deal-Kasten anderer Profile derselben Rubrik. Im eigenen Profil zeigt der Deal-Kasten nur den Code.
 * Werbelinks mit subId1 „tipp-<deal>“. Gepflegt im Reiter „Einnahmen“.
 */
class ADM_Spotlight {

	const OPTION = 'adm_spotlight';
	const MAX    = 4;

	public static function init() {
		add_shortcode( 'adm_spotlight', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_adm_spotlight', array( __CLASS__, 'handle_save' ) );
		add_action( 'adm_tick_done', array( __CLASS__, 'expire' ), 30 );
	}

	public static function data() {
		$d = get_option( self::OPTION );
		$d = is_array( $d ) ? $d : array();
		return $d + array( 'until' => 0, 'label' => 'Tipp', 'items' => array(), 'expired' => 0 );
	}

	/** Läuft die Aktion noch? */
	public static function running( $d = null ) {
		$d = null === $d ? self::data() : $d;
		return $d['items'] && (int) $d['until'] >= time();
	}

	/**
	 * Laufende Einträge mit Deal (nur kaufbare).
	 *
	 * @return array Liste von array( 'item' => …, 'deal' => … ).
	 */
	public static function entries() {
		$d = self::data();
		if ( ! self::running( $d ) ) {
			return array();
		}
		$out = array();
		foreach ( $d['items'] as $it ) {
			$deal = ADM_DB::get_by_slug( $it['deal'] );
			if ( $deal && 'kaufbar' === $deal['status'] ) {
				$out[] = array( 'item' => $it, 'deal' => $deal );
			}
		}
		return $out;
	}

	/** Einträge, deren Tool in der Rubrik liegt (optional ohne einen Deal). */
	public static function for_rubric( $slug, $exclude_id = 0 ) {
		return array_values( array_filter( self::entries(), function ( $e ) use ( $slug, $exclude_id ) {
			return '' !== (string) $slug && ADM_Rubrics::for_deal( $e['deal'] ) === $slug && (int) $e['deal']['id'] !== (int) $exclude_id;
		} ) );
	}

	/** Code für den Deal-Kasten des Tools selbst. */
	public static function code_html( array $deal ) {
		foreach ( self::entries() as $e ) {
			if ( (int) $e['deal']['id'] === (int) $deal['id'] && '' !== $e['item']['code'] ) {
				return '<p class="adm-promo"><span class="adm-promo__label">Code</span> <code class="adm-promo__code">' . esc_html( $e['item']['code'] ) . '</code> beim Kauf bei AppSumo eingeben</p>';
			}
		}
		return '';
	}

	/** Eine Karte. */
	private static function card( array $e, $compact = false ) {
		$it   = $e['item'];
		$deal = $e['deal'];
		$post = ! empty( $deal['post_id'] ) && 'publish' === get_post_status( (int) $deal['post_id'] ) ? (int) $deal['post_id'] : 0;
		$name = $post ? '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $deal['title'] ) . '</a>' : esc_html( $deal['title'] );
		$st   = ADM_Shortcodes::dist_stats( $deal );
		$fact = array_filter( array(
			ADM_Shortcodes::price( $deal ),
			$st ? sprintf( "%d\xc2\xa0%% zufrieden (%s Bewertungen)", $st['pgood'], number_format_i18n( $st['all'] ) ) : '',
		) );
		$link = self::link( $it, $deal );
		$img  = ! empty( $it['att'] ) ? wp_get_attachment_image( (int) $it['att'], 'full', false, array( 'class' => 'adm-spot__img', 'loading' => 'lazy' ) ) : '';
		$more = '' !== $it['guide'] ? array( $it['guide'], 'Vergleich lesen' ) : ( $post ? array( get_permalink( $post ), 'Zum Tool-Profil' ) : null );

		$out  = '<article class="adm-spot__item' . ( '' !== $img ? ' adm-spot__item--img' : '' ) . '">';
		$out .= '' !== $img && '' !== $link ? '<a class="adm-spot__banner" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">' . $img . '<span class="adm-banner__ad">Anzeige<span class="adm-mark" aria-hidden="true">*</span></span></a>' : '';
		$out .= '<p class="adm-spot__title">' . $name . ( '' !== $it['instead'] ? ' <span>statt ' . esc_html( $it['instead'] ) . '</span>' : '' ) . '</p>';
		$out .= '' !== $it['text'] ? '<p class="adm-spot__text">' . esc_html( $it['text'] ) . '</p>' : '';
		$out .= $fact ? '<p class="adm-spot__facts">' . esc_html( implode( ' · ', $fact ) ) . '</p>' : '';
		$out .= '' !== $it['code'] ? '<p class="adm-spot__code">Code <code class="adm-promo__code">' . esc_html( $it['code'] ) . '</code></p>' : '';
		$out .= '<div class="adm-spot__cta">';
		$out .= '' !== $link ? '<a class="adm-btn adm-btn--small" href="' . esc_url( $link ) . '" rel="sponsored nofollow noopener" target="_blank">Zum Angebot<span class="adm-mark" aria-label="Werbelink">*</span></a>' : '';
		$out .= $more && ! $compact ? '<a class="adm-btn adm-btn--quiet adm-btn--small" href="' . esc_url( $more[0] ) . '">' . esc_html( $more[1] ) . '</a>' : '';
		$out .= $more && $compact ? '<a class="adm-spot__more" href="' . esc_url( $more[0] ) . '">' . esc_html( $more[1] ) . '</a>' : '';
		return $out . '</div></article>';
	}

	/**
	 * Kasten für einen Platz: home (alle), rubric (Übersicht einer Rubrik), side (Deal-Kasten).
	 *
	 * @param string $slot    Platz.
	 * @param string $rubric  Rubrik-Slug für rubric und side.
	 * @param int    $exclude Deal-ID, die nicht erscheinen soll (das Profil selbst).
	 */
	public static function html( $slot, $rubric = '', $exclude = 0 ) {
		$list = 'home' === $slot ? self::entries() : self::for_rubric( $rubric, $exclude );
		if ( ! $list ) {
			return '';
		}
		$d     = self::data();
		$label = '' !== $d['label'] ? $d['label'] : 'Tipp';
		$list  = 'side' === $slot ? array_slice( $list, 0, 1 ) : $list;
		$cards = '';
		foreach ( $list as $e ) {
			$cards .= self::card( $e, 'side' === $slot );
		}
		wp_enqueue_style( 'adm-frontend' );
		return '<section class="adm adm-spot adm-spot--' . esc_attr( $slot ) . '" aria-label="' . esc_attr( $label ) . '"><p class="adm-spot__label">' . esc_html( $label ) . '</p><div class="adm-spot__grid">' . $cards . '</div></section>';
	}

	/** [adm_spotlight] für eigene Seiten (alle laufenden Einträge). */
	public static function shortcode() {
		return self::html( 'home' );
	}

	/** Nach dem Ende einmal den Seiten-Cache leeren, damit der Kasten überall verschwindet. */
	public static function expire() {
		$d = self::data();
		if ( $d['items'] && (int) $d['until'] && (int) $d['until'] < time() && ! $d['expired'] ) {
			$d['expired'] = 1;
			update_option( self::OPTION, $d, false );
			ADM_Cache::purge_all();
		}
	}

	/**
	 * Eingaben prüfen und speichern.
	 *
	 * @param array $in Rohwerte (until als JJJJ-MM-TT, label, items[] mit deal, instead, text, code, guide).
	 * @return array Gespeicherte Daten.
	 */
	public static function save( array $in ) {
		$old   = array();
		foreach ( self::data()['items'] as $it ) {
			$old[ $it['deal'] ] = $it;
		}
		$until = isset( $in['until'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['until'] ) ? strtotime( $in['until'] . ' 23:59:59 ' . wp_timezone_string() ) : 0;
		$items = array();
		foreach ( array_slice( isset( $in['items'] ) ? (array) $in['items'] : array(), 0, self::MAX ) as $row ) {
			$row  = (array) $row;
			$slug = isset( $row['deal'] ) ? sanitize_title( (string) $row['deal'] ) : '';
			if ( '' === $slug || ! ADM_DB::get_by_slug( $slug ) ) {
				continue;
			}
			$guide   = isset( $row['guide'] ) ? esc_url_raw( trim( (string) $row['guide'] ), array( 'https', 'http' ) ) : '';
			$banner  = substr( preg_replace( '/\D/', '', isset( $row['banner'] ) ? (string) $row['banner'] : '' ), 0, 12 );
			$items[] = self::with_banner( $slug, $banner, $old ) + array(
				'deal'    => $slug,
				'instead' => self::text( $row, 'instead', 40 ),
				'text'    => self::text( $row, 'text', 160 ),
				'code'    => substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $row['code'] ) ? (string) $row['code'] : '' ), 0, 40 ),
				'guide'   => $guide,
			);
		}
		$d = array(
			'until'   => $until ? (int) $until : 0,
			'label'   => self::text( $in, 'label', 40 ),
			'items'   => $items,
			'expired' => 0,
		);
		update_option( self::OPTION, $d, false );
		ADM_Cache::purge_all();
		return $d;
	}

	/**
	 * Banner von Impact zum Eintrag: einmal über Ads/<ID> prüfen (Programm, Typ) und das Bild in die Mediathek holen.
	 * Unveränderte IDs behalten ihr Bild.
	 */
	private static function with_banner( $slug, $banner, array $old ) {
		$none = array( 'banner' => '', 'att' => 0, 'bw' => 0, 'bh' => 0, 'berr' => '' );
		if ( '' === $banner ) {
			return $none;
		}
		if ( isset( $old[ $slug ] ) && $banner === $old[ $slug ]['banner'] && ! empty( $old[ $slug ]['att'] ) && wp_attachment_is_image( (int) $old[ $slug ]['att'] ) ) {
			return array_intersect_key( $old[ $slug ], $none ) + $none;
		}
		$r  = ADM_Impact::get( 'Ads/' . $banner );
		$ad = is_array( $r['data'] ) ? $r['data'] : array();
		if ( '' !== $r['error'] || empty( $ad['Id'] ) ) {
			return array( 'banner' => $banner, 'berr' => 'Banner ' . $banner . ' nicht gefunden' . ( '' !== $r['error'] ? ' (' . $r['error'] . ')' : '' ) ) + $none;
		}
		if ( (string) $ad['CampaignId'] !== (string) ADM_Settings::get( 'program_id' ) || 'BANNER' !== strtoupper( (string) $ad['Type'] ) ) {
			return array( 'banner' => $banner, 'berr' => 'Banner ' . $banner . ' ist kein Banner des AppSumo-Programms' ) + $none;
		}
		$creative = isset( $ad['CreativeUrl'] ) ? (string) $ad['CreativeUrl'] : '';
		$att      = ADM_Banners::import( array(
			'id'       => $banner,
			'name'     => sanitize_text_field( (string) $ad['Name'] ),
			'creative' => '' !== $creative ? esc_url_raw( 0 === strpos( $creative, '//' ) ? 'https:' . $creative : $creative ) : '',
		) );
		if ( is_wp_error( $att ) ) {
			return array( 'banner' => $banner, 'berr' => 'Banner ' . $banner . ': ' . $att->get_error_message() ) + $none;
		}
		$deal = ADM_DB::get_by_slug( $slug );
		update_post_meta( $att, '_wp_attachment_image_alt', $deal ? $deal['title'] . ' bei AppSumo' : 'Angebot bei AppSumo' );
		return array( 'banner' => $banner, 'att' => (int) $att, 'bw' => (int) $ad['Width'], 'bh' => (int) $ad['Height'], 'berr' => '' );
	}

	/** Werbelink: über den Banner, falls gesetzt, sonst der normale Deep-Link. Ziel ist immer die Produktseite. */
	public static function link( array $it, array $deal ) {
		if ( empty( $it['att'] ) || '' === (string) $it['banner'] ) {
			return ADM_Links::url( $deal['url'], 'tipp-' . $deal['slug'] );
		}
		return sprintf( 'https://%s/c/%s/%s/%s', ADM_Settings::get( 'tracking_host' ), rawurlencode( (string) ADM_Settings::get( 'partner_id' ) ), rawurlencode( (string) $it['banner'] ), rawurlencode( (string) ADM_Settings::get( 'program_id' ) ) )
			. '?u=' . rawurlencode( (string) $deal['url'] ) . '&subId1=' . rawurlencode( 'tipp-' . $deal['slug'] );
	}

	private static function text( array $in, $key, $max ) {
		$v = isset( $in[ $key ] ) ? trim( sanitize_text_field( (string) $in[ $key ] ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $max ) : substr( $v, 0, $max );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_spotlight' );
		$in = isset( $_POST['spot'] ) ? wp_unslash( (array) $_POST['spot'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $_POST['end_now'] ) ) {
			$in['until'] = '';
		}
		$d = self::save( $in );
		set_transient( 'adm_notice', self::running( $d ) ? 'Eigene Aktion gespeichert: ' . count( $d['items'] ) . ' Tools bis ' . wp_date( 'd.m.Y', $d['until'] ) . '.' : 'Eigene Aktion gespeichert, sie ist nicht aktiv (kein Tool oder Enddatum vorbei).', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . ADM_Stats::SLUG . '#eigene-aktion' ) );
		exit;
	}

	/** Formular im Reiter „Einnahmen“. */
	public static function admin_form() {
		$d    = self::data();
		$rows = $d['items'];
		while ( count( $rows ) < self::MAX ) {
			$rows[] = array( 'deal' => '', 'instead' => '', 'text' => '', 'code' => '', 'guide' => '', 'banner' => '', 'att' => 0, 'berr' => '' );
		}
		$state = self::running( $d ) ? 'Läuft bis ' . wp_date( 'd.m.Y', (int) $d['until'] ) . '.' : 'Nicht aktiv.';
		echo '<h2 id="eigene-aktion" style="margin-top:28px">Eigene Aktion</h2>';
		echo '<p>' . esc_html( $state ) . ' Bis zu vier Tools, die bis zum Enddatum oben auf der Startseite ([adm_promos]), in der Übersicht ihrer Rubrik und im Deal-Kasten anderer Profile derselben Rubrik erscheinen. Preis und Zufriedenheit kommen live von AppSumo. Kennung in den Einnahmen: <code>tipp-&lt;deal&gt;</code>. Banner-ID: ein Banner des AppSumo-Programms aus Impact (Assets → Banner, Suche nach dem Tool); das Bild wird einmal in die Mediathek kopiert, der Klick läuft über den Tracking-Link dieses Banners.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adm_spotlight' );
		echo '<input type="hidden" name="action" value="adm_spotlight">';
		echo '<p><label>Beschriftung <input type="text" name="spot[label]" value="' . esc_attr( $d['label'] ) . '" placeholder="Tipp" style="width:160px"></label> ';
		echo '<label style="margin-left:16px">Ende <input type="date" name="spot[until]" value="' . esc_attr( $d['until'] ? wp_date( 'Y-m-d', (int) $d['until'] ) : '' ) . '"></label></p>';
		echo '<table class="widefat striped"><thead><tr><th>Deal (Slug)</th><th>statt</th><th>Text (ein Satz)</th><th>Code</th><th>Ratgeber-Link (optional)</th><th>Banner-ID (optional)</th></tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			$n = 'spot[items][' . (int) $i . ']';
			echo '<tr><td><input type="text" name="' . esc_attr( $n ) . '[deal]" value="' . esc_attr( $r['deal'] ) . '" placeholder="tidycal" style="width:120px"></td>';
			echo '<td><input type="text" name="' . esc_attr( $n ) . '[instead]" value="' . esc_attr( $r['instead'] ) . '" placeholder="Calendly" style="width:110px"></td>';
			echo '<td style="min-width:320px"><textarea name="' . esc_attr( $n ) . '[text]" maxlength="160" rows="2" style="width:100%">' . esc_textarea( $r['text'] ) . '</textarea></td>';
			echo '<td><input type="text" name="' . esc_attr( $n ) . '[code]" value="' . esc_attr( $r['code'] ) . '" style="width:110px"></td>';
			echo '<td><input type="url" name="' . esc_attr( $n ) . '[guide]" value="' . esc_attr( $r['guide'] ) . '" style="width:100%"></td>';
			$thumb = ! empty( $r['att'] ) ? wp_get_attachment_image( (int) $r['att'], array( 60, 50 ), false, array( 'style' => 'vertical-align:middle;margin-left:6px;height:auto' ) ) : '';
			echo '<td><input type="text" name="' . esc_attr( $n ) . '[banner]" value="' . esc_attr( isset( $r['banner'] ) ? $r['banner'] : '' ) . '" style="width:90px">' . $thumb . ( ! empty( $r['berr'] ) ? '<br><span style="color:#b32d2e">' . esc_html( $r['berr'] ) . '</span>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<p><button class="button button-primary">Eigene Aktion speichern</button> <button class="button" name="end_now" value="1">Jetzt beenden</button></p></form>';
	}
}
