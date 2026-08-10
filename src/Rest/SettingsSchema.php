<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

defined( 'ABSPATH' ) || exit;

final class SettingsSchema {

	public function __construct( private readonly SettingsValidator $validator ) {}

	/** @return array<string, array<string, mixed>> */
	public function args(): array {
		$text           = static fn ( $value ): string => is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		$key            = static fn ( $value ): string => is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		$bool           = static fn ( $value ): bool => is_scalar( $value ) ? ( filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false ) : false;
		$int            = static fn ( $value ): int => is_scalar( $value ) ? max( 0, absint( $value ) ) : 0;
		$money          = static fn ( $value ): float => is_scalar( $value ) ? round( (float) str_replace( ',', '.', sanitize_text_field( (string) $value ) ), 2 ) : 0.0;
		$money_validate = static fn ( $value ): bool => is_scalar( $value ) && is_numeric( str_replace( ',', '.', (string) $value ) ) && (float) str_replace( ',', '.', (string) $value ) >= 0 && (float) str_replace( ',', '.', (string) $value ) <= 999;
		$time_validate  = static fn ( $value ): bool => is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) === 1;
		$range_validate  = static fn ( int $min, int $max ): callable => static fn ( $value ): bool => is_numeric( $value ) && (int) $value >= $min && (int) $value <= $max;
		$time_slot_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties' => array(
				'id'          => array( 'type' => 'string', 'maxLength' => 64 ),
				'type'        => array( 'type' => 'string', 'enum' => array( 'daytime', 'evening' ) ),
				'enabled'     => array(
					'type' => 'boolean',
				),
				'label'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 80,
				),
				'time_from'   => array(
					'type'    => 'string',
					'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$',
				),
				'time_to'     => array(
					'type'    => 'string',
					'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$',
				),
				'cutoff_time' => array(
					'type'    => 'string',
					'pattern' => '^([01]\\d|2[0-3]):[0-5]\\d$',
				),
				'weekdays'    => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 7,
					'items'    => array(
						'type' => 'string',
						'enum' => $this->validator->allowed_delivery_weekdays(),
					),
				),
				'sort_order'  => array(
					'type' => 'integer',
				),
			),
		);

		$delivery_rule_schema = array(
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
				'cutoff_time'      => array(
					'type'    => 'string',
					'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
				),
			),
		);

		$schedule_slot_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties' => array(
				'id'          => array( 'type' => 'string', 'maxLength' => 64 ),
				'type'        => array( 'type' => 'string', 'enum' => array( 'daytime', 'evening' ) ),
				'enabled'     => array( 'type' => 'boolean' ),
				'label'       => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 80 ),
				'time_from'   => array(
					'type'    => 'string',
					'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
				),
				'time_to'     => array(
					'type'    => 'string',
					'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
				),
				'cutoff_time' => array(
					'type'    => 'string',
					'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
				),
				'sort_order'  => array( 'type' => 'integer' ),
			),
		);
		$schedule_rule_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties' => array(
				'enabled'          => array( 'type' => 'boolean' ),
				'delivery_weekday' => array(
					'type' => 'string',
					'enum' => $this->validator->allowed_delivery_weekdays(),
				),
				'cutoff_weekday'   => array(
					'type' => 'string',
					'enum' => $this->validator->allowed_delivery_weekdays(),
				),
				'cutoff_time'      => array(
					'type'    => 'string',
					'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
				),
				'sort_order'       => array( 'type' => 'integer' ),
				'slots'            => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 12,
					'items' => $schedule_slot_schema,
				),
			),
		);

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
			),
			'enable_pickup'              => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
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
			'pickup_days_offset'         => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 0, 29 ),
			),
			'pickup_time_from'           => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $time_validate,
			),
			'pickup_time_to'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $time_validate,
			),
			'delivery_time_from'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $time_validate,
			),
			'delivery_time_to'           => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
				'validate_callback' => $time_validate,
			),
			'delivery_days_offset'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 0, 30 ),
			),
			'checkout_delivery_enabled'  => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
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
			),
			'checkout_delivery_fee_tax_class' => array(
				'type'              => 'string',
				'required'          => false,
				'maxLength'         => 200,
				'sanitize_callback' => $key,
			),
			'auto_submit_enabled'        => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
			),
			'auto_submit_status'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
				'enum'              => array( 'pending', 'processing', 'completed', 'on-hold' ),
				'validate_callback' => array( $this->validator, 'validate_auto_submit_status' ),
			),
			'allow_resubmit'             => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
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
}
