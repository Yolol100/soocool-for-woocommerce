<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an authorized shop manager read the current webhook token
 * (so it can be configured in SooCool as the webhook URL token or optional header token) and rotate it.
 * The token is never exposed in the general settings payload; it is only returned here on demand,
 * gated behind the manage_woocommerce capability and the REST nonce.
 */
final class WebhookSecretController extends AbstractRestController {

	public function __construct( private readonly OptionRepository $options ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/webhook/secret',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'reveal' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function reveal(): WP_REST_Response {
		return $this->secret_response( $this->options->webhook_secret() );
	}

	public function regenerate(): WP_REST_Response {
		return $this->secret_response( $this->options->regenerate_webhook_secret() );
	}

	private function secret_response( string $secret ): WP_REST_Response {
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
