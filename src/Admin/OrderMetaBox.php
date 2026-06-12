<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderMetaBox {

	public function __construct( private readonly OrderMeta $meta, private readonly OrderStatusPresenter $presenter ) {}

	public function register(): void {
		add_meta_box(
			'soocool-order-status',
			__( 'SooCool', 'soocool-for-woocommerce' ),
			array( $this, 'render' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	public function render( object $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		$status           = (string) $order->get_meta( OrderMeta::SYNC_STATUS, true );
		$error            = (string) $order->get_meta( OrderMeta::LAST_ERROR, true );
		$tracking_code    = (string) $order->get_meta( OrderMeta::TRACKING_CODE, true );
		$tracking_url     = (string) $order->get_meta( OrderMeta::TRACKING_URL, true );
		$good_ids         = $this->meta->get_good_ids( $order );

		echo '<div class="soocool-order-card">';
		echo '<div class="soocool-order-card__header"><span class="' . esc_attr( $this->presenter->badge_class( $status ) ) . '">' . esc_html( $this->presenter->label( $status ) ) . '</span></div>';
		echo '<dl class="soocool-order-meta-list">';
		$this->render_meta_row( __( 'SooCool order ID', 'soocool-for-woocommerce' ), '' !== $soocool_order_id ? $soocool_order_id : __( 'Not linked yet', 'soocool-for-woocommerce' ) );
		if ( '' !== $tracking_code ) {
			$this->render_meta_row( __( 'Track & trace code', 'soocool-for-woocommerce' ), $tracking_code );
		}
		if ( array() !== $good_ids ) {
			$this->render_meta_row( __( 'SooCool good IDs', 'soocool-for-woocommerce' ), implode( ', ', $good_ids ) );
		}
		echo '</dl>';

		if ( '' !== $tracking_url ) {
			echo '<p class="soocool-order-actions"><a class="button" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open track & trace', 'soocool-for-woocommerce' ) . '</a></p>';
		}

		if ( '' !== $error ) {
			echo '<div class="soocool-order-alert is-error"><strong>' . esc_html__( 'Last error', 'soocool-for-woocommerce' ) . '</strong><br />' . esc_html( $error ) . '</div>';
		}

		if ( '' !== $soocool_order_id && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="soocool-order-action-group">';
			echo '<div class="soocool-order-action-group__title">' . esc_html__( 'Labels', 'soocool-for-woocommerce' ) . '</div>';
			echo '<div class="soocool-order-button-stack">';
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . absint( $order->get_id() ) ),
				'soocool_download_label_' . absint( $order->get_id() )
			);
			echo '<a class="button button-secondary soocool-order-button" href="' . esc_url( $url ) . '">' . esc_html__( 'Order label', 'soocool-for-woocommerce' ) . '</a>';

			if ( array() !== $good_ids ) {
				$good_label_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . absint( $order->get_id() ) . '&good_ids=' . rawurlencode( implode( ',', $good_ids ) ) ),
					'soocool_download_good_labels_' . absint( $order->get_id() )
				);
				echo '<a class="button button-secondary soocool-order-button" href="' . esc_url( $good_label_url ) . '">' . esc_html__( 'Good labels', 'soocool-for-woocommerce' ) . '</a>';
			}
			echo '</div>';
			echo '<p class="description soocool-order-action-help">' . esc_html__( 'These downloads use the selected label output setting.', 'soocool-for-woocommerce' ) . '</p>';
			echo '</div>';
		} elseif ( '' === $soocool_order_id ) {
			echo '<p class="description">' . esc_html__( 'Use the order action “SooCool: create/send order” before downloading labels.', 'soocool-for-woocommerce' ) . '</p>';
		}
		echo '</div>';
	}

	private function render_meta_row( string $label, string $value ): void {
		echo '<div class="soocool-order-meta-row"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}
}
