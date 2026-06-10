<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsController extends AbstractRestController {

	public function __construct( private readonly OptionRepository $options ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $this->schema_args(),
				),
			)
		);
	}

	public function get(): WP_REST_Response {
		return new WP_REST_Response( $this->options->public_settings() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$json = $request->get_json_params();
		if ( null !== $json && ! is_array( $json ) ) {
			return new WP_Error( 'soocool_invalid_payload', __( 'Invalid settings payload.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$payload = array();
		foreach ( array_keys( $this->schema_args() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$payload[ $key ] = $request->get_param( $key );
			}
		}

		$settings = $this->options->preview_update( $payload );
		if ( (bool) $settings['enable_pickup'] && (int) $settings['delivery_days_offset'] < 1 ) {
			return new WP_Error( 'soocool_invalid_delivery_offset', __( 'Delivery days offset must be at least 1 when pickup tasks are enabled.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( (bool) $settings['enable_pickup'] && (int) $settings['delivery_days_offset'] <= (int) $settings['pickup_days_offset'] ) {
			return new WP_Error( 'soocool_invalid_delivery_date', __( 'Delivery date offset must be later than the pickup date offset when pickup tasks are enabled.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( (bool) $settings['enable_pickup'] && (string) $settings['pickup_time_to'] <= (string) $settings['pickup_time_from'] ) {
			return new WP_Error( 'soocool_invalid_pickup_window', __( 'Pickup window end time must be later than the pickup window start time.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$this->options->update( $payload );
		return $this->get();
	}

	/** @return array<string, array<string, mixed>> */
	private function schema_args(): array {
		$text           = static fn ( $value ): string => sanitize_text_field( (string) $value );
		$key            = static fn ( $value ): string => sanitize_key( (string) $value );
		$bool           = static fn ( $value ): bool => filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) $value;
		$int            = static fn ( $value ): int => max( 0, absint( $value ) );
		$time_validate  = static fn ( $value ): bool => is_string( $value ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) === 1;
		$range_validate = static fn ( int $min, int $max ): callable => static fn ( $value ): bool => is_numeric( $value ) && (int) $value >= $min && (int) $value <= $max;

		return array(
			'environment'                => array(
				'type'              => 'string',
				'enum'              => array( 'test', 'production' ),
				'required'          => false,
				'sanitize_callback' => $key,
				'validate_callback' => array( $this, 'validate_environment' ),
			),
			'test_base_url'              => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this, 'validate_api_base_url_or_empty' ),
			),
			'production_base_url'        => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this, 'validate_api_base_url_or_empty' ),
			),
			'api_key'                    => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
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
				'validate_callback' => array( $this, 'validate_country' ),
			),
			'pickup_days_offset'         => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 0, 30 ),
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
			'auto_submit_enabled'        => array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => $bool,
			),
			'auto_submit_status'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
				'enum'              => array( 'processing', 'completed', 'on-hold' ),
				'validate_callback' => array( $this, 'validate_auto_submit_status' ),
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
				'validate_callback' => array( $this, 'validate_label_output' ),
			),
			'webhook_url'                => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'esc_url_raw',
				'validate_callback' => array( $this, 'validate_https_url_or_empty' ),
			),
			'goods_description_fallback' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $text,
			),
			'temperature_regime'         => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => $key,
				'enum'              => array( 'cooled', 'frozen', 'ambient' ),
				'validate_callback' => array( $this, 'validate_temperature_regime' ),
			),
			'log_retention'              => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => $int,
				'validate_callback' => $range_validate( 20, 500 ),
			),
		);
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
