<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Requirements {

	public function is_supported(): bool {
		return PHP_VERSION_ID >= 80100 && class_exists( 'WooCommerce' );
	}

	public function get_missing_message(): string {
		if ( PHP_VERSION_ID < 80100 ) {
			return __( 'SooCool for WooCommerce requires PHP 8.1 or higher.', 'soocool-for-woocommerce' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'SooCool for WooCommerce requires WooCommerce to be active.', 'soocool-for-woocommerce' );
		}

		return '';
	}
}
