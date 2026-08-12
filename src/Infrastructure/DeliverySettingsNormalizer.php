<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class DeliverySettingsNormalizer {

	public function __construct( private readonly OptionDefaults $defaults ) {}

	/** @return array<int, array<string, mixed>> */
	private function default_delivery_rules(): array {
		return $this->defaults->delivery_rules();
	}

	/** @return array<int, array<string, mixed>> */
	private function default_delivery_time_slots(): array {
		return $this->defaults->delivery_time_slots();
	}

	/** @return array<int, array<string, mixed>> */
	private function default_delivery_schedule(): array {
		return $this->defaults->delivery_schedule();
	}

	public function sanitize_delivery_rules( mixed $value, array $fallback ): array {
		$allowed_weekdays = $this->allowed_weekdays();
		$rules            = is_array( $value ) ? $value : $fallback;
		$clean            = array();
		$seen_weekdays    = array();

		foreach ( $this->enabled_first_rule_entries( $rules, 7 ) as $entry ) {
			$rule = $entry['rule'];

			$delivery_weekday = sanitize_key( $this->scalar_string( $rule['delivery_weekday'] ?? null ) );
			$cutoff_weekday   = sanitize_key( $this->scalar_string( $rule['cutoff_weekday'] ?? null ) );
			$cutoff_time      = $this->sanitize_time( sanitize_text_field( $this->scalar_string( $rule['cutoff_time'] ?? null, '13:00' ) ), '13:00' );
			if ( ! in_array( $delivery_weekday, $allowed_weekdays, true ) || ! in_array( $cutoff_weekday, $allowed_weekdays, true ) ) {
				continue;
			}

			$clean_rule = array(
				'enabled'          => $this->to_bool( $rule['enabled'] ?? true ),
				'delivery_weekday' => $delivery_weekday,
				'cutoff_weekday'   => $cutoff_weekday,
				'cutoff_time'      => $cutoff_time,
			);
			if ( isset( $seen_weekdays[ $delivery_weekday ] ) ) {
				$existing_index = (int) $seen_weekdays[ $delivery_weekday ];
				if ( ! (bool) $clean[ $existing_index ]['enabled'] && (bool) $clean_rule['enabled'] ) {
					$clean[ $existing_index ] = $clean_rule;
				}
				continue;
			}

			$seen_weekdays[ $delivery_weekday ] = count( $clean );
			$clean[]                             = $clean_rule;
		}

		$enabled = array_filter( $clean, static fn ( array $rule ): bool => (bool) $rule['enabled'] );
		if ( array() === $clean || array() === $enabled ) {
			return $this->default_delivery_rules();
		}

		return array_values( $clean );
	}

	public function sanitize_delivery_time_slots( mixed $value, array $fallback ): array {
		$allowed_weekdays = $this->allowed_weekdays();
		$slots            = is_array( $value ) ? $value : $fallback;
		$clean            = array();
		$seen             = array();

		foreach ( $this->enabled_first_slot_entries( $slots, 84 ) as $entry ) {
			$index = $entry['index'];
			$slot  = $entry['slot'];

			$sanitized = $this->sanitize_slot( $slot );
			if ( null === $sanitized ) {
				continue;
			}

			$time_from       = $sanitized['time_from'];
			$time_to         = $sanitized['time_to'];
			$enabled          = (bool) $sanitized['enabled'];
			$has_weekdays_key = array_key_exists( 'weekdays', $slot );
			$raw_weekdays    = is_array( $slot['weekdays'] ?? null ) ? $slot['weekdays'] : ( $has_weekdays_key ? array() : $allowed_weekdays );
			$weekdays        = array();
			foreach ( $raw_weekdays as $weekday ) {
				$weekday = sanitize_key( $this->scalar_string( $weekday ) );
				if ( in_array( $weekday, $allowed_weekdays, true ) ) {
					$weekdays[] = $weekday;
				}
			}
			$weekdays = array_values( array_unique( $weekdays ) );
			if ( array() === $weekdays ) {
				if ( $enabled && $has_weekdays_key ) {
					continue;
				}
				$weekdays = $allowed_weekdays;
			}

			$duplicate = false;
			foreach ( $weekdays as $weekday ) {
				$fingerprint = $time_from . '|' . $time_to . '|' . $weekday;
				if ( isset( $seen[ $fingerprint ] ) ) {
					$duplicate = true;
					break;
				}
			}
			if ( $duplicate ) {
				continue;
			}
			foreach ( $weekdays as $weekday ) {
				$seen[ $time_from . '|' . $time_to . '|' . $weekday ] = true;
			}

			$sanitized['weekdays']   = $weekdays;
			$sanitized['sort_order'] = is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : (int) $index;
			$clean[]                  = $sanitized;
		}

		$enabled = array_filter( $clean, static fn ( array $slot ): bool => (bool) $slot['enabled'] );
		if ( array() === $clean || array() === $enabled ) {
			return $this->default_delivery_time_slots();
		}

		usort(
			$clean,
			static function ( array $a, array $b ): int {
				$sort = (int) $a['sort_order'] <=> (int) $b['sort_order'];
				return 0 !== $sort ? $sort : strcmp( (string) $a['time_from'], (string) $b['time_from'] );
			}
		);

		return array_values( $clean );
	}

	public function sanitize_delivery_schedule( mixed $value, array $fallback ): array {
		$allowed_weekdays = $this->allowed_weekdays();
		$schedule         = is_array( $value ) ? $value : $fallback;
		$clean            = array();
		$seen_weekdays    = array();

		foreach ( $this->enabled_first_rule_entries( $schedule ) as $entry ) {
			$rule_index = $entry['index'];
			$rule       = $entry['rule'];

			$delivery_weekday = sanitize_key( $this->scalar_string( $rule['delivery_weekday'] ?? $rule['delivery_day'] ?? null ) );
			$cutoff_weekday   = sanitize_key( $this->scalar_string( $rule['cutoff_weekday'] ?? $rule['cutoff_day'] ?? null ) );
			$cutoff_time      = $this->sanitize_time( sanitize_text_field( $this->scalar_string( $rule['cutoff_time'] ?? null, '13:00' ) ), '13:00' );
			if ( ! in_array( $delivery_weekday, $allowed_weekdays, true ) || ! in_array( $cutoff_weekday, $allowed_weekdays, true ) || isset( $seen_weekdays[ $delivery_weekday ] ) ) {
				continue;
			}
			$seen_weekdays[ $delivery_weekday ] = true;

			$rule_enabled  = $this->to_bool( $rule['enabled'] ?? true );
			$slots         = $this->sanitize_schedule_slots( $rule['slots'] ?? array(), $delivery_weekday );
			$enabled_slots = array_filter( $slots, static fn ( array $slot ): bool => (bool) $slot['enabled'] );
			if ( array() === $slots || ( $rule_enabled && array() === $enabled_slots ) ) {
				$slots = $this->sanitize_schedule_slots( $this->default_delivery_time_slots(), $delivery_weekday );
			}

			$clean[] = array(
				'enabled'          => $rule_enabled,
				'delivery_weekday' => $delivery_weekday,
				'cutoff_weekday'   => $cutoff_weekday,
				'cutoff_time'      => $cutoff_time,
				'sort_order'       => is_numeric( $rule['sort_order'] ?? null ) ? (int) $rule['sort_order'] : ( (int) $rule_index + 1 ) * 10,
				'slots'            => $slots,
			);
		}

		$enabled = array_filter( $clean, static fn ( array $rule ): bool => (bool) $rule['enabled'] );
		if ( array() === $clean || array() === $enabled ) {
			return $this->default_delivery_schedule();
		}

		usort(
			$clean,
			static function ( array $a, array $b ): int {
				$sort = (int) $a['sort_order'] <=> (int) $b['sort_order'];
				return 0 !== $sort ? $sort : strcmp( (string) $a['delivery_weekday'], (string) $b['delivery_weekday'] );
			}
		);

		return array_values( $clean );
	}

	private function sanitize_schedule_slots( mixed $value, string $delivery_weekday ): array {
		$slots = is_array( $value ) ? $value : array();
		$clean = array();
		$seen  = array();

		foreach ( $this->enabled_first_slot_entries( $slots, 12 ) as $entry ) {
			$index = $entry['index'];
			$slot  = $entry['slot'];

			$sanitized = $this->sanitize_slot( $slot );
			if ( null === $sanitized ) {
				continue;
			}

			$time_from   = $sanitized['time_from'];
			$time_to     = $sanitized['time_to'];
			$fingerprint = $time_from . '|' . $time_to;
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}
			$seen[ $fingerprint ] = true;

			$sanitized['weekdays']   = array( $delivery_weekday );
			$sanitized['sort_order'] = is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : ( (int) $index + 1 ) * 10;
			$clean[]                  = $sanitized;
		}

		usort(
			$clean,
			static function ( array $a, array $b ): int {
				$sort = (int) $a['sort_order'] <=> (int) $b['sort_order'];
				return 0 !== $sort ? $sort : strcmp( (string) $a['time_from'], (string) $b['time_from'] );
			}
		);

		return array_values( $clean );
	}


	private function sanitize_slot( array $slot ): ?array {
		$time_from   = $this->sanitize_time( sanitize_text_field( $this->scalar_string( $slot['time_from'] ?? null ) ), '' );
		$time_to     = $this->sanitize_time( sanitize_text_field( $this->scalar_string( $slot['time_to'] ?? null ) ), '' );
		$cutoff_time = $this->sanitize_time( sanitize_text_field( $this->scalar_string( $slot['cutoff_time'] ?? $time_from, $time_from ) ), $time_from );

		if ( '' === $time_from || '' === $time_to || '' === $cutoff_time || $time_to <= $time_from || $cutoff_time > $time_to ) {
			return null;
		}

		return array(
			'id'          => $this->slot_identity( $slot, $time_from, $time_to ),
			'type'        => $this->slot_type( $slot, $time_from, $time_to ),
			'enabled'     => $this->to_bool( $slot['enabled'] ?? true ),
			'label'       => $this->slot_label( $slot['label'] ?? null, $time_from, $time_to ),
			'time_from'   => $time_from,
			'time_to'     => $time_to,
			'cutoff_time' => $cutoff_time,
		);
	}

	private function enabled_first_slot_entries( array $slots, int $limit ): array {
		$entries = array();
		foreach ( $slots as $index => $slot ) {
			if ( is_array( $slot ) ) {
				$entries[] = array(
					'index' => (int) $index,
					'slot'  => $slot,
				);
			}
		}

		usort(
			$entries,
			function ( array $a, array $b ): int {
				$enabled = (int) $this->to_bool( $b['slot']['enabled'] ?? true ) <=> (int) $this->to_bool( $a['slot']['enabled'] ?? true );
				return 0 !== $enabled ? $enabled : (int) $a['index'] <=> (int) $b['index'];
			}
		);

		return array_slice( $entries, 0, max( 0, $limit ) );
	}

	private function enabled_first_rule_entries( array $rules, int $limit = 7 ): array {
		$entries = array();
		foreach ( $rules as $index => $rule ) {
			if ( is_array( $rule ) ) {
				$entries[] = array(
					'index' => (int) $index,
					'rule'  => $rule,
				);
			}
		}

		usort(
			$entries,
			function ( array $a, array $b ): int {
				$enabled = (int) $this->to_bool( $b['rule']['enabled'] ?? true ) <=> (int) $this->to_bool( $a['rule']['enabled'] ?? true );
				return 0 !== $enabled ? $enabled : (int) $a['index'] <=> (int) $b['index'];
			}
		);

		return array_slice( $entries, 0, max( 0, $limit ) );
	}

	public function schedule_from_legacy( array $rules, array $slots ): array {
		$clean_rules = $this->sanitize_delivery_rules( $rules, $this->default_delivery_rules() );
		$clean_slots = $this->sanitize_delivery_time_slots( $slots, $this->default_delivery_time_slots() );
		$schedule    = array();

		foreach ( $clean_rules as $index => $rule ) {
			$delivery_weekday = (string) $rule['delivery_weekday'];
			$rule_slots       = array();

			foreach ( $clean_slots as $slot ) {
				$weekdays = is_array( $slot['weekdays'] ?? null ) ? $slot['weekdays'] : $this->allowed_weekdays();
				if ( ! in_array( $delivery_weekday, $weekdays, true ) ) {
					continue;
				}
				$slot['weekdays'] = array( $delivery_weekday );
				$rule_slots[]     = $slot;
			}

			if ( array() === $rule_slots ) {
				$rule_slots = $this->sanitize_schedule_slots( $this->default_delivery_time_slots(), $delivery_weekday );
			}

			$schedule[] = array(
				'enabled'          => (bool) $rule['enabled'],
				'delivery_weekday' => $delivery_weekday,
				'cutoff_weekday'   => (string) $rule['cutoff_weekday'],
				'cutoff_time'      => (string) $rule['cutoff_time'],
				'sort_order'       => ( (int) $index + 1 ) * 10,
				'slots'            => array_values( $rule_slots ),
			);
		}

		return $schedule;
	}

	public function delivery_rules_from_schedule( array $schedule ): array {
		$rules = array();
		foreach ( $schedule as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rules[] = array(
				'enabled'          => (bool) ( $rule['enabled'] ?? true ),
				'delivery_weekday' => $this->scalar_string( $rule['delivery_weekday'] ?? null, 'monday' ),
				'cutoff_weekday'   => $this->scalar_string( $rule['cutoff_weekday'] ?? null, 'saturday' ),
				'cutoff_time'      => $this->scalar_string( $rule['cutoff_time'] ?? null, '13:00' ),
			);
		}
		return array() !== $rules ? $rules : $this->default_delivery_rules();
	}

	public function delivery_time_slots_from_schedule( array $schedule ): array {
		$slots = array();
		foreach ( $schedule as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$delivery_weekday = sanitize_key( $this->scalar_string( $rule['delivery_weekday'] ?? null ) );
			foreach ( is_array( $rule['slots'] ?? null ) ? $rule['slots'] : array() as $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}
				$slot['weekdays'] = array( $delivery_weekday );
				$slots[]          = $slot;
			}
		}

		if ( array() === $slots ) {
			return $this->default_delivery_time_slots();
		}

		usort(
			$slots,
			static function ( array $a, array $b ): int {
				$sort = (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 );
				return 0 !== $sort ? $sort : strcmp( (string) ( $a['time_from'] ?? '' ), (string) ( $b['time_from'] ?? '' ) );
			}
		);

		return array_values( $slots );
	}

	public function migrate_slot_identities( array $settings ): array {
		foreach ( array( 'checkout_delivery_time_slots' ) as $key ) {
			if ( ! is_array( $settings[ $key ] ?? null ) ) {
				continue;
			}
			foreach ( $settings[ $key ] as $index => $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}
				$from = $this->scalar_string( $slot['time_from'] ?? null );
				$to   = $this->scalar_string( $slot['time_to'] ?? null );
				$settings[ $key ][ $index ]['id']   = $this->slot_identity( $slot, $from, $to );
				$settings[ $key ][ $index ]['type'] = $this->slot_type( $slot, $from, $to );
			}
		}

		if ( is_array( $settings['checkout_delivery_schedule'] ?? null ) ) {
			foreach ( $settings['checkout_delivery_schedule'] as $rule_index => $rule ) {
				if ( ! is_array( $rule ) || ! is_array( $rule['slots'] ?? null ) ) {
					continue;
				}
				foreach ( $rule['slots'] as $slot_index => $slot ) {
					if ( ! is_array( $slot ) ) {
						continue;
					}
					$from = $this->scalar_string( $slot['time_from'] ?? null );
					$to   = $this->scalar_string( $slot['time_to'] ?? null );
					$settings['checkout_delivery_schedule'][ $rule_index ]['slots'][ $slot_index ]['id']   = $this->slot_identity( $slot, $from, $to );
					$settings['checkout_delivery_schedule'][ $rule_index ]['slots'][ $slot_index ]['type'] = $this->slot_type( $slot, $from, $to );
				}
			}
		}

		return $settings;
	}

	public function rename_legacy_daypart_labels( array $settings ): array {
		if ( is_array( $settings['checkout_delivery_time_slots'] ?? null ) ) {
			$settings['checkout_delivery_time_slots'] = $this->rename_legacy_daypart_slot_labels( $settings['checkout_delivery_time_slots'] );
		}

		if ( is_array( $settings['checkout_delivery_schedule'] ?? null ) ) {
			foreach ( $settings['checkout_delivery_schedule'] as $rule_index => $rule ) {
				if ( ! is_array( $rule ) || ! is_array( $rule['slots'] ?? null ) ) {
					continue;
				}
				$settings['checkout_delivery_schedule'][ $rule_index ]['slots'] = $this->rename_legacy_daypart_slot_labels( $rule['slots'] );
			}
		}

		return $settings;
	}

	private function rename_legacy_daypart_slot_labels( array $slots ): array {
		foreach ( $slots as $index => $slot ) {
			if ( is_array( $slot ) && in_array( $this->scalar_string( $slot['label'] ?? null ), array( 'Ochtend', 'Middag' ), true ) ) {
				$slots[ $index ]['label'] = 'Ochtend - Middag';
			}
		}

		return $slots;
	}

	private function slot_identity( array $slot, string $time_from, string $time_to ): string {
		$id = sanitize_key( $this->scalar_string( $slot['id'] ?? null ) );
		if ( '' !== $id ) {
			return substr( $id, 0, 64 );
		}

		return '17:00' === $time_from && '22:00' === $time_to ? 'evening' : 'slot-' . str_replace( ':', '', $time_from . '-' . $time_to );
	}

	private function slot_type( array $slot, string $time_from, string $time_to ): string {
		$type = sanitize_key( $this->scalar_string( $slot['type'] ?? $slot['id'] ?? null ) );
		if ( in_array( $type, array( 'daytime', 'evening' ), true ) ) {
			return $type;
		}

		return '17:00' === $time_from && '22:00' === $time_to ? 'evening' : 'daytime';
	}

	public function sanitize_holidays( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = array_values( array_filter( $value, 'is_scalar' ) );
			$raw   = implode( ',', array_map( 'strval', $value ) );
		} else {
			$raw = is_scalar( $value ) ? (string) $value : '';
		}
		$dates = array();
		foreach ( preg_split( '/[\s,]+/', sanitize_text_field( $raw ) ) ?: array() as $date ) {
			$date = trim( (string) $date );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}

			$parts = array_map( 'absint', explode( '-', $date ) );
			if ( checkdate( $parts[1] ?? 0, $parts[2] ?? 0, $parts[0] ?? 0 ) ) {
				$dates[] = $date;
				if ( count( $dates ) >= 366 ) {
					break;
				}
			}
		}

		$dates = array_values( array_unique( $dates ) );
		sort( $dates, SORT_STRING );

		return implode( ',', $dates );
	}

	public function sanitize_packaging_type( mixed $value ): string {
		$value = sanitize_key( $this->scalar_string( $value, 'box' ) );
		$value = $this->truncate( $value, 32 );

		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/', $value ) ? $value : 'box';
	}

	private function slot_label( mixed $value, string $time_from, string $time_to ): string {
		$label = $this->truncate( sanitize_text_field( $this->scalar_string( $value ) ), 80 );

		return '' !== $label ? $label : $time_from . '–' . $time_to;
	}

	public function truncate( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length, 'UTF-8' );
		}

		if ( 1 === preg_match( '/^.{0,' . $length . '}/us', $value, $matches ) ) {
			return $matches[0];
		}

		return substr( $value, 0, $length );
	}

	private function allowed_weekdays(): array {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}

	public function money_amount( mixed $value, float $min, float $max, float $fallback ): float {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		$normalized = str_replace( ',', '.', sanitize_text_field( (string) $value ) );
		if ( ! is_numeric( $normalized ) ) {
			return $fallback;
		}

		$amount = round( (float) $normalized, 2 );
		if ( $amount < $min ) {
			return $min;
		}

		if ( $amount > $max ) {
			return $max;
		}

		return $amount;
	}

	public function positive_int_between( mixed $value, int $min, int $max, int $fallback ): int {
		if ( is_int( $value ) ) {
			return $value >= $min && $value <= $max ? $value : $fallback;
		}
		if ( ! is_string( $value ) || ! ctype_digit( $value ) ) {
			return $fallback;
		}

		$int = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => $min, 'max_range' => $max ) ) );
		return false !== $int ? $int : $fallback;
	}


	private function scalar_string( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private function sanitize_time( string $value, string $fallback ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
	}

	private function to_bool( mixed $value ): bool {
		if ( ! is_scalar( $value ) && ! is_bool( $value ) ) {
			return false;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}
}
