<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds SooCool task objects to match the SooCool API 1.2.1 create-order schema.
 *
 * A task contains:
 *   - taskType    : "delivery" | "pickup"
 *   - timeWindow  : { startTime, endTime } (ISO-8601)
 *   - address     : { person, street, houseNumber, postCode, city, country }
 *   - contactInfo : { email, phone, mobile } at task level
 *   - goods       : array of good IDs from the goods manifest
 *   - instructions: optional string
 */
final class TaskFactory {

	private const DELIVERY_TIME_FROM = '08:00';
	private const DELIVERY_TIME_TO   = '18:00';

	public function __construct( private readonly OptionRepository $options, private readonly AddressParser $address_parser ) {}

	/** @param array<int, int|string> $good_ids Requested good IDs to attach to every task. @return array<int, array<string, mixed>> */
	public function create_tasks( WC_Order $order, array $good_ids = array() ): array {
		$settings        = $this->options->all();
		$good_ids        = $this->normalize_good_ids( $good_ids );
		$pickup_offset   = (int) $settings['pickup_days_offset'];
		$pickup_date     = $this->date_for_offset( $pickup_offset );
		$delivery_offset = (int) $settings['delivery_days_offset'];
		if ( (bool) $settings['enable_pickup'] && $delivery_offset <= $pickup_offset ) {
			$delivery_offset = $pickup_offset + 1;
		}
		$delivery_date = $this->date_for_offset( $delivery_offset );
		$tasks         = array();

		if ( (bool) $settings['enable_pickup'] ) {
			$tasks[] = $this->pickup_task( $settings, $pickup_date, $good_ids );
		}

		$tasks[] = $this->delivery_task( $order, $settings, $delivery_date, $good_ids );

		return $tasks;
	}

	/** @param array<string, mixed> $settings @param array<int, int> $good_ids @return array<string, mixed> */
	private function pickup_task( array $settings, string $date, array $good_ids ): array {
		foreach ( array( 'pickup_company', 'pickup_street', 'pickup_house_number', 'pickup_postal_code', 'pickup_city', 'pickup_country' ) as $field ) {
			if ( '' === trim( (string) ( $settings[ $field ] ?? '' ) ) ) {
				throw new PayloadValidationException( esc_html__( 'Pickup settings are incomplete. Complete the pickup address before sending orders to SooCool.', 'soocool-for-woocommerce' ) );
			}
		}

		$task = array(
			'taskType'    => 'pickup',
			'timeWindow'  => array(
				'startTime' => $this->date_time_for_api( $date, (string) $settings['pickup_time_from'] ),
				'endTime'   => $this->date_time_for_api( $date, (string) $settings['pickup_time_to'] ),
			),
			'address'     => array(
				'person'      => sanitize_text_field( (string) ( '' !== trim( (string) $settings['pickup_contact_name'] ) ? $settings['pickup_contact_name'] : $settings['pickup_company'] ) ),
				'street'      => sanitize_text_field( (string) $settings['pickup_street'] ),
				'houseNumber' => sanitize_text_field( (string) $settings['pickup_house_number'] ),
				'postCode'    => $this->postal_code( (string) $settings['pickup_postal_code'] ),
				'city'        => sanitize_text_field( (string) $settings['pickup_city'] ),
				'country'     => $this->country_code( (string) $settings['pickup_country'] ),
			),
			'contactInfo' => $this->compact(
				array(
					'email' => sanitize_email( (string) $settings['pickup_email'] ),
					'phone' => sanitize_text_field( (string) $settings['pickup_phone'] ),
				)
			),
			'goods'       => $good_ids,
		);

		return $this->compact( $task );
	}

	/** @param array<string, mixed> $settings @param array<int, int> $good_ids @return array<string, mixed> */
	private function delivery_task( WC_Order $order, array $settings, string $date, array $good_ids ): array {
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

		$time_from    = ! empty( $settings['delivery_time_from'] ) ? (string) $settings['delivery_time_from'] : self::DELIVERY_TIME_FROM;
		$time_to      = ! empty( $settings['delivery_time_to'] ) ? (string) $settings['delivery_time_to'] : self::DELIVERY_TIME_TO;
		$instructions = sanitize_text_field( wp_strip_all_tags( (string) $order->get_customer_note() ) );

		$task = array(
			'taskType'     => 'delivery',
			'timeWindow'   => array(
				'startTime' => $this->date_time_for_api( $date, $time_from ),
				'endTime'   => $this->date_time_for_api( $date, $time_to ),
			),
			'instructions' => '' !== $instructions ? $instructions : null,
			'address'      => array(
				'person'      => sanitize_text_field( $this->recipient_name( $order ) ),
				'street'      => sanitize_text_field( (string) $address['street'] ),
				'houseNumber' => sanitize_text_field( (string) $address['houseNumber'] ),
				'postCode'    => $this->postal_code( (string) $postal_code ),
				'city'        => sanitize_text_field( (string) $city ),
				'country'     => $this->country_code( (string) $country ),
			),
			'contactInfo'  => $this->delivery_contact_info( $order ),
			'goods'        => $good_ids,
		);

		return $this->compact( $task );
	}

	/** @return array<string, string> */
	private function delivery_contact_info( WC_Order $order ): array {
		$phone = sanitize_text_field( $order->get_billing_phone() );

		$info = array(
			'email' => sanitize_email( $order->get_billing_email() ),
			'phone' => $phone,
		);

		if ( '' !== $phone && $this->looks_like_mobile( $phone ) ) {
			$info['mobile'] = $phone;
		}

		/**
		 * Filters the SooCool task contactInfo (email, phone, mobile).
		 *
		 * @param array<string, string> $info
		 * @param WC_Order             $order
		 */
		$info = apply_filters( 'soocool_task_contact_info', $info, $order );

		return $this->compact( is_array( $info ) ? $info : array() );
	}

	private function looks_like_mobile( string $phone ): bool {
		$normalized = (string) preg_replace( '/[^\d+]/', '', $phone );
		return 1 === preg_match( '/^(?:\+?31|0)6\d{8}$/', $normalized );
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function compact( array $values ): array {
		return array_filter(
			$values,
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
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

	private function postal_code( string $postal_code ): string {
		$postal_code = strtoupper( sanitize_text_field( trim( $postal_code ) ) );
		return (string) preg_replace( '/\s+/', '', $postal_code );
	}

	private function date_time_for_api( string $date, string $time ): string {
		$time = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : self::DELIVERY_TIME_FROM;
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		try {
			$date_time = new \DateTimeImmutable( $date . ' ' . $time, $timezone );
		} catch ( \Exception ) {
			throw new PayloadValidationException( esc_html__( 'SooCool task time could not be generated.', 'soocool-for-woocommerce' ) );
		}

		return $date_time->format( DATE_ATOM );
	}

	private function date_for_offset( int $days ): string {
		try {
			$base = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ) );
			$date = $base->modify( '+' . max( 0, $days ) . ' days' );
		} catch ( \Exception ) {
			$date = ( new \DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' ) ) )->modify( '+' . max( 0, $days ) . ' days' );
		}

		return $date->format( 'Y-m-d' );
	}

	/** @param array<int, int|string> $good_ids @return array<int, int> */
	private function normalize_good_ids( array $good_ids ): array {
		$normalized = array();
		foreach ( $good_ids as $good_id ) {
			if ( is_int( $good_id ) ) {
				$id = $good_id;
			} elseif ( is_string( $good_id ) && preg_match( '/^-?\d+$/', $good_id ) ) {
				$id = (int) $good_id;
			} else {
				continue;
			}

			if ( 0 !== $id ) {
				$normalized[] = $id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
