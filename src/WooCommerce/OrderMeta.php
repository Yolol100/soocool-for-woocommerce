<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderMeta {

	public const ORDER_ID       = '_soocool_soocool_order_id';
	public const OUR_REFERENCE  = '_soocool_soocool_our_reference';
	public const SYNC_STATUS    = '_soocool_sync_status';
	public const LAST_ERROR     = '_soocool_last_error';
	public const LAST_SYNCED_AT = '_soocool_last_synced_at';

	/** @param array<string, mixed> $body */
	public function save_success( WC_Order $order, array $body ): void {
		$soocool_order_id = $this->extract_order_id( $body );
		if ( '' === $soocool_order_id ) {
			throw new \InvalidArgumentException( 'Missing valid SooCool order ID.' );
		}

		$order->update_meta_data( self::ORDER_ID, $soocool_order_id );
		if ( isset( $body['ourReference'] ) ) {
			$order->update_meta_data( self::OUR_REFERENCE, sanitize_text_field( (string) $body['ourReference'] ) );
		}
		$order->update_meta_data( self::SYNC_STATUS, 'synced' );
		$order->update_meta_data( self::LAST_SYNCED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( self::LAST_ERROR );
		$order->save();
	}

	public function save_pending( WC_Order $order ): void {
		$order->update_meta_data( self::SYNC_STATUS, 'pending' );
		$order->save();
	}

	public function save_error( WC_Order $order, string $message ): void {
		$order->update_meta_data( self::SYNC_STATUS, 'failed' );
		$order->update_meta_data( self::LAST_ERROR, sanitize_text_field( $message ) );
		$order->save();
	}

	/** @param array<string, mixed> $body */
	public function extract_order_id( array $body ): string {
		if ( ! isset( $body['orderId'] ) || is_array( $body['orderId'] ) || is_object( $body['orderId'] ) ) {
			return '';
		}

		$order_id = trim( sanitize_text_field( (string) $body['orderId'] ) );
		if ( ! ctype_digit( $order_id ) || 0 >= (int) $order_id ) {
			return '';
		}

		return (string) (int) $order_id;
	}

	public function get_soocool_order_id( WC_Order $order ): string {
		$value = $order->get_meta( self::ORDER_ID, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$order_id = trim( sanitize_text_field( (string) $value ) );
		if ( ! ctype_digit( $order_id ) || 0 >= (int) $order_id ) {
			return '';
		}

		return (string) (int) $order_id;
	}

	public function is_synced( WC_Order $order ): bool {
		return '' !== $this->get_soocool_order_id( $order );
	}
}
