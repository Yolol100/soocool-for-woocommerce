<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SettingsValidator {

	private readonly DeliverySettingsValidator $delivery_validator;

	private const MAX_HOLIDAYS         = 366;

	public function __construct( private readonly OptionRepository $options ) {
		$this->delivery_validator = new DeliverySettingsValidator();
	}

	/** @param array<string, mixed> $payload */
	public function validate_payload( array $payload ): ?WP_Error {
		$array_fields = array( 'checkout_delivery_rules', 'checkout_delivery_time_slots', 'checkout_delivery_schedule' );
		foreach ( $payload as $key => $value ) {
			$is_array_field = in_array( (string) $key, $array_fields, true );
			if ( $is_array_field ? ! is_array( $value ) : ! is_scalar( $value ) ) {
				return new WP_Error( 'soocool_invalid_setting_type', __( 'Eén of meer SooCool-instellingen hebben een ongeldig gegevenstype.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}

		$length_error = $this->validate_scalar_lengths( $payload );
		if ( $length_error instanceof WP_Error ) {
			return $length_error;
		}

		if ( array_key_exists( 'pickup_email', $payload ) && ! $this->validate_email_or_empty( $payload['pickup_email'] ) ) {
			return new WP_Error( 'soocool_invalid_pickup_email', __( 'Vul een geldig ophaal-e-mailadres in of laat het veld leeg.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'checkout_delivery_holidays', $payload ) && ! $this->validate_holiday_dates( $payload['checkout_delivery_holidays'] ) ) {
			return new WP_Error( 'soocool_invalid_holidays', __( 'Gebruik voor feestdagen geldige datums in het formaat JJJJ-MM-DD, gescheiden door komma’s.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'packaging_type', $payload ) && ! $this->validate_packaging_type( $payload['packaging_type'] ) ) {
			return new WP_Error( 'soocool_invalid_packaging_type', __( 'Het verpakkingstype mag alleen kleine letters, cijfers, streepjes en underscores bevatten en maximaal 32 tekens lang zijn.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$raw_window_error = $this->validate_requested_time_windows( $payload );
		if ( $raw_window_error instanceof WP_Error ) {
			return $raw_window_error;
		}

		$pickup_offset_error = $this->validate_requested_pickup_offsets( $payload );
		if ( $pickup_offset_error instanceof WP_Error ) {
			return $pickup_offset_error;
		}

		$delivery_window_error = $this->validate_fixed_delivery_window( $payload );
		if ( $delivery_window_error instanceof WP_Error ) {
			return $delivery_window_error;
		}

		$delivery_rules_error = $this->delivery_validator->validate_requested_delivery_rules_payload( $payload );
		if ( $delivery_rules_error instanceof WP_Error ) {
			return $delivery_rules_error;
		}

		$schedule_error = $this->delivery_validator->validate_requested_delivery_schedule_payload( $payload );
		if ( $schedule_error instanceof WP_Error ) {
			return $schedule_error;
		}

		$time_slots_error = $this->delivery_validator->validate_requested_time_slots_payload( $payload );
		if ( $time_slots_error instanceof WP_Error ) {
			return $time_slots_error;
		}

		$settings = $this->options->preview_update( $payload );
		if ( (bool) $settings['enable_pickup'] && (int) $settings['pickup_days_offset'] > 29 ) {
			return new WP_Error( 'soocool_invalid_pickup_offset', __( 'Ophaaldatum-offset mag maximaal 29 dagen zijn, zodat de bezorgdatum altijd later kan vallen.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
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
	private function validate_requested_pickup_offsets( array $payload ): ?WP_Error {
		$current = $this->options->all();
		$enabled = $this->bool_value( $payload['enable_pickup'] ?? $current['enable_pickup'] ?? false, false );
		if ( ! $enabled ) {
			return null;
		}

		$pickup_offset   = absint( $payload['pickup_days_offset'] ?? $current['pickup_days_offset'] ?? 0 );
		$delivery_offset = absint( $payload['delivery_days_offset'] ?? $current['delivery_days_offset'] ?? 0 );
		if ( $pickup_offset > 29 ) {
			return new WP_Error( 'soocool_invalid_pickup_offset', __( 'Ophaaldatum-offset mag maximaal 29 dagen zijn, zodat de bezorgdatum altijd later kan vallen.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( $delivery_offset < 1 ) {
			return new WP_Error( 'soocool_invalid_delivery_offset', __( 'Bezorgdagen-offset moet minimaal 1 zijn wanneer ophaaltaken zijn ingeschakeld.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( $delivery_offset <= $pickup_offset ) {
			return new WP_Error( 'soocool_invalid_delivery_date', __( 'Bezorgdatum-offset moet later zijn dan de ophaaldatum-offset wanneer ophaaltaken zijn ingeschakeld.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
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
		$value = sanitize_text_field( $this->scalar_string( $value ) );

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
		$from = array_key_exists( 'delivery_time_from', $payload ) ? sanitize_text_field( $this->scalar_string( $payload['delivery_time_from'] ) ) : '08:00';
		$to   = array_key_exists( 'delivery_time_to', $payload ) ? sanitize_text_field( $this->scalar_string( $payload['delivery_time_to'] ) ) : '18:00';

		if ( $this->payload_touches_any( $payload, array( 'delivery_time_from', 'delivery_time_to' ) ) && ( '08:00' !== $from || '18:00' !== $to ) ) {
			return new WP_Error( 'soocool_invalid_delivery_window_fixed', __( 'Het fallback-bezorgvenster blijft 08:00-18:00. Het gekozen dagdeel uit Bezorgschema is leidend voor nieuwe checkout-orders.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
	}

	/** @param mixed $value @return array<int, array<string, mixed>> */
	public function sanitize_delivery_rules_for_rest( mixed $value ): array {
		return $this->delivery_validator->sanitize_delivery_rules_for_rest( $value );
	}

	/** @param mixed $value @return array<int, array<string, mixed>> */
	public function sanitize_delivery_time_slots_for_rest( mixed $value ): array {
		return $this->delivery_validator->sanitize_delivery_time_slots_for_rest( $value );
	}

	/** @param mixed $value @return array<int, array<string, mixed>> */
	public function sanitize_delivery_schedule_for_rest( mixed $value ): array {
		return $this->delivery_validator->sanitize_delivery_schedule_for_rest( $value );
	}

	public function validate_delivery_schedule( mixed $value ): bool {
		return $this->delivery_validator->validate_delivery_schedule( $value );
	}

	public function validate_delivery_time_slots( mixed $value ): bool {
		return $this->delivery_validator->validate_delivery_time_slots( $value );
	}

	public function validate_delivery_rules( mixed $value ): bool {
		return $this->delivery_validator->validate_delivery_rules( $value );
	}

	/** @return array<int, string> */
	public function allowed_delivery_weekdays(): array {
		return $this->delivery_validator->allowed_delivery_weekdays();
	}

	/** @param array<string, mixed> $payload */
	private function validate_scalar_lengths( array $payload ): ?WP_Error {
		$limits = array(
			'test_base_url'              => 2048,
			'production_base_url'        => 2048,
			'api_key'                    => 512,
			'test_api_key'               => 512,
			'production_api_key'         => 512,
			'order_reference_prefix'     => 32,
			'pickup_company'             => 200,
			'pickup_contact_name'        => 200,
			'pickup_email'               => 254,
			'pickup_phone'               => 40,
			'pickup_street'              => 200,
			'pickup_house_number'        => 32,
			'pickup_postal_code'         => 32,
			'pickup_city'                => 100,
			'checkout_delivery_holidays' => 5000,
			'checkout_delivery_fee_tax_class' => 200,
			'webhook_url'                => 2048,
			'goods_description_fallback' => 255,
			'packaging_type'             => 32,
		);

		foreach ( $limits as $key => $limit ) {
			if ( ! array_key_exists( $key, $payload ) || ! is_scalar( $payload[ $key ] ) ) {
				continue;
			}
			if ( $this->text_length( (string) $payload[ $key ] ) > $limit ) {
				return new WP_Error( 'soocool_setting_too_long', __( 'Eén of meer SooCool-instellingen bevatten te veel tekens.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}

		return null;
	}

	public function validate_email_or_empty( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = trim( (string) $value );
		return '' === $value || ( strlen( $value ) <= 254 && false !== is_email( $value ) );
	}

	public function validate_holiday_dates( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$dates = array_values( array_filter( preg_split( '/[\s,]+/', trim( (string) $value ) ) ?: array(), static fn ( string $date ): bool => '' !== $date ) );
		if ( count( $dates ) > self::MAX_HOLIDAYS ) {
			return false;
		}

		foreach ( $dates as $date ) {
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) || ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return false;
			}
		}

		return true;
	}

	public function validate_packaging_type( mixed $value ): bool {
		return is_scalar( $value ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/', (string) $value );
	}

	private function text_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		$characters = preg_match_all( '/./us', $value, $matches );

		return false === $characters ? strlen( $value ) : $characters;
	}

	private function scalar_string( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private function bool_value( mixed $value, bool $fallback ): bool {
		if ( ! is_scalar( $value ) && ! is_bool( $value ) ) {
			return $fallback;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? $fallback;
	}

	public function validate_environment( mixed $value ): bool {
		return in_array( $this->scalar_string( $value ), array( 'test', 'production' ), true );
	}

	public function validate_auto_submit_status( mixed $value ): bool {
		return in_array( $this->scalar_string( $value ), array( 'pending', 'processing', 'completed', 'on-hold' ), true );
	}

	public function validate_label_output( mixed $value ): bool {
		return in_array( $this->scalar_string( $value ), array( 'a6', 'collated_a4' ), true );
	}

	public function validate_temperature_regime( mixed $value ): bool {
		return in_array( $this->scalar_string( $value ), array( 'cooled', 'frozen', 'ambient' ), true );
	}

	public function validate_https_url_or_empty( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		return false !== wp_http_validate_url( $value );
	}

	public function validate_api_base_url_or_empty( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}

		$url = esc_url_raw( $value );
		return false !== wp_http_validate_url( $url ) && $this->options->is_allowed_api_url( $url );
	}

	public function validate_country( mixed $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-zA-Z]{2}$/', $value ) === 1;
	}
}
