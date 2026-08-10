<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Domain\RemoteStatusPolicy;
use SooCool\WooCommerce\Infrastructure\HttpsUrl;
use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
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
	public const LAST_WEBHOOK_EVENT_AT = '_soocool_last_webhook_event_at';
	public const LAST_WEBHOOK_EVENT_ID = '_soocool_last_webhook_event_id';
	public const LAST_WEBHOOK_SEQUENCE = '_soocool_last_webhook_sequence';
	public const WEBHOOK_EVENT_IDS = '_soocool_webhook_event_ids';
	public const TRACKING_CODE   = '_soocool_tracking_code';
	public const TRACKING_URL    = '_soocool_tracking_url';
	public const GOOD_IDS        = '_soocool_good_ids';
	public const REQUESTED_DELIVERY_DATE  = '_soocool_requested_delivery_date';
	public const REQUESTED_DELIVERY_LABEL = '_soocool_requested_delivery_label';
	public const REQUESTED_DELIVERY_TIME_FROM  = '_soocool_requested_delivery_time_from';
	public const REQUESTED_DELIVERY_TIME_TO    = '_soocool_requested_delivery_time_to';
	public const REQUESTED_DELIVERY_TIME_LABEL = '_soocool_requested_delivery_time_label';

	private const FAILURE_STATUSES = array( 'failed', 'soocool_failed', 'soocool_rejected' );
	private const MAX_WEBHOOK_EVENT_IDS = 50;

	private readonly RemoteStatusPolicy $statuses;

	public function __construct( ?RemoteStatusPolicy $statuses = null ) {
		$this->statuses = $statuses ?? new RemoteStatusPolicy();
	}

	/** @return array<int, string> */
	public static function failure_statuses(): array {
		return self::FAILURE_STATUSES;
	}

	public function get_sync_status( WC_Order $order ): string {
		return sanitize_key( $this->scalar_string( $order->get_meta( self::SYNC_STATUS, true ) ) );
	}

	public function get_last_error( WC_Order $order ): string {
		return trim( sanitize_text_field( $this->scalar_string( $order->get_meta( self::LAST_ERROR, true ) ) ) );
	}

	public function get_tracking_code( WC_Order $order ): string {
		return trim( sanitize_text_field( $this->scalar_string( $order->get_meta( self::TRACKING_CODE, true ) ) ) );
	}

	public function get_tracking_url( WC_Order $order ): string {
		return HttpsUrl::sanitize( $order->get_meta( self::TRACKING_URL, true ) );
	}

	public function get_requested_delivery_date( WC_Order $order ): string {
		$value = $order->get_meta( self::REQUESTED_DELIVERY_DATE, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$date = sanitize_text_field( (string) $value );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parts = array_map( 'intval', explode( '-', $date ) );
		return checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : '';
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

		$from = $this->get_requested_delivery_time_from( $order );
		$to   = $this->get_requested_delivery_time_to( $order );
		if ( '' !== $from && '' !== $to && $to <= $from ) {
			return '';
		}

		$label = trim( sanitize_text_field( (string) $value ) );
		if ( '' !== $label ) {
			return $label;
		}

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

	/**
	 * @param array<string, mixed> $body
	 * @param bool $preserve_existing_failure Keep an existing failure while a lookup/refresh
	 *                                        is still waiting for an authoritative remote status.
	 */
	public function save_success( WC_Order $order, array $body, string $order_reference = '', bool $preserve_existing_failure = false ): void {
		if ( method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}

		$previous_order_id = $this->get_soocool_order_id( $order );
		$soocool_order_id  = $this->extract_order_id( $body );
		if ( '' === $soocool_order_id ) {
			throw new \InvalidArgumentException( 'Missing valid SooCool order ID.' );
		}

		$same_remote_order        = '' !== $previous_order_id && hash_equals( $previous_order_id, $soocool_order_id );
		$remote_order_changed     = '' !== $previous_order_id && ! $same_remote_order;
		$current_sync_status      = $this->get_sync_status( $order );
		$preserve_failure_status  = $preserve_existing_failure && ! $remote_order_changed && in_array( $current_sync_status, array( 'soocool_failed', 'soocool_rejected' ), true );
		$preserve_workflow_status = ! $remote_order_changed && ( $preserve_failure_status || $this->has_nonfailure_authoritative_workflow_status( $order ) );

		if ( $remote_order_changed ) {
			foreach ( array( self::TRACKING_CODE, self::TRACKING_URL, self::GOOD_IDS, self::LAST_WEBHOOK_AT, self::LAST_WEBHOOK_EVENT_AT, self::LAST_WEBHOOK_EVENT_ID, self::LAST_WEBHOOK_SEQUENCE, self::WEBHOOK_EVENT_IDS ) as $remote_meta_key ) {
				$order->delete_meta_data( $remote_meta_key );
			}
		}

		$order->update_meta_data( self::ORDER_ID, $soocool_order_id );

		$customer_reference = $this->extract_order_reference( $body, $order_reference );
		if ( '' !== $customer_reference ) {
			$order->update_meta_data( self::ORDER_REFERENCE, $customer_reference );
		}

		$good_ids = $this->extract_good_ids( $body );
		if ( array() !== $good_ids ) {
			$order->update_meta_data( self::GOOD_IDS, implode( ',', $good_ids ) );
		} elseif ( $this->has_explicit_empty_goods_collection( $body ) ) {
			$order->delete_meta_data( self::GOOD_IDS );
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

		if ( ! $preserve_workflow_status ) {
			$order->update_meta_data( self::SYNC_STATUS, 'synced' );
		}
		$order->update_meta_data( self::LAST_SYNCED_AT, current_time( 'mysql' ) );
		if ( ! $preserve_failure_status ) {
			$order->delete_meta_data( self::LAST_ERROR );
		}
		$order->save();
		if ( ! $preserve_failure_status ) {
			do_action( 'soocool_order_synced', $order );
		}
	}

	public function save_pending( WC_Order $order ): void {
		if ( ! $this->has_authoritative_workflow_status( $order, true ) ) {
			$order->update_meta_data( self::SYNC_STATUS, 'pending' );
		}
		$order->save();
	}

	public function save_retry_pending( WC_Order $order, string $message ): void {
		if ( ! $this->has_authoritative_workflow_status( $order, true ) ) {
			$order->update_meta_data( self::SYNC_STATUS, 'pending' );
		}
		$order->update_meta_data( self::LAST_ERROR, sanitize_text_field( $message ) );
		$order->save();
	}

	public function save_updated( WC_Order $order ): void {
		$authoritative_status = $this->has_authoritative_workflow_status( $order, true );
		$current_status       = $this->get_sync_status( $order );
		$remote_failure       = in_array( $current_status, array( 'soocool_failed', 'soocool_rejected' ), true );

		if ( ! $authoritative_status ) {
			$order->update_meta_data( self::SYNC_STATUS, 'synced' );
		}
		$order->update_meta_data( self::LAST_SYNCED_AT, current_time( 'mysql' ) );
		if ( ! $remote_failure ) {
			$order->delete_meta_data( self::LAST_ERROR );
		}
		$order->save();
	}

	public function save_cancelled( WC_Order $order ): void {
		$order->update_meta_data( self::SYNC_STATUS, 'cancelled' );
		$order->delete_meta_data( self::LAST_ERROR );
		$order->save();
	}

	/** @param array<string, string> $data @param array<string, mixed> $event */
	public function save_webhook_update( WC_Order $order, array $data, bool $mark_webhook = true, array $event = array() ): bool {
		$result = $this->apply_webhook_update( $order, $data, $mark_webhook, $event );

		return $result['changed'];
	}

	/**
	 * @param array<string, string> $data
	 * @param array<string, mixed>  $event
	 * @return array{accepted: bool, changed: bool, reason: string}
	 */
	public function apply_webhook_update( WC_Order $order, array $data, bool $mark_webhook = true, array $event = array() ): array {
		if ( method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}

		$status                = $this->normalize_sync_status( $this->scalar_string( $data['status'] ?? '' ) );
		$tracking_code         = sanitize_text_field( $this->scalar_string( $data['tracking_code'] ?? '' ) );
		$tracking_url          = HttpsUrl::sanitize( $data['tracking_url'] ?? '' );
		$tracking_url_is_valid = '' !== $tracking_url;
		$current_status        = $this->get_sync_status( $order );
		$position              = $this->event_position( $order, $event );

		if ( '' !== $position['event_id'] && in_array( $position['event_id'], $this->webhook_event_ids( $order ), true ) ) {
			$this->save_webhook_receipt( $order, $mark_webhook );
			return array( 'accepted' => false, 'changed' => false, 'reason' => 'duplicate_event' );
		}

		if ( 0 > $position['comparison'] ) {
			$this->save_ignored_webhook_event( $order, $mark_webhook, $position['event_id'] );
			return array( 'accepted' => false, 'changed' => false, 'reason' => 'stale_event' );
		}
		if ( '' === $status && '' === $tracking_code && ! $tracking_url_is_valid ) {
			$this->save_ignored_webhook_event( $order, $mark_webhook, $position['event_id'] );
			return array( 'accepted' => false, 'changed' => false, 'reason' => 'no_actionable_data' );
		}
		if ( '' !== $status && ! $this->statuses->allows_transition( $current_status, $status ) ) {
			$this->save_ignored_webhook_event( $order, $mark_webhook, $position['event_id'] );
			return array( 'accepted' => false, 'changed' => false, 'reason' => 'regressive_status' );
		}

		$changed         = false;
		$metadata_changed = false;
		$status_advanced = '' !== $status && $status !== $current_status;
		$can_replace_tracking = 0 < $position['comparison'] || $status_advanced;

		if ( $status_advanced ) {
			$order->update_meta_data( self::SYNC_STATUS, $status );
			$changed = true;
		}

		$current_tracking_code = $this->get_tracking_code( $order );
		if ( '' !== $tracking_code && $current_tracking_code !== $tracking_code && ( '' === $current_tracking_code || $can_replace_tracking ) ) {
			$order->update_meta_data( self::TRACKING_CODE, $tracking_code );
			$changed = true;
		}

		$current_tracking_url = $this->get_tracking_url( $order );
		if ( $tracking_url_is_valid && $current_tracking_url !== $tracking_url && ( '' === $current_tracking_url || $can_replace_tracking ) ) {
			$order->update_meta_data( self::TRACKING_URL, $tracking_url );
			$changed = true;
		}

		$failure_status                 = in_array( $status, self::failure_statuses(), true );
		$successful_status_confirmation = '' !== $status && ! $failure_status;
		if ( $successful_status_confirmation && '' !== $this->get_last_error( $order ) ) {
			$order->delete_meta_data( self::LAST_ERROR );
			$changed = true;
		}

		if ( $position['incoming_has_position'] ) {
			$metadata_changed = $this->save_event_position( $order, $position ) || $metadata_changed;
		}
		$metadata_changed = $this->remember_webhook_event_id( $order, $position['event_id'] ) || $metadata_changed;
		if ( $mark_webhook ) {
			$order->update_meta_data( self::LAST_WEBHOOK_AT, current_time( 'mysql' ) );
			$metadata_changed = true;
		}
		if ( $changed || $metadata_changed ) {
			$order->save();
		}

		return array( 'accepted' => true, 'changed' => $changed, 'reason' => $changed ? 'applied' : 'no_change' );
	}

	public function save_error( WC_Order $order, string $message ): void {
		if ( ! $this->has_authoritative_workflow_status( $order, true ) ) {
			$order->update_meta_data( self::SYNC_STATUS, 'failed' );
		}
		$order->update_meta_data( self::LAST_ERROR, sanitize_text_field( $message ) );
		$order->save();
	}

	/** @param array<string, mixed> $body */
	public function extract_order_id( array $body ): string {
		$containers = $this->response_containers( $body );
		foreach ( array( array( 'orderId', 'soocoolOrderId' ), array( 'id' ) ) as $keys ) {
			$order_ids = array();
			foreach ( $containers as $container ) {
				foreach ( $keys as $key ) {
					if ( ! isset( $container[ $key ] ) || is_array( $container[ $key ] ) || is_object( $container[ $key ] ) ) {
						continue;
					}

					$order_id = $this->normalize_positive_id( $container[ $key ] );
					if ( null !== $order_id ) {
						$order_ids[ (string) $order_id ] = true;
					}
				}
			}

			if ( array() !== $order_ids ) {
				return 1 === count( $order_ids ) ? (string) array_key_first( $order_ids ) : '';
			}
		}

		return '';
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
			$id = $this->normalize_positive_id( $good_id );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** @param array<string, mixed> $body */
	private function extract_order_reference( array $body, string $fallback ): string {
		$containers = $this->response_containers( $body );
		foreach ( array( 'orderReference', 'ourReference', 'reference' ) as $key ) {
			foreach ( $containers as $container ) {
				if ( isset( $container[ $key ] ) && ! is_array( $container[ $key ] ) && ! is_object( $container[ $key ] ) ) {
					$reference = trim( sanitize_text_field( (string) $container[ $key ] ) );
					if ( '' !== $reference ) {
						return $reference;
					}
				}
			}
		}

		return '' !== $fallback ? sanitize_text_field( $fallback ) : '';
	}

	/** @param array<string, mixed> $body */
	private function has_explicit_empty_goods_collection( array $body ): bool {
		$found_empty = false;
		foreach ( $this->response_containers( $body ) as $container ) {
			if ( ! array_key_exists( 'goods', $container ) ) {
				continue;
			}

			if ( ! is_array( $container['goods'] ) || array() !== $container['goods'] ) {
				return false;
			}

			$found_empty = true;
		}

		return $found_empty;
	}

	/** @param array<string, mixed> $body @return array<int, int> */
	private function extract_good_ids( array $body ): array {
		$ids = array();
		foreach ( $this->response_containers( $body ) as $container ) {
			if ( ! is_array( $container ) || ! isset( $container['goods'] ) || ! is_array( $container['goods'] ) ) {
				continue;
			}

			foreach ( $container['goods'] as $good ) {
				if ( ! is_array( $good ) ) {
					continue;
				}

				foreach ( array( 'goodId', 'id' ) as $key ) {
					if ( ! isset( $good[ $key ] ) || is_array( $good[ $key ] ) || is_object( $good[ $key ] ) ) {
						continue;
					}

					$id = $this->normalize_positive_id( $good[ $key ] );
					if ( null !== $id ) {
						$ids[] = $id;
						break;
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** @param array<string, mixed> $body @return array<int, array<string, mixed>> */
	private function response_containers( array $body ): array {
		$containers = array( $body );

		foreach ( array( 'order', 'data' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				$containers[] = $body[ $key ];
			}
		}

		if ( isset( $body['data']['order'] ) && is_array( $body['data']['order'] ) ) {
			$containers[] = $body['data']['order'];
		}

		if ( isset( $body['order']['data'] ) && is_array( $body['order']['data'] ) ) {
			$containers[] = $body['order']['data'];
		}

		return $containers;
	}

	private function normalize_positive_id( mixed $value ): ?int {
		return NumericIdentifier::positive( $value );
	}

	public function get_soocool_order_id( WC_Order $order ): string {
		$value = $order->get_meta( self::ORDER_ID, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$order_id = $this->normalize_positive_id( $value );
		return null !== $order_id ? (string) $order_id : '';
	}

	public function is_synced( WC_Order $order ): bool {
		return '' !== $this->get_soocool_order_id( $order );
	}

	/**
	 * @param array<string, mixed> $event
	 * @return array{comparison: int, current_has_position: bool, incoming_has_position: bool, sequence: int, timestamp: int, event_id: string}
	 */
	private function event_position( WC_Order $order, array $event ): array {
		$current_sequence  = NumericIdentifier::positive( $order->get_meta( self::LAST_WEBHOOK_SEQUENCE, true ) ) ?? 0;
		$current_timestamp = NumericIdentifier::positive( $order->get_meta( self::LAST_WEBHOOK_EVENT_AT, true ) ) ?? 0;
		$current_event_id  = $this->sanitize_event_id( $order->get_meta( self::LAST_WEBHOOK_EVENT_ID, true ) );
		$sequence          = NumericIdentifier::positive( $event['sequence'] ?? null ) ?? 0;
		$timestamp         = NumericIdentifier::positive( $event['timestamp'] ?? null ) ?? 0;
		$event_id          = $this->sanitize_event_id( $event['event_id'] ?? null );
		$comparison        = 0;

		if ( 0 < $current_sequence || 0 < $sequence ) {
			if ( 0 < $current_sequence && 0 >= $sequence ) {
				$comparison = -1;
			} elseif ( 0 < $sequence && 0 >= $current_sequence ) {
				$comparison = 1;
			} else {
				$comparison = $sequence <=> $current_sequence;
				if ( 0 === $comparison && '' !== $current_event_id && '' !== $event_id && ! hash_equals( $current_event_id, $event_id ) ) {
					$comparison = -1;
				}
			}
		} elseif ( 0 < $current_timestamp || 0 < $timestamp ) {
			if ( 0 < $current_timestamp && 0 >= $timestamp ) {
				$comparison = -1;
			} elseif ( 0 < $timestamp && 0 >= $current_timestamp ) {
				$comparison = 1;
			} else {
				$comparison = $timestamp <=> $current_timestamp;
			}
		}

		return array(
			'comparison'             => $comparison,
			'current_has_position'   => 0 < $current_sequence || 0 < $current_timestamp,
			'incoming_has_position'  => 0 < $sequence || 0 < $timestamp,
			'sequence'               => $sequence,
			'timestamp'              => $timestamp,
			'event_id'               => $event_id,
		);
	}

	/** @param array{comparison: int, current_has_position: bool, incoming_has_position: bool, sequence: int, timestamp: int, event_id: string} $position */
	private function save_event_position( WC_Order $order, array $position ): bool {
		$changed = false;
		$current_sequence = NumericIdentifier::positive( $order->get_meta( self::LAST_WEBHOOK_SEQUENCE, true ) ) ?? 0;
		if ( $position['sequence'] > $current_sequence ) {
			$order->update_meta_data( self::LAST_WEBHOOK_SEQUENCE, $position['sequence'] );
			$changed = true;
		}
		$current_timestamp = NumericIdentifier::positive( $order->get_meta( self::LAST_WEBHOOK_EVENT_AT, true ) ) ?? 0;
		if ( $position['timestamp'] > $current_timestamp ) {
			$order->update_meta_data( self::LAST_WEBHOOK_EVENT_AT, $position['timestamp'] );
			$changed = true;
		}
		$current_event_id = $this->sanitize_event_id( $order->get_meta( self::LAST_WEBHOOK_EVENT_ID, true ) );
		$position_advanced = $position['sequence'] > $current_sequence || $position['timestamp'] > $current_timestamp;
		if ( '' !== $position['event_id'] && $position['event_id'] !== $current_event_id ) {
			$order->update_meta_data( self::LAST_WEBHOOK_EVENT_ID, $position['event_id'] );
			$changed = true;
		} elseif ( $position_advanced && '' === $position['event_id'] && '' !== $current_event_id ) {
			$order->delete_meta_data( self::LAST_WEBHOOK_EVENT_ID );
			$changed = true;
		}

		return $changed;
	}

	/** @return array<int, string> */
	private function webhook_event_ids( WC_Order $order ): array {
		$value = $order->get_meta( self::WEBHOOK_EVENT_IDS, true );
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( $value as $event_id ) {
			$event_id = $this->sanitize_event_id( $event_id );
			if ( '' !== $event_id ) {
				$ids[ $event_id ] = $event_id;
			}
		}

		return array_slice( array_values( $ids ), -self::MAX_WEBHOOK_EVENT_IDS );
	}

	private function remember_webhook_event_id( WC_Order $order, string $event_id ): bool {
		$event_id = $this->sanitize_event_id( $event_id );
		if ( '' === $event_id ) {
			return false;
		}

		$ids = $this->webhook_event_ids( $order );
		if ( in_array( $event_id, $ids, true ) ) {
			return false;
		}

		$ids[] = $event_id;
		$order->update_meta_data( self::WEBHOOK_EVENT_IDS, array_slice( $ids, -self::MAX_WEBHOOK_EVENT_IDS ) );
		return true;
	}

	private function save_ignored_webhook_event( WC_Order $order, bool $mark_webhook, string $event_id ): void {
		$changed = $this->remember_webhook_event_id( $order, $event_id );
		if ( $mark_webhook ) {
			$order->update_meta_data( self::LAST_WEBHOOK_AT, current_time( 'mysql' ) );
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
	}

	private function save_webhook_receipt( WC_Order $order, bool $mark_webhook ): void {
		if ( ! $mark_webhook ) {
			return;
		}

		$order->update_meta_data( self::LAST_WEBHOOK_AT, current_time( 'mysql' ) );
		$order->save();
	}

	private function sanitize_event_id( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		return 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $value ) ? $value : '';
	}

	private function has_authoritative_workflow_status( WC_Order $order, bool $refresh = false ): bool {
		if ( $refresh && method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}

		$status = $this->get_sync_status( $order );
		return 'cancelled' === $status || str_starts_with( $status, 'soocool_' );
	}

	private function has_nonfailure_authoritative_workflow_status( WC_Order $order, bool $refresh = false ): bool {
		if ( $refresh && method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}

		$status = $this->get_sync_status( $order );
		return ! in_array( $status, self::failure_statuses(), true ) && ( 'cancelled' === $status || str_starts_with( $status, 'soocool_' ) );
	}

	private function scalar_string( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private function normalize_sync_status( string $status ): string {
		$status = $this->statuses->normalize( $status );

		// Local terminal state follows manual cancel actions and filters.
		return 'soocool_cancelled' === $status ? 'cancelled' : $status;
	}
}
