<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reveals or rotates the webhook secret for authorized shop managers.
 */
final class WebhookSecretController extends AbstractRestController {

	public function __construct( private readonly OptionRepository $options ) {}

	public function register_routes(): void {
		$this->register_secret_action_route( '/webhook/secret/reveal', 'reveal' );
		$this->register_secret_action_route( '/webhook/secret/rotate', 'regenerate' );

		// Retains the existing POST-only rotate route for backward compatibility.
		$this->register_secret_action_route( '/webhook/secret', 'regenerate' );
	}

	public function reveal(): WP_REST_Response|WP_Error {
		$secret = $this->options->existing_webhook_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'soocool_webhook_secret_missing', __( 'Webhookgeheim ontbreekt. Genereer een nieuw geheim.', 'soocool-for-woocommerce' ), array( 'status' => 404 ) );
		}

		return $this->secret_response( $secret );
	}

	public function regenerate(): WP_REST_Response|WP_Error {
		return $this->secret_response( $this->options->regenerate_webhook_secret() );
	}

	private function register_secret_action_route( string $route, string $callback ): void {
		register_rest_route(
			$this->namespace,
			$route,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_manage_secrets' ),
			)
		);
	}

	private function secret_response( string $secret ): WP_REST_Response|WP_Error {
		if ( '' === $secret ) {
			return new WP_Error( 'soocool_webhook_secret_save_failed', __( 'Webhookgeheim kon niet worden opgeslagen.', 'soocool-for-woocommerce' ), array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( $this->payload( $secret ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/** @return array<string, string> */
	private function payload( string $secret ): array {
		return array(
			'secret'                => $secret,
			'header_name'           => 'X-SooCool-Webhook-Token',
			'timestamp_header_name' => 'X-SooCool-Webhook-Timestamp',
			'signature_header_name' => 'X-SooCool-Webhook-Signature',
			'event_id_header_name'  => 'X-SooCool-Webhook-Id',
			'webhook_url'           => $this->options->effective_webhook_url(),
		);
	}
}
