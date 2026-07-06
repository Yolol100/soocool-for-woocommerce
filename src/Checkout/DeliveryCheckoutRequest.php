<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

defined( 'ABSPATH' ) || exit;

final class DeliveryCheckoutRequest {

	public const FIELD_DATE      = 'soocool_requested_delivery_date';
	public const FIELD_TIME_SLOT = 'soocool_requested_delivery_time_slot';

	public function posted_delivery_date(): string {
		$value = $this->posted_value( self::FIELD_DATE );

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/** @return array{time_from:string,time_to:string} */
	public function posted_time_slot(): array {
		$value = $this->posted_value( self::FIELD_TIME_SLOT );
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d\|([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return array( 'time_from' => '', 'time_to' => '' );
		}

		$parts = explode( '|', $value, 2 );
		return array(
			'time_from' => (string) ( $parts[0] ?? '' ),
			'time_to'   => (string) ( $parts[1] ?? '' ),
		);
	}

	public function posted_value( string $field ): string {
		if ( '' === $field ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce validates checkout nonces before checkout processing; update_order_review posts serialized checkout data and values are sanitized here.
		if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
		}

		$posted_data = $this->posted_checkout_data();
		if ( isset( $posted_data[ $field ] ) && is_scalar( $posted_data[ $field ] ) ) {
			return sanitize_text_field( (string) $posted_data[ $field ] );
		}

		return '';
	}

	/** @param array<int, array<string, mixed>> $options */
	public function selected_delivery_date( array $options ): string {
		$posted = $this->posted_delivery_date();
		foreach ( $options as $option ) {
			$date = (string) $option['date'];
			if ( '' !== $posted && $posted === $date ) {
				return $posted;
			}
		}

		return '';
	}

	/** @return array{time_from:string,time_to:string} */
	public function selected_time_slot( DeliverySchedule $schedule, string $current_date ): array {
		$empty = array( 'time_from' => '', 'time_to' => '' );
		if ( '' === $current_date ) {
			return $empty;
		}

		$slot = $this->posted_time_slot();
		if ( '' !== $slot['time_from'] && '' !== $slot['time_to'] && $schedule->is_valid_time_slot( $current_date, $slot['time_from'], $slot['time_to'] ) ) {
			return $slot;
		}

		return $empty;
	}

	/** @return array<string, mixed> */
	private function posted_checkout_data(): array {
		static $posted_data = null;

		if ( null !== $posted_data ) {
			return $posted_data;
		}

		$posted_data = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce update_order_review sends checkout form data in post_data; individual values are sanitized after parsing.
		$raw = isset( $_POST['post_data'] ) && is_scalar( $_POST['post_data'] ) ? wp_unslash( (string) $_POST['post_data'] ) : '';
		if ( '' === $raw ) {
			return $posted_data;
		}

		wp_parse_str( $raw, $parsed );
		if ( is_array( $parsed ) ) {
			$posted_data = $parsed;
		}

		return $posted_data;
	}
}
