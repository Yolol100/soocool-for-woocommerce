<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\WooCommerce\OrderMeta;

defined( 'ABSPATH' ) || exit;

final class PrivacyPolicy {

	/** @param array<string, string> $meta */
	public function add_order_export_meta( array $meta ): array {
		return $meta + array(
			OrderMeta::ORDER_ID                      => __( 'SooCool order-ID', 'soocool-for-woocommerce' ),
			OrderMeta::OUR_REFERENCE                 => __( 'SooCool-referentie', 'soocool-for-woocommerce' ),
			OrderMeta::ORDER_REFERENCE               => __( 'Orderreferentie', 'soocool-for-woocommerce' ),
			OrderMeta::TRACKING_CODE                 => __( 'Trackingcode', 'soocool-for-woocommerce' ),
			OrderMeta::TRACKING_URL                  => __( 'Tracking-URL', 'soocool-for-woocommerce' ),
			OrderMeta::GOOD_IDS                      => __( 'SooCool-goederen-ID’s', 'soocool-for-woocommerce' ),
			OrderMeta::REQUESTED_DELIVERY_DATE       => __( 'Bezorgdatum', 'soocool-for-woocommerce' ),
			OrderMeta::REQUESTED_DELIVERY_LABEL      => __( 'Bezorgdag', 'soocool-for-woocommerce' ),
			OrderMeta::REQUESTED_DELIVERY_TIME_FROM  => __( 'Bezorgvenster vanaf', 'soocool-for-woocommerce' ),
			OrderMeta::REQUESTED_DELIVERY_TIME_TO    => __( 'Bezorgvenster tot', 'soocool-for-woocommerce' ),
			OrderMeta::REQUESTED_DELIVERY_TIME_LABEL => __( 'Dagdeel', 'soocool-for-woocommerce' ),
		);
	}

	/** @param array<string, string> $meta */
	public function add_order_erasure_meta( array $meta ): array {
		return $meta + array(
			OrderMeta::TRACKING_CODE                 => 'text',
			OrderMeta::TRACKING_URL                  => 'text',
			OrderMeta::REQUESTED_DELIVERY_DATE       => 'text',
			OrderMeta::REQUESTED_DELIVERY_LABEL      => 'text',
			OrderMeta::REQUESTED_DELIVERY_TIME_FROM  => 'text',
			OrderMeta::REQUESTED_DELIVERY_TIME_TO    => 'text',
			OrderMeta::REQUESTED_DELIVERY_TIME_LABEL => 'text',
		);
	}

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
			__( 'Bij API-verzoeken bevat de technische HTTP User-Agent de pluginversie en de publieke site-URL, zodat de SooCool-integratie aan de ontvangende zijde herkenbaar is.', 'soocool-for-woocommerce' ),
			__( 'De site-eigenaar blijft verantwoordelijk voor het beschrijven van de SooCool transportdienst, de juridische grondslag voor verzendverwerking en eventuele bewaartermijnen in het privacybeleid van de site.', 'soocool-for-woocommerce' ),
			__( 'WooCommerce-privacyexports nemen de SooCool verzend- en bezorggegevens op die op de order zijn opgeslagen. Bij een WooCommerce-verwijderverzoek worden gekozen bezorgmomenten en trackinggegevens verwijderd; operationele SooCool-koppelings- en syncgegevens blijven behouden om dubbele transportaanmeldingen te voorkomen en de orderhistorie controleerbaar te houden.', 'soocool-for-woocommerce' ),
		);

		$html = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		return $html;
	}
}
