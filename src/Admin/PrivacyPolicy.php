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
			__( 'SooCool for WooCommerce kan WooCommerce order-, bezorg-, ophaal-, ontvanger- en labelgegevens naar de ingestelde SooCool transport-API sturen wanneer een order handmatig of automatisch wordt gesynchroniseerd.', 'soocool-for-woocommerce' ),
			__( 'Afhankelijk van de instellingen en orderinhoud kan dit bestaan uit naam van de ontvanger, verzend- of factuuradres, postcode, plaats, e-mailadres, telefoonnummer, orderreferentie, pakketgegevens, bezorginstructies, trackinggegevens en verzendlabelreferenties.', 'soocool-for-woocommerce' ),
			__( 'De plugin bewaart API-koppelingsinstellingen en een webhookgeheim in WordPress-opties. API-keys en webhookgeheimen worden in de beheeromgeving gemaskeerd en mogen niet worden gedeeld in logs, screenshots of supportexports.', 'soocool-for-woocommerce' ),
			__( 'De site-eigenaar blijft verantwoordelijk voor het beschrijven van de SooCool transportdienst, de juridische grondslag voor verzendverwerking en eventuele bewaartermijnen in het privacybeleid van de site.', 'soocool-for-woocommerce' ),
		);

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		return $html;
	}
}
