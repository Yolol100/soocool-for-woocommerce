<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookAuthenticator {

	public function __construct( private readonly OptionRepository $options ) {}

	public function can_receive( WP_REST_Request $request ): bool|WP_Error {
		$expected = $this->options->existing_webhook_secret();
		$provided = $this->provided_token( $request );

		if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'soocool_webhook_forbidden', __( 'Invalid SooCool webhook token.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		return true;
	}

	private function provided_token( WP_REST_Request $request ): string {
		$token = $request->get_header( 'x-soocool-webhook-token' );
		if ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) {
			$token = $request->get_header( 'x_webhook_token' );
		}
		if ( ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) && $this->options->query_token_fallback_enabled() && $request->has_param( 'token' ) ) {
			$token = $request->get_param( 'token' );
		}

		return is_scalar( $token ) ? trim( sanitize_text_field( (string) $token ) ) : '';
	}
}
