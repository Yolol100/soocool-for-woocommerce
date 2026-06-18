<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Requirements {

	public function is_supported(): bool {
		return '' === $this->get_missing_message();
	}

	public function get_missing_message(): string {
		if ( PHP_VERSION_ID < 80100 ) {
			return __( 'SooCool for WooCommerce vereist PHP 8.1 of hoger.', 'soocool-for-woocommerce' );
		}

		if ( ! function_exists( 'get_bloginfo' ) || version_compare( (string) get_bloginfo( 'version' ), '6.5', '<' ) ) {
			return __( 'SooCool for WooCommerce vereist WordPress 6.5 of hoger.', 'soocool-for-woocommerce' );
		}

		if ( ! class_exists( 'WP_REST_Controller' ) ) {
			return __( 'SooCool for WooCommerce vereist dat de WordPress REST API-classes beschikbaar zijn.', 'soocool-for-woocommerce' );
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'SooCool for WooCommerce vereist dat WooCommerce actief is.', 'soocool-for-woocommerce' );
		}

		return '';
	}
}
