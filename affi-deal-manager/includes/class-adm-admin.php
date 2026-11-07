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
		add_action( 'admin_post_adm_set_type', array( __CLASS__, 'handle_set_type' ) );
		add_action( 'admin_post_adm_profile', array( __CLASS__, 'handle_profile' ) );
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
		ADM_Profiles::resume();
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

	public static function video_url( $id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'adm_profile', 'do' => 'video_one', 'id' => (int) $id ), admin_url( 'admin-post.php' ) ), 'adm_profile' );
	}

	public static function profile_url( $id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'adm_profile', 'do' => 'one', 'id' => (int) $id ), admin_url( 'admin-post.php' ) ), 'adm_profile' );
	}

	/** Profile vormerken, sofort erzeugen oder Warteschlange leeren. */
	public static function handle_profile() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'adm_profile' );
		$do  = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		$msg = '';
		if ( ! ADM_AI::configured() && in_array( $do, array( 'one', 'best', 'run', 'drafts' ), true ) ) {
			$msg = ADM_AI::missing_key_message();
		} elseif ( 'one' === $do ) {
			$id  = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
			$n   = ADM_Profiles::enqueue( array( $id ) );
			$msg = $n ? 'Profil vorgemerkt. Es wird in den nächsten Minuten erzeugt.' : 'Für diesen Deal kann kein Profil erzeugt werden (veröffentlicht oder schon vorgemerkt).';
		} elseif ( 'best' === $do ) {
			$n   = ADM_Profiles::enqueue( ADM_Profiles::best_candidates( (int) ADM_Settings::get( 'batch_profiles' ) ) );
			$msg = $n . ' Profile vorgemerkt.';
		} elseif ( 'videos' === $do ) {
			$n   = ADM_Profiles::enqueue_videos( ADM_Profiles::drafts_without_videos() );
			$msg = $n . ' Entwürfe zum Nachtragen der Videos vorgemerkt. Der Text bleibt, wie er ist.';
		} elseif ( 'video_one' === $do ) {
			$n   = ADM_Profiles::enqueue_videos( array( isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0 ) );
			$msg = $n ? 'Videos werden in den nächsten Minuten neu gesucht. Der Text bleibt, wie er ist.' : 'Für diesen Deal gibt es keinen Entwurf.';
		} elseif ( 'drafts' === $do ) {
			$n   = ADM_Profiles::enqueue( ADM_Profiles::draft_deals() );
			$msg = $n . ' Entwürfe zum Neuschreiben vorgemerkt. Veröffentlichte Profile bleiben unangetastet.';
		} elseif ( 'run' === $do ) {
			$before = count( ADM_Profiles::queue() ) + count( ADM_Profiles::video_queue() );
			delete_option( ADM_Profiles::PAUSED );
			ADM_Profiles::process_queue();
			$log = get_option( ADM_Profiles::LOG, array() );
			$msg = $before ? 'Ein Auftrag verarbeitet: ' . ( isset( $log[0]['msg'] ) ? self::log_text( $log[0]['msg'], 'erfolgreich' ) : '' ) : 'Die Warteschlange ist leer.';
		} elseif ( 'clear' === $do ) {
			ADM_Profiles::clear_queue();
			update_option( ADM_Profiles::VQUEUE, array(), false );
			$msg = 'Warteschlange geleert.';
		}
		set_transient( 'adm_notice_' . get_current_user_id(), $msg, 60 );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function set_type_url( $id, $type ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'adm_set_type', 'id' => (int) $id, 'type' => $type ), admin_url( 'admin-post.php' ) ), 'adm_set_type_' . (int) $id );
	}

	public static function handle_set_type() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'adm_set_type_' . $id );
		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
		$deal = ADM_DB::get( $id );
		if ( $deal && false !== ADM_DB::set_type( $id, $type ) ) {
			$name = '' !== $deal['name'] ? $deal['name'] : $deal['slug'];
			$msg  = '' === $type ? $name . ': Typ wird beim nächsten Abgleich automatisch erkannt.' : $name . ': Typ auf „' . ADM_DB::type_label( $type ) . '“ gesetzt.';
			set_transient( 'adm_notice_' . get_current_user_id(), $msg, 60 );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
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
			<?php $last_sc = (int) get_option( 'adm_last_servercron', 0 ); ?>
			<?php if ( 'servercron' === ADM_Settings::get( 'mode' ) && ( ! $last_sc || $last_sc < time() - HOUR_IN_SECONDS ) ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html( $last_sc ? 'Modus „Server-Cron“: Der letzte Aufruf vom Server war am ' . self::date( $last_sc ) . '. Prüfe die geplante Aufgabe in Plesk.' : 'Modus „Server-Cron“ ist eingestellt, aber es kam noch kein Aufruf vom Server. Den Befehl findest du in den Einstellungen.' ); ?></p></div>
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

			<?php
			$types = ADM_DB::type_counts();
			$parts = array();
			foreach ( ADM_DB::TYPES as $key => $label ) {
				$parts[] = '<a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'status' => 'kaufbar', 'type' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . ' ' . (int) $types[ $key ] . '</a>';
			}
			$free = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . ADM_DB::table() . " WHERE status = 'kaufbar' AND price IS NOT NULL AND price = 0" ); // phpcs:ignore
			$parts[] = '<a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'status' => 'kaufbar', 'type' => 'kostenlos' ), admin_url( 'admin.php' ) ) ) . '">davon kostenlos ' . $free . '</a>';
			?>
			<p><strong>Kaufbar nach Typ:</strong> <?php echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
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

			<?php self::profiles_panel(); ?>

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
					<p><code style="display:block;padding:8px;word-break:break-all">curl -fsSk -A "ThokaCron/1.0" -X POST -d "" -H "X-ADM-Key: <?php echo esc_html( $secret ); ?>" <?php echo esc_html( $url ); ?></code></p>
					<p class="description"><code>-k</code> umgeht fehlende Zertifikatsdateien in der Plesk-Umgebung (der Server ruft sich selbst auf), <code>-d ""</code> verhindert eine Sperre durch ModSecurity. Im Befehl kein <code>%</code>-Zeichen verwenden, Plesk schneidet dort ab. Für einen Testlauf <code>-fsSk</code> durch <code>-sSk</code> ersetzen, dann zeigt Plesk die Antwort.</p>
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

				<h2 id="adm-ki">Tool-Profile und KI</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adm-ai_provider">KI-Anbieter</label></th>
						<td><select id="adm-ai_provider" name="adm[ai_provider]">
							<?php foreach ( ADM_Settings::AI_PROVIDERS as $key => $p ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['ai_provider'], $key ); ?>><?php echo esc_html( $p['label'] ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<?php
					?>
					<tr>
						<th scope="row">API-Schlüssel</th>
						<td><?php self::key_status(); ?></td>
					</tr>
					<?php
					self::model_suggestions();
					self::field( 'ai_model', 'Modell', $s, 'Leer lassen für das Standardmodell (' . ADM_Settings::AI_PROVIDERS[ isset( ADM_Settings::AI_PROVIDERS[ $s['ai_provider'] ] ) ? $s['ai_provider'] : 'openrouter' ]['model'] . ').' );
					self::field( 'title_template', 'Seitentitel', $s, '{tool} wird durch den Namen ersetzt.' );
					self::field( 'seo_title_template', 'SEO-Titel (Rank Math)', $s, 'Möglichst unter 60 Zeichen.' );
					self::field( 'batch_profiles', 'Anzahl beim Sammelknopf', $s, '1 bis 50 kaufbare Software-Deals mit den meisten Bewertungen.', 'number' );
					self::field( 'reviews_newest', 'Neueste Bewertungen auswerten', $s, '0 bis 100.', 'number' );
					self::field( 'reviews_critical', 'Kritischste Bewertungen auswerten', $s, '0 bis 100.', 'number' );
					?>
					<tr>
						<th scope="row"><label for="adm-box_placement">Deal-Kasten</label></th>
						<td><select id="adm-box_placement" name="adm[box_placement]">
							<option value="content" <?php selected( $s['box_placement'], 'content' ); ?>>Einfach: Kasten oben im Text, Titel vom Theme</option>
							<option value="sidebar" <?php selected( $s['box_placement'], 'sidebar' ); ?>>Profil-Layout: Kopfbereich mit Eckdaten, Kasten in der Seitenleiste (auf dem Handy oben im Text), Übersichten /tools/ und /rubrik/ im selben Stil</option>
						</select>
						<p class="description">Beim Profil-Layout baut das Plugin Kopfbereich und Seitenleiste um den Beitragsinhalt; das Theme oder Elementor gibt nur den Inhalt aus. Bei Hello Elementor entfällt der Theme-Titel automatisch, andere Themes zeigen ihn eventuell doppelt.</p></td>
					</tr>
					<tr>
						<th scope="row">Videos zusammenfassen</th>
						<td><label><input type="checkbox" name="adm[video_summaries]" value="1" <?php checked( $s['video_summaries'] ); ?>> Gemini sieht sich die ausgewählten YouTube-Videos an und fasst sie zusammen</label>
						<p class="description">Braucht <code><?php echo esc_html( ADM_Settings::GEMINI_CONSTANT ); ?></code> in der wp-config.php (<?php echo '' !== ADM_Settings::gemini_key() ? 'gesetzt' : 'fehlt'; ?>). Schlüssel bei aistudio.google.com. Ohne Schlüssel zeigt das Profil die Videos ohne Inhaltsangabe.</p></td>
					</tr>
					<?php self::field( 'gemini_model', 'Gemini-Modell', $s, 'Standard: gemini-3.6-flash. Ist das Modell überlastet, weicht das Plugin auf gemini-3.5-flash-lite aus.' ); ?>
					<tr>
						<th scope="row">Automatik</th>
						<td><label><input type="checkbox" name="adm[auto_profiles]" value="1" <?php checked( $s['auto_profiles'] ); ?>> Neue kaufbare Software-Deals bekommen automatisch einen Profil-Entwurf</label>
						<p class="description">Erst einschalten, wenn du die ersten Texte abgenommen hast.</p></td>
					</tr>
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

	/** Kasten „Tool-Profile“ auf der Deal-Seite: Warteschlange, Sammelknopf, letzte Ergebnisse. */
	private static function profiles_panel() {
		$queue = ADM_Profiles::queue();
		$log   = get_option( ADM_Profiles::LOG, array() );
		$n     = (int) ADM_Settings::get( 'batch_profiles' );
		$cands = count( ADM_Profiles::best_candidates( $n ) );
		$url   = admin_url( 'admin-post.php' );
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;margin:0 0 16px">
			<h2 style="margin:0 0 8px;font-size:15px">Tool-Profile</h2>
			<?php if ( ADM_Profiles::paused() && $queue ) : ?>
				<div class="notice notice-error inline"><p><strong>Warteschlange angehalten:</strong> <?php echo esc_html( ADM_Profiles::paused() ); ?> Nach dem Speichern der Einstellungen oder mit „Nächstes jetzt erzeugen“ geht es weiter.</p></div>
			<?php endif; ?>
			<?php if ( ! ADM_AI::configured() ) : ?>
				<p><?php echo esc_html( ADM_AI::missing_key_message() ); ?> <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG_SETTINGS ), admin_url( 'admin.php' ) ) ); ?>#adm-ki">Zu den Einstellungen</a></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" style="display:inline">
				<?php wp_nonce_field( 'adm_profile' ); ?>
				<input type="hidden" name="action" value="adm_profile">
				<button class="button button-primary" name="do" value="best" <?php disabled( ! $cands ); ?>>Profile für die <?php echo (int) $n; ?> besten Software-Deals vormerken (<?php echo (int) $cands; ?> ohne Profil)</button>
				<?php $drafts = count( ADM_Profiles::draft_deals() ); ?>
				<button class="button" name="do" value="drafts" <?php disabled( ! $drafts ); ?>>Alle Entwürfe neu erzeugen (<?php echo (int) $drafts; ?>)</button>
				<?php $novid = count( ADM_Profiles::drafts_without_videos() ); ?>
				<button class="button" name="do" value="videos" <?php disabled( ! $novid ); ?>>Videos nachtragen (<?php echo (int) $novid; ?> Entwürfe ohne Video)</button>
				<button class="button" name="do" value="run" <?php disabled( ! $queue && ! ADM_Profiles::video_queue() ); ?>>Nächstes jetzt erzeugen</button>
				<button class="button" name="do" value="clear" <?php disabled( ! $queue && ! ADM_Profiles::video_queue() ); ?>>Warteschlange leeren</button>
			</form>
			<p style="color:#646970;margin:8px 0 0">In der Warteschlange: <?php echo count( $queue ); ?><?php echo ADM_Profiles::video_queue() ? ', Videos nachtragen: ' . count( ADM_Profiles::video_queue() ) : ''; ?>. Ein Profil dauert etwa eine bis zwei Minuten und wird als Entwurf angelegt.
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . ADM_Profiles::CPT ) ); ?>">Alle Tool-Profile</a></p>
			<?php if ( is_array( $log ) && $log ) : ?>
				<details style="margin-top:6px"><summary>Letzte Ergebnisse</summary><ul style="margin:6px 0 0 18px;list-style:disc">
				<?php
				foreach ( array_slice( $log, 0, 10 ) as $l ) {
					$deal = ADM_DB::get( (int) $l['deal'] );
					$name = $deal ? $deal['name'] : '#' . (int) $l['deal'];
					$link = $l['post'] ? ' <a href="' . esc_url( get_edit_post_link( (int) $l['post'], 'url' ) ) . '">bearbeiten</a>' : '';
					echo '<li>' . esc_html( self::date( $l['time'] ) . ', ' . $name . ': ' . self::log_text( $l['msg'], 'Entwurf erstellt' ) ) . $link . '</li>'; // phpcs:ignore
				}
				?>
				</ul></details>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function field( $key, $label, array $s, $help, $type = 'text' ) {
		$id = 'adm-' . $key;
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="%1$s" name="adm[%4$s]" value="%5$s"%7$s>%6$s</td></tr>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( (string) $s[ $key ] ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : '',
			'ai_model' === $key ? ' list="adm-models" autocomplete="off"' : ''
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

	/** Vorschlagsliste für das Modellfeld (je Anbieter). */
	private static function model_suggestions() {
		echo '<datalist id="adm-models">';
		foreach ( ADM_Settings::MODEL_SUGGESTIONS as $provider => $models ) {
			foreach ( $models as $m ) {
				printf( '<option value="%s" label="%s">', esc_attr( $m ), esc_attr( ADM_Settings::AI_PROVIDERS[ $provider ]['label'] ) );
			}
		}
		echo '</datalist>';
	}

	/** Status der Schlüssel aus der wp-config.php, ohne die Schlüssel zu zeigen. */
	private static function key_status() {
		$rows = array();
		foreach ( ADM_Settings::AI_PROVIDERS as $key => $p ) {
			$rows[] = array( $p['label'], $p['constant'], '' !== ADM_Settings::ai_key( $key ) );
		}
		$rows[] = array( 'YouTube (optional)', ADM_Settings::YOUTUBE_CONSTANT, '' !== ADM_Settings::youtube_key() );
		$rows[] = array( 'Gemini (optional, Videos)', ADM_Settings::GEMINI_CONSTANT, '' !== ADM_Settings::gemini_key() );
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td style="color:%s;font-weight:600">%s</td></tr>',
				esc_html( $r[0] ),
				esc_html( $r[1] ),
				$r[2] ? '#00a32a' : '#b32d2e',
				$r[2] ? 'gesetzt' : 'fehlt'
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">Die Schlüssel stehen aus Sicherheitsgründen nur in der <code>wp-config.php</code>, oberhalb der Zeile „That’s all, stop editing!“:</p>';
		echo '<pre style="background:#f6f7f7;padding:8px 10px;max-width:640px;overflow:auto">';
		foreach ( $rows as $r ) {
			echo esc_html( "define( '" . $r[1] . "', 'DEIN-SCHLÜSSEL' );" ) . "\n";
		}
		echo '</pre><p class="description">Nötig ist nur der Schlüssel des oben gewählten Anbieters. Ohne YouTube-Schlüssel kommen die Videos aus der AppSumo-Liste, ohne Gemini-Schlüssel gibt es keine Videozusammenfassung.</p>';
	}

	/** Protokolleintrag lesbar: „ok“ wird zum Erfolgstext, Stilhinweise bleiben dahinter. */
	private static function log_text( $msg, $ok ) {
		$msg = (string) $msg;
		if ( 'ok' === $msg ) {
			return $ok;
		}
		return 0 === strpos( $msg, 'ok; ' ) ? $ok . '; ' . substr( $msg, 4 ) : $msg;
	}

	/** Zeitstempel in Ortszeit. */
	public static function date( $ts ) {
		return wp_date( 'd.m.Y H:i', (int) $ts );
	}
}
