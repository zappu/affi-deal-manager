<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend: Deal-Liste (Hauptseite) und Einstellungen (Reiter) im gemeinsamen Menü „Thoka“.
 */
class ADM_Admin {

	const SLUG          = 'affi-deal-manager';
	const SLUG_SETTINGS = 'affi-deal-manager-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_adm_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_adm_action', array( __CLASS__, 'handle_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ADM_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		if ( ! function_exists( 'thoka_menu_load' ) || ! thoka_menu_load() ) {
			return;
		}
		Thoka_Admin_Menu::add_page( array(
			'group'       => 'affi-deal-manager',
			'group_label' => 'Affi Deal Manager',
			'description' => 'Gleicht AppSumo-Deals täglich ab und baut Impact-Affiliate-Links.',
			'slug'        => self::SLUG,
			'page_title'  => 'Thoka Affi Deal Manager',
			'menu_title'  => 'Deals',
			'capability'  => 'manage_options',
			'callback'    => array( __CLASS__, 'page_deals' ),
			'main'        => true,
		) );
		Thoka_Admin_Menu::add_page( array(
			'group'      => 'affi-deal-manager',
			'slug'       => self::SLUG_SETTINGS,
			'page_title' => 'Thoka Affi Deal Manager: Einstellungen',
			'menu_title' => 'Einstellungen',
			'capability' => 'manage_options',
			'callback'   => array( __CLASS__, 'page_settings' ),
		) );
	}

	public static function action_links( $links ) {
		$url = class_exists( 'Thoka_Admin_Menu' ) ? Thoka_Admin_Menu::page_url( self::SLUG_SETTINGS ) : admin_url( 'admin.php?page=' . self::SLUG_SETTINGS );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Einstellungen</a>' );
		return $links;
	}

	/* ---------------- Handler ---------------- */

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_save_settings' );
		$in = isset( $_POST['adm'] ) && is_array( $_POST['adm'] ) ? wp_unslash( $_POST['adm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		ADM_Settings::save( $in );
		if ( ! empty( $_POST['adm_regen_secret'] ) ) {
			ADM_Settings::cron_secret( true );
		} elseif ( 'servercron' === ADM_Settings::get( 'mode' ) ) {
			ADM_Settings::cron_secret();
		}
		ADM_Sync::reschedule();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_SETTINGS, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_action' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$msg = '';
		if ( 'run' === $do ) {
			$r   = ADM_Sync::tick( true );
			$msg = $r['skipped'] ? $r['skipped'] : sprintf( '%d Deals geprüft, %d Fehler.%s', $r['checked'], $r['errors'], $r['sitemap'] ? ' Sitemap: ' . $r['sitemap'] : '' );
		} elseif ( 'sitemap' === $do ) {
			delete_option( ADM_Sync::SITEMAP_READ );
			$msg = 'Sitemap: ' . ADM_Sync::read_sitemap();
		} elseif ( 'unlock' === $do ) {
			delete_transient( ADM_Sync::LOCK );
			$msg = 'Sperre aufgehoben.';
		}
		set_transient( 'adm_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------- Seiten ---------------- */

	public static function page_deals() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once ADM_DIR . 'includes/class-adm-list-table.php';
		$table = new ADM_List_Table();
		$table->prepare_items();

		$counts = ADM_DB::counts();
		$health = ADM_Sync::health();
		$tick   = get_option( 'adm_last_tick', array() );
		$smap   = (int) get_option( ADM_Sync::SITEMAP_READ, 0 );
		$next   = wp_next_scheduled( ADM_Sync::HOOK );
		$notice = get_transient( 'adm_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'adm_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1>Thoka Affi Deal Manager</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'off' === ADM_Settings::get( 'mode' ) ) : ?>
				<div class="notice notice-warning"><p>Der automatische Abgleich ist ausgeschaltet (Einstellungen).</p></div>
			<?php endif; ?>
			<?php if ( 'servercron' === ADM_Settings::get( 'mode' ) && ( empty( $tick['time'] ) || 'servercron' !== ( isset( $tick['report']['source'] ) ? $tick['report']['source'] : '' ) ) ) : ?>
				<div class="notice notice-warning"><p>Modus „Server-Cron“ ist eingestellt, aber es kam noch kein Aufruf vom Server. Den Befehl findest du in den Einstellungen.</p></div>
			<?php endif; ?>
			<?php if ( $health['failures'] >= ADM_Sync::FAIL_LIMIT ) : ?>
				<div class="notice notice-error"><p><strong>Abgleich gestört:</strong> <?php echo esc_html( $health['last_error'] ); ?></p></div>
			<?php endif; ?>

			<div class="adm-status" style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0">
				<?php
				$boxes = array(
					'Kaufbar'      => $counts['kaufbar'],
					'Ausgelaufen'  => $counts['ausgelaufen'],
					'Noch ungeprüft' => $counts['neu'],
					'Ignoriert'    => $counts['ignoriert'],
					'Jetzt fällig' => ADM_DB::count_due(),
				);
				foreach ( $boxes as $label => $n ) :
					?>
					<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;min-width:120px">
						<div style="font-size:22px;font-weight:600"><?php echo esc_html( number_format_i18n( $n ) ); ?></div>
						<div style="color:#646970"><?php echo esc_html( $label ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<p style="color:#646970">
				Sitemap zuletzt gelesen: <?php echo esc_html( $smap ? self::date( $smap ) : 'noch nie' ); ?> ·
				Letzter erfolgreicher Abruf: <?php echo esc_html( $health['last_success'] ? self::date( $health['last_success'] ) : 'noch keiner' ); ?> ·
				Zeitplan: <?php echo esc_html( self::schedule_summary() ); ?>
				<?php if ( 'wpcron' === ADM_Settings::get( 'mode' ) ) : ?>
					· Nächster Schritt: <?php echo esc_html( $next ? self::date( $next ) : 'nicht geplant' ); ?>
				<?php endif; ?>
				<?php if ( ! empty( $tick['report'] ) ) : ?>
					<br>Letzter Schritt: <?php echo esc_html( self::date( $tick['time'] ) . ', ' . self::source_label( isset( $tick['report']['source'] ) ? $tick['report']['source'] : '' ) . ': ' . ( $tick['report']['skipped'] && ! $tick['report']['checked'] ? $tick['report']['skipped'] : sprintf( '%d geprüft, %d Fehler', $tick['report']['checked'], $tick['report']['errors'] ) ) ); ?>
				<?php endif; ?>
				<?php if ( $health['last_error'] && (int) $health['last_error_time'] >= (int) $health['last_success'] ) : ?>
					<br>Letzter Fehler (<?php echo esc_html( self::date( $health['last_error_time'] ) ); ?>): <?php echo esc_html( $health['last_error'] ); ?>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
				<?php wp_nonce_field( 'adm_action' ); ?>
				<input type="hidden" name="action" value="adm_action">
				<button class="button button-primary" name="do" value="run">Jetzt abgleichen</button>
				<button class="button" name="do" value="sitemap">Sitemap neu lesen</button>
				<button class="button" name="do" value="unlock">Sperre aufheben</button>
				<span style="color:#646970;margin-left:8px">Ein Schritt prüft bis zu <?php echo (int) ADM_Settings::get( 'batch_size' ); ?> Deals mit <?php echo (int) ADM_Settings::get( 'delay' ); ?> s Pause.</span>
			</form>

			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( ! empty( $_GET['status'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( $_GET['status'] ) ); // phpcs:ignore ?>">
				<?php endif; ?>
				<?php $table->search_box( 'Deals durchsuchen', 'adm-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = ADM_Settings::all();
		$example = ADM_Links::url( 'https://appsumo.com/products/neuronwriter/', 'beispiel' );
		?>
		<div class="wrap">
			<h1>Thoka Affi Deal Manager: Einstellungen</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['checked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Nach Updates gesucht.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'adm_save_settings' ); ?>
				<input type="hidden" name="action" value="adm_save_settings">

				<h2>Affiliate-Links (Impact)</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'partner_id', 'Partner-ID', $s, 'Deine Impact-ID (Konto „Thoka“).' );
					self::field( 'ad_id', 'Werbemittel-ID', $s, 'Textlink, über den alle Deep-Links laufen. Die Zuordnung zur Seite erfolgt über subId1.' );
					self::field( 'program_id', 'Programm-ID', $s, 'AppSumo-Programm bei Impact.' );
					self::field( 'tracking_host', 'Tracking-Domain', $s, '' );
					?>
					<tr><th scope="row">Beispiel</th><td><code style="word-break:break-all"><?php echo esc_html( $example ); ?></code></td></tr>
				</table>

				<h2>Zeitplan</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adm-mode">Abgleich</label></th>
						<td>
							<select id="adm-mode" name="adm[mode]">
								<?php foreach ( ADM_Settings::MODES as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['mode'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">WP-Cron läuft nur, wenn jemand die Seite aufruft. Für einen verlässlichen Takt ohne Besucher den Server-Cron in Plesk nutzen.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adm-interval">Takt</label></th>
						<td>
							<select id="adm-interval" name="adm[interval]">
								<?php foreach ( ADM_Settings::INTERVALS as $min => $label ) : ?>
									<option value="<?php echo (int) $min; ?>" <?php selected( (int) $s['interval'], $min ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Gilt für WP-Cron. Beim Server-Cron bestimmt der Zeitplan in Plesk den Takt.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Zeitfenster</th>
						<td>
							von <?php self::hour_select( 'window_start', (int) $s['window_start'], 0, 23 ); ?>
							bis <?php self::hour_select( 'window_end', (int) $s['window_end'], 1, 24 ); ?> Uhr
							<p class="description">Nur in diesem Zeitraum laufen automatische Schritte (Zeitzone der Website). 0 bis 24 Uhr heißt rund um die Uhr, 22 bis 6 Uhr geht über Mitternacht.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Sitemap lesen ab</th>
						<td>
							<?php self::hour_select( 'sitemap_hour', (int) $s['sitemap_hour'], 0, 23 ); ?> Uhr
							<p class="description">Einmal am Tag beim ersten Schritt ab dieser Uhrzeit.
								<?php if ( ! ADM_Settings::in_window( (int) $s['sitemap_hour'] ) ) : ?>
									<strong style="color:#b32d2e">Die Uhrzeit liegt außerhalb des Zeitfensters, die Sitemap wird dann erst zu Beginn des Fensters gelesen.</strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<?php
					self::field( 'batch_size', 'Deals pro Schritt', $s, '1 bis 100. Ein Schritt endet nach spätestens etwa 3 Minuten, der Rest folgt im nächsten.', 'number' );
					self::field( 'delay', 'Pause zwischen Abrufen (Sekunden)', $s, '0 bis 10. Hält die Last bei AppSumo gering.', 'number' );
					?>
					<tr><th scope="row">Durchsatz</th><td><?php echo esc_html( self::estimate() ); ?></td></tr>
				</table>

				<?php if ( 'servercron' === $s['mode'] ) : ?>
					<?php
					$secret = ADM_Settings::cron_secret();
					$url    = ADM_Sync::server_cron_url();
					?>
					<h2>Server-Cron in Plesk</h2>
					<p>In Plesk unter <em>Geplante Aufgaben</em> eine neue Aufgabe anlegen. Variante 1 als <em>Befehl ausführen</em> (empfohlen, der Schlüssel landet nicht im Server-Log):</p>
					<p><code style="display:block;padding:8px;word-break:break-all">curl -fsS -X POST -H "X-ADM-Key: <?php echo esc_html( $secret ); ?>" <?php echo esc_html( $url ); ?></code></p>
					<p>Variante 2 als <em>URL abrufen</em>:</p>
					<p><code style="display:block;padding:8px;word-break:break-all"><?php echo esc_html( add_query_arg( 'key', $secret, $url ) ); ?></code></p>
					<p class="description">Zeitplan in Plesk zum Beispiel alle 5 oder 15 Minuten. Das Zeitfenster oben gilt auch hier. Gib den Schlüssel nicht weiter.</p>
					<p><label><input type="checkbox" name="adm_regen_secret" value="1"> Beim Speichern einen neuen Schlüssel erzeugen (der alte Befehl funktioniert dann nicht mehr)</label></p>
				<?php endif; ?>

				<h2>Abgleich mit AppSumo</h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( 'sitemap_url', 'Sitemap', $s, '' );
					self::field( 'recheck_available_hrs', 'Kaufbare Deals prüfen alle (Stunden)', $s, '', 'number' );
					self::field( 'recheck_other_days', 'Übrige Deals prüfen alle (Tage)', $s, 'Gilt für ausgelaufene und ignorierte Deals. Geänderte Seiten laut Sitemap werden sofort geprüft.', 'number' );
					self::field( 'notify_email', 'E-Mail bei Störungen', $s, 'Höchstens eine Nachricht am Tag.', 'email' );
					?>
				</table>

				<h2>Updates</h2>
				<table class="form-table" role="presentation">
					<?php self::field( 'update_token', 'Update-Token (optional)', $s, '' ); ?>
				</table>
				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'adm_check_update' ); ?>
				<input type="hidden" name="action" value="adm_check_update">
				<p>Version <?php echo esc_html( ADM_VERSION ); ?> · <button class="button">Nach Updates suchen</button></p>
			</form>
		</div>
		<?php
	}

	private static function field( $key, $label, array $s, $help, $type = 'text' ) {
		$id = 'adm-' . $key;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="%1$s" name="adm[%4$s]" value="%5$s">%6$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( (string) $s[ $key ] ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	private static function hour_select( $key, $value, $min, $max ) {
		printf( '<select name="adm[%1$s]" id="adm-%1$s">', esc_attr( $key ) );
		for ( $h = $min; $h <= $max; $h++ ) {
			printf( '<option value="%1$d" %2$s>%1$02d</option>', (int) $h, selected( $value, $h, false ) );
		}
		echo '</select>';
	}

	/** Kurzbeschreibung des Zeitplans für die Deal-Seite. */
	public static function schedule_summary() {
		$mode = ADM_Settings::get( 'mode' );
		if ( 'off' === $mode ) {
			return 'aus, nur manuell';
		}
		$what   = 'wpcron' === $mode ? 'WP-Cron ' . ADM_Settings::INTERVALS[ (int) ADM_Settings::get( 'interval' ) ] : 'Server-Cron';
		$window = 24 === ADM_Settings::window_hours() ? 'rund um die Uhr' : sprintf( '%02d bis %02d Uhr', ADM_Settings::get( 'window_start' ), ADM_Settings::get( 'window_end' ) );
		return sprintf( '%s, %s, je %d Deals, Sitemap ab %02d Uhr', $what, $window, ADM_Settings::get( 'batch_size' ), ADM_Settings::get( 'sitemap_hour' ) );
	}

	public static function source_label( $source ) {
		$labels = array( 'wpcron' => 'WP-Cron', 'servercron' => 'Server-Cron', 'manuell' => 'manuell' );
		return isset( $labels[ $source ] ) ? $labels[ $source ] : 'unbekannt';
	}

	/** Grobe Schätzung des Durchsatzes für die Einstellungsseite. */
	private static function estimate() {
		$mode  = ADM_Settings::get( 'mode' );
		$batch = (int) ADM_Settings::get( 'batch_size' );
		if ( 'wpcron' !== $mode ) {
			return 'servercron' === $mode
				? sprintf( 'Je Aufruf bis zu %d Deals. Bei einem Plesk-Takt von 5 Minuten im vollen Zeitfenster sind das bis zu %s Deals am Tag.', $batch, number_format_i18n( $batch * 12 * ADM_Settings::window_hours() ) )
				: 'Der Abgleich ist ausgeschaltet.';
		}
		$per_day = (int) floor( ADM_Settings::window_hours() * 60 / (int) ADM_Settings::get( 'interval' ) ) * $batch;
		$counts  = ADM_DB::counts();
		$total   = max( 1, $counts['neu'] );
		$text    = sprintf( 'Bis zu %s Deals am Tag, sofern die Seite regelmäßig aufgerufen wird.', number_format_i18n( $per_day ) );
		if ( $counts['neu'] > 0 && $per_day > 0 ) {
			$text .= sprintf( ' Die %s ungeprüften Deals sind damit in etwa %s abgearbeitet.', number_format_i18n( $counts['neu'] ), self::duration( $total / $per_day * DAY_IN_SECONDS ) );
		}
		return $text;
	}

	private static function duration( $secs ) {
		if ( $secs < 2 * HOUR_IN_SECONDS ) {
			return max( 1, (int) round( $secs / MINUTE_IN_SECONDS ) ) . ' Minuten';
		}
		if ( $secs < 2 * DAY_IN_SECONDS ) {
			return (int) round( $secs / HOUR_IN_SECONDS ) . ' Stunden';
		}
		return (int) round( $secs / DAY_IN_SECONDS ) . ' Tagen';
	}

	/** Zeitstempel in Ortszeit. */
	public static function date( $ts ) {
		return wp_date( 'd.m.Y H:i', (int) $ts );
	}
}
