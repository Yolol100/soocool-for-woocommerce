<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsValidator {

	public function __construct( private readonly OptionRepository $options ) {}

	/** @param array<string, mixed> $payload */
	public function validate_payload( array $payload ): ?WP_Error {
		$raw_window_error = $this->validate_requested_time_windows( $payload );
		if ( $raw_window_error instanceof WP_Error ) {
			return $raw_window_error;
		}

		$delivery_window_error = $this->validate_fixed_delivery_window( $payload );
		if ( $delivery_window_error instanceof WP_Error ) {
			return $delivery_window_error;
		}

		$delivery_rules_error = $this->validate_requested_delivery_rules_payload( $payload );
		if ( $delivery_rules_error instanceof WP_Error ) {
			return $delivery_rules_error;
		}

		$schedule_error = $this->validate_requested_delivery_schedule_payload( $payload );
		if ( $schedule_error instanceof WP_Error ) {
			return $schedule_error;
		}

		$time_slots_error = $this->validate_requested_time_slots_payload( $payload );
		if ( $time_slots_error instanceof WP_Error ) {
			return $time_slots_error;
		}

		$settings = $this->options->preview_update( $payload );
		if ( (bool) $settings['enable_pickup'] && (int) $settings['delivery_days_offset'] < 1 ) {
			return new WP_Error( 'soocool_invalid_delivery_offset', __( 'Bezorgdagen-offset moet minimaal 1 zijn wanneer ophaaltaken zijn ingeschakeld.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( (bool) $settings['enable_pickup'] && (int) $settings['delivery_days_offset'] <= (int) $settings['pickup_days_offset'] ) {
			return new WP_Error( 'soocool_invalid_delivery_date', __( 'Bezorgdatum-offset moet later zijn dan de ophaaldatum-offset wanneer ophaaltaken zijn ingeschakeld.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( (bool) $settings['enable_pickup'] && (string) $settings['pickup_time_to'] <= (string) $settings['pickup_time_from'] ) {
			return new WP_Error( 'soocool_invalid_pickup_window', __( 'Eindtijd van het ophaalvenster moet later zijn dan de starttijd van het ophaalvenster.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( (string) $settings['delivery_time_to'] <= (string) $settings['delivery_time_from'] ) {
			return new WP_Error( 'soocool_invalid_delivery_window', __( 'Eindtijd van het bezorgvenster moet later zijn dan de starttijd van het bezorgvenster.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	/** @param array<string, mixed> $payload */
	private function validate_requested_time_windows( array $payload ): ?WP_Error {
		$current = $this->options->all();

		$pickup_from = $this->normalized_requested_time( $payload, $current, 'pickup_time_from' );
		$pickup_to   = $this->normalized_requested_time( $payload, $current, 'pickup_time_to' );
		if ( $this->payload_touches_any( $payload, array( 'pickup_time_from', 'pickup_time_to' ) ) && '' !== $pickup_from && '' !== $pickup_to && $pickup_to <= $pickup_from ) {
			return new WP_Error( 'soocool_invalid_pickup_window', __( 'Eindtijd van het ophaalvenster moet later zijn dan de starttijd van het ophaalvenster.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$delivery_from = $this->normalized_requested_time( $payload, $current, 'delivery_time_from' );
		$delivery_to   = $this->normalized_requested_time( $payload, $current, 'delivery_time_to' );
		if ( $this->payload_touches_any( $payload, array( 'delivery_time_from', 'delivery_time_to' ) ) && '' !== $delivery_from && '' !== $delivery_to && $delivery_to <= $delivery_from ) {
			return new WP_Error( 'soocool_invalid_delivery_window', __( 'Eindtijd van het bezorgvenster moet later zijn dan de starttijd van het bezorgvenster.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	/** @param array<string, mixed> $payload @param array<string, mixed> $current */
	private function normalized_requested_time( array $payload, array $current, string $key ): string {
		$value = array_key_exists( $key, $payload ) ? $payload[ $key ] : ( $current[ $key ] ?? '' );
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function payload_touches_any( array $payload, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return true;
			}
		}

		return false;
	}


	/** @param array<string, mixed> $payload */
	private function validate_fixed_delivery_window( array $payload ): ?WP_Error {
		$from = array_key_exists( 'delivery_time_from', $payload ) ? sanitize_text_field( (string) $payload['delivery_time_from'] ) : '08:00';
		$to   = array_key_exists( 'delivery_time_to', $payload ) ? sanitize_text_field( (string) $payload['delivery_time_to'] ) : '18:00';

		if ( $this->payload_touches_any( $payload, array( 'delivery_time_from', 'delivery_time_to' ) ) && ( '08:00' !== $from || '18:00' !== $to ) ) {
			return new WP_Error( 'soocool_invalid_delivery_window_fixed', __( 'SooCool-bezorgtaken moeten voor deze koppeling exact het bezorgvenster 08:00-18:00 gebruiken.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}


	/** @param mixed $value @return array<int, array<string, mixed>> */
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
				'enabled'          => filter_var( $rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $rule['enabled'] ?? true ),
				'delivery_weekday' => sanitize_key( (string) ( $rule['delivery_weekday'] ?? '' ) ),
				'cutoff_weekday'   => sanitize_key( (string) ( $rule['cutoff_weekday'] ?? '' ) ),
				'cutoff_time'      => sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '' ) ),
			);
		}

		return $clean;
	}

	/** @param mixed $value @return array<int, array<string, mixed>> */
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
					$weekdays[] = sanitize_key( (string) $weekday );
				}
			}

			$clean[] = array(
				'enabled'     => filter_var( $slot['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $slot['enabled'] ?? true ),
				'label'       => sanitize_text_field( (string) ( $slot['label'] ?? '' ) ),
				'time_from'   => sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) ),
				'time_to'     => sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) ),
				'cutoff_time' => sanitize_text_field( (string) ( $slot['cutoff_time'] ?? $slot['time_from'] ?? '' ) ),
				'weekdays'    => array_values( array_unique( $weekdays ) ),
				'sort_order'  => is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : (int) $index,
			);
		}

		return $clean;
	}


	/** @param mixed $value @return array<int, array<string, mixed>> */
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
				if ( ! is_array( $slot ) ) {
					continue;
				}
				$slots[] = array(
					'enabled'     => filter_var( $slot['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $slot['enabled'] ?? true ),
					'label'       => sanitize_text_field( (string) ( $slot['label'] ?? '' ) ),
					'time_from'   => sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) ),
					'time_to'     => sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) ),
					'cutoff_time' => sanitize_text_field( (string) ( $slot['cutoff_time'] ?? $slot['time_from'] ?? '' ) ),
					'sort_order'  => is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : ( (int) $slot_index + 1 ) * 10,
				);
			}

			$clean[] = array(
				'enabled'          => filter_var( $rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $rule['enabled'] ?? true ),
				'delivery_weekday' => sanitize_key( (string) ( $rule['delivery_weekday'] ?? $rule['delivery_day'] ?? '' ) ),
				'cutoff_weekday'   => sanitize_key( (string) ( $rule['cutoff_weekday'] ?? $rule['cutoff_day'] ?? '' ) ),
				'cutoff_time'      => sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '' ) ),
				'sort_order'       => is_numeric( $rule['sort_order'] ?? null ) ? (int) $rule['sort_order'] : ( (int) $rule_index + 1 ) * 10,
				'slots'            => $slots,
			);
		}

		return $clean;
	}

	public function validate_delivery_schedule( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$has_enabled_rule = false;
		foreach ( $value as $rule ) {
			if ( ! is_array( $rule ) ) {
				return false;
			}

			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? $rule['delivery_day'] ?? '' ) );
			$cutoff_weekday   = sanitize_key( (string) ( $rule['cutoff_weekday'] ?? $rule['cutoff_day'] ?? '' ) );
			$cutoff_time      = sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '' ) );
			if ( ! in_array( $delivery_weekday, $this->allowed_delivery_weekdays(), true ) || ! in_array( $cutoff_weekday, $this->allowed_delivery_weekdays(), true ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff_time ) ) {
				return false;
			}

			$slots = is_array( $rule['slots'] ?? null ) ? $rule['slots'] : array();
			if ( array() === $slots ) {
				return false;
			}

			$has_enabled_slot = false;
			foreach ( $slots as $slot ) {
				if ( ! is_array( $slot ) ) {
					return false;
				}
				$time_from   = sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) );
				$time_to     = sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) );
				$cutoff_slot = sanitize_text_field( (string) ( $slot['cutoff_time'] ?? $time_from ) );
				if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time_from ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time_to ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff_slot ) || $time_to <= $time_from ) {
					return false;
				}
				if ( filter_var( $slot['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $slot['enabled'] ?? true ) ) {
					$has_enabled_slot = true;
				}
			}

			if ( filter_var( $rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $rule['enabled'] ?? true ) ) {
				if ( ! $has_enabled_slot ) {
					return false;
				}
				$has_enabled_rule = true;
			}
		}

		return $has_enabled_rule;
	}

	public function validate_delivery_time_slots( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$has_enabled = false;
		foreach ( $value as $slot ) {
			if ( ! is_array( $slot ) ) {
				return false;
			}

			$time_from   = sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) );
			$time_to     = sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) );
			$cutoff_time = sanitize_text_field( (string) ( $slot['cutoff_time'] ?? $time_from ) );
			if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time_from ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time_to ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff_time ) || $time_to <= $time_from ) {
				return false;
			}

			if ( is_array( $slot['weekdays'] ?? null ) ) {
				foreach ( $slot['weekdays'] as $weekday ) {
					if ( ! in_array( sanitize_key( (string) $weekday ), $this->allowed_delivery_weekdays(), true ) ) {
						return false;
					}
				}
			}

			$enabled = filter_var( $slot['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $slot['enabled'] ?? true );
			if ( $enabled ) {
				$has_enabled = true;
			}
		}

		return $has_enabled;
	}


	/** @param array<string, mixed> $payload */
	private function validate_requested_delivery_schedule_payload( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_schedule', $payload ) ) {
			return null;
		}

		if ( ! $this->validate_delivery_schedule( $payload['checkout_delivery_schedule'] ) ) {
			return new WP_Error( 'soocool_invalid_checkout_delivery_schedule', __( 'Het checkout-bezorgschema moet minimaal één ingeschakelde bezorgdag met een geldig ingeschakeld tijdslot bevatten.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	/** @param array<string, mixed> $payload */
	private function validate_requested_time_slots_payload( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_time_slots', $payload ) ) {
			return null;
		}

		if ( ! $this->validate_delivery_time_slots( $payload['checkout_delivery_time_slots'] ) ) {
			return new WP_Error( 'soocool_invalid_checkout_delivery_time_slots', __( 'Checkout-bezorgtijdsloten moeten minimaal één ingeschakeld geldig tijdslot bevatten.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	public function validate_delivery_rules( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$has_enabled = false;
		foreach ( $value as $rule ) {
			if ( ! is_array( $rule ) ) {
				return false;
			}

			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? '' ) );
			$cutoff_weekday   = sanitize_key( (string) ( $rule['cutoff_weekday'] ?? '' ) );
			$cutoff_time      = sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '' ) );
			if ( ! in_array( $delivery_weekday, $this->allowed_delivery_weekdays(), true ) || ! in_array( $cutoff_weekday, $this->allowed_delivery_weekdays(), true ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff_time ) ) {
				return false;
			}

			$enabled = filter_var( $rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) ( $rule['enabled'] ?? true );
			if ( $enabled ) {
				$has_enabled = true;
			}
		}

		return $has_enabled;
	}

	/** @param array<string, mixed> $payload */
	private function validate_requested_delivery_rules_payload( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_rules', $payload ) ) {
			return null;
		}

		if ( ! $this->validate_delivery_rules( $payload['checkout_delivery_rules'] ) ) {
			return new WP_Error( 'soocool_invalid_checkout_delivery_rules', __( 'Checkout-bezorgregels moeten minimaal één ingeschakelde regel met geldige weekdagen en cut-offtijd bevatten.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	/** @return array<int, string> */
	public function allowed_delivery_weekdays(): array {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}

	public function validate_environment( mixed $value ): bool {
		return in_array( (string) $value, array( 'test', 'production' ), true );
	}

	public function validate_auto_submit_status( mixed $value ): bool {
		return in_array( (string) $value, array( 'processing', 'completed', 'on-hold' ), true );
	}

	public function validate_label_output( mixed $value ): bool {
		return in_array( (string) $value, array( 'a6', 'collated_a4' ), true );
	}

	public function validate_temperature_regime( mixed $value ): bool {
		return in_array( (string) $value, array( 'cooled', 'frozen', 'ambient' ), true );
	}

	public function validate_https_url_or_empty( mixed $value ): bool {
		if ( '' === (string) $value ) {
			return true;
		}

		return str_starts_with( (string) $value, 'https://' ) && false !== wp_http_validate_url( (string) $value );
	}

	public function validate_api_base_url_or_empty( mixed $value ): bool {
		if ( '' === (string) $value ) {
			return true;
		}

		$url = esc_url_raw( (string) $value );
		return false !== wp_http_validate_url( $url ) && $this->options->is_allowed_api_url( $url );
	}

	public function validate_country( mixed $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-zA-Z]{2}$/', $value ) === 1;
	}
}
