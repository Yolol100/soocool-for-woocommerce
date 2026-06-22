<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelBulkTokenStore {

	private const PREFIX = 'soocool_bulk_label_';
	private const TTL    = 300;

	/** @param array<int, int> $order_ids */
	public function create( string $action, array $order_ids, string $output ): string {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$token = sanitize_key( str_replace( '-', '', $token ) );
		if ( '' === $token ) {
			return '';
		}

		$payload = array(
			'user_id'   => get_current_user_id(),
			'action'    => sanitize_key( $action ),
			'order_ids' => array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) ),
			'output'    => 'collated_a4' === $output ? 'collated_a4' : 'a6',
			'created'   => time(),
		);

		return set_transient( $this->transient_key( $token ), $payload, self::TTL ) ? $token : '';
	}

	/** @return array<string, mixed> */
	public function consume( string $token ): array {
		$payload = get_transient( $this->transient_key( $token ) );
		delete_transient( $this->transient_key( $token ) );

		return is_array( $payload ) ? $payload : array();
	}

	public function nonce_action( string $token ): string {
		return 'soocool_download_bulk_labels_' . md5( $token );
	}

	private function transient_key( string $token ): string {
		return self::PREFIX . md5( $token );
	}
}
