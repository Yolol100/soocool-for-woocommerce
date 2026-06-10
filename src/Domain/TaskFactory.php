<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TaskFactory {

	private const DELIVERY_TIME_FROM = '08:00';
	private const DELIVERY_TIME_TO   = '18:00';

	public function __construct( private readonly OptionRepository $options, private readonly AddressParser $address_parser ) {}

	/** @return array<int, array<string, mixed>> */
	public function create_tasks( WC_Order $order ): array {
		$settings        = $this->options->all();
		$pickup_date     = $this->date_for_offset( (int) $settings['pickup_days_offset'] );
		$delivery_offset = (int) $settings['delivery_days_offset'];
		if ( (bool) $settings['enable_pickup'] && $delivery_offset < 1 ) {
			$delivery_offset = 1;
		}
		$delivery_date = $this->date_for_offset( $delivery_offset );
		$tasks         = array();

		if ( (bool) $settings['enable_pickup'] ) {
			$tasks[] = $this->pickup_task( $settings, $pickup_date );
		}

		$tasks[] = $this->delivery_task( $order, $settings, $delivery_date );

		return $tasks;
	}

	/** @param array<string, mixed> $settings @return array<string, mixed> */
	private function pickup_task( array $settings, string $date ): array {
		foreach ( array( 'pickup_company', 'pickup_street', 'pickup_house_number', 'pickup_postal_code', 'pickup_city', 'pickup_country' ) as $field ) {
			if ( '' === trim( (string) ( $settings[ $field ] ?? '' ) ) ) {
				throw new PayloadValidationException( esc_html__( 'Pickup settings are incomplete. Complete the pickup address before sending orders to SooCool.', 'soocool-for-woocommerce' ) );
			}
		}

		return array(
			'type'       => 'pickup',
			'name'       => sanitize_text_field( (string) $settings['pickup_company'] ),
			'address'    => array(
				'street'      => sanitize_text_field( (string) $settings['pickup_street'] ),
				'houseNumber' => sanitize_text_field( (string) $settings['pickup_house_number'] ),
				'postalCode'  => strtoupper( sanitize_text_field( (string) $settings['pickup_postal_code'] ) ),
				'city'        => sanitize_text_field( (string) $settings['pickup_city'] ),
				'country'     => $this->country_code( (string) $settings['pickup_country'] ),
			),
			'date'       => $date,
			'timeWindow' => array(
				'from' => (string) $settings['pickup_time_from'],
				'to'   => (string) $settings['pickup_time_to'],
			),
			'contact'    => array_filter(
				array(
					'name'  => sanitize_text_field( (string) $settings['pickup_contact_name'] ),
					'email' => sanitize_email( (string) $settings['pickup_email'] ),
					'phone' => sanitize_text_field( (string) $settings['pickup_phone'] ),
				)
			),
		);
	}

	/** @param array<string, mixed> $settings @return array<string, mixed> */
	private function delivery_task( WC_Order $order, array $settings, string $date ): array {
		$address     = $this->address_parser->split(
			$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
			$order->get_shipping_address_2() ?: $order->get_billing_address_2()
		);
		$postal_code = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
		$city        = $order->get_shipping_city() ?: $order->get_billing_city();
		$country     = $order->get_shipping_country() ?: $order->get_billing_country() ?: 'NL';

		if ( '' === trim( $this->recipient_name( $order ) ) || '' === trim( (string) $address['street'] ) || '' === trim( (string) $address['houseNumber'] ) || '' === trim( (string) $postal_code ) || '' === trim( (string) $city ) ) {
			throw new PayloadValidationException( esc_html__( 'Delivery address is incomplete. Complete the WooCommerce shipping or billing address before sending this order to SooCool.', 'soocool-for-woocommerce' ) );
		}

		return array(
			'type'       => 'delivery',
			'name'       => sanitize_text_field( $this->recipient_name( $order ) ),
			'address'    => array(
				'street'      => sanitize_text_field( (string) $address['street'] ),
				'houseNumber' => sanitize_text_field( (string) $address['houseNumber'] ),
				'postalCode'  => strtoupper( sanitize_text_field( (string) $postal_code ) ),
				'city'        => sanitize_text_field( (string) $city ),
				'country'     => $this->country_code( (string) $country ),
			),
			'date'       => $date,
			'timeWindow' => array(
				'from' => self::DELIVERY_TIME_FROM,
				'to'   => self::DELIVERY_TIME_TO,
			),
			'contact'    => array_filter(
				array(
					'email' => sanitize_email( $order->get_billing_email() ),
					'phone' => sanitize_text_field( $order->get_billing_phone() ),
				)
			),
		);
	}

	private function recipient_name( WC_Order $order ): string {
		$shipping = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$billing  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return '' !== $shipping ? $shipping : $billing;
	}

	private function country_code( string $country ): string {
		$country = strtoupper( sanitize_key( $country ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'NL';
	}

	private function date_for_offset( int $days ): string {
		return wp_date( 'Y-m-d', strtotime( '+' . max( 0, $days ) . ' days', current_time( 'timestamp' ) ) );
	}
}
