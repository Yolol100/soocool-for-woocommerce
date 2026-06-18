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

	private readonly OptionRepository $options;
	private readonly SettingsSchema $settings_schema;
	private readonly SettingsValidator $validator;

	public function __construct( OptionRepository $options, ?SettingsSchema $settings_schema = null, ?SettingsValidator $validator = null ) {
		$this->options         = $options;
		$this->validator       = $validator ?? new SettingsValidator( $options );
		$this->settings_schema = $settings_schema ?? new SettingsSchema( $this->validator );
	}

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
			return new WP_Error( 'soocool_invalid_payload', __( 'Ongeldige instellingen-payload.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$payload = array();
		foreach ( array_keys( $this->schema_args() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$payload[ $key ] = $request->get_param( $key );
			}
		}

		$validation_error = $this->validator->validate_payload( $payload );
		if ( $validation_error instanceof WP_Error ) {
			return $validation_error;
		}

		$this->options->update( $payload );
		return $this->get();
	}

	/** @return array<string, array<string, mixed>> */
	private function schema_args(): array {
		return $this->settings_schema->args();
	}

}
