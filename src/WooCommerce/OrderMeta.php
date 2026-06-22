<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderMeta {

	public const ORDER_ID       = '_soocool_soocool_order_id';
	public const OUR_REFERENCE  = '_soocool_soocool_our_reference';
	public const ORDER_REFERENCE = '_soocool_order_reference';
	public const SYNC_STATUS    = '_soocool_sync_status';
	public const LAST_ERROR     = '_soocool_last_error';
	public const LAST_SYNCED_AT  = '_soocool_last_synced_at';
	public const LAST_WEBHOOK_AT = '_soocool_last_webhook_at';
	public const TRACKING_CODE   = '_soocool_tracking_code';
	public const TRACKING_URL    = '_soocool_tracking_url';
	public const GOOD_IDS        = '_soocool_good_ids';
	public const REQUESTED_DELIVERY_DATE  = '_soocool_requested_delivery_date';
	public const REQUESTED_DELIVERY_LABEL = '_soocool_requested_delivery_label';
	public const REQUESTED_DELIVERY_TIME_FROM  = '_soocool_requested_delivery_time_from';
	public const REQUESTED_DELIVERY_TIME_TO    = '_soocool_requested_delivery_time_to';
	public const REQUESTED_DELIVERY_TIME_LABEL = '_soocool_requested_delivery_time_label';


	public function get_requested_delivery_date( WC_Order $order ): string {
		$value = $order->get_meta( self::REQUESTED_DELIVERY_DATE, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$date = sanitize_text_field( (string) $value );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	public function get_requested_delivery_label( WC_Order $order ): string {
		$value = $order->get_meta( self::REQUESTED_DELIVERY_LABEL, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	public function get_requested_delivery_time_from( WC_Order $order ): string {
		return $this->get_requested_delivery_time( $order, self::REQUESTED_DELIVERY_TIME_FROM );
	}

	public function get_requested_delivery_time_to( WC_Order $order ): string {
		return $this->get_requested_delivery_time( $order, self::REQUESTED_DELIVERY_TIME_TO );
	}

	public function get_requested_delivery_time_label( WC_Order $order ): string {
		$value = $order->get_meta( self::REQUESTED_DELIVERY_TIME_LABEL, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$label = trim( sanitize_text_field( (string) $value ) );
		if ( '' !== $label ) {
			return $label;
		}

		$from = $this->get_requested_delivery_time_from( $order );
		$to   = $this->get_requested_delivery_time_to( $order );
		return '' !== $from && '' !== $to ? $from . '-' . $to : '';
	}

	private function get_requested_delivery_time( WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$time = sanitize_text_field( (string) $value );
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	/** @param array<string, mixed> $body */
	public function save_success( WC_Order $order, array $body, string $order_reference = '' ): void {
		$soocool_order_id = $this->extract_order_id( $body );
		if ( '' === $soocool_order_id ) {
			throw new \InvalidArgumentException( 'Missing valid SooCool order ID.' );
		}

		$order->update_meta_data( self::ORDER_ID, $soocool_order_id );

		$customer_reference = $this->extract_order_reference( $body, $order_reference );
		if ( '' !== $customer_reference ) {
			$order->update_meta_data( self::ORDER_REFERENCE, $customer_reference );
		}

		$good_ids = $this->extract_good_ids( $body );
		if ( array() !== $good_ids ) {
			$order->update_meta_data( self::GOOD_IDS, implode( ',', $good_ids ) );
		}

		$our_reference = '';
		if ( isset( $body['ourReference'] ) && ! is_array( $body['ourReference'] ) && ! is_object( $body['ourReference'] ) ) {
			$our_reference = sanitize_text_field( (string) $body['ourReference'] );
		} elseif ( '' !== $customer_reference ) {
			$our_reference = $customer_reference;
		}

		if ( '' !== $our_reference ) {
			$order->update_meta_data( self::OUR_REFERENCE, $our_reference );
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

	public function save_updated( WC_Order $order ): void {
		$order->update_meta_data( self::SYNC_STATUS, 'synced' );
		$order->update_meta_data( self::LAST_SYNCED_AT, current_time( 'mysql' ) );
		$order->delete_meta_data( self::LAST_ERROR );
		$order->save();
	}

	public function save_cancelled( WC_Order $order ): void {
		$order->update_meta_data( self::SYNC_STATUS, 'cancelled' );
		$order->delete_meta_data( self::LAST_ERROR );
		$order->save();
	}

	/** @param array<string, string> $data */
	public function save_webhook_update( WC_Order $order, array $data, bool $mark_webhook = true ): bool {
		$changed = false;

		$status = $this->normalize_sync_status( (string) ( $data['status'] ?? '' ) );
		if ( '' !== $status && (string) $order->get_meta( self::SYNC_STATUS, true ) !== $status ) {
			$order->update_meta_data( self::SYNC_STATUS, $status );
			$changed = true;
		}
		if ( '' !== ( $data['tracking_code'] ?? '' ) && (string) $order->get_meta( self::TRACKING_CODE, true ) !== $data['tracking_code'] ) {
			$order->update_meta_data( self::TRACKING_CODE, sanitize_text_field( $data['tracking_code'] ) );
			$changed = true;
		}
		if ( '' !== ( $data['tracking_url'] ?? '' ) && (string) $order->get_meta( self::TRACKING_URL, true ) !== $data['tracking_url'] ) {
			$order->update_meta_data( self::TRACKING_URL, esc_url_raw( $data['tracking_url'] ) );
			$changed = true;
		}

		if ( $mark_webhook ) {
			$order->update_meta_data( self::LAST_WEBHOOK_AT, current_time( 'mysql' ) );
		}
		$order->save();

		return $changed;
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

	public function get_our_reference( WC_Order $order ): string {
		$value = $order->get_meta( self::OUR_REFERENCE, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	public function get_order_reference( WC_Order $order ): string {
		$value = $order->get_meta( self::ORDER_REFERENCE, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	/** @return array<int, int> */
	public function get_good_ids( WC_Order $order ): array {
		$value = $order->get_meta( self::GOOD_IDS, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', sanitize_text_field( (string) $value ) ) as $good_id ) {
			$id = absint( $good_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** @param array<string, mixed> $body */
	private function extract_order_reference( array $body, string $fallback ): string {
		if ( isset( $body['orderReference'] ) && ! is_array( $body['orderReference'] ) && ! is_object( $body['orderReference'] ) ) {
			return sanitize_text_field( (string) $body['orderReference'] );
		}

		return '' !== $fallback ? sanitize_text_field( $fallback ) : '';
	}

	/** @param array<string, mixed> $body @return array<int, int> */
	private function extract_good_ids( array $body ): array {
		$ids = array();
		foreach ( array( $body, $body['order'] ?? null, $body['data'] ?? null ) as $container ) {
			if ( ! is_array( $container ) || ! isset( $container['goods'] ) || ! is_array( $container['goods'] ) ) {
				continue;
			}

			foreach ( $container['goods'] as $good ) {
				if ( ! is_array( $good ) ) {
					continue;
				}

				foreach ( array( 'goodId', 'id' ) as $key ) {
					if ( isset( $good[ $key ] ) && ! is_array( $good[ $key ] ) && ! is_object( $good[ $key ] ) ) {
						$id = absint( $good[ $key ] );
						if ( $id > 0 ) {
							$ids[] = $id;
						}
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
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

	private function normalize_sync_status( string $status ): string {
		$status = sanitize_key( $status );

		// Local terminal state follows manual cancel actions and filters.
		return 'soocool_cancelled' === $status ? 'cancelled' : $status;
	}
}
