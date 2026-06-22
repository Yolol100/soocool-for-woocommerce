<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class TaskAddressFactory {

	public function __construct( private readonly AddressParser $address_parser, private readonly TaskContactFactory $contacts ) {}

	/** @return array{address:array{street:string,houseNumber:string},postal_code:string,city:string,country:string,recipient_name:string} */
	public function delivery_context( WC_Order $order ): array {
		$shipping_values = array(
			$order->get_shipping_first_name(),
			$order->get_shipping_last_name(),
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_postcode(),
			$order->get_shipping_city(),
			$order->get_shipping_country(),
		);
		$has_shipping = array() !== array_filter( $shipping_values, static fn ( mixed $value ): bool => '' !== trim( (string) $value ) );

		$prefix    = $has_shipping ? 'shipping' : 'billing';
		$address_1 = 'shipping' === $prefix ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$address_2 = 'shipping' === $prefix ? $order->get_shipping_address_2() : $order->get_billing_address_2();

		return array(
			'address'        => $this->address_parser->split( (string) $address_1, (string) $address_2 ),
			'postal_code'    => (string) ( 'shipping' === $prefix ? $order->get_shipping_postcode() : $order->get_billing_postcode() ),
			'city'           => (string) ( 'shipping' === $prefix ? $order->get_shipping_city() : $order->get_billing_city() ),
			'country'        => (string) ( 'shipping' === $prefix ? $order->get_shipping_country() : $order->get_billing_country() ),
			'recipient_name' => $this->recipient_name_for_prefix( $order, $prefix ),
		);
	}

	/** @param array{street:string, houseNumber:string} $address @return array<int, string> */
	public function missing_delivery_fields( WC_Order $order, array $address, string $postal_code, string $city, string $country, string $recipient_name ): array {
		$fields = array();

		if ( '' === trim( $recipient_name ) ) {
			$fields[] = __( 'naam ontvanger', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( (string) $address['street'] ) ) {
			$fields[] = __( 'straatnaam', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( (string) $address['houseNumber'] ) ) {
			$fields[] = __( 'huisnummer', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( $postal_code ) ) {
			$fields[] = __( 'postcode', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( $city ) ) {
			$fields[] = __( 'plaats', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( $country ) ) {
			$fields[] = __( 'land', 'soocool-for-woocommerce' );
		}
		if ( array() === $this->contacts->for_delivery_order( $order ) ) {
			$fields[] = __( 'e-mailadres, telefoonnummer of geldig Nederlands mobiel nummer', 'soocool-for-woocommerce' );
		}

		return $fields;
	}

	/** @param array<int, string> $missing_fields */
	public function missing_delivery_fields_message( array $missing_fields ): string {
		$fields = esc_html( implode( ', ', array_map( 'sanitize_text_field', $missing_fields ) ) );

		return sprintf(
			/* translators: %s: comma-separated list of missing WooCommerce address fields. */
			esc_html__( 'Bezorgadres is onvolledig. Ontbrekende WooCommerce-velden: %s. Vul het verzend- of factuuradres aan voordat deze order naar SooCool wordt gestuurd.', 'soocool-for-woocommerce' ),
			$fields
		);
	}

	private function recipient_name_for_prefix( WC_Order $order, string $prefix ): string {
		if ( 'shipping' === $prefix ) {
			return trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}
}
