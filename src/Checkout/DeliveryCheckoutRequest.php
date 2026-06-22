<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

defined( 'ABSPATH' ) || exit;

final class DeliveryCheckoutRequest {

	public const FIELD_DATE      = 'soocool_requested_delivery_date';
	public const FIELD_TIME_SLOT = 'soocool_requested_delivery_time_slot';

	public function posted_delivery_date(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce validates the checkout nonce before processing checkout fields; value is sanitized immediately on this line.
		$value = isset( $_POST[ self::FIELD_DATE ] ) && is_scalar( $_POST[ self::FIELD_DATE ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_DATE ] ) ) : '';

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/** @return array{time_from:string,time_to:string} */
	public function posted_time_slot(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce validates the checkout nonce before processing checkout fields; value is sanitized immediately on this line.
		$value = isset( $_POST[ self::FIELD_TIME_SLOT ] ) && is_scalar( $_POST[ self::FIELD_TIME_SLOT ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_TIME_SLOT ] ) ) : '';
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d\|([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return array( 'time_from' => '', 'time_to' => '' );
		}

		$parts = explode( '|', $value, 2 );
		return array(
			'time_from' => (string) ( $parts[0] ?? '' ),
			'time_to'   => (string) ( $parts[1] ?? '' ),
		);
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
}
