<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Requirements {

	private const MINIMUM_WOOCOMMERCE_VERSION = '8.0';

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

		if ( ! $this->woocommerce_version_is_supported() ) {
			return sprintf(
				/* translators: %s: minimum WooCommerce version. */
				__( 'SooCool for WooCommerce vereist WooCommerce %s of hoger.', 'soocool-for-woocommerce' ),
				self::MINIMUM_WOOCOMMERCE_VERSION
			);
		}

		return '';
	}

	private function woocommerce_version_is_supported(): bool {
		$version = $this->active_woocommerce_version();
		return '' !== $version && version_compare( $version, self::MINIMUM_WOOCOMMERCE_VERSION, '>=' );
	}

	private function active_woocommerce_version(): string {
		if ( defined( 'WC_VERSION' ) && is_scalar( constant( 'WC_VERSION' ) ) ) {
			return (string) constant( 'WC_VERSION' );
		}

		if ( function_exists( 'WC' ) ) {
			$woocommerce = WC();
			if ( is_object( $woocommerce ) && isset( $woocommerce->version ) && is_scalar( $woocommerce->version ) ) {
				return (string) $woocommerce->version;
			}
		}

		return '';
	}
}
