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
 *   - contactInfo : { email, phone, mobile } at task level, matching the SooCool API examples.
 *   - goods       : array of good IDs from the goods manifest
 *   - instructions: optional string
 */
final class TaskFactory {

	private const DELIVERY_TIME_FROM = '08:00';
	private const DELIVERY_TIME_TO   = '18:00';

	public function __construct( private readonly OptionRepository $options, private readonly AddressParser $address_parser ) {}

	/** @param array<int, int|string> $good_ids Requested good IDs to attach to every task. @return array<int, array<string, mixed>> */
	public function create_tasks( WC_Order $order, array $good_ids = array() ): array {
		$settings      = $this->options->all();
		$good_ids      = $this->normalize_good_ids( $good_ids );
		$pickup_offset = (int) $settings['pickup_days_offset'];
		if ( (bool) $settings['enable_pickup'] ) {
			$pickup_offset = $this->effective_offset_for_window(
				$pickup_offset,
				(string) ( $settings['pickup_time_to'] ?? self::DELIVERY_TIME_TO )
			);
		}
		$pickup_date     = $this->date_for_offset( $pickup_offset );
		$delivery_offset = (int) $settings['delivery_days_offset'];

		if ( (bool) $settings['enable_pickup'] && $delivery_offset <= $pickup_offset ) {
			$delivery_offset = $pickup_offset + 1;
		} elseif ( ! (bool) $settings['enable_pickup'] ) {
			$delivery_offset = $this->effective_offset_for_window(
				$delivery_offset,
				(string) ( $settings['delivery_time_to'] ?? self::DELIVERY_TIME_TO )
			);
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

		$contact_info = $this->contact_info(
			sanitize_email( (string) $settings['pickup_email'] ),
			sanitize_text_field( (string) $settings['pickup_phone'] )
		);
		if ( array() === $contact_info ) {
			throw new PayloadValidationException( esc_html__( 'Pickup contact is incomplete. Add a pickup email address, phone number or valid Dutch mobile number in the SooCool settings.', 'soocool-for-woocommerce' ) );
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
			'contactInfo' => $contact_info,
			'goods'       => $good_ids,
		);

		return $this->compact( $task );
	}

	/** @param array<string, mixed> $settings @param array<int, int> $good_ids @return array<string, mixed> */
	private function delivery_task( WC_Order $order, array $settings, string $date, array $good_ids ): array {
		$delivery_context = $this->delivery_address_context( $order );
		$address          = $delivery_context['address'];
		$postal_code      = $delivery_context['postal_code'];
		$city             = $delivery_context['city'];
		$country          = $delivery_context['country'];
		$recipient_name   = $delivery_context['recipient_name'];

		$missing_fields = $this->delivery_address_missing_fields( $order, $address, (string) $postal_code, (string) $city, (string) $country, (string) $recipient_name );
		if ( array() !== $missing_fields ) {
			$message = $this->delivery_address_error_message( $missing_fields );
			throw new PayloadValidationException( esc_html( $message ) );
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
				'person'      => sanitize_text_field( (string) $recipient_name ),
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
		$info = $this->contact_info(
			sanitize_email( $order->get_billing_email() ),
			sanitize_text_field( $order->get_billing_phone() )
		);

		/**
		 * Filters the SooCool task contactInfo. SooCool API 1.2.1 examples allow
		 * email, phone and mobile. Phone-like values are normalized before sending.
		 *
		 * @param array<string, string> $info
		 * @param WC_Order             $order
		 */
		$info = apply_filters( 'soocool_task_contact_info', $info, $order );
		$info = is_array( $info ) ? $info : array();

		return $this->sanitize_contact_info( $info );
	}

	/** @return array<string, string> */
	private function contact_info( string $email, string $phone ): array {
		$phone = $this->normalize_phone_number( $phone );
		$info  = array( 'email' => sanitize_email( $email ) );

		if ( '' !== $phone && $this->looks_like_phone_number( $phone ) ) {
			$info['phone'] = $phone;
		}

		if ( '' !== $phone && $this->looks_like_mobile( $phone ) ) {
			$info['mobile'] = $phone;
		}

		return $this->compact( $info );
	}

	/** @param array<string, mixed> $info @return array<string, string> */
	private function sanitize_contact_info( array $info ): array {
		$clean = array();

		if ( isset( $info['email'] ) ) {
			$clean['email'] = sanitize_email( (string) $info['email'] );
		}

		foreach ( array( 'phone', 'mobile' ) as $key ) {
			if ( ! isset( $info[ $key ] ) ) {
				continue;
			}

			$phone = $this->normalize_phone_number( sanitize_text_field( (string) $info[ $key ] ) );
			if ( 'mobile' === $key && ! $this->looks_like_mobile( $phone ) ) {
				continue;
			}
			if ( '' !== $phone && $this->looks_like_phone_number( $phone ) ) {
				$clean[ $key ] = $phone;
			}
		}

		return $this->compact( $clean );
	}

	private function normalize_phone_number( string $phone ): string {
		$phone = preg_replace( '/[^\d+]/', '', $phone ) ?? '';
		if ( 1 === preg_match( '/^0(\d{9})$/', $phone, $matches ) ) {
			return '+31' . $matches[1];
		}
		if ( 1 === preg_match( '/^31(\d{9})$/', $phone, $matches ) ) {
			return '+31' . $matches[1];
		}
		return sanitize_text_field( $phone );
	}

	private function looks_like_mobile( string $phone ): bool {
		$normalized = (string) preg_replace( '/[^\d+]/', '', $phone );
		$normalized = ltrim( $normalized, '+' );

		return 1 === preg_match( '/^(?:31|0)6\d{8}$/', $normalized );
	}

	private function looks_like_phone_number( string $phone ): bool {
		$normalized = (string) preg_replace( '/[^\d+]/', '', $phone );
		return 1 === preg_match( '/^\+?\d{10,15}$/', $normalized );
	}

	/**
	 * @param array{street:string, houseNumber:string} $address
	 * @return array<int, string>
	 */
	private function delivery_address_missing_fields( WC_Order $order, array $address, string $postal_code, string $city, string $country, string $recipient_name ): array {
		$fields = array();

		if ( '' === trim( $recipient_name ) ) {
			$fields[] = __( 'recipient name', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( (string) $address['street'] ) ) {
			$fields[] = __( 'street name', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( (string) $address['houseNumber'] ) ) {
			$fields[] = __( 'house number', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( $postal_code ) ) {
			$fields[] = __( 'postcode', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( $city ) ) {
			$fields[] = __( 'city', 'soocool-for-woocommerce' );
		}
		if ( '' === trim( $country ) ) {
			$fields[] = __( 'country', 'soocool-for-woocommerce' );
		}
		if ( array() === $this->delivery_contact_info( $order ) ) {
			$fields[] = __( 'email, phone number or valid Dutch mobile number', 'soocool-for-woocommerce' );
		}

		return $fields;
	}

	/** @param array<int, string> $missing_fields */
	private function delivery_address_error_message( array $missing_fields ): string {
		$fields = esc_html( implode( ', ', array_map( 'sanitize_text_field', $missing_fields ) ) );

		return sprintf(
			/* translators: %s: comma-separated list of missing WooCommerce address fields. */
			esc_html__( 'Delivery address is incomplete. Missing WooCommerce field(s): %s. Complete the shipping or billing address before sending this order to SooCool.', 'soocool-for-woocommerce' ),
			$fields
		);
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function compact( array $values ): array {
		return array_filter(
			$values,
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);
	}

	/** @return array{address:array{street:string,houseNumber:string},postal_code:string,city:string,country:string,recipient_name:string} */
	private function delivery_address_context( WC_Order $order ): array {
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

		$prefix = $has_shipping ? 'shipping' : 'billing';
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

	private function recipient_name_for_prefix( WC_Order $order, string $prefix ): string {
		if ( 'shipping' === $prefix ) {
			return trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		return trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	}

	private function country_code( string $country ): string {
		$country = strtoupper( sanitize_key( $country ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'NL';
	}

	private function postal_code( string $postal_code ): string {
		$postal_code = strtoupper( sanitize_text_field( trim( $postal_code ) ) );
		return (string) preg_replace( '/\s+/', '', $postal_code );
	}


	private function effective_offset_for_window( int $days, string $time_to ): int {
		$days = max( 0, $days );
		if ( 0 !== $days ) {
			return $days;
		}

		try {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$now      = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', $timezone );
			$end      = new \DateTimeImmutable( $this->date_for_offset( 0 ) . ' ' . $this->sanitize_time_for_window( $time_to ), $timezone );
		} catch ( \Exception ) {
			return $days;
		}

		return $end <= $now ? 1 : $days;
	}

	private function sanitize_time_for_window( string $time ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : self::DELIVERY_TIME_TO;
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
