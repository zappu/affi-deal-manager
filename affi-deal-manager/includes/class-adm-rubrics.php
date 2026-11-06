<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rubriken der Website (Taxonomie adm_rubrik an den Tool-Profilen) und die Zuordnung der AppSumo-Kategorien.
 *
 * Die Zuordnung läuft über Regeln auf dem Kategoriepfad von AppSumo („Marketing > SEO & Analytics > SEO“).
 * Die erste passende Regel gewinnt, deshalb stehen die genauen Regeln vor den groben.
 * Eigene Regeln: Filter adm_rubric_rules. Eine Rubrik, die im Profil von Hand gesetzt ist, bleibt erhalten.
 */
class ADM_Rubrics {

	const TAX = 'adm_rubrik';

	/** Slug => Name, Reihenfolge wie im Projektplan (die letzten drei aus den AppSumo-Bereichen unter „Build & Code“). */
	const RUBRICS = array(
		'seo'                        => 'SEO',
		'content-und-ki-text'        => 'Content und KI-Text',
		'video-und-audio'            => 'Video und Audio',
		'social-media'               => 'Social Media',
		'marketing-und-werbung'      => 'Marketing und Werbung',
		'e-mail-und-kommunikation'   => 'E-Mail und Kommunikation',
		'verkauf-und-kurse'          => 'Verkauf und Kurse',
		'wordpress-plugins'          => 'WordPress-Plugins',
		'produktivitaet-und-organisation' => 'Produktivität und Organisation',
		'design-und-bilder'          => 'Design und Bilder',
		'entwickler-tools'           => 'Entwickler-Tools',
		'formulare-und-app-baukaesten' => 'Formulare und App-Baukästen',
		'sicherheit-und-datenschutz' => 'Sicherheit und Datenschutz',
	);

	/** Teil des AppSumo-Kategoriepfads => Rubrik. Groß- und Kleinschreibung egal. */
	const RULES = array(
		'GEO & AI Search Optimization'        => 'seo',
		'Website Builders & WordPress > SEO'  => 'seo',
		'SEO & Analytics'                     => 'seo',
		'Website Builders & WordPress'        => 'wordpress-plugins',
		'Social Proof'                        => 'marketing-und-werbung',
		'Social Media'                        => 'social-media',
		'Email & Inbox Management'            => 'e-mail-und-kommunikation',
		'Email & SMS Marketing'               => 'e-mail-und-kommunikation',
		'Cold Email'                          => 'e-mail-und-kommunikation',
		'Customer Support'                    => 'e-mail-und-kommunikation',
		'Chatbots & AI Agents'                => 'e-mail-und-kommunikation',
		'Content Marketing'                   => 'content-und-ki-text',
		'Paid Ads'                            => 'marketing-und-werbung',
		'Webinars & Streaming'                => 'marketing-und-werbung',
		'Feedback & Surveys'                  => 'marketing-und-werbung',
		'Sales & Leads'                       => 'verkauf-und-kurse',
		'Course & Community Builders'         => 'verkauf-und-kurse',
		'Business & Entrepreneurship'         => 'verkauf-und-kurse',
		'Audio & Podcasting'                  => 'video-und-audio',
		'Media & Design > Video'              => 'video-und-audio',
		'Design & Branding'                   => 'design-und-bilder',
		'Photos & Stock'                      => 'design-und-bilder',
		'Operations'                          => 'produktivitaet-und-organisation',
		'Developer Tools'                     => 'entwickler-tools',
		'Forms & App Builders'                => 'formulare-und-app-baukaesten',
		'Security & Compliance'               => 'sicherheit-und-datenschutz',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_create_terms' ), 20 );
		add_action( self::TAX . '_edit_form_fields', array( __CLASS__, 'image_field' ) );
		add_action( 'edited_' . self::TAX, array( __CLASS__, 'save_image' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	const IMAGE_META = 'adm_image';

	/** Bild der Rubrik (Mediathek-ID); dient als Beitragsbild für Profile ohne eigenes Bild. */
	public static function image_id( $term_id ) {
		$id = (int) get_term_meta( (int) $term_id, self::IMAGE_META, true );
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	public static function image_html( $term_id, $size = 'medium' ) {
		$id = self::image_id( $term_id );
		return $id ? wp_get_attachment_image( $id, $size, false, array( 'alt' => '', 'loading' => 'eager' ) ) : '';
	}

	public static function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'term.php' === $hook && $screen && self::TAX === $screen->taxonomy ) {
			wp_enqueue_media();
		}
	}

	public static function image_field( $term ) {
		$id = self::image_id( $term->term_id );
		wp_nonce_field( 'adm_rubric_image', 'adm_rubric_image_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="adm-rubric-image">Bild der Rubrik</label></th>
			<td>
				<div id="adm-rubric-image-preview" style="margin-bottom:8px"><?php echo $id ? wp_get_attachment_image( $id, 'thumbnail' ) : ''; // phpcs:ignore ?></div>
				<input type="hidden" id="adm-rubric-image" name="adm_rubric_image" value="<?php echo (int) $id; ?>">
				<button type="button" class="button" id="adm-rubric-image-pick">Bild wählen</button>
				<button type="button" class="button-link" id="adm-rubric-image-clear" style="margin-left:8px">entfernen</button>
				<p class="description">Wird Beitragsbild der Tool-Profile dieser Rubrik, die kein eigenes Bild haben. KI-Bilder von Personen als Symbolbild kennzeichnen (Bildunterschrift oder Beschreibung).</p>
				<script>
				( function () {
					var pick = document.getElementById( 'adm-rubric-image-pick' ), input = document.getElementById( 'adm-rubric-image' ), prev = document.getElementById( 'adm-rubric-image-preview' ), frame;
					pick.addEventListener( 'click', function () {
						frame = frame || wp.media( { title: 'Bild der Rubrik', library: { type: 'image' }, multiple: false } );
						frame.off( 'select' ).on( 'select', function () {
							var a = frame.state().get( 'selection' ).first().toJSON(), img = document.createElement( 'img' );
							input.value = a.id; img.src = ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url ); img.style.maxWidth = '150px';
							prev.innerHTML = ''; prev.appendChild( img );
						} );
						frame.open();
					} );
					document.getElementById( 'adm-rubric-image-clear' ).addEventListener( 'click', function () { input.value = ''; prev.innerHTML = ''; } );
				} )();
				</script>
			</td>
		</tr>
		<?php
	}

	public static function save_image( $term_id ) {
		if ( ! isset( $_POST['adm_rubric_image_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['adm_rubric_image_nonce'] ) ), 'adm_rubric_image' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$id = isset( $_POST['adm_rubric_image'] ) ? (int) $_POST['adm_rubric_image'] : 0;
		if ( $id && wp_attachment_is_image( $id ) ) {
			update_term_meta( (int) $term_id, self::IMAGE_META, $id );
		} else {
			delete_term_meta( (int) $term_id, self::IMAGE_META );
		}
	}

	public static function register() {
		register_taxonomy( self::TAX, array( ADM_Profiles::CPT ), array(
			'labels'            => array(
				'name'          => 'Rubriken',
				'singular_name' => 'Rubrik',
				'all_items'     => 'Alle Rubriken',
				'edit_item'     => 'Rubrik bearbeiten',
				'add_new_item'  => 'Neue Rubrik',
				'search_items'  => 'Rubriken durchsuchen',
				'not_found'     => 'Keine Rubriken gefunden',
			),
			'public'            => true,
			'hierarchical'      => true, // Auswahl per Häkchen im Editor
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'rubrik', 'with_front' => false ),
		) );
	}

	/** Die Rubriken aus dem Projektplan einmal anlegen; umbenannte oder gelöschte Begriffe bleiben, wie sie sind. */
	public static function maybe_create_terms() {
		if ( get_option( 'adm_rubrics_version' ) === ADM_VERSION ) {
			return;
		}
		foreach ( self::RUBRICS as $slug => $name ) {
			if ( ! get_term_by( 'slug', $slug, self::TAX ) ) {
				wp_insert_term( $name, self::TAX, array( 'slug' => $slug ) );
			}
		}
		update_option( 'adm_rubrics_version', ADM_VERSION, false );
	}

	/**
	 * Rubrik-Slug zu einem AppSumo-Kategoriepfad oder leer.
	 *
	 * @param string $category z. B. „Marketing > SEO & Analytics > SEO“.
	 * @return string
	 */
	public static function for_category( $category ) {
		$category = strtolower( (string) $category );
		if ( '' === $category ) {
			return '';
		}
		$rules = (array) apply_filters( 'adm_rubric_rules', self::RULES );
		foreach ( $rules as $needle => $slug ) {
			if ( false !== strpos( $category, strtolower( (string) $needle ) ) ) {
				return (string) $slug;
			}
		}
		return '';
	}

	public static function label( $slug ) {
		$term = '' !== (string) $slug ? get_term_by( 'slug', $slug, self::TAX ) : null;
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}
		return isset( self::RUBRICS[ $slug ] ) ? self::RUBRICS[ $slug ] : '';
	}

	/** Rubrik eines Deals: die im Profil gesetzte, sonst die aus der Zuordnung. */
	public static function for_deal( array $deal ) {
		if ( ! empty( $deal['post_id'] ) ) {
			$terms = get_the_terms( (int) $deal['post_id'], self::TAX );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return $terms[0]->slug;
			}
		}
		return self::for_category( isset( $deal['category'] ) ? $deal['category'] : '' );
	}

	/** Beim Erzeugen eines Profils: Rubrik setzen, wenn noch keine gesetzt ist. */
	public static function assign( $post_id, array $deal ) {
		$terms = get_the_terms( (int) $post_id, self::TAX );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return;
		}
		$slug = self::for_category( $deal['category'] );
		if ( '' === $slug ) {
			return;
		}
		self::maybe_create_terms();
		$term = get_term_by( 'slug', $slug, self::TAX );
		if ( ! $term ) {
			$res  = wp_insert_term( self::label( $slug ), self::TAX, array( 'slug' => $slug ) );
			$term = is_wp_error( $res ) ? null : get_term( (int) $res['term_id'], self::TAX );
		}
		if ( $term && ! is_wp_error( $term ) ) {
			wp_set_object_terms( (int) $post_id, array( (int) $term->term_id ), self::TAX );
		}
	}

	/** Beitragsbild aus der Rubrik setzen, wenn das Profil noch keins hat. */
	public static function assign_image( $post_id ) {
		if ( has_post_thumbnail( (int) $post_id ) ) {
			return;
		}
		$terms = get_the_terms( (int) $post_id, self::TAX );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$img = self::image_id( $terms[0]->term_id );
			if ( $img ) {
				set_post_thumbnail( (int) $post_id, $img );
			}
		}
	}
}
