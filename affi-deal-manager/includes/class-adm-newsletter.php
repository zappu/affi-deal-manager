<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Newsletter-Anmeldung über SendFox.
 *
 * Eigenes Formular ohne fremdes Skript; WordPress gibt die Adresse über die SendFox-API (POST /contacts)
 * an die eingestellte Liste weiter. Die Bestätigung (Double Opt-in) verschickt SendFox, wenn im Konto
 * „Require double opt-in“ eingeschaltet ist. Schlüssel nur aus der wp-config.php (THOKA_SENDFOX_API_KEY).
 * Schutz gegen Bots: verstecktes Feld, Mindestzeit, höchstens fünf Anmeldungen pro Stunde und IP.
 */
class ADM_Newsletter {

	const CONSTANT = 'THOKA_SENDFOX_API_KEY';
	const API      = 'https://api.sendfox.com/';
	const MIN_SECS = 3;
	const PER_HOUR = 5;

	public static function init() {
		add_shortcode( 'adm_newsletter', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_adm_newsletter', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_adm_newsletter', array( __CLASS__, 'handle' ) );
	}

	public static function key() {
		return ADM_Settings::constant_key( self::CONSTANT );
	}

	/** Liste eingestellt und Schlüssel vorhanden? */
	public static function configured() {
		return '' !== self::key() && (int) ADM_Settings::get( 'newsletter_list' ) > 0;
	}

	/**
	 * Anfrage an die SendFox-API.
	 *
	 * @return array code, data, error
	 */
	public static function request( $method, $path, array $body = null ) {
		if ( '' === self::key() ) {
			return array( 'code' => 0, 'data' => null, 'error' => 'Schlüssel fehlt' );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::key(),
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'data' => null, 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$err  = $code >= 400 || $code < 200 ? ( is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code ) : '';
		return array( 'code' => $code, 'data' => $data, 'error' => str_replace( self::key(), '***', $err ) );
	}

	/** Prüfwert für den Zeitstempel im Formular. */
	private static function sign( $ts ) {
		return substr( hash_hmac( 'sha256', 'adm-nl|' . (int) $ts, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/** Anmeldekasten. */
	public static function html( $title = '', $text = '' ) {
		if ( ! self::configured() ) {
			return '';
		}
		$title = '' !== $title ? $title : (string) ADM_Settings::get( 'newsletter_title' );
		$text  = '' !== $text ? $text : (string) ADM_Settings::get( 'newsletter_text' );
		$ts    = time();
		$priv  = get_privacy_policy_url();
		$id    = 'adm-nl-' . wp_rand( 1000, 9999 );
		wp_enqueue_style( 'adm-frontend' );
		wp_enqueue_script( 'adm-newsletter' );
		$out  = '<section class="adm adm-nl" id="newsletter" aria-labelledby="' . esc_attr( $id ) . '-t">';
		$out .= '<p class="adm-nl__title" id="' . esc_attr( $id ) . '-t">' . esc_html( $title ) . '</p>';
		$out .= '' !== $text ? '<p class="adm-nl__text">' . esc_html( $text ) . '</p>' : '';
		$out .= '<form class="adm-nl__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= '<input type="hidden" name="action" value="adm_newsletter"><input type="hidden" name="ts" value="' . (int) $ts . '"><input type="hidden" name="sig" value="' . esc_attr( self::sign( $ts ) ) . '">';
		$out .= '<input type="hidden" name="back" value="' . esc_url( self::current_url() ) . '">';
		$out .= '<div class="adm-nl__hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		$out .= '<label class="screen-reader-text" for="' . esc_attr( $id ) . '-e">E-Mail-Adresse</label>';
		$out .= '<input class="adm-nl__email" id="' . esc_attr( $id ) . '-e" type="email" name="email" required autocomplete="email" placeholder="deine@e-mail.de">';
		$out .= '<button class="adm-btn adm-nl__btn" type="submit">Anmelden</button></form>';
		$out .= '<p class="adm-nl__msg" role="status" aria-live="polite"></p>';
		$out .= '<p class="adm-nl__small">Du bekommst zuerst eine E-Mail zur Bestätigung. Versand über SendFox (USA), Abmeldung jederzeit über den Link in jeder E-Mail.' . ( $priv ? ' Mehr dazu in der <a href="' . esc_url( $priv ) . '">Datenschutzerklärung</a>.' : '' ) . '</p>';
		return $out . '</section>';
	}

	private static function current_url() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return home_url( strtok( $req, '?' ) );
	}

	/** [adm_newsletter title="…" text="…"] */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'title' => '', 'text' => '' ), $atts, 'adm_newsletter' );
		return self::html( (string) $atts['title'], (string) $atts['text'] );
	}

	/** Automatisch am Ende der Profile und unter den Bestenlisten (Einstellung). */
	public static function auto_html() {
		return ADM_Settings::get( 'newsletter_auto' ) ? self::html() : '';
	}

	/**
	 * Anmeldung prüfen und an SendFox geben.
	 *
	 * @param array  $in Formularwerte.
	 * @param string $ip IP-Adresse.
	 * @return array ok (bool), msg (Text für die Seite).
	 */
	public static function subscribe( array $in, $ip ) {
		$fail  = array( 'ok' => false, 'msg' => 'Die Anmeldung hat nicht geklappt. Bitte versuche es später noch einmal.' );
		$email = isset( $in['email'] ) ? sanitize_email( (string) $in['email'] ) : '';
		$ts    = isset( $in['ts'] ) ? (int) $in['ts'] : 0;
		if ( ! self::configured() ) {
			return $fail;
		}
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'msg' => 'Bitte gib eine gültige E-Mail-Adresse ein.' );
		}
		// Bots: verstecktes Feld ausgefüllt, Zeitstempel falsch oder zu schnell. Nach außen wie Erfolg.
		$sig = isset( $in['sig'] ) ? (string) $in['sig'] : '';
		if ( ! empty( $in['website'] ) || ! hash_equals( self::sign( $ts ), $sig ) || time() - $ts < self::MIN_SECS ) {
			return self::ok();
		}
		$bucket = 'adm_nl_' . substr( md5( (string) $ip . wp_salt( 'nonce' ) ), 0, 20 );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::PER_HOUR ) {
			return array( 'ok' => false, 'msg' => 'Zu viele Anmeldungen in kurzer Zeit. Bitte versuche es später noch einmal.' );
		}
		set_transient( $bucket, $count + 1, HOUR_IN_SECONDS );
		$body = array( 'email' => $email, 'lists' => array( (int) ADM_Settings::get( 'newsletter_list' ) ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['ip_address'] = $ip;
		}
		$r = self::request( 'POST', 'contacts', $body );
		if ( '' !== $r['error'] ) {
			update_option( 'adm_newsletter_error', array( 'time' => time(), 'error' => $r['error'] ), false );
			return $fail;
		}
		update_option( 'adm_newsletter_last', time(), false );
		return self::ok();
	}

	private static function ok() {
		return array( 'ok' => true, 'msg' => 'Fast geschafft: Bitte bestätige deine Anmeldung über den Link in der E-Mail, die wir dir gerade geschickt haben.' );
	}

	public static function handle() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$res = self::subscribe( wp_unslash( $_POST ), $ip ); // phpcs:ignore WordPress.Security.NonceVerification
		$ajax = isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( (string) $_SERVER['HTTP_ACCEPT'], 'application/json' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $ajax ) {
			wp_send_json( $res, $res['ok'] ? 200 : 400 );
		}
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification
		$back = wp_validate_redirect( $back, home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'newsletter', $res['ok'] ? 'ok' : 'fehler', $back ) . '#newsletter' );
		exit;
	}

	/** Zeile in den Einstellungen: Schlüssel, Liste und Verbindung. */
	public static function settings_rows( array $s ) {
		$key  = '' !== self::key();
		$info = '';
		if ( $key && (int) $s['newsletter_list'] > 0 ) {
			$cache = get_transient( 'adm_nl_check' );
			if ( ! is_array( $cache ) || (int) $cache['list'] !== (int) $s['newsletter_list'] ) {
				$r     = self::request( 'GET', 'lists/' . (int) $s['newsletter_list'] );
				$cache = array( 'list' => (int) $s['newsletter_list'], 'name' => is_array( $r['data'] ) && isset( $r['data']['name'] ) ? (string) $r['data']['name'] : '', 'error' => $r['error'] );
				set_transient( 'adm_nl_check', $cache, 10 * MINUTE_IN_SECONDS );
			}
			$info = '' === $cache['error'] ? 'Verbunden, Liste „' . $cache['name'] . '“.' : 'SendFox meldet: ' . $cache['error'];
		}
		$err = get_option( 'adm_newsletter_error' );
		?>
		<tr>
			<th scope="row">SendFox-Schlüssel</th>
			<td><?php echo $key ? 'gesetzt' : 'fehlt'; ?> (<code><?php echo esc_html( self::CONSTANT ); ?></code> in der wp-config.php)<?php echo '' !== $info ? '<p class="description">' . esc_html( $info ) . '</p>' : ''; ?>
			<?php echo is_array( $err ) && $err['time'] > time() - WEEK_IN_SECONDS ? '<p class="description" style="color:#b32d2e">Letzter Fehler bei einer Anmeldung (' . esc_html( wp_date( 'd.m.Y H:i', (int) $err['time'] ) . '): ' . esc_html( $err['error'] ) ) . '</p>' : ''; ?></td>
		</tr>
		<?php
	}
}
