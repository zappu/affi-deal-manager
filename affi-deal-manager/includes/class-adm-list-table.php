<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Deal-Liste im Backend.
 */
class ADM_List_Table extends WP_List_Table {

	private $status = 'kaufbar';

	public function __construct() {
		parent::__construct( array( 'singular' => 'deal', 'plural' => 'deals', 'ajax' => false ) );
		$s = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'kaufbar'; // phpcs:ignore WordPress.Security.NonceVerification
		$this->status = in_array( $s, array_merge( ADM_DB::STATUSES, array( 'alle' ) ), true ) ? $s : 'kaufbar';
	}

	public function get_columns() {
		return array(
			'name'         => 'Deal',
			'category'     => 'Kategorie bei AppSumo',
			'price'        => 'Preis',
			'rating'       => 'Bewertung',
			'status'       => 'Status',
			'last_checked' => 'Geprüft',
			'link'         => 'Affiliate-Link',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'name'         => array( 'name', false ),
			'price'        => array( 'price', false ),
			'rating'       => array( 'review_count', true ),
			'last_checked' => array( 'last_checked', true ),
		);
	}

	protected function get_views() {
		$counts = ADM_DB::counts();
		$labels = array(
			'kaufbar'     => 'Kaufbar',
			'ausgelaufen' => 'Ausgelaufen',
			'neu'         => 'Noch ungeprüft',
			'ignoriert'   => 'Ignoriert',
			'alle'        => 'Alle',
		);
		$views = array();
		foreach ( $labels as $key => $label ) {
			$n     = 'alle' === $key ? array_sum( $counts ) : $counts[ $key ];
			$url   = add_query_arg( array( 'page' => ADM_Admin::SLUG, 'status' => $key ), admin_url( 'admin.php' ) );
			$class = $this->status === $key ? ' class="current"' : '';
			$views[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url( $url ), $class, esc_html( $label ), esc_html( number_format_i18n( $n ) ) );
		}
		return $views;
	}

	public function prepare_items() {
		global $wpdb;
		$per_page = 50;
		$paged    = max( 1, $this->get_pagenum() );
		$where    = array( '1=1' );
		$args     = array();
		if ( 'alle' !== $this->status ) {
			$where[] = 'status = %s';
			$args[]  = $this->status;
		}
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(name LIKE %s OR slug LIKE %s OR brand LIKE %s OR category LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$allowed = array( 'name', 'price', 'review_count', 'last_checked' );
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'review_count'; // phpcs:ignore WordPress.Security.NonceVerification
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'review_count';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( $_GET['order'] ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

		$t         = ADM_DB::table();
		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$sql         = "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d";
		$query_args  = array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A ); // phpcs:ignore

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page ) );
	}

	public function no_items() {
		echo 'Keine Deals in dieser Ansicht. Der erste Abgleich legt die Deals nach und nach an.';
	}

	protected function column_name( $item ) {
		$name = '' !== $item['name'] ? $item['name'] : $item['slug'];
		$out  = '<strong>' . esc_html( $name ) . '</strong>';
		if ( '' !== $item['description'] ) {
			$out .= '<br><span style="color:#646970">' . esc_html( wp_trim_words( $item['description'], 14 ) ) . '</span>';
		}
		$actions = array(
			'appsumo' => '<a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">Bei AppSumo ansehen</a>',
		);
		$videos = json_decode( (string) $item['videos'], true );
		if ( is_array( $videos ) ) {
			$n = count( array_filter( $videos, function ( $v ) {
				return empty( $v['appsumo'] );
			} ) );
			if ( $n ) {
				$actions['videos'] = esc_html( $n . ' Testvideo' . ( 1 === $n ? '' : 's' ) );
			}
		}
		return $out . $this->row_actions( $actions );
	}

	protected function column_category( $item ) {
		return esc_html( $item['category'] );
	}

	protected function column_price( $item ) {
		if ( null === $item['price'] || '' === (string) $item['price'] ) {
			return '–';
		}
		$cur = 'USD' === $item['currency'] ? '$' : $item['currency'] . ' ';
		return esc_html( $cur . number_format_i18n( (float) $item['price'], (float) $item['price'] === floor( (float) $item['price'] ) ? 0 : 2 ) );
	}

	protected function column_rating( $item ) {
		if ( null === $item['rating'] || '' === (string) $item['rating'] ) {
			return '–';
		}
		return esc_html( number_format_i18n( (float) $item['rating'], 1 ) . ' ★ (' . number_format_i18n( (int) $item['review_count'] ) . ')' );
	}

	protected function column_status( $item ) {
		$colors = array( 'kaufbar' => '#00a32a', 'ausgelaufen' => '#b32d2e', 'neu' => '#646970', 'ignoriert' => '#a7aaad' );
		$labels = array( 'kaufbar' => 'Kaufbar', 'ausgelaufen' => 'Ausgelaufen', 'neu' => 'Ungeprüft', 'ignoriert' => 'Ignoriert' );
		$c      = isset( $colors[ $item['status'] ] ) ? $colors[ $item['status'] ] : '#646970';
		$l      = isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : $item['status'];
		$out    = '<span style="color:' . esc_attr( $c ) . ';font-weight:600">' . esc_html( $l ) . '</span>';
		$out   .= '<br><span style="color:#646970">' . esc_html( ADM_DB::reason( $item ) ) . '</span>';
		if ( '' !== $item['last_error'] && 'Gone' !== $item['availability'] ) {
			$out .= '<br><span style="color:#b32d2e" title="' . esc_attr( $item['last_error'] ) . '">' . esc_html( wp_trim_words( $item['last_error'], 6 ) ) . '</span>';
		}
		return $out;
	}

	protected function column_last_checked( $item ) {
		return $item['last_checked'] ? esc_html( ADM_Admin::date( strtotime( $item['last_checked'] . ' UTC' ) ) ) : '–';
	}

	protected function column_link( $item ) {
		$link = ADM_Links::url( $item['url'], $item['slug'] );
		return '<input type="text" readonly class="regular-text" style="width:100%;font-size:12px" value="' . esc_attr( $link ) . '" onclick="this.select()" aria-label="Affiliate-Link">';
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
