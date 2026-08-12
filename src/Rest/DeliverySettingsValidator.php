<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class DeliverySettingsValidator {

	private const MAX_DELIVERY_RULES = 7;
	private const MAX_SLOTS_PER_RULE = 12;
	private const MAX_TIME_SLOTS = 84;
	private const MAX_SLOT_LABEL_CHARS = 80;

	public function sanitize_delivery_rules_for_rest( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$clean[] = array(
				'enabled'          => $this->bool_value( $rule['enabled'] ?? true, true ),
				'delivery_weekday' => sanitize_key( $this->scalar_string( $rule['delivery_weekday'] ?? null ) ),
				'cutoff_weekday'   => sanitize_key( $this->scalar_string( $rule['cutoff_weekday'] ?? null ) ),
				'cutoff_time'      => sanitize_text_field( $this->scalar_string( $rule['cutoff_time'] ?? null ) ),
			);
		}

		return $clean;
	}

	public function sanitize_delivery_time_slots_for_rest( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $index => $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$weekdays = array();
			if ( is_array( $slot['weekdays'] ?? null ) ) {
				foreach ( $slot['weekdays'] as $weekday ) {
					$weekdays[] = sanitize_key( $this->scalar_string( $weekday ) );
				}
			}

			$sanitized               = $this->sanitize_slot_for_rest( $slot );
			$sanitized['weekdays']   = array_values( array_unique( $weekdays ) );
			$sanitized['sort_order'] = is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : (int) $index;
			$clean[]                  = $sanitized;
		}

		return $clean;
	}

	public function sanitize_delivery_schedule_for_rest( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $rule_index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$slots = array();
			foreach ( is_array( $rule['slots'] ?? null ) ? $rule['slots'] : array() as $slot_index => $slot ) {
				if ( is_array( $slot ) ) {
					$sanitized               = $this->sanitize_slot_for_rest( $slot );
					$sanitized['sort_order'] = is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : ( (int) $slot_index + 1 ) * 10;
					$slots[]                  = $sanitized;
				}
			}

			$clean[] = array(
				'enabled'          => $this->bool_value( $rule['enabled'] ?? true, true ),
				'delivery_weekday' => sanitize_key( $this->scalar_string( $rule['delivery_weekday'] ?? null ) ),
				'cutoff_weekday'   => sanitize_key( $this->scalar_string( $rule['cutoff_weekday'] ?? null ) ),
				'cutoff_time'      => sanitize_text_field( $this->scalar_string( $rule['cutoff_time'] ?? null ) ),
				'sort_order'       => is_numeric( $rule['sort_order'] ?? null ) ? (int) $rule['sort_order'] : ( (int) $rule_index + 1 ) * 10,
				'slots'            => $slots,
			);
		}

		return $clean;
	}

	public function validate_delivery_schedule( mixed $value ): bool {
		if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_DELIVERY_RULES ) {
			return false;
		}

		$has_enabled_rule = false;
		$seen_weekdays    = array();
		foreach ( $value as $rule ) {
			if ( ! is_array( $rule ) ) {
				return false;
			}

			$validated_rule = $this->validated_rule( $rule );
			if ( null === $validated_rule || isset( $seen_weekdays[ $validated_rule['delivery_weekday'] ] ) ) {
				return false;
			}
			$seen_weekdays[ $validated_rule['delivery_weekday'] ] = true;

			$slots = is_array( $rule['slots'] ?? null ) ? $rule['slots'] : array();
			if ( array() === $slots || count( $slots ) > self::MAX_SLOTS_PER_RULE ) {
				return false;
			}

			$has_enabled_slot = false;
			$seen_slots        = array();
			foreach ( $slots as $slot ) {
				if ( ! is_array( $slot ) ) {
					return false;
				}

				$validated_slot = $this->validated_slot( $slot );
				if ( null === $validated_slot ) {
					return false;
				}

				$fingerprint = $validated_slot['time_from'] . '|' . $validated_slot['time_to'];
				if ( isset( $seen_slots[ $fingerprint ] ) ) {
					return false;
				}
				$seen_slots[ $fingerprint ] = true;

				if ( $this->bool_value( $slot['enabled'] ?? true, true ) ) {
					$has_enabled_slot = true;
				}
			}

			if ( $this->bool_value( $rule['enabled'] ?? true, true ) ) {
				if ( ! $has_enabled_slot ) {
					return false;
				}
				$has_enabled_rule = true;
			}
		}

		return $has_enabled_rule;
	}

	public function validate_delivery_time_slots( mixed $value ): bool {
		if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_TIME_SLOTS ) {
			return false;
		}

		$has_enabled = false;
		$seen        = array();
		foreach ( $value as $slot ) {
			if ( ! is_array( $slot ) ) {
				return false;
			}

			$validated_slot = $this->validated_slot( $slot );
			if ( null === $validated_slot ) {
				return false;
			}

			$weekdays = array();
			if ( is_array( $slot['weekdays'] ?? null ) ) {
				foreach ( $slot['weekdays'] as $weekday ) {
					$weekday = sanitize_key( $this->scalar_string( $weekday ) );
					if ( ! in_array( $weekday, $this->allowed_delivery_weekdays(), true ) ) {
						return false;
					}
					$weekdays[] = $weekday;
				}
			}
			$weekdays = array_values( array_unique( $weekdays ) );
			if ( $this->bool_value( $slot['enabled'] ?? true, true ) && array() === $weekdays ) {
				return false;
			}

			foreach ( $weekdays as $weekday ) {
				$fingerprint = $validated_slot['time_from'] . '|' . $validated_slot['time_to'] . '|' . $weekday;
				if ( isset( $seen[ $fingerprint ] ) ) {
					return false;
				}
				$seen[ $fingerprint ] = true;
			}

			if ( $this->bool_value( $slot['enabled'] ?? true, true ) ) {
				$has_enabled = true;
			}
		}

		return $has_enabled;
	}

	public function validate_requested_delivery_schedule_payload( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_schedule', $payload ) ) {
			return null;
		}

		if ( ! $this->validate_delivery_schedule( $payload['checkout_delivery_schedule'] ) ) {
			return new WP_Error( 'soocool_invalid_checkout_delivery_schedule', __( 'Het checkout-bezorgschema moet minimaal één ingeschakelde bezorgdag met een geldig ingeschakeld dagdeel bevatten.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	public function validate_requested_time_slots_payload( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_time_slots', $payload ) ) {
			return null;
		}

		if ( ! $this->validate_delivery_time_slots( $payload['checkout_delivery_time_slots'] ) ) {
			return new WP_Error( 'soocool_invalid_checkout_delivery_time_slots', __( 'Checkout-bezorgdagdelen moeten minimaal één ingeschakeld geldig dagdeel bevatten.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	public function validate_delivery_rules( mixed $value ): bool {
		if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_DELIVERY_RULES ) {
			return false;
		}

		$has_enabled = false;
		$seen        = array();
		foreach ( $value as $rule ) {
			if ( ! is_array( $rule ) ) {
				return false;
			}

			$validated_rule = $this->validated_rule( $rule );
			if ( null === $validated_rule || isset( $seen[ $validated_rule['delivery_weekday'] ] ) ) {
				return false;
			}
			$seen[ $validated_rule['delivery_weekday'] ] = true;

			if ( $this->bool_value( $rule['enabled'] ?? true, true ) ) {
				$has_enabled = true;
			}
		}

		return $has_enabled;
	}

	public function validate_requested_delivery_rules_payload( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_rules', $payload ) ) {
			return null;
		}

		if ( ! $this->validate_delivery_rules( $payload['checkout_delivery_rules'] ) ) {
			return new WP_Error( 'soocool_invalid_checkout_delivery_rules', __( 'Checkout-bezorgregels moeten minimaal één ingeschakelde regel met geldige weekdagen en cut-offtijd bevatten.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	public function allowed_delivery_weekdays(): array {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}

	private function sanitize_slot_for_rest( array $slot ): array {
		return array(
			'id'          => sanitize_key( $this->scalar_string( $slot['id'] ?? null ) ),
			'type'        => $this->slot_type_for_rest( $slot ),
			'enabled'     => $this->bool_value( $slot['enabled'] ?? true, true ),
			'label'       => sanitize_text_field( $this->scalar_string( $slot['label'] ?? null ) ),
			'time_from'   => sanitize_text_field( $this->scalar_string( $slot['time_from'] ?? null ) ),
			'time_to'     => sanitize_text_field( $this->scalar_string( $slot['time_to'] ?? null ) ),
			'cutoff_time' => sanitize_text_field( $this->scalar_string( $slot['cutoff_time'] ?? $slot['time_from'] ?? null ) ),
		);
	}

	private function validated_rule( array $rule ): ?array {
		$delivery_weekday = sanitize_key( $this->scalar_string( $rule['delivery_weekday'] ?? null ) );
		$cutoff_weekday   = sanitize_key( $this->scalar_string( $rule['cutoff_weekday'] ?? null ) );
		$cutoff_time      = sanitize_text_field( $this->scalar_string( $rule['cutoff_time'] ?? null ) );
		$allowed_weekdays = $this->allowed_delivery_weekdays();

		if ( ! in_array( $delivery_weekday, $allowed_weekdays, true ) || ! in_array( $cutoff_weekday, $allowed_weekdays, true ) || ! $this->is_time( $cutoff_time ) ) {
			return null;
		}

		return array(
			'delivery_weekday' => $delivery_weekday,
			'cutoff_weekday'   => $cutoff_weekday,
			'cutoff_time'      => $cutoff_time,
		);
	}

	private function validated_slot( array $slot ): ?array {
		$label       = trim( sanitize_text_field( $this->scalar_string( $slot['label'] ?? null ) ) );
		$time_from   = sanitize_text_field( $this->scalar_string( $slot['time_from'] ?? null ) );
		$time_to     = sanitize_text_field( $this->scalar_string( $slot['time_to'] ?? null ) );
		$cutoff_time = sanitize_text_field( $this->scalar_string( $slot['cutoff_time'] ?? $time_from, $time_from ) );

		if ( '' === $label || $this->text_length( $label ) > self::MAX_SLOT_LABEL_CHARS || ! $this->is_time( $time_from ) || ! $this->is_time( $time_to ) || ! $this->is_time( $cutoff_time ) || $time_to <= $time_from || $cutoff_time > $time_to ) {
			return null;
		}

		return array(
			'time_from'   => $time_from,
			'time_to'     => $time_to,
			'cutoff_time' => $cutoff_time,
		);
	}

	private function slot_type_for_rest( array $slot ): string {
		$type = sanitize_key( $this->scalar_string( $slot['type'] ?? $slot['id'] ?? null ) );
		if ( in_array( $type, array( 'daytime', 'evening' ), true ) ) {
			return $type;
		}

		$from = $this->scalar_string( $slot['time_from'] ?? null );
		$to   = $this->scalar_string( $slot['time_to'] ?? null );
		return '17:00' === $from && '22:00' === $to ? 'evening' : 'daytime';
	}

	private function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function is_time( string $value ): bool {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	private function scalar_string( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private function bool_value( mixed $value, bool $fallback ): bool {
		if ( null === $value ) {
			return $fallback;
		}

		if ( ! is_scalar( $value ) && ! is_bool( $value ) ) {
			return false;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}
}
