<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Adds SooCool status controls to HPOS and legacy WooCommerce order lists.
 */
final class OrderListColumn {

	private const COLUMN_KEY  = 'soocool_status';
	private const FILTER_PARAM = 'soocool_sync';

	public function __construct( private readonly OrderMeta $meta, private readonly OrderStatusPresenter $presenter ) {}

	public function register(): void {
		// HPOS orders table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filter' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query_args' ) );

		// Legacy post-based orders table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_legacy_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_legacy_query' ) );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new[ self::COLUMN_KEY ] = __( 'SooCool', 'soocool-for-woocommerce' );
			}
		}

		if ( ! isset( $new[ self::COLUMN_KEY ] ) ) {
			$new[ self::COLUMN_KEY ] = __( 'SooCool', 'soocool-for-woocommerce' );
		}

		return $new;
	}

	public function render_hpos_column( string $column, mixed $order ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( $order instanceof WC_Order ) {
			$this->render_badge( $order );
		}
	}

	public function render_legacy_column( string $column, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			$this->render_badge( $order );
		}
	}

	private function render_badge( WC_Order $order ): void {
		$status         = $this->meta->get_sync_status( $order );
		$display_status = $this->presenter->display_status( $status, $this->meta->is_synced( $order ) );
		$tracking       = $this->meta->get_tracking_code( $order );

		printf(
			'<span class="%1$s">%2$s</span>',
			esc_attr( $this->presenter->badge_class( $display_status ) ),
			esc_html( $this->presenter->label( $display_status ) )
		);

		if ( '' !== $tracking ) {
			echo '<br /><small class="soocool-order-tracking-code">' . esc_html( $tracking ) . '</small>';
		}

		$this->render_sync_action( $order, $status );
		$this->render_label_links( $order );
	}

	private function render_sync_action( WC_Order $order, string $status ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || $this->meta->is_synced( $order ) ) {
			return;
		}

		$order_id = absint( $order->get_id() );
		if ( 0 >= $order_id ) {
			return;
		}

		$status       = sanitize_key( $status );
		$is_failed    = in_array( $status, $this->presenter->failed_statuses(), true );
		$is_pending   = in_array( $status, $this->presenter->pending_statuses(), true );
		$button_label = $is_failed
			? __( 'Opnieuw synchroniseren', 'soocool-for-woocommerce' )
			: ( $is_pending ? __( 'Nu synchroniseren', 'soocool-for-woocommerce' ) : __( 'Synchroniseer nu', 'soocool-for-woocommerce' ) );

		echo '<div class="soocool-list-sync-action soocool-order-sync-action">';
		echo '<button type="button" class="button button-secondary soocool-list-sync-action__button" data-soocool-manual-sync="1" data-action="' . esc_attr( OrderActions::MANUAL_SYNC_AJAX_ACTION ) . '" data-order-id="' . esc_attr( (string) $order_id ) . '" data-nonce="' . esc_attr( wp_create_nonce( OrderActions::MANUAL_SYNC_NONCE_ACTION . $order_id ) ) . '" data-soocool-confirm="' . esc_attr__( 'Deze order nu naar SooCool synchroniseren?', 'soocool-for-woocommerce' ) . '">' . esc_html( $button_label ) . '</button>';
		echo '<div class="soocool-order-alert soocool-manual-sync-feedback" hidden aria-live="polite"></div>';

		$error = $this->presenter->display_error( $this->meta->get_last_error( $order ) );
		if ( $is_failed && '' !== $error ) {
			echo '<details class="soocool-list-sync-error-details"><summary>' . esc_html__( 'Foutdetail', 'soocool-for-woocommerce' ) . '</summary><small class="soocool-list-sync-error">' . esc_html( $error ) . '</small></details>';
		}

		echo '</div>';
	}

	private function render_label_links( WC_Order $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->meta->is_synced( $order ) ) {
			return;
		}

		$order_id = absint( $order->get_id() );
		$order_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . $order_id ),
			'soocool_download_label_' . $order_id
		);

		echo '<div class="soocool-list-label-actions">';
		echo '<a class="soocool-list-label-actions__link" href="' . esc_url( $order_url ) . '">' . esc_html__( 'Download orderlabel', 'soocool-for-woocommerce' ) . '</a>';

		$good_ids = $this->meta->get_good_ids( $order );
		if ( array() !== $good_ids ) {
			$good_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . $order_id . '&good_ids=' . rawurlencode( implode( ',', $good_ids ) ) ),
				'soocool_download_good_labels_' . $order_id
			);
			echo '<a class="soocool-list-label-actions__link" href="' . esc_url( $good_url ) . '">' . esc_html__( 'Download goederenlabel', 'soocool-for-woocommerce' ) . '</a>';
		}

		echo '</div>';
	}

	public function render_filter(): void {
		$this->print_select();
	}

	public function render_legacy_filter(): void {
		if ( 'shop_order' !== ( $GLOBALS['typenow'] ?? '' ) ) {
			return;
		}
		$this->print_select();
	}

	private function print_select(): void {
		$selected = $this->selected_filter(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.

		echo '<select name="' . esc_attr( self::FILTER_PARAM ) . '" id="soocool-sync-filter">';
		echo '<option value="">' . esc_html__( 'SooCool: alles', 'soocool-for-woocommerce' ) . '</option>';
		foreach ( $this->presenter->filter_options() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $selected, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * @param array<string, mixed> $query_args
	 * @return array<string, mixed>
	 */
	public function filter_hpos_query_args( array $query_args ): array {
		$filter = $this->selected_filter(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$clause = $this->meta_query_clause( $filter );
		if ( array() === $clause ) {
			return $query_args;
		}

		$meta_query   = isset( $query_args['meta_query'] ) && is_array( $query_args['meta_query'] ) ? $query_args['meta_query'] : array();
		$meta_query[] = $clause;
		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin list filter.

		return $query_args;
	}

	public function filter_legacy_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		$filter = $this->selected_filter(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$clause = $this->meta_query_clause( $filter );
		if ( array() === $clause ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = $clause;
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Build the meta_query clause for the selected filter value.
	 *
	 * @return array<string, mixed>
	 */
	private function meta_query_clause( string $filter ): array {
		if ( 'not_synced' === $filter ) {
			return array(
				'relation' => 'AND',
				$this->missing_remote_order_clause(),
				array(
					'relation' => 'OR',
					array(
						'key'     => OrderMeta::SYNC_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => OrderMeta::SYNC_STATUS,
						'value'   => '',
						'compare' => '=',
					),
				),
			);
		}

		if ( 'synced' === $filter ) {
			return array(
				'relation' => 'AND',
				array(
					'key'     => OrderMeta::ORDER_ID,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => OrderMeta::ORDER_ID,
					'value'   => '',
					'compare' => '!=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => OrderMeta::SYNC_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => OrderMeta::SYNC_STATUS,
						'value'   => array_merge( $this->presenter->failed_statuses(), $this->presenter->cancelled_statuses() ),
						'compare' => 'NOT IN',
					),
				),
			);
		}

		if ( 'pending' === $filter ) {
			return array(
				'relation' => 'AND',
				array(
					'key'     => OrderMeta::SYNC_STATUS,
					'value'   => $this->presenter->pending_statuses(),
					'compare' => 'IN',
				),
				$this->missing_remote_order_clause(),
			);
		}

		$status_groups = array(
			'failed'    => $this->presenter->failed_statuses(),
			'cancelled' => $this->presenter->cancelled_statuses(),
		);

		if ( isset( $status_groups[ $filter ] ) ) {
			return array(
				'key'     => OrderMeta::SYNC_STATUS,
				'value'   => $status_groups[ $filter ],
				'compare' => 'IN',
			);
		}

		return array();
	}

	/** @return array<string, mixed> */
	private function missing_remote_order_clause(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => OrderMeta::ORDER_ID,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => OrderMeta::ORDER_ID,
				'value'   => '',
				'compare' => '=',
			),
		);
	}
	private function selected_filter(): string {
		if ( ! isset( $_GET[ self::FILTER_PARAM ] ) || ! is_scalar( $_GET[ self::FILTER_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			return '';
		}

		return sanitize_key( wp_unslash( (string) $_GET[ self::FILTER_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
	}

}
