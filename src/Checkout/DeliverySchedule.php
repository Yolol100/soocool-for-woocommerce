<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

use SooCool\WooCommerce\Infrastructure\OptionDefaults;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\StrictLocalDateTime;

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

	/** @var array<int, int> SooCool collects on Wednesday, Friday and Saturday. */
	private const SOOCOOL_PICKUP_WEEKDAYS = array( 3, 5, 6 );

	/** @var array<string, mixed>|null */
	private ?array $settings_cache = null;

	/** @var array<int, array{date:string,label:string,weekday:string,cutoff:string}>|null */
	private ?array $available_options_cache = null;

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $time_slots_cache = array();

	public function __construct( private readonly OptionRepository $options ) {}

	/** @return array<int, array<string, mixed>> */
	public function default_rules(): array {
		return $this->options->default_delivery_rules();
	}

	/** @return array<int, array<string, mixed>> */
	public function default_time_slots(): array {
		return $this->options->default_delivery_time_slots();
	}

	/** @return array<int, array<string, mixed>> */
	private function default_schedule(): array {
		return $this->options->default_delivery_schedule();
	}

	/** @return array<int, array{date:string,label:string,weekday:string,cutoff:string}> */
	public function available_options(): array {
		if ( null !== $this->available_options_cache ) {
			return $this->available_options_cache;
		}

		$settings = $this->settings();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			$this->available_options_cache = array();
			return $this->available_options_cache;
		}

		$now        = $this->now();
		$today      = $now->setTime( 0, 0, 0 );
		$days_ahead = max( 7, min( 92, absint( $settings['checkout_delivery_days_ahead'] ?? 92 ) ) );
		$last_day   = $today->modify( '+' . $days_ahead . ' days' );
		$holidays   = $this->holiday_dates( (string) ( $settings['checkout_delivery_holidays'] ?? '' ) );
		$rules      = $this->rules( $settings['checkout_delivery_schedule'] ?? $this->default_schedule() );
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

				if ( ! $this->is_pickup_window_available( $date, $now ) ) {
					continue;
				}

				$cutoff = $this->cutoff_for_delivery( $delivery_date, $cutoff_weekday, $cutoff_time );
				if ( null === $cutoff || $now >= $cutoff ) {
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

		$this->available_options_cache = array_values( $options );
		return $this->available_options_cache;
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
		return '' !== $this->available_time_slot_label( $date, $time_from, $time_to );
	}

	/** @return array<int, array{enabled:bool,label:string,time_from:string,time_to:string,cutoff_time:string,weekdays:array<int,string>,sort_order:int,available:bool,status_label:string,display_label:string}> */
	public function available_time_slots_for_date( string $date, bool $include_unavailable = false ): array {
		$date = $this->sanitize_date( $date );
		if ( '' === $date || ! $this->is_valid_date( $date ) ) {
			return array();
		}

		$cache_key = $date . '|' . ( $include_unavailable ? '1' : '0' );
		if ( isset( $this->time_slots_cache[ $cache_key ] ) ) {
			return $this->time_slots_cache[ $cache_key ];
		}

		$settings = $this->settings();
		$slots    = $this->time_slots_for_date( $date, $settings );
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

		$this->time_slots_cache[ $cache_key ] = $visible;
		return $this->time_slots_cache[ $cache_key ];
	}

	public function available_time_slot_label( string $date, string $time_from, string $time_to ): string {
		$date      = $this->sanitize_date( $date );
		$time_from = $this->sanitize_time( $time_from );
		$time_to   = $this->sanitize_time( $time_to );
		if ( '' === $date || '' === $time_from || '' === $time_to || $time_to <= $time_from ) {
			return '';
		}

		foreach ( $this->available_time_slots_for_date( $date, true ) as $slot ) {
			if ( $time_from === $slot['time_from'] && $time_to === $slot['time_to'] && (bool) $slot['available'] ) {
				return (string) $slot['display_label'];
			}
		}

		return '';
	}

	/** @return array<string, mixed> */
	public function matching_time_slot( string $date, string $time_from, string $time_to ): array {
		foreach ( $this->available_time_slots_for_date( $date, true ) as $slot ) {
			if ( $time_from === (string) $slot['time_from'] && $time_to === (string) $slot['time_to'] ) {
				return $slot;
			}
		}

		return array();
	}

	public function format_time_slot_label( string $time_from, string $time_to, string $label = '' ): string {
		$time_from = $this->sanitize_time( $time_from );
		$time_to   = $this->sanitize_time( $time_to );
		$label     = $this->localized_slot_label( sanitize_text_field( $label ) );
		if ( '' === $time_from || '' === $time_to || $time_to <= $time_from ) {
			return '';
		}

		$time_label = $time_from . ' - ' . $time_to;
		return '' !== $label ? trim( $label . ' (' . $time_label . ')' ) : $time_label;
	}

	public function pickup_date_for_delivery( string $date ): string {
		$date = $this->sanitize_date( $date );
		if ( '' === $date ) {
			return '';
		}

		try {
			$delivery_date = new \DateTimeImmutable( $date . ' 00:00:00', $this->timezone() );
		} catch ( \Exception ) {
			return '';
		}

		for ( $days_before = 1; $days_before <= 7; $days_before++ ) {
			$candidate = $delivery_date->modify( '-' . $days_before . ' days' );
			if ( in_array( (int) $candidate->format( 'N' ), self::SOOCOOL_PICKUP_WEEKDAYS, true ) ) {
				return $candidate->format( 'Y-m-d' );
			}
		}

		return '';
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

		$label = function_exists( 'wp_date' )
			? wp_date( 'l j F', $date_time->getTimestamp(), $this->timezone() )
			: $date_time->format( 'l j F' );

		return trim( $label );
	}

	private function localized_slot_label( string $label ): string {
		return match ( $label ) {
			'Ochtend - Middag' => __( 'Ochtend - Middag', 'soocool-for-woocommerce' ),
			'Avond'            => __( 'Avond', 'soocool-for-woocommerce' ),
			default            => $label,
		};
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
			if ( '' === $time_from || '' === $time_to || '' === $cutoff || $time_to <= $time_from || $cutoff > $time_to ) {
				continue;
			}

			$clean[] = array(
				'id'          => sanitize_key( (string) ( $slot['id'] ?? '' ) ),
				'type'        => sanitize_key( (string) ( $slot['type'] ?? $slot['id'] ?? '' ) ),
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
		foreach ( $this->time_slots_for_date( $date, $settings ) as $slot ) {
			if ( $this->is_time_slot_available_for_date( $slot, $date ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $settings @return array<int, array<string, mixed>> */
	private function time_slots_for_date( string $date, array $settings ): array {
		$weekday = $this->weekday_key_for_date( $date );
		if ( '' === $weekday ) {
			return array();
		}

		$schedule = is_array( $settings['checkout_delivery_schedule'] ?? null ) ? $settings['checkout_delivery_schedule'] : $this->default_schedule();
		foreach ( $schedule as $rule ) {
			if ( ! is_array( $rule ) || ! (bool) ( $rule['enabled'] ?? true ) ) {
				continue;
			}

			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? '' ) );
			if ( $weekday !== $delivery_weekday ) {
				continue;
			}

			return $this->time_slots( $rule['slots'] ?? array() );
		}

		return array();
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

		$cutoff = StrictLocalDateTime::from_date_and_time( $date, (string) $slot['cutoff_time'], $this->timezone() );
		return null !== $cutoff && $this->now() < $cutoff;
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

	private function cutoff_for_delivery( \DateTimeImmutable $delivery_date, string $cutoff_weekday, string $cutoff_time ): ?\DateTimeImmutable {
		$delivery_iso = (int) $delivery_date->format( 'N' );
		$cutoff_iso   = self::WEEKDAYS[ $cutoff_weekday ] ?? $delivery_iso;
		$days_before  = ( $delivery_iso - $cutoff_iso + 7 ) % 7;
		$cutoff_date  = $delivery_date->modify( '-' . $days_before . ' days' );

		return StrictLocalDateTime::from_date_and_time( $cutoff_date->format( 'Y-m-d' ), $cutoff_time, $this->timezone() );
	}

	private function is_pickup_window_available( string $date, \DateTimeImmutable $now ): bool {
		$pickup_date = $this->pickup_date_for_delivery( $date );
		if ( '' === $pickup_date ) {
			return false;
		}

		$pickup_end = StrictLocalDateTime::from_date_and_time( $pickup_date, OptionDefaults::PICKUP_TIME_TO, $this->timezone() );

		return null !== $pickup_end && $now < $pickup_end;
	}

	/** @return array<string, mixed> */
	private function settings(): array {
		if ( null === $this->settings_cache ) {
			$this->settings_cache = $this->options->all();
		}

		return $this->settings_cache;
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
