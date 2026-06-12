<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PrivacyPolicy {

	public function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'SooCool for WooCommerce', 'soocool-for-woocommerce' ),
			$this->content()
		);
	}

	private function content(): string {
		$paragraphs = array(
			__( 'SooCool for WooCommerce can send WooCommerce order, delivery, pickup, recipient and label data to the configured SooCool transport API when an order is manually or automatically synchronized.', 'soocool-for-woocommerce' ),
			__( 'Depending on your settings and order contents, this can include recipient name, shipping or billing address, postcode, city, email address, phone number, order reference, package details, delivery instructions, tracking data and shipping label references.', 'soocool-for-woocommerce' ),
			__( 'The plugin stores API connection settings and a webhook secret in WordPress options. API keys and webhook secrets are masked in the admin interface and should not be shared in logs, screenshots or support exports.', 'soocool-for-woocommerce' ),
			__( 'The site owner remains responsible for documenting the SooCool transport service, the legal basis for shipment processing and any retention periods in the site privacy policy.', 'soocool-for-woocommerce' ),
		);

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		return $html;
	}
}
