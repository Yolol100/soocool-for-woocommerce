<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class DeliverySchedule {

	/** @var array<string, int> */
	private const WEEKDAYS = array(
		'monday'    => 1,
		'tuesday'   => 2,
		'wednesday' => 3,
		'thursday'  => 4,
		'friday'    => 5,
		'saturday'  => 6,
		'sunday'    => 7,
	);

	/** @var array<string, string> */
	private const DUTCH_WEEKDAYS = array(
		'monday'    => 'Maandag',
		'tuesday'   => 'Dinsdag',
		'wednesday' => 'Woensdag',
		'thursday'  => 'Donderdag',
		'friday'    => 'Vrijdag',
		'saturday'  => 'Zaterdag',
		'sunday'    => 'Zondag',
	);

	/** @var array<int, string> */
	private const DUTCH_MONTHS = array(
		1  => 'januari',
		2  => 'februari',
		3  => 'maart',
		4  => 'april',
		5  => 'mei',
		6  => 'juni',
		7  => 'juli',
		8  => 'augustus',
		9  => 'september',
		10 => 'oktober',
		11 => 'november',
		12 => 'december',
	);

	public function __construct( private readonly OptionRepository $options ) {}

	/** @return array<int, array<string, mixed>> */
	public function default_rules(): array {
		return $this->options->default_delivery_rules();
	}

	/** @return array<int, array<string, mixed>> */
	public function default_time_slots(): array {
		return $this->options->default_delivery_time_slots();
	}

	/** @return array<int, array{date:string,label:string,weekday:string,cutoff:string}> */
	public function available_options(): array {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			return array();
		}

		$now        = $this->now();
		$today      = $now->setTime( 0, 0, 0 );
		$days_ahead = max( 7, min( 92, absint( $settings['checkout_delivery_days_ahead'] ?? 92 ) ) );
		$last_day   = $today->modify( '+' . $days_ahead . ' days' );
		$holidays   = $this->holiday_dates( (string) ( $settings['checkout_delivery_holidays'] ?? '' ) );
		$rules      = $this->rules( $settings['checkout_delivery_rules'] ?? $this->default_rules() );
		$options    = array();

		foreach ( $rules as $rule ) {
			$delivery_weekday = (string) $rule['delivery_weekday'];
			$cutoff_weekday   = (string) $rule['cutoff_weekday'];
			$cutoff_time      = (string) $rule['cutoff_time'];
			$first_delivery   = $this->next_weekday_date( $today, $delivery_weekday );
			$max_weeks        = (int) ceil( ( $days_ahead + 7 ) / 7 );

			for ( $week = 0; $week <= $max_weeks; $week++ ) {
				$delivery_date = $first_delivery->modify( '+' . $week . ' weeks' );
				if ( $delivery_date > $last_day ) {
					continue;
				}

				$date = $delivery_date->format( 'Y-m-d' );
				if ( in_array( $date, $holidays, true ) ) {
					continue;
				}

				if ( ! $this->is_after_pickup_date_if_needed( $date, $settings, $today ) ) {
					continue;
				}

				$cutoff = $this->cutoff_for_delivery( $delivery_date, $cutoff_weekday, $cutoff_time );
				if ( $now >= $cutoff ) {
					continue;
				}

				if ( ! $this->has_available_time_slot_for_date( $date, $settings ) ) {
					continue;
				}

				$options[ $date ] = array(
					'date'    => $date,
					'label'   => $this->format_label( $date ),
					'weekday' => $delivery_weekday,
					'cutoff'  => $cutoff->format( DATE_ATOM ),
				);
			}
		}

		ksort( $options );

		return array_values( $options );
	}

	public function is_valid_date( string $date ): bool {
		$date = $this->sanitize_date( $date );
		if ( '' === $date ) {
			return false;
		}

		foreach ( $this->available_options() as $option ) {
			if ( $date === $option['date'] ) {
				return true;
			}
		}

		return false;
	}

	public function is_valid_time_slot( string $date, string $time_from, string $time_to ): bool {
		$date      = $this->sanitize_date( $date );
		$time_from = $this->sanitize_time( $time_from );
		$time_to   = $this->sanitize_time( $time_to );
		if ( '' === $date || '' === $time_from || '' === $time_to || $time_to <= $time_from ) {
			return false;
		}

		foreach ( $this->available_time_slots_for_date( $date, true ) as $slot ) {
			if ( $time_from === $slot['time_from'] && $time_to === $slot['time_to'] && (bool) $slot['available'] ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<int, array{enabled:bool,label:string,time_from:string,time_to:string,cutoff_time:string,weekdays:array<int,string>,sort_order:int,available:bool,status_label:string,display_label:string}> */
	public function available_time_slots_for_date( string $date, bool $include_unavailable = false ): array {
		$date = $this->sanitize_date( $date );
		if ( '' === $date ) {
			return array();
		}

		$settings = $this->options->all();
		$slots    = $this->time_slots( $settings['checkout_delivery_time_slots'] ?? $this->default_time_slots() );
		$visible  = array();

		foreach ( $slots as $slot ) {
			if ( ! $this->slot_matches_date( $slot, $date ) ) {
				continue;
			}

			$available            = $this->is_time_slot_available_for_date( $slot, $date );
			$slot['available']    = $available;
			$slot['status_label'] = $available ? __( 'Beschikbaar', 'soocool-for-woocommerce' ) : __( 'Niet meer beschikbaar', 'soocool-for-woocommerce' );
			$slot['display_label'] = $this->format_time_slot_label( (string) $slot['time_from'], (string) $slot['time_to'], (string) $slot['label'] );

			if ( $available || $include_unavailable ) {
				$visible[] = $slot;
			}
		}

		usort(
			$visible,
			static function ( array $a, array $b ): int {
				$sort = (int) $a['sort_order'] <=> (int) $b['sort_order'];
				return 0 !== $sort ? $sort : strcmp( (string) $a['time_from'], (string) $b['time_from'] );
			}
		);

		return $visible;
	}

	public function format_time_slot_label( string $time_from, string $time_to, string $label = '' ): string {
		$time_from = $this->sanitize_time( $time_from );
		$time_to   = $this->sanitize_time( $time_to );
		$label     = sanitize_text_field( $label );
		if ( '' === $time_from || '' === $time_to ) {
			return '';
		}

		$time_label = $time_from . ' - ' . $time_to;
		return '' !== $label ? trim( $label . ' (' . $time_label . ')' ) : $time_label;
	}

	public function is_usable_order_date( string $date, string $minimum_after_date = '' ): bool {
		$date = $this->sanitize_date( $date );
		if ( '' === $date ) {
			return false;
		}

		$today = $this->now()->setTime( 0, 0, 0 )->format( 'Y-m-d' );
		if ( $date < $today ) {
			return false;
		}

		$minimum_after_date = $this->sanitize_date( $minimum_after_date );
		if ( '' !== $minimum_after_date && $date <= $minimum_after_date ) {
			return false;
		}

		return true;
	}

	public function format_label( string $date ): string {
		$date = $this->sanitize_date( $date );
		if ( '' === $date ) {
			return '';
		}

		try {
			$date_time = new \DateTimeImmutable( $date . ' 00:00:00', $this->timezone() );
		} catch ( \Exception ) {
			return '';
		}

		$weekday = array_search( (int) $date_time->format( 'N' ), self::WEEKDAYS, true );
		$month   = self::DUTCH_MONTHS[ (int) $date_time->format( 'n' ) ] ?? $date_time->format( 'F' );

		return trim( ( self::DUTCH_WEEKDAYS[ (string) $weekday ] ?? '' ) . ' ' . $date_time->format( 'j' ) . ' ' . $month );
	}

	private function sanitize_date( string $date ): string {
		$date = sanitize_text_field( $date );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$parts = array_map( 'absint', explode( '-', $date ) );
		return checkdate( $parts[1] ?? 0, $parts[2] ?? 0, $parts[0] ?? 0 ) ? $date : '';
	}

	private function sanitize_time( string $time ): string {
		$time = sanitize_text_field( $time );
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '';
	}

	/** @param mixed $rules @return array<int, array{delivery_weekday:string,cutoff_weekday:string,cutoff_time:string}> */
	private function rules( mixed $rules ): array {
		if ( ! is_array( $rules ) ) {
			$rules = $this->default_rules();
		}

		$clean = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! (bool) ( $rule['enabled'] ?? true ) ) {
				continue;
			}

			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? '' ) );
			$cutoff_weekday   = sanitize_key( (string) ( $rule['cutoff_weekday'] ?? '' ) );
			$cutoff_time      = sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '13:00' ) );
			if ( ! isset( self::WEEKDAYS[ $delivery_weekday ], self::WEEKDAYS[ $cutoff_weekday ] ) || 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff_time ) ) {
				continue;
			}

			$clean[] = array(
				'delivery_weekday' => $delivery_weekday,
				'cutoff_weekday'   => $cutoff_weekday,
				'cutoff_time'      => $cutoff_time,
			);
		}

		return array() !== $clean ? $clean : $this->rules( $this->default_rules() );
	}

	/** @param mixed $value @return array<int, array{enabled:bool,label:string,time_from:string,time_to:string,cutoff_time:string,weekdays:array<int,string>,sort_order:int}> */
	private function time_slots( mixed $value ): array {
		$slots = is_array( $value ) ? $value : $this->default_time_slots();
		$clean = array();

		foreach ( $slots as $index => $slot ) {
			if ( ! is_array( $slot ) || ! (bool) ( $slot['enabled'] ?? true ) ) {
				continue;
			}

			$time_from = $this->sanitize_time( (string) ( $slot['time_from'] ?? '' ) );
			$time_to   = $this->sanitize_time( (string) ( $slot['time_to'] ?? '' ) );
			$cutoff    = $this->sanitize_time( (string) ( $slot['cutoff_time'] ?? $time_from ) );
			if ( '' === $time_from || '' === $time_to || '' === $cutoff || $time_to <= $time_from ) {
				continue;
			}

			$clean[] = array(
				'enabled'     => true,
				'label'       => sanitize_text_field( (string) ( $slot['label'] ?? '' ) ),
				'time_from'   => $time_from,
				'time_to'     => $time_to,
				'cutoff_time' => $cutoff,
				'weekdays'    => $this->sanitize_weekdays( $slot['weekdays'] ?? array_keys( self::WEEKDAYS ) ),
				'sort_order'  => is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : (int) $index,
			);
		}

		return array() !== $clean ? $clean : $this->time_slots( $this->default_time_slots() );
	}

	/** @param mixed $value @return array<int, string> */
	private function sanitize_weekdays( mixed $value ): array {
		$raw   = is_array( $value ) ? $value : array_keys( self::WEEKDAYS );
		$clean = array();
		foreach ( $raw as $weekday ) {
			$weekday = sanitize_key( (string) $weekday );
			if ( isset( self::WEEKDAYS[ $weekday ] ) ) {
				$clean[] = $weekday;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		return array() !== $clean ? $clean : array_keys( self::WEEKDAYS );
	}

	/** @param array<string, mixed> $settings */
	private function has_available_time_slot_for_date( string $date, array $settings ): bool {
		foreach ( $this->time_slots( $settings['checkout_delivery_time_slots'] ?? $this->default_time_slots() ) as $slot ) {
			if ( $this->slot_matches_date( $slot, $date ) && $this->is_time_slot_available_for_date( $slot, $date ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $slot */
	private function slot_matches_date( array $slot, string $date ): bool {
		$weekday = $this->weekday_key_for_date( $date );
		return '' !== $weekday && in_array( $weekday, $slot['weekdays'] ?? array(), true );
	}

	/** @param array<string, mixed> $slot */
	private function is_time_slot_available_for_date( array $slot, string $date ): bool {
		$date = $this->sanitize_date( $date );
		if ( '' === $date ) {
			return false;
		}

		try {
			$cutoff = new \DateTimeImmutable( $date . ' ' . (string) $slot['cutoff_time'], $this->timezone() );
		} catch ( \Exception ) {
			return false;
		}

		return $this->now() < $cutoff;
	}

	private function weekday_key_for_date( string $date ): string {
		try {
			$date_time = new \DateTimeImmutable( $date . ' 00:00:00', $this->timezone() );
		} catch ( \Exception ) {
			return '';
		}

		return (string) array_search( (int) $date_time->format( 'N' ), self::WEEKDAYS, true );
	}

	/** @return array<int, string> */
	private function holiday_dates( string $value ): array {
		$dates = array();
		foreach ( preg_split( '/[\s,]+/', $value ) ?: array() as $date ) {
			$date = $this->sanitize_date( (string) $date );
			if ( '' !== $date ) {
				$dates[] = $date;
			}
		}

		return array_values( array_unique( $dates ) );
	}

	private function next_weekday_date( \DateTimeImmutable $base, string $weekday ): \DateTimeImmutable {
		$current_iso = (int) $base->format( 'N' );
		$target_iso  = self::WEEKDAYS[ $weekday ] ?? $current_iso;
		$days        = ( $target_iso - $current_iso + 7 ) % 7;

		return $base->modify( '+' . $days . ' days' );
	}

	private function cutoff_for_delivery( \DateTimeImmutable $delivery_date, string $cutoff_weekday, string $cutoff_time ): \DateTimeImmutable {
		$delivery_iso = (int) $delivery_date->format( 'N' );
		$cutoff_iso   = self::WEEKDAYS[ $cutoff_weekday ] ?? $delivery_iso;
		$days_before  = ( $delivery_iso - $cutoff_iso + 7 ) % 7;
		$cutoff_date  = $delivery_date->modify( '-' . $days_before . ' days' );

		try {
			return new \DateTimeImmutable( $cutoff_date->format( 'Y-m-d' ) . ' ' . $cutoff_time, $this->timezone() );
		} catch ( \Exception ) {
			return $delivery_date->setTime( 0, 0, 0 );
		}
	}

	/** @param array<string, mixed> $settings */
	private function is_after_pickup_date_if_needed( string $date, array $settings, \DateTimeImmutable $today ): bool {
		if ( ! (bool) ( $settings['enable_pickup'] ?? false ) ) {
			return true;
		}

		$pickup_offset = max( 0, absint( $settings['pickup_days_offset'] ?? 0 ) );
		$pickup_date   = $today->modify( '+' . $pickup_offset . ' days' )->format( 'Y-m-d' );

		return $date > $pickup_date;
	}

	private function now(): \DateTimeImmutable {
		$override = apply_filters( 'soocool_delivery_schedule_now', null );
		if ( $override instanceof \DateTimeInterface ) {
			return new \DateTimeImmutable( $override->format( 'Y-m-d H:i:s' ), $this->timezone() );
		}
		if ( is_string( $override ) && '' !== trim( $override ) ) {
			try {
				return new \DateTimeImmutable( sanitize_text_field( $override ), $this->timezone() );
			} catch ( \Exception ) {
				// Fall through to the real WordPress site time.
			}
		}

		$current = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', $this->timezone() );
		if ( $current instanceof \DateTimeImmutable ) {
			return $current;
		}

		return new \DateTimeImmutable( $current->format( 'Y-m-d H:i:s' ), $this->timezone() );
	}

	private function timezone(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}
}
