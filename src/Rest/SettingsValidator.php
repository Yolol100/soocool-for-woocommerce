<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Domain\TaskContactFactory;
use SooCool\WooCommerce\Infrastructure\ApiCredentialResolver;
use SooCool\WooCommerce\Infrastructure\OptionDefaults;
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

		$api_key_resolver = new ApiCredentialResolver();
		foreach ( array( 'api_key', 'test_api_key', 'production_api_key' ) as $api_key_field ) {
			if ( ! array_key_exists( $api_key_field, $payload ) ) {
				continue;
			}

			$raw_api_key = trim( sanitize_text_field( $this->scalar_string( $payload[ $api_key_field ] ) ) );
			if ( '' !== $raw_api_key && ! $api_key_resolver->is_masked_or_invalid_secret( $raw_api_key ) && '' === $api_key_resolver->normalize_secret( $raw_api_key ) ) {
				return new WP_Error( 'soocool_invalid_api_key', __( 'De opgeslagen API-key is ongeldig of bevat nog een gemaskeerde waarde. Plak de echte SooCool API-key en sla opnieuw op.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}

		if ( array_key_exists( 'pickup_email', $payload ) && ! $this->validate_email_or_empty( $payload['pickup_email'] ) ) {
			return new WP_Error( 'soocool_invalid_pickup_email', __( 'Vul een geldig ophaal-e-mailadres in of laat het veld leeg.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'pickup_phone', $payload ) && ! $this->validate_phone_or_empty( $payload['pickup_phone'] ) ) {
			return new WP_Error( 'soocool_invalid_pickup_phone', __( 'Vul een geldig ophaaltelefoonnummer in of laat het veld leeg.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$pickup_error = $this->validate_effective_pickup_settings( $payload );
		if ( $pickup_error instanceof WP_Error ) {
			return $pickup_error;
		}

		if ( array_key_exists( 'checkout_delivery_holidays', $payload ) && ! $this->validate_holiday_dates( $payload['checkout_delivery_holidays'] ) ) {
			return new WP_Error( 'soocool_invalid_holidays', __( 'Gebruik voor feestdagen geldige datums in het formaat JJJJ-MM-DD, gescheiden door komma’s.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'packaging_type', $payload ) && ! $this->validate_packaging_type( $payload['packaging_type'] ) ) {
			return new WP_Error( 'soocool_invalid_packaging_type', __( 'Het verpakkingstype mag alleen kleine letters, cijfers, streepjes en underscores bevatten en maximaal 32 tekens lang zijn.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$fixed_rule_error = $this->validate_fixed_integration_settings( $payload );
		if ( $fixed_rule_error instanceof WP_Error ) {
			return $fixed_rule_error;
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

		$representation_error = $this->validate_delivery_schedule_representations( $payload );
		if ( $representation_error instanceof WP_Error ) {
			return $representation_error;
		}

		return null;
	}

	/** @param array<string, mixed> $payload */
	private function validate_fixed_integration_settings( array $payload ): ?WP_Error {
		$fixed = array(
			'enable_pickup'       => OptionDefaults::PICKUP_ENABLED,
			'pickup_time_from'    => OptionDefaults::PICKUP_TIME_FROM,
			'pickup_time_to'      => OptionDefaults::PICKUP_TIME_TO,
			'delivery_time_from'  => OptionDefaults::DELIVERY_TIME_FROM,
			'delivery_time_to'    => OptionDefaults::DELIVERY_TIME_TO,
			'auto_submit_enabled' => OptionDefaults::AUTO_SUBMIT_ENABLED,
			'auto_submit_status'  => OptionDefaults::AUTO_SUBMIT_STATUS,
		);

		foreach ( $fixed as $key => $expected ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}

			$actual = $payload[ $key ];
			if ( is_bool( $expected ) ) {
				$actual = is_scalar( $actual ) ? filter_var( $actual, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;
			} else {
				$actual = sanitize_text_field( $this->scalar_string( $actual ) );
			}

			if ( $actual !== $expected ) {
				return new WP_Error( 'soocool_fixed_integration_setting', __( 'Ophalen, de vaste tijdvensters en automatische inzending zijn vaste SooCool-integratieregels en kunnen niet via de instellingen-API worden gewijzigd.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}

		return null;
	}

	/** @param array<string, mixed> $payload */
	private function validate_delivery_schedule_representations( array $payload ): ?WP_Error {
		if ( ! array_key_exists( 'checkout_delivery_schedule', $payload ) ) {
			return null;
		}
		if ( ! array_key_exists( 'checkout_delivery_rules', $payload ) && ! array_key_exists( 'checkout_delivery_time_slots', $payload ) ) {
			return null;
		}

		$canonical = $this->options->preview_update(
			array( 'checkout_delivery_schedule' => $payload['checkout_delivery_schedule'] )
		);

		if ( array_key_exists( 'checkout_delivery_rules', $payload ) ) {
			$requested_rules = $this->normalized_rules_for_compare( $payload['checkout_delivery_rules'] );
			$canonical_rules = $this->normalized_rules_for_compare( $canonical['checkout_delivery_rules'] ?? array() );
			if ( $requested_rules !== $canonical_rules ) {
				return $this->conflicting_delivery_schedule_error();
			}
		}

		if ( array_key_exists( 'checkout_delivery_time_slots', $payload ) ) {
			$rule_weekdays   = array_column( $this->normalized_rules_for_compare( $canonical['checkout_delivery_rules'] ?? array() ), 'delivery_weekday' );
			$requested_slots = $this->normalized_slots_for_compare( $payload['checkout_delivery_time_slots'], $rule_weekdays );
			$canonical_slots = $this->normalized_slots_for_compare( $canonical['checkout_delivery_time_slots'] ?? array(), $rule_weekdays );
			if ( $requested_slots !== $canonical_slots ) {
				return $this->conflicting_delivery_schedule_error();
			}
		}

		return null;
	}

	/** @return array<int, array<string, mixed>> */
	private function normalized_rules_for_compare( mixed $value ): array {
		$rules = $this->sanitize_delivery_rules_for_rest( $value );
		usort( $rules, static fn ( array $a, array $b ): int => strcmp( (string) $a['delivery_weekday'], (string) $b['delivery_weekday'] ) );

		return array_values( $rules );
	}

	/** @param array<int, string> $allowed_weekdays @return array<int, array<string, mixed>> */
	private function normalized_slots_for_compare( mixed $value, array $allowed_weekdays ): array {
		$slots = $this->sanitize_delivery_time_slots_for_rest( $value );
		$rows  = array();
		foreach ( $slots as $slot ) {
			$weekdays = is_array( $slot['weekdays'] ?? null ) ? $slot['weekdays'] : array();
			foreach ( $weekdays as $weekday ) {
				$weekday = sanitize_key( $this->scalar_string( $weekday ) );
				if ( ! in_array( $weekday, $allowed_weekdays, true ) ) {
					continue;
				}
				$rows[] = array(
					'weekday'    => $weekday,
					'id'         => (string) ( $slot['id'] ?? '' ),
					'type'       => (string) ( $slot['type'] ?? '' ),
					'enabled'    => (bool) ( $slot['enabled'] ?? true ),
					'label'      => (string) ( $slot['label'] ?? '' ),
					'time_from'  => (string) ( $slot['time_from'] ?? '' ),
					'time_to'    => (string) ( $slot['time_to'] ?? '' ),
					'cutoff_time' => (string) ( $slot['cutoff_time'] ?? '' ),
					'sort_order' => (int) ( $slot['sort_order'] ?? 0 ),
				);
			}
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => strcmp( implode( '|', array_map( 'strval', $a ) ), implode( '|', array_map( 'strval', $b ) ) )
		);

		return array_values( $rows );
	}

	private function conflicting_delivery_schedule_error(): WP_Error {
		return new WP_Error( 'soocool_conflicting_delivery_schedule', __( 'Het nieuwe bezorgschema en de meegestuurde legacy bezorgregels of dagdelen spreken elkaar tegen. Stuur één representatie of gelijkwaardige waarden.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
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

	public function validate_phone_or_empty( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$phone = trim( (string) $value );
		if ( '' === $phone ) {
			return true;
		}

		$contact = ( new TaskContactFactory() )->from_email_phone( '', $phone, 'NL' );
		return isset( $contact['phone'] ) && '' !== (string) $contact['phone'];
	}

	/** @param array<string, mixed> $payload */
	private function validate_effective_pickup_settings( array $payload ): ?WP_Error {
		$pickup_fields = array( 'pickup_company', 'pickup_contact_name', 'pickup_email', 'pickup_phone', 'pickup_street', 'pickup_house_number', 'pickup_postal_code', 'pickup_city', 'pickup_country' );
		if ( array() === array_intersect( $pickup_fields, array_keys( $payload ) ) ) {
			return null;
		}

		$current = $this->options->all();
		$effective = static function ( string $key ) use ( $payload, $current ): string {
			$value = array_key_exists( $key, $payload ) ? $payload[ $key ] : ( $current[ $key ] ?? '' );
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		foreach ( array( 'pickup_company', 'pickup_street', 'pickup_house_number', 'pickup_postal_code', 'pickup_city', 'pickup_country' ) as $required ) {
			if ( '' === $effective( $required ) ) {
				return new WP_Error( 'soocool_incomplete_pickup_address', __( 'Vul het volledige ophaaladres in voordat je de ophaalinstellingen opslaat.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
			}
		}

		$email   = $effective( 'pickup_email' );
		$phone   = $effective( 'pickup_phone' );
		$country = $effective( 'pickup_country' );
		$contact = ( new TaskContactFactory() )->from_email_phone( $email, $phone, $country );
		if ( array() === $contact ) {
			return new WP_Error( 'soocool_incomplete_pickup_contact', __( 'Vul voor de ophaallocatie een geldig e-mailadres of telefoonnummer in.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return null;
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

	public function validate_environment( mixed $value ): bool {
		return in_array( $this->scalar_string( $value ), array( 'test', 'production' ), true );
	}

	public function validate_auto_submit_status( mixed $value ): bool {
		return OptionDefaults::AUTO_SUBMIT_STATUS === $this->scalar_string( $value );
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
