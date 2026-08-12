<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionDefaults;

defined( 'ABSPATH' ) || exit;

final class SettingsSchema {

	public function __construct( private readonly SettingsValidator $validator ) {}

	/** @return array<string, array<string, mixed>> */
	public function args(): array {
		$text           = static fn ( $value ): string => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		$key            = static fn ( $value ): string => is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		$bool           = static fn ( $value ): bool => is_scalar( $value ) ? ( filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false ) : false;
		$bool_validate  = static function ( $value ): bool {
			if ( is_bool( $value ) ) {
				return true;
			}
			if ( is_int( $value ) ) {
				return 0 === $value || 1 === $value;
			}
			if ( ! is_string( $value ) ) {
				return false;
			}

			return in_array( strtolower( trim( $value ) ), array( '0', '1', 'false', 'true' ), true );
		};
		$fixed_bool_validate = static fn ( bool $expected ): callable => static function ( $value ) use ( $expected ): bool {
			if ( is_bool( $value ) ) {
				return $value === $expected;
			}
			if ( is_int( $value ) && ( 0 === $value || 1 === $value ) ) {
				return (bool) $value === $expected;
			}
			if ( ! is_string( $value ) || ! in_array( strtolower( trim( $value ) ), array( '0', '1', 'false', 'true' ), true ) ) {
				return false;
			}

			return ( filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? ! $expected ) === $expected;
		};
		$fixed_text_validate = static fn ( string $expected ): callable => static fn ( $value ): bool => is_string( $value ) && sanitize_text_field( $value ) === $expected;
		$int            = static fn ( $value ): int => is_scalar( $value ) ? max( 0, absint( $value ) ) : 0;
		$money          = static fn ( $value ): float => is_scalar( $value ) ? round( (float) str_replace( ',', '.', sanitize_text_field( (string) $value ) ), 2 ) : 0.0;
		$money_validate = static function ( $value ): bool {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				return false;
			}

			$raw = trim( (string) $value );
			if ( 1 !== preg_match( '/^\d+(?:[.,]\d{0,2})?$/', $raw ) ) {
				return false;
			}

			$amount = (float) str_replace( ',', '.', $raw );
			return $amount >= 0 && $amount <= 999;
		};
		$range_validate = static fn ( int $min, int $max ): callable => static function ( $value ) use ( $min, $max ): bool {
			if ( is_int( $value ) ) {
				$integer = $value;
			} elseif ( is_string( $value ) && 1 === preg_match( '/^\d+$/', trim( $value ) ) ) {
				$integer = (int) trim( $value );
			} else {
				return false;
			}

			return $integer >= $min && $integer <= $max;
		};
		$time_slot_schema     = $this->time_slot_schema();
		$delivery_rule_schema = $this->delivery_rule_schema();
		$schedule_slot_schema = $this->schedule_slot_schema();
		$schedule_rule_schema = $this->schedule_rule_schema( $schedule_slot_schema );

		return array(
			'environment'                => array(
				'type'              => 'string',
				'enum'              => array( 'test', 'production' ),
				'required'          => false,
				'sanitize_callback' => $key,
				'validate_callback' => array( $this->validator, 'validate_environment' ),
			),
			'test_base_url'              => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this->validator, 'validate_api_base_url_or_empty' ),
			),
			'production_base_url'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this->validator, 'validate_api_base_url_or_empty' ),
			),
			'api_key'                    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'test_api_key'               => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'production_api_key'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'clear_active_api_key'       => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $bool_validate,
			),
			'enable_pickup'              => array(
				'type'              => 'boolean',
				'enum'              => array( OptionDefaults::PICKUP_ENABLED ),
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $fixed_bool_validate( OptionDefaults::PICKUP_ENABLED ),
			),
			'order_reference_prefix'     => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
			),
			'pickup_company'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'pickup_contact_name'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'pickup_email'               => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => array( $this->validator, 'validate_email_or_empty' ),
			),
			'pickup_phone'               => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => array( $this->validator, 'validate_phone_or_empty' ),
			),
			'pickup_street'              => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'pickup_house_number'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'pickup_postal_code'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'pickup_city'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'pickup_country'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
				'validate_callback' => array( $this->validator, 'validate_country' ),
			),
			'pickup_time_from'           => array(
				'type'              => 'string',
				'enum'              => array( OptionDefaults::PICKUP_TIME_FROM ),
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $fixed_text_validate( OptionDefaults::PICKUP_TIME_FROM ),
			),
			'pickup_time_to'             => array(
				'type'              => 'string',
				'enum'              => array( OptionDefaults::PICKUP_TIME_TO ),
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $fixed_text_validate( OptionDefaults::PICKUP_TIME_TO ),
			),
			'delivery_time_from'         => array(
				'type'              => 'string',
				'enum'              => array( OptionDefaults::DELIVERY_TIME_FROM ),
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $fixed_text_validate( OptionDefaults::DELIVERY_TIME_FROM ),
			),
			'delivery_time_to'           => array(
				'type'              => 'string',
				'enum'              => array( OptionDefaults::DELIVERY_TIME_TO ),
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $fixed_text_validate( OptionDefaults::DELIVERY_TIME_TO ),
			),
			'delivery_days_offset'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 1, 30 ),
			),
			'checkout_delivery_enabled'  => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $bool_validate,
			),
			'checkout_delivery_days_ahead' => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 7, 92 ),
			),
			'checkout_delivery_holidays' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => array( $this->validator, 'validate_holiday_dates' ),
			),
			'checkout_delivery_rules' => array(
				'type'              => 'array',
				'required'          => false,
				'minItems'          => 1,
				'maxItems'          => 7,
				'items'             => $delivery_rule_schema,
				'sanitize_callback' => array( $this->validator, 'sanitize_delivery_rules_for_rest' ),
				'validate_callback' => array( $this->validator, 'validate_delivery_rules' ),
			),
			'checkout_delivery_time_slots' => array(
				'type'              => 'array',
				'required'          => false,
				'minItems'          => 1,
				'maxItems'          => 84,
				'items'             => $time_slot_schema,
				'sanitize_callback' => array( $this->validator, 'sanitize_delivery_time_slots_for_rest' ),
				'validate_callback' => array( $this->validator, 'validate_delivery_time_slots' ),
			),
			'checkout_delivery_schedule' => array(
				'type'              => 'array',
				'required'          => false,
				'minItems'          => 1,
				'maxItems'          => 7,
				'items'             => $schedule_rule_schema,
				'sanitize_callback' => array( $this->validator, 'sanitize_delivery_schedule_for_rest' ),
				'validate_callback' => array( $this->validator, 'validate_delivery_schedule' ),
			),
			'checkout_delivery_hide_unavailable_slots' => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $bool_validate,
			),
			'checkout_delivery_netherlands_surcharge_amount' => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => $money,
				'validate_callback' => $money_validate,
			),
			'checkout_delivery_netherlands_evening_surcharge_amount' => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => $money,
				'validate_callback' => $money_validate,
			),
			'checkout_delivery_belgium_surcharge_amount' => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => $money,
				'validate_callback' => $money_validate,
			),
			'checkout_delivery_belgium_evening_surcharge_amount' => array(
				'type'              => 'number',
				'required'          => false,
				'sanitize_callback' => $money,
				'validate_callback' => $money_validate,
			),
			'checkout_delivery_fee_taxable' => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $bool_validate,
			),
			'checkout_delivery_fee_tax_class' => array(
				'type'              => 'string',
				'required'          => false,
				'maxLength'         => 200,
				'sanitize_callback' => $key,
			),
			'auto_submit_enabled'        => array(
				'type'              => 'boolean',
				'enum'              => array( OptionDefaults::AUTO_SUBMIT_ENABLED ),
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $fixed_bool_validate( OptionDefaults::AUTO_SUBMIT_ENABLED ),
			),
			'auto_submit_status'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
				'enum'              => array( OptionDefaults::AUTO_SUBMIT_STATUS ),
				'validate_callback' => array( $this->validator, 'validate_auto_submit_status' ),
			),
			'allow_resubmit'             => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
				'validate_callback' => $bool_validate,
			),
			'label_output'               => array(
				'type'              => 'string',
				'enum'              => array( 'a6', 'collated_a4' ),
				'required'          => false,
				'sanitize_callback' => $key,
				'validate_callback' => array( $this->validator, 'validate_label_output' ),
			),
			'webhook_url'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this->validator, 'validate_https_url_or_empty' ),
			),
			'goods_description_fallback' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'packaging_type'             => array(
				'type'              => 'string',
				'required'          => false,
				'minLength'         => 1,
				'maxLength'         => 32,
				'pattern'           => '^[a-z0-9][a-z0-9_-]{0,31}$',
				'sanitize_callback' => $key,
				'validate_callback' => array( $this->validator, 'validate_packaging_type' ),
			),
			'temperature_regime'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
				'enum'              => array( 'cooled', 'frozen', 'ambient' ),
				'validate_callback' => array( $this->validator, 'validate_temperature_regime' ),
			),
			'package_width'              => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 1, 9999 ),
			),
			'package_depth'              => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 1, 9999 ),
			),
			'package_height'             => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 1, 9999 ),
			),
			'package_weight'             => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 1, 999999 ),
			),
			'missing_product_weight'     => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 1, 999999 ),
			),
			'log_retention'              => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 20, 500 ),
			),
		);
	}

	private function slot_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'          => array( 'type' => 'string', 'maxLength' => 64 ),
				'type'        => array( 'type' => 'string', 'enum' => array( 'daytime', 'evening' ) ),
				'enabled'     => array( 'type' => 'boolean' ),
				'label'       => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
				'time_from'   => array( 'type' => 'string', 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$' ),
				'time_to'     => array( 'type' => 'string', 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$' ),
				'cutoff_time' => array( 'type' => 'string', 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$' ),
			),
		);
	}

	private function time_slot_schema(): array {
		$schema = $this->slot_schema();
		$schema['properties']['weekdays'] = array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => 7,
			'items'    => array(
				'type' => 'string',
				'enum' => $this->validator->allowed_delivery_weekdays(),
			),
		);
		$schema['properties']['sort_order'] = array( 'type' => 'integer' );

		return $schema;
	}

	private function schedule_slot_schema(): array {
		$schema = $this->slot_schema();
		$schema['properties']['sort_order'] = array( 'type' => 'integer' );

		return $schema;
	}

	private function delivery_rule_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'enabled'          => array( 'type' => 'boolean' ),
				'delivery_weekday' => array(
					'type' => 'string',
					'enum' => $this->validator->allowed_delivery_weekdays(),
				),
				'cutoff_weekday'   => array(
					'type' => 'string',
					'enum' => $this->validator->allowed_delivery_weekdays(),
				),
				'cutoff_time'      => array( 'type' => 'string', 'pattern' => '^([01]\d|2[0-3]):[0-5]\d$' ),
			),
		);
	}

	private function schedule_rule_schema( array $slot_schema ): array {
		$schema = $this->delivery_rule_schema();
		$schema['properties']['sort_order'] = array( 'type' => 'integer' );
		$schema['properties']['slots'] = array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => 12,
			'items'    => $slot_schema,
		);

		return $schema;
	}

}
