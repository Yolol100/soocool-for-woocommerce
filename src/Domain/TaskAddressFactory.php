<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class TaskAddressFactory {

	private const SPLIT_ADDRESS_META = array(
		'billing'  => array(
			'street'       => '_billing_street_name',
			'house_number' => '_billing_house_number',
			'suffix'       => '_billing_house_number_suffix',
		),
		'shipping' => array(
			'street'       => '_shipping_street_name',
			'house_number' => '_shipping_house_number',
			'suffix'       => '_shipping_house_number_suffix',
		),
	);

	public function __construct( private readonly AddressParser $address_parser, private readonly TaskContactFactory $contacts ) {}

	/** @return array{address:array{street:string,houseNumber:string},postal_code:string,city:string,country:string,recipient_name:string} */
	public function delivery_context( WC_Order $order ): array {
		$shipping_address_values = array(
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_postcode(),
			$order->get_shipping_city(),
		);
		$has_shipping_address = array() !== array_filter( $shipping_address_values, static fn ( mixed $value ): bool => '' !== trim( (string) $value ) );

		$prefix    = $has_shipping_address ? 'shipping' : 'billing';
		$address_1 = 'shipping' === $prefix ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$address_2 = 'shipping' === $prefix ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$address   = $this->address_with_split_meta_fallback( $order, $prefix, $this->address_parser->split( (string) $address_1, (string) $address_2 ) );

		return array(
			'address'        => $address,
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

	/** @param array{street:string, houseNumber:string} $address @return array{street:string, houseNumber:string} */
	private function address_with_split_meta_fallback( WC_Order $order, string $prefix, array $address ): array {
		$meta_keys = self::SPLIT_ADDRESS_META[ $prefix ] ?? array();
		if ( array() === $meta_keys ) {
			return $address;
		}

		$street       = trim( (string) $address['street'] );
		$house_number = trim( (string) $address['houseNumber'] );

		if ( '' === $street ) {
			$street = $this->order_meta_text( $order, (string) $meta_keys['street'] );
		}

		if ( '' === $house_number ) {
			$house_number = $this->combined_house_number_from_meta( $order, (string) $meta_keys['house_number'], (string) $meta_keys['suffix'] );
		}

		return array(
			'street'      => $street,
			'houseNumber' => $house_number,
		);
	}

	private function combined_house_number_from_meta( WC_Order $order, string $number_key, string $suffix_key ): string {
		$number = $this->order_meta_text( $order, $number_key );
		$suffix = $this->order_meta_text( $order, $suffix_key );

		return trim( $number . $suffix );
	}

	private function order_meta_text( WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return trim( sanitize_text_field( wp_strip_all_tags( (string) $value ) ) );
	}

	private function recipient_name_for_prefix( WC_Order $order, string $prefix ): string {
		if ( 'shipping' === $prefix ) {
			$shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
			if ( '' !== $shipping_name ) {
				return $shipping_name;
			}
		}

		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}
}
