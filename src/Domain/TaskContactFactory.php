<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class TaskContactFactory {

	/** @return array<string, string> */
	public function for_delivery_order( WC_Order $order ): array {
		$info = $this->from_email_phone(
			sanitize_email( $order->get_billing_email() ),
			sanitize_text_field( $order->get_billing_phone() )
		);

		/**
		 * @param array<string, string> $info
		 * @param WC_Order             $order
		 */
		$info = apply_filters( 'soocool_task_contact_info', $info, $order );
		$info = is_array( $info ) ? $info : array();

		return $this->sanitize( $info );
	}

	/** @return array<string, string> */
	public function from_email_phone( string $email, string $phone ): array {
		$phone = $this->normalize_phone_number( $phone );
		$info  = array( 'email' => sanitize_email( $email ) );

		if ( '' !== $phone && $this->looks_like_phone_number( $phone ) ) {
			$info['phone'] = $phone;
		}

		if ( '' !== $phone && $this->looks_like_mobile( $phone ) ) {
			$info['mobile'] = $phone;
		}

		return $this->compact( $info );
	}

	/** @param array<string, mixed> $info @return array<string, string> */
	private function sanitize( array $info ): array {
		$clean = array();

		if ( isset( $info['email'] ) ) {
			$clean['email'] = sanitize_email( (string) $info['email'] );
		}

		foreach ( array( 'phone', 'mobile' ) as $key ) {
			if ( ! isset( $info[ $key ] ) ) {
				continue;
			}

			$phone = $this->normalize_phone_number( sanitize_text_field( (string) $info[ $key ] ) );
			if ( 'mobile' === $key && ! $this->looks_like_mobile( $phone ) ) {
				continue;
			}
			if ( '' !== $phone && $this->looks_like_phone_number( $phone ) ) {
				$clean[ $key ] = $phone;
			}
		}

		return $this->compact( $clean );
	}

	private function normalize_phone_number( string $phone ): string {
		$phone = preg_replace( '/[^\d+]/', '', $phone ) ?? '';
		if ( 1 === preg_match( '/^0(\d{9})$/', $phone, $matches ) ) {
			return '+31' . $matches[1];
		}
		if ( 1 === preg_match( '/^31(\d{9})$/', $phone, $matches ) ) {
			return '+31' . $matches[1];
		}
		return sanitize_text_field( $phone );
	}

	private function looks_like_mobile( string $phone ): bool {
		$normalized = (string) preg_replace( '/[^\d+]/', '', $phone );
		$normalized = ltrim( $normalized, '+' );

		return 1 === preg_match( '/^(?:31|0)6\d{8}$/', $normalized );
	}

	private function looks_like_phone_number( string $phone ): bool {
		$normalized = (string) preg_replace( '/[^\d+]/', '', $phone );
		return 1 === preg_match( '/^\+?\d{10,15}$/', $normalized );
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function compact( array $values ): array {
		return array_filter( $values, static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value );
	}
}
