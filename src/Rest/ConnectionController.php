<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConnectionController extends AbstractRestController {

	public function __construct( private readonly ApiClient $client ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/connection/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function test(): WP_REST_Response {
		try {
			$response         = $this->client->ping();
			$body             = $response->body();
			$matches_contract = $this->body_matches_ping_contract( $body );

			if ( ! $matches_contract ) {
				return new WP_REST_Response(
					array(
						'success'          => true,
						'status'           => $response->status_code(),
						'message'          => __( 'Verbinding gelukt.', 'soocool-for-woocommerce' ),
						'contract_warning' => true,
					),
					200
				);
			}

			return new WP_REST_Response(
				array(
					'success'          => true,
					'status'           => $response->status_code(),
					'message'          => __( 'Verbinding gelukt.', 'soocool-for-woocommerce' ),
					'contract_warning' => false,
				)
			);
		} catch ( ApiException $exception ) {
			$status = $exception->status_code();
			if ( $status < 400 || $status > 599 ) {
				$status = 400;
			}

			return new WP_REST_Response(
				array(
					'success' => false,
					'status'  => $exception->status_code(),
					'message' => '' !== $exception->getMessage() ? sanitize_text_field( $exception->getMessage() ) : __( 'Verbinding mislukt. Controleer de SooCool API-key en basis-URL.', 'soocool-for-woocommerce' ),
				),
				$status
			);
		} catch ( \Throwable $exception ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'status'  => 500,
					'message' => __( 'Verbindingstest onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' ),
				),
				500
			);
		}
	}

	private function body_matches_ping_contract( mixed $body ): bool {
		if ( is_array( $body ) && isset( $body['ping'] ) && is_scalar( $body['ping'] ) ) {
			return 'pong' === strtolower( trim( (string) $body['ping'] ) );
		}

		if ( is_string( $body ) ) {
			$normalized = strtolower( trim( wp_strip_all_tags( $body ) ) );
			return in_array( $normalized, array( 'pong', 'ping, pong!', '{"ping":"pong"}', '{ "ping": "pong" }' ), true );
		}

		return false;
	}
}
