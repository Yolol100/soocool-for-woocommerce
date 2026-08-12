<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Checkout\DeliverySchedule;
use SooCool\WooCommerce\Infrastructure\OptionDefaults;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\Infrastructure\StrictLocalDateTime;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Builds pickup and delivery tasks for the SooCool API 1.2.1 schema.
 */
final class TaskFactory {


	public function __construct( private readonly OptionRepository $options, private readonly TaskAddressFactory $addresses, private readonly TaskContactFactory $contacts, private readonly ?DeliverySchedule $delivery_schedule = null ) {}

	/** @param array<int, int|string> $good_ids Requested good IDs to attach to every task. @return array<int, array<string, mixed>> */
	public function create_tasks( WC_Order $order, array $good_ids = array() ): array {
		$settings        = $this->options->all();
		$good_ids        = $this->normalize_good_ids( $good_ids );
		$delivery_offset = (int) $settings['delivery_days_offset'];
		[ $pickup_date, $delivery_date ] = $this->fallback_pickup_and_delivery_dates( $delivery_offset );

		$requested_delivery_date       = $this->requested_delivery_date( $order );
		$use_requested_delivery_window = '' !== $requested_delivery_date && $this->requested_delivery_window_is_usable( $order, $requested_delivery_date );
		if ( $use_requested_delivery_window ) {
			$delivery_date          = $requested_delivery_date;
			$scheduled_pickup_date = $this->pickup_date_for_delivery( $delivery_date );
			if ( '' === $scheduled_pickup_date || ! $this->pickup_window_is_future( $scheduled_pickup_date ) ) {
				throw new PayloadValidationException( __( 'Het SooCool-ophaalvenster voor de gekozen bezorgdatum is verstreken. Kies een nieuwe bezorgdatum voordat de order opnieuw wordt gesynchroniseerd.', 'soocool-for-woocommerce' ) );
			}
			$pickup_date = $scheduled_pickup_date;
		}

		$tasks   = array();
		$tasks[] = $this->pickup_task( $settings, $pickup_date, $good_ids );
		$tasks[] = $this->delivery_task( $order, $settings, $delivery_date, $good_ids, $use_requested_delivery_window );

		return $tasks;
	}

	private function requested_delivery_date( WC_Order $order ): string {
		$value = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_DATE, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$date = sanitize_text_field( (string) $value );
		if ( null !== $this->delivery_schedule ) {
			return $this->delivery_schedule->is_usable_order_date( $date ) ? $date : '';
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches )
			|| ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
			return '';
		}

		if ( $date < $this->date_for_offset( 0 ) ) {
			return '';
		}

		return $date;
	}

	private function pickup_date_for_delivery( string $delivery_date ): string {
		if ( null !== $this->delivery_schedule ) {
			$scheduled = $this->delivery_schedule->pickup_date_for_delivery( $delivery_date );
			if ( '' !== $scheduled ) {
				return $scheduled;
			}
		}

		try {
			return ( new \DateTimeImmutable( $delivery_date . ' 00:00:00', $this->soocool_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		} catch ( \Exception ) {
			return '';
		}
	}

	/** @return array{0:string,1:string} Pickup date followed by delivery date. */
	private function fallback_pickup_and_delivery_dates( int $delivery_offset ): array {
		$delivery_offset = max( 1, min( 30, $delivery_offset ) );
		for ( $offset = $delivery_offset; $offset <= $delivery_offset + 7; $offset++ ) {
			$delivery_date = $this->date_for_offset( $offset );
			$pickup_date   = $this->pickup_date_for_delivery( $delivery_date );
			if ( '' !== $pickup_date && $pickup_date < $delivery_date && $this->pickup_window_is_future( $pickup_date ) ) {
				return array( $pickup_date, $delivery_date );
			}
		}

		throw new PayloadValidationException( __( 'Er kon geen geldige toekomstige SooCool-ophaaldatum vóór de fallback-bezorgdatum worden bepaald.', 'soocool-for-woocommerce' ) );
	}

	private function pickup_window_is_future( string $pickup_date ): bool {
		$timezone   = $this->soocool_timezone();
		$pickup_end = StrictLocalDateTime::from_date_and_time( $pickup_date, OptionDefaults::PICKUP_TIME_TO, $timezone );

		return null !== $pickup_end && new \DateTimeImmutable( 'now', $timezone ) < $pickup_end;
	}

	/** @param array<string, mixed> $settings @param array<int, int> $good_ids @return array<string, mixed> */
	private function pickup_task( array $settings, string $date, array $good_ids ): array {
		foreach ( array( 'pickup_company', 'pickup_street', 'pickup_house_number', 'pickup_postal_code', 'pickup_city', 'pickup_country' ) as $field ) {
			if ( '' === trim( (string) ( $settings[ $field ] ?? '' ) ) ) {
				throw new PayloadValidationException( __( 'Ophaalinstellingen zijn onvolledig. Vul het ophaaladres aan voordat orders naar SooCool worden gestuurd.', 'soocool-for-woocommerce' ) );
			}
		}

		$pickup_country = $this->country_code( (string) $settings['pickup_country'] );
		if ( '' === $pickup_country ) {
			throw new PayloadValidationException( __( 'Ophaalland moet een geldige ISO-landcode van twee letters zijn.', 'soocool-for-woocommerce' ) );
		}

		$contact_info = $this->contacts->from_email_phone(
			sanitize_email( (string) $settings['pickup_email'] ),
			sanitize_text_field( (string) $settings['pickup_phone'] ),
			$pickup_country
		);
		if ( array() === $contact_info ) {
			throw new PayloadValidationException( __( 'Ophaalcontact is onvolledig. Voeg in de SooCool-instellingen een geldig e-mailadres of telefoonnummer toe.', 'soocool-for-woocommerce' ) );
		}

		$pickup_window = array(
			'time_from' => OptionDefaults::PICKUP_TIME_FROM,
			'time_to'   => OptionDefaults::PICKUP_TIME_TO,
		);

		$task = array(
			'taskType'    => 'pickup',
			'timeWindow'  => array(
				'startTime' => $this->date_time_for_api( $date, $pickup_window['time_from'] ),
				'endTime'   => $this->date_time_for_api( $date, $pickup_window['time_to'] ),
			),
			'address'     => array(
				'person'      => sanitize_text_field( (string) ( '' !== trim( (string) $settings['pickup_contact_name'] ) ? $settings['pickup_contact_name'] : $settings['pickup_company'] ) ),
				'street'      => sanitize_text_field( (string) $settings['pickup_street'] ),
				'houseNumber' => sanitize_text_field( (string) $settings['pickup_house_number'] ),
				'postCode'    => $this->postal_code( (string) $settings['pickup_postal_code'] ),
				'city'        => sanitize_text_field( (string) $settings['pickup_city'] ),
				'country'     => $pickup_country,
			),
			'contactInfo' => $contact_info,
			'goods'       => $good_ids,
		);

		return $this->compact( $task );
	}

	/** @param array<string, mixed> $settings @param array<int, int> $good_ids @return array<string, mixed> */
	private function delivery_task( WC_Order $order, array $settings, string $date, array $good_ids, bool $use_requested_delivery_window ): array {
		$delivery_context = $this->addresses->delivery_context( $order );
		$address          = $delivery_context['address'];
		$postal_code      = $delivery_context['postal_code'];
		$city             = $delivery_context['city'];
		$country          = $delivery_context['country'];
		$recipient_name   = $delivery_context['recipient_name'];

		$missing_fields = $this->addresses->missing_delivery_fields( $order, $address, (string) $postal_code, (string) $city, (string) $country, (string) $recipient_name );
		if ( array() !== $missing_fields ) {
			$message = $this->addresses->missing_delivery_fields_message( $missing_fields );
			throw new PayloadValidationException( $message );
		}

		$time_slot    = $use_requested_delivery_window
			? $this->requested_delivery_time_slot( $order )
			: $this->normalized_time_window( OptionDefaults::DELIVERY_TIME_FROM, OptionDefaults::DELIVERY_TIME_TO );
		$time_from    = $time_slot['time_from'];
		$time_to      = $time_slot['time_to'];
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
			'contactInfo'  => $this->contacts->for_delivery_order( $order, (string) $country ),
			'goods'        => $good_ids,
		);

		return $this->compact( $task );
	}

	/** @return array{time_from:string,time_to:string} */
	private function requested_delivery_time_slot( WC_Order $order ): array {
		$requested_from = $this->requested_delivery_time( $order, OrderMeta::REQUESTED_DELIVERY_TIME_FROM );
		$requested_to   = $this->requested_delivery_time( $order, OrderMeta::REQUESTED_DELIVERY_TIME_TO );
		if ( '' !== $requested_from && '' !== $requested_to && $requested_to > $requested_from ) {
			return array(
				'time_from' => $requested_from,
				'time_to'   => $requested_to,
			);
		}

		return $this->normalized_time_window( OptionDefaults::DELIVERY_TIME_FROM, OptionDefaults::DELIVERY_TIME_TO );
	}

	private function requested_delivery_time( WC_Order $order, string $meta_key ): string {
		$value = $order->get_meta( $meta_key, true );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$time = sanitize_text_field( (string) $value );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	private function requested_delivery_window_is_usable( WC_Order $order, string $date ): bool {
		$time_slot = $this->requested_delivery_time_slot( $order );
		$end       = StrictLocalDateTime::from_date_and_time( $date, $time_slot['time_to'], $this->soocool_timezone() );
		if ( null === $end ) {
			return false;
		}

		return $end > new \DateTimeImmutable( 'now', $this->soocool_timezone() );
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function compact( array $values ): array {
		return array_filter(
			$values,
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);
	}

	private function country_code( string $country ): string {
		$country = strtoupper( sanitize_key( $country ) );
		return 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	private function postal_code( string $postal_code ): string {
		$postal_code = strtoupper( sanitize_text_field( trim( $postal_code ) ) );
		return (string) preg_replace( '/\s+/', '', $postal_code );
	}

	/** @return array{time_from:string,time_to:string} */
	private function normalized_time_window( string $time_from, string $time_to ): array {
		$time_from = $this->sanitize_time( $time_from, OptionDefaults::DELIVERY_TIME_FROM );
		$time_to   = $this->sanitize_time( $time_to, OptionDefaults::DELIVERY_TIME_TO );

		if ( $time_to <= $time_from ) {
			return array(
				'time_from' => OptionDefaults::DELIVERY_TIME_FROM,
				'time_to'   => OptionDefaults::DELIVERY_TIME_TO,
			);
		}

		return array(
			'time_from' => $time_from,
			'time_to'   => $time_to,
		);
	}

	private function sanitize_time( string $time, string $fallback ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : $fallback;
	}

	private function date_time_for_api( string $date, string $time ): string {
		$time = $this->sanitize_time( $time, OptionDefaults::DELIVERY_TIME_FROM );
		$date_time = StrictLocalDateTime::from_date_and_time( $date, $time, $this->soocool_timezone() );
		if ( null === $date_time ) {
			throw new PayloadValidationException( __( 'SooCool taaktijd kon niet worden gegenereerd.', 'soocool-for-woocommerce' ) );
		}

		return $date_time->format( DATE_ATOM );
	}

	private function date_for_offset( int $days ): string {
		try {
			$date = ( new \DateTimeImmutable( 'now', $this->soocool_timezone() ) )->modify( '+' . max( 0, $days ) . ' days' );
		} catch ( \Exception ) {
			$date = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Europe/Amsterdam' ) ) )->modify( '+' . max( 0, $days ) . ' days' );
		}

		return $date->format( 'Y-m-d' );
	}

	private function soocool_timezone(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Amsterdam' );
	}

	/** @param array<int, int|string> $good_ids @return array<int, int> */
	private function normalize_good_ids( array $good_ids ): array {
		$normalized = array();
		foreach ( $good_ids as $good_id ) {
			$id = NumericIdentifier::non_zero( $good_id );
			if ( null !== $id ) {
				$normalized[] = $id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
