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
			<?php if ( ! ADM_Settings::get( 'enabled' ) ) : ?>
				<div class="notice notice-warning"><p>Der automatische Abgleich ist ausgeschaltet (Einstellungen).</p></div>
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
				Nächster Schritt: <?php echo esc_html( $next ? self::date( $next ) : 'nicht geplant' ); ?>
				<?php if ( ! empty( $tick['report'] ) ) : ?>
					· Letzter Schritt: <?php echo esc_html( sprintf( '%d geprüft, %d Fehler', $tick['report']['checked'], $tick['report']['errors'] ) ); ?>
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

				<h2>Abgleich mit AppSumo</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Automatischer Abgleich</th>
						<td><label><input type="checkbox" name="adm[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> alle 5 Minuten einen Schritt ausführen</label></td>
					</tr>
					<?php
					self::field( 'sitemap_url', 'Sitemap', $s, 'Wird höchstens einmal am Tag gelesen.' );
					self::field( 'batch_size', 'Deals pro Schritt', $s, '1 bis 50. Bei 15 Deals alle 5 Minuten ist der erste vollständige Durchlauf nach etwa einem Tag fertig.', 'number' );
					self::field( 'delay', 'Pause zwischen Abrufen (Sekunden)', $s, '0 bis 10. Hält die Last bei AppSumo gering.', 'number' );
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

	/** Zeitstempel in Ortszeit. */
	public static function date( $ts ) {
		return wp_date( 'd.m.Y H:i', (int) $ts );
	}
}
