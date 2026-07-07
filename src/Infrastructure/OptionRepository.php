<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class OptionRepository {

	private readonly OptionDefaults $defaults;

	public function __construct( ?OptionDefaults $defaults = null ) {
		$this->defaults = $defaults ?? new OptionDefaults();
	}

	public const OPTION_NAME                = 'soocool_settings';
	private const MASK_PLACEHOLDER          = '__SOOCOOL_KEEP_CURRENT_SECRET__';
	private const DEFAULT_ALLOWED_API_HOSTS        = array( 'api.staging.soocool.nl', 'api.soocool.nl' );
	private const DAYPART_LABEL_MIGRATION_OPTION = 'soocool_daypart_label_migration_20260707_ochtend_middag';

	/** @return array<string, mixed> */
	public function defaults(): array {
		return $this->defaults->settings();
	}

	/** @return array<int, array<string, mixed>> */
	public function default_delivery_rules(): array {
		return $this->defaults->delivery_rules();
	}

	/** @return array<int, array<string, mixed>> */
	public function default_delivery_time_slots(): array {
		return $this->defaults->delivery_time_slots();
	}

	/** @return array<int, array<string, mixed>> */
	public function default_delivery_schedule(): array {
		return $this->defaults->delivery_schedule();
	}

	public function migrate_for_current_version(): void {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			return;
		}

		$settings = wp_parse_args( $stored, $this->defaults() );
		$changed  = false;

		if ( 'https://api-test.soocool.nl' === untrailingslashit( (string) ( $settings['test_base_url'] ?? '' ) ) ) {
			$settings['test_base_url'] = 'https://api.staging.soocool.nl';
			$changed                   = true;
		}

		// Keep the legacy fallback delivery window predictable for orders without a selected checkout daypart.
		if ( '08:00' !== (string) ( $settings['delivery_time_from'] ?? '' ) || '18:00' !== (string) ( $settings['delivery_time_to'] ?? '' ) ) {
			$settings['delivery_time_from'] = '08:00';
			$settings['delivery_time_to']   = '18:00';
			$changed                       = true;
		}

		if ( (bool) ( $settings['enable_pickup'] ?? false ) && 0 === absint( $settings['pickup_days_offset'] ?? 0 ) ) {
			$settings['pickup_days_offset'] = 1;
			if ( absint( $settings['delivery_days_offset'] ?? 0 ) <= 1 ) {
				$settings['delivery_days_offset'] = 2;
			}
			$changed = true;
		}

		if ( empty( $settings['webhook_secret'] ) ) {
			$settings['webhook_secret'] = $this->generate_webhook_secret();
			$changed                    = true;
		}

		if ( ! get_option( self::DAYPART_LABEL_MIGRATION_OPTION, false ) ) {
			$renamed_settings = $this->rename_legacy_daypart_labels( $settings );
			if ( $renamed_settings !== $settings ) {
				$settings = $renamed_settings;
				$changed  = true;
			}
			update_option( self::DAYPART_LABEL_MIGRATION_OPTION, '1', false );
		}

		if ( ! is_array( $settings['checkout_delivery_schedule'] ?? null ) || array() === $settings['checkout_delivery_schedule'] ) {
			$settings['checkout_delivery_schedule'] = $this->schedule_from_legacy(
				is_array( $settings['checkout_delivery_rules'] ?? null ) ? $settings['checkout_delivery_rules'] : $this->default_delivery_rules(),
				is_array( $settings['checkout_delivery_time_slots'] ?? null ) ? $settings['checkout_delivery_time_slots'] : $this->default_delivery_time_slots()
			);
			$changed = true;
		}

		$normalized_settings = $this->sanitize_settings( $settings, $this->defaults() );
		if ( $normalized_settings !== $settings ) {
			$settings = $normalized_settings;
			$changed  = true;
		}

		if ( $changed ) {
			update_option( self::OPTION_NAME, $settings, false );
		}
	}

	/** @return array<string, mixed> */
	public function all(): array {
		$stored   = get_option( self::OPTION_NAME, array() );
		$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );

		if ( 'https://api-test.soocool.nl' === untrailingslashit( (string) $settings['test_base_url'] ) ) {
			$settings['test_base_url'] = 'https://api.staging.soocool.nl';
		}

		$settings['delivery_time_from'] = '08:00';
		$settings['delivery_time_to']   = '18:00';

		if ( ! is_array( $settings['checkout_delivery_schedule'] ?? null ) || array() === $settings['checkout_delivery_schedule'] ) {
			$settings['checkout_delivery_schedule'] = $this->schedule_from_legacy(
				is_array( $settings['checkout_delivery_rules'] ?? null ) ? $settings['checkout_delivery_rules'] : $this->default_delivery_rules(),
				is_array( $settings['checkout_delivery_time_slots'] ?? null ) ? $settings['checkout_delivery_time_slots'] : $this->default_delivery_time_slots()
			);
		}

		return $this->sanitize_settings( $settings, $this->defaults() );
	}

	/** @param array<string, mixed> $settings */
	public function update( array $settings ): void {
		update_option( self::OPTION_NAME, $this->sanitize_settings( $settings, $this->all() ), false );
	}

	/** @param array<string, mixed> $settings @return array<string, mixed> */
	public function preview_update( array $settings ): array {
		return $this->sanitize_settings( $settings, $this->all() );
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current @return array<string, mixed> */
	private function sanitize_settings( array $settings, array $current ): array {
		$clean = array();

		$defaults = $this->defaults();

		$clean['environment']         = $this->one_of( $settings['environment'] ?? $current['environment'], array( 'test', 'production' ), 'test' );
		$clean['test_base_url']       = $this->sanitize_url( (string) ( $settings['test_base_url'] ?? $current['test_base_url'] ), (string) $defaults['test_base_url'] );
		$clean['production_base_url'] = $this->sanitize_url( (string) ( $settings['production_base_url'] ?? $current['production_base_url'] ), (string) $defaults['production_base_url'] );
		$clean['api_key']             = $this->api_key_is_managed_by_constant() ? (string) $current['api_key'] : $this->sanitize_secret( $settings['api_key'] ?? null, (string) $current['api_key'] );
		$clean['test_api_key']        = $this->api_key_is_managed_by_constant() ? (string) ( $current['test_api_key'] ?? '' ) : $this->sanitize_secret( $settings['test_api_key'] ?? null, (string) ( $current['test_api_key'] ?? '' ) );
		$clean['production_api_key']  = $this->api_key_is_managed_by_constant() ? (string) ( $current['production_api_key'] ?? '' ) : $this->sanitize_secret( $settings['production_api_key'] ?? null, (string) ( $current['production_api_key'] ?? '' ) );

		$clean['enable_pickup']          = $this->to_bool( $settings['enable_pickup'] ?? $current['enable_pickup'] );
		$clean['order_reference_prefix'] = sanitize_key( (string) ( $settings['order_reference_prefix'] ?? $current['order_reference_prefix'] ) );
		$clean['pickup_company']         = sanitize_text_field( (string) ( $settings['pickup_company'] ?? $current['pickup_company'] ) );
		$clean['pickup_contact_name']    = sanitize_text_field( (string) ( $settings['pickup_contact_name'] ?? $current['pickup_contact_name'] ) );
		$clean['pickup_email']           = sanitize_email( (string) ( $settings['pickup_email'] ?? $current['pickup_email'] ) );
		$clean['pickup_phone']           = sanitize_text_field( (string) ( $settings['pickup_phone'] ?? $current['pickup_phone'] ) );
		$clean['pickup_street']          = sanitize_text_field( (string) ( $settings['pickup_street'] ?? $current['pickup_street'] ) );
		$clean['pickup_house_number']    = sanitize_text_field( (string) ( $settings['pickup_house_number'] ?? $current['pickup_house_number'] ) );
		$clean['pickup_postal_code']     = strtoupper( (string) preg_replace( '/\s+/', '', sanitize_text_field( (string) ( $settings['pickup_postal_code'] ?? $current['pickup_postal_code'] ) ) ) );
		$clean['pickup_city']            = sanitize_text_field( (string) ( $settings['pickup_city'] ?? $current['pickup_city'] ) );
		$clean['pickup_country']         = $this->sanitize_country( (string) ( $settings['pickup_country'] ?? $current['pickup_country'] ) );

		$clean['pickup_days_offset'] = max( 0, min( 30, absint( $settings['pickup_days_offset'] ?? $current['pickup_days_offset'] ) ) );
		$clean['pickup_time_from']   = $this->sanitize_time( (string) ( $settings['pickup_time_from'] ?? $current['pickup_time_from'] ), '08:00' );
		$clean['pickup_time_to']     = $this->sanitize_time( (string) ( $settings['pickup_time_to'] ?? $current['pickup_time_to'] ), '18:00' );
		// Fallback only; selected checkout dayparts override this in the SooCool payload.
		$clean['delivery_time_from'] = '08:00';
		$clean['delivery_time_to']   = '18:00';

		if ( $clean['pickup_time_to'] <= $clean['pickup_time_from'] ) {
			$clean['pickup_time_from'] = '08:00';
			$clean['pickup_time_to']   = '18:00';
		}

		$delivery_days_offset = max( 0, min( 30, absint( $settings['delivery_days_offset'] ?? $current['delivery_days_offset'] ) ) );
		if ( (bool) $clean['enable_pickup'] && $delivery_days_offset <= (int) $clean['pickup_days_offset'] ) {
			$delivery_days_offset = (int) $clean['pickup_days_offset'] + 1;
		}
		$clean['delivery_days_offset'] = min( 30, $delivery_days_offset );

		$clean['checkout_delivery_enabled']    = $this->to_bool( $settings['checkout_delivery_enabled'] ?? $current['checkout_delivery_enabled'] );
		$clean['checkout_delivery_days_ahead'] = max( 7, min( 92, absint( $settings['checkout_delivery_days_ahead'] ?? $current['checkout_delivery_days_ahead'] ) ) );
		$clean['checkout_delivery_holidays']   = $this->sanitize_holidays( $settings['checkout_delivery_holidays'] ?? $current['checkout_delivery_holidays'] ?? '' );
		$current_schedule = is_array( $current['checkout_delivery_schedule'] ?? null ) ? $current['checkout_delivery_schedule'] : $this->schedule_from_legacy(
			is_array( $current['checkout_delivery_rules'] ?? null ) ? $current['checkout_delivery_rules'] : $this->default_delivery_rules(),
			is_array( $current['checkout_delivery_time_slots'] ?? null ) ? $current['checkout_delivery_time_slots'] : $this->default_delivery_time_slots()
		);
		if ( is_array( $settings['checkout_delivery_schedule'] ?? null ) ) {
			$clean['checkout_delivery_schedule'] = $this->sanitize_delivery_schedule( $settings['checkout_delivery_schedule'], $current_schedule );
			$clean['checkout_delivery_rules']    = $this->delivery_rules_from_schedule( $clean['checkout_delivery_schedule'] );
			$clean['checkout_delivery_time_slots'] = $this->delivery_time_slots_from_schedule( $clean['checkout_delivery_schedule'] );
		} else {
			$clean['checkout_delivery_rules']      = $this->sanitize_delivery_rules( $settings['checkout_delivery_rules'] ?? $current['checkout_delivery_rules'] ?? array(), is_array( $current['checkout_delivery_rules'] ?? null ) ? $current['checkout_delivery_rules'] : $this->default_delivery_rules() );
			$clean['checkout_delivery_time_slots'] = $this->sanitize_delivery_time_slots( $settings['checkout_delivery_time_slots'] ?? $current['checkout_delivery_time_slots'] ?? array(), is_array( $current['checkout_delivery_time_slots'] ?? null ) ? $current['checkout_delivery_time_slots'] : $this->default_delivery_time_slots() );
			$clean['checkout_delivery_schedule']   = $this->schedule_from_legacy( $clean['checkout_delivery_rules'], $clean['checkout_delivery_time_slots'] );
			}
			$clean['checkout_delivery_hide_unavailable_slots'] = $this->to_bool( $settings['checkout_delivery_hide_unavailable_slots'] ?? $current['checkout_delivery_hide_unavailable_slots'] ?? true );
			$clean['checkout_delivery_netherlands_surcharge_amount'] = $this->money_amount( $settings['checkout_delivery_netherlands_surcharge_amount'] ?? $current['checkout_delivery_netherlands_surcharge_amount'] ?? $defaults['checkout_delivery_netherlands_surcharge_amount'], 0.0, 999.0, (float) $defaults['checkout_delivery_netherlands_surcharge_amount'] );
			$clean['checkout_delivery_netherlands_evening_surcharge_amount'] = $this->money_amount( $settings['checkout_delivery_netherlands_evening_surcharge_amount'] ?? $current['checkout_delivery_netherlands_evening_surcharge_amount'] ?? $defaults['checkout_delivery_netherlands_evening_surcharge_amount'], 0.0, 999.0, (float) $defaults['checkout_delivery_netherlands_evening_surcharge_amount'] );
			$clean['checkout_delivery_belgium_surcharge_amount'] = $this->money_amount( $settings['checkout_delivery_belgium_surcharge_amount'] ?? $current['checkout_delivery_belgium_surcharge_amount'] ?? $defaults['checkout_delivery_belgium_surcharge_amount'], 0.0, 999.0, (float) $defaults['checkout_delivery_belgium_surcharge_amount'] );
			$clean['checkout_delivery_belgium_evening_surcharge_amount'] = $this->money_amount( $settings['checkout_delivery_belgium_evening_surcharge_amount'] ?? $current['checkout_delivery_belgium_evening_surcharge_amount'] ?? $defaults['checkout_delivery_belgium_evening_surcharge_amount'], 0.0, 999.0, (float) $defaults['checkout_delivery_belgium_evening_surcharge_amount'] );

		$clean['auto_submit_enabled']        = $this->to_bool( $settings['auto_submit_enabled'] ?? $current['auto_submit_enabled'] );
		$clean['auto_submit_status']         = $this->one_of( $settings['auto_submit_status'] ?? $current['auto_submit_status'], array( 'processing', 'completed', 'on-hold' ), 'processing' );
		$clean['allow_resubmit']             = $this->to_bool( $settings['allow_resubmit'] ?? $current['allow_resubmit'] );
		$clean['label_output']               = $this->one_of( $settings['label_output'] ?? $current['label_output'], array( 'a6', 'collated_a4' ), 'a6' );
		$clean['webhook_url']                = $this->sanitize_url_or_empty( (string) ( $settings['webhook_url'] ?? $current['webhook_url'] ) );
		$clean['webhook_secret']             = $this->sanitize_webhook_secret( $settings['webhook_secret'] ?? $current['webhook_secret'] ?? '' );
		$clean['goods_description_fallback'] = sanitize_text_field( (string) ( $settings['goods_description_fallback'] ?? $current['goods_description_fallback'] ) );
		$clean['packaging_type']             = sanitize_key( (string) ( $settings['packaging_type'] ?? $current['packaging_type'] ?? 'box' ) );
		$clean['packaging_type']             = '' !== $clean['packaging_type'] ? $clean['packaging_type'] : 'box';
		$clean['temperature_regime']         = $this->one_of( $settings['temperature_regime'] ?? $current['temperature_regime'], array( 'cooled', 'frozen', 'ambient' ), 'cooled' );
		$clean['package_width']              = $this->positive_int_between( $settings['package_width'] ?? $current['package_width'] ?? $defaults['package_width'], 1, 9999, (int) $defaults['package_width'] );
		$clean['package_depth']              = $this->positive_int_between( $settings['package_depth'] ?? $current['package_depth'] ?? $defaults['package_depth'], 1, 9999, (int) $defaults['package_depth'] );
		$clean['package_height']             = $this->positive_int_between( $settings['package_height'] ?? $current['package_height'] ?? $defaults['package_height'], 1, 9999, (int) $defaults['package_height'] );
		$clean['package_weight']             = $this->positive_int_between( $settings['package_weight'] ?? $current['package_weight'] ?? $defaults['package_weight'], 1, 999999, (int) $defaults['package_weight'] );
		$clean['log_retention']              = max( 20, min( 500, absint( $settings['log_retention'] ?? $current['log_retention'] ) ) );

		return $clean;
	}


	/** @param mixed $value @return array<int, array<string, mixed>> */
	private function sanitize_delivery_rules( mixed $value, array $fallback ): array {
		$allowed_weekdays = $this->allowed_weekdays();
		$rules            = is_array( $value ) ? $value : $fallback;
		$clean            = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? '' ) );
			$cutoff_weekday   = sanitize_key( (string) ( $rule['cutoff_weekday'] ?? '' ) );
			$cutoff_time      = $this->sanitize_time( sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '13:00' ) ), '13:00' );
			if ( ! in_array( $delivery_weekday, $allowed_weekdays, true ) || ! in_array( $cutoff_weekday, $allowed_weekdays, true ) ) {
				continue;
			}

			$clean[] = array(
				'enabled'          => $this->to_bool( $rule['enabled'] ?? true ),
				'delivery_weekday' => $delivery_weekday,
				'cutoff_weekday'   => $cutoff_weekday,
				'cutoff_time'      => $cutoff_time,
			);
		}

		$enabled = array_filter( $clean, static fn ( array $rule ): bool => (bool) $rule['enabled'] );
		if ( array() === $clean || array() === $enabled ) {
			return $this->default_delivery_rules();
		}

		return array_values( $clean );
	}

	/** @param mixed $value @param array<int, array<string, mixed>> $fallback @return array<int, array<string, mixed>> */
	private function sanitize_delivery_time_slots( mixed $value, array $fallback ): array {
		$allowed_weekdays = $this->allowed_weekdays();
		$slots            = is_array( $value ) ? $value : $fallback;
		$clean            = array();

		foreach ( $slots as $index => $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$time_from   = $this->sanitize_time( sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) ), '' );
			$time_to     = $this->sanitize_time( sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) ), '' );
			$cutoff_time = $this->sanitize_time( sanitize_text_field( (string) ( $slot['cutoff_time'] ?? $time_from ) ), $time_from );
			if ( '' === $time_from || '' === $time_to || '' === $cutoff_time || $time_to <= $time_from ) {
				continue;
			}

			$weekdays = array();
			$raw_weekdays = is_array( $slot['weekdays'] ?? null ) ? $slot['weekdays'] : $allowed_weekdays;
			foreach ( $raw_weekdays as $weekday ) {
				$weekday = sanitize_key( (string) $weekday );
				if ( in_array( $weekday, $allowed_weekdays, true ) ) {
					$weekdays[] = $weekday;
				}
			}
			$weekdays = array_values( array_unique( $weekdays ) );
			if ( array() === $weekdays ) {
				$weekdays = $allowed_weekdays;
			}

			$clean[] = array(
				'enabled'     => $this->to_bool( $slot['enabled'] ?? true ),
				'label'       => sanitize_text_field( (string) ( $slot['label'] ?? '' ) ),
				'time_from'   => $time_from,
				'time_to'     => $time_to,
				'cutoff_time' => $cutoff_time,
				'weekdays'    => $weekdays,
				'sort_order'  => is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : (int) $index,
			);
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


	/** @param mixed $value @param array<int, array<string, mixed>> $fallback @return array<int, array<string, mixed>> */
	private function sanitize_delivery_schedule( mixed $value, array $fallback ): array {
		$allowed_weekdays = $this->allowed_weekdays();
		$schedule         = is_array( $value ) ? $value : $fallback;
		$clean            = array();

		foreach ( $schedule as $rule_index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? $rule['delivery_day'] ?? '' ) );
			$cutoff_weekday   = sanitize_key( (string) ( $rule['cutoff_weekday'] ?? $rule['cutoff_day'] ?? '' ) );
			$cutoff_time      = $this->sanitize_time( sanitize_text_field( (string) ( $rule['cutoff_time'] ?? '13:00' ) ), '13:00' );
			if ( ! in_array( $delivery_weekday, $allowed_weekdays, true ) || ! in_array( $cutoff_weekday, $allowed_weekdays, true ) ) {
				continue;
			}

			$slots = $this->sanitize_schedule_slots( $rule['slots'] ?? array(), $delivery_weekday );
			if ( array() === $slots ) {
				$slots = $this->sanitize_schedule_slots( $this->default_delivery_time_slots(), $delivery_weekday );
			}

			$clean[] = array(
				'enabled'          => $this->to_bool( $rule['enabled'] ?? true ),
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

	/** @param mixed $value @return array<int, array<string, mixed>> */
	private function sanitize_schedule_slots( mixed $value, string $delivery_weekday ): array {
		$slots = is_array( $value ) ? $value : array();
		$clean = array();

		foreach ( $slots as $index => $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$time_from   = $this->sanitize_time( sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) ), '' );
			$time_to     = $this->sanitize_time( sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) ), '' );
			$cutoff_time = $this->sanitize_time( sanitize_text_field( (string) ( $slot['cutoff_time'] ?? $time_from ) ), $time_from );
			if ( '' === $time_from || '' === $time_to || '' === $cutoff_time || $time_to <= $time_from ) {
				continue;
			}

			$clean[] = array(
				'enabled'     => $this->to_bool( $slot['enabled'] ?? true ),
				'label'       => sanitize_text_field( (string) ( $slot['label'] ?? '' ) ),
				'time_from'   => $time_from,
				'time_to'     => $time_to,
				'cutoff_time' => $cutoff_time,
				'weekdays'    => array( $delivery_weekday ),
				'sort_order'  => is_numeric( $slot['sort_order'] ?? null ) ? (int) $slot['sort_order'] : ( (int) $index + 1 ) * 10,
			);
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

	/** @param array<int, array<string, mixed>> $rules @param array<int, array<string, mixed>> $slots @return array<int, array<string, mixed>> */
	private function schedule_from_legacy( array $rules, array $slots ): array {
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

	/** @param array<int, array<string, mixed>> $schedule @return array<int, array<string, mixed>> */
	private function delivery_rules_from_schedule( array $schedule ): array {
		$rules = array();
		foreach ( $schedule as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rules[] = array(
				'enabled'          => (bool) ( $rule['enabled'] ?? true ),
				'delivery_weekday' => (string) ( $rule['delivery_weekday'] ?? 'monday' ),
				'cutoff_weekday'   => (string) ( $rule['cutoff_weekday'] ?? 'saturday' ),
				'cutoff_time'      => (string) ( $rule['cutoff_time'] ?? '13:00' ),
			);
		}
		return array() !== $rules ? $rules : $this->default_delivery_rules();
	}

	/** @param array<int, array<string, mixed>> $schedule @return array<int, array<string, mixed>> */
	private function delivery_time_slots_from_schedule( array $schedule ): array {
		$slots = array();
		foreach ( $schedule as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$delivery_weekday = sanitize_key( (string) ( $rule['delivery_weekday'] ?? '' ) );
			foreach ( is_array( $rule['slots'] ?? null ) ? $rule['slots'] : array() as $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}
				$slot['weekdays'] = array( $delivery_weekday );
				$slots[]          = $slot;
			}
		}
		return array() !== $slots ? $slots : $this->default_delivery_time_slots();
	}



	/** @param array<string, mixed> $settings @return array<string, mixed> */
	private function rename_legacy_daypart_labels( array $settings ): array {
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

	/** @param array<int, mixed> $slots @return array<int, mixed> */
	private function rename_legacy_daypart_slot_labels( array $slots ): array {
		foreach ( $slots as $index => $slot ) {
			if ( is_array( $slot ) && in_array( (string) ( $slot['label'] ?? '' ), array( 'Ochtend', 'Middag' ), true ) ) {
				$slots[ $index ]['label'] = 'Ochtend - Middag';
			}
		}

		return $slots;
	}

	private function sanitize_holidays( mixed $value ): string {
		$raw = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
		$dates = array();
		foreach ( preg_split( '/[\s,]+/', sanitize_text_field( $raw ) ) ?: array() as $date ) {
			$date = trim( (string) $date );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}

			$parts = array_map( 'absint', explode( '-', $date ) );
			if ( checkdate( $parts[1] ?? 0, $parts[2] ?? 0, $parts[0] ?? 0 ) ) {
				$dates[] = $date;
			}
		}

		return implode( ',', array_values( array_unique( $dates ) ) );
	}

	/** @return array<int, string> */
	private function allowed_weekdays(): array {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}

	/** @param mixed $value */
	private function money_amount( mixed $value, float $min, float $max, float $fallback ): float {
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

	private function positive_int_between( mixed $value, int $min, int $max, int $fallback ): int {
		if ( is_numeric( $value ) ) {
			$int = (int) $value;
			if ( $int >= $min && $int <= $max ) {
				return $int;
			}
		}

		return $fallback;
	}

	public function api_key(): string {
		$constant_api_key = $this->normalized_constant_api_key();
		if ( '' !== $constant_api_key ) {
			return $constant_api_key;
		}

		$environment_key = $this->normalized_environment_api_key();
		if ( '' !== $environment_key ) {
			return $environment_key;
		}

		return $this->normalized_stored_api_key();
	}

	public function api_key_length(): int {
		return strlen( $this->api_key() );
	}

	public function base_url(): string {
		$settings = $this->all();
		$defaults = $this->defaults();
		$is_production = 'production' === (string) ( $settings['environment'] ?? 'test' );
		$base = $is_production ? (string) ( $settings['production_base_url'] ?? '' ) : (string) ( $settings['test_base_url'] ?? '' );
		$fallback = $is_production ? (string) $defaults['production_base_url'] : (string) $defaults['test_base_url'];

		return untrailingslashit( $this->sanitize_url( $base, $fallback ) );
	}

	/** @return array<string, mixed> */
	public function public_settings(): array {
		$settings                    = $this->all();
		$settings['api_key_present']       = '' !== $this->api_key();
		$settings['api_key_source']        = $this->api_key_source();
		$settings['api_key_length']        = $this->api_key_length();
		$settings['api_key_status']        = $this->api_key_status();
		$settings['api_key_masked']        = $this->masked_api_key();
		$settings['test_api_key_present']  = '' !== $this->normalized_stored_api_key_for_environment( 'test' );
		$settings['production_api_key_present'] = '' !== $this->normalized_stored_api_key_for_environment( 'production' );
		$settings['api_key']               = $settings['api_key_present'] ? $settings['api_key_masked'] : '';
		$settings['test_api_key']          = $settings['test_api_key_present'] ? self::MASK_PLACEHOLDER : '';
		$settings['production_api_key']    = $settings['production_api_key_present'] ? self::MASK_PLACEHOLDER : '';
		$settings['effective_base_url'] = $this->base_url();
		$settings['api_base_url']       = $settings['effective_base_url'];
		$settings['generated_webhook_url']          = $this->generated_webhook_url();
		$settings['webhook_header_name']            = 'X-SooCool-Webhook-Token';
		$settings['webhook_timestamp_header_name']  = 'X-SooCool-Webhook-Timestamp';
		$settings['webhook_signature_header_name']  = 'X-SooCool-Webhook-Signature';
		$settings['webhook_event_id_header_name']   = 'X-SooCool-Webhook-Id';
		$settings['webhook_signature_required']     = $this->webhook_signature_required();
		$settings['query_token_fallback_enabled']   = $this->query_token_fallback_enabled();
		$settings['effective_webhook_url']          = $this->effective_webhook_url();
		unset( $settings['webhook_secret'] );
		return $settings;
	}

	public function existing_webhook_secret(): string {
		$settings = $this->all();
		return $this->sanitize_webhook_secret( $settings['webhook_secret'] ?? '' );
	}

	public function webhook_secret(): string {
		$secret = $this->existing_webhook_secret();
		if ( '' !== $secret ) {
			return $secret;
		}

		$settings = $this->all();
		$secret   = $this->generate_webhook_secret();
		$settings['webhook_secret'] = $secret;
		update_option( self::OPTION_NAME, $this->sanitize_settings( $settings, $this->defaults() ), false );

		return $secret;
	}

	/**
	 * Force a brand-new webhook secret and persist it. Returns the new secret so
	 * the operator can reconfigure SooCool with the rotated token.
	 */
	public function regenerate_webhook_secret(): string {
		$settings                   = $this->all();
		$secret                     = $this->generate_webhook_secret();
		$settings['webhook_secret'] = $secret;
		update_option( self::OPTION_NAME, $this->sanitize_settings( $settings, $this->defaults() ), false );

		return $secret;
	}

	public function generated_webhook_url(): string {
		$url = rest_url( 'soocool/v1/webhook' );

		return esc_url_raw( $url );
	}

	public function signed_webhook_url(): string {
		$url = add_query_arg(
			'token',
			$this->webhook_secret(),
			$this->generated_webhook_url()
		);

		return esc_url_raw( $url );
	}

	public function legacy_webhook_url(): string {
		return $this->signed_webhook_url();
	}

	public function effective_webhook_url(): string {
		$settings = $this->all();
		$custom   = $this->sanitize_url_or_empty( (string) ( $settings['webhook_url'] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$generated = $this->query_token_fallback_enabled() ? $this->signed_webhook_url() : $this->generated_webhook_url();
		return str_starts_with( $generated, 'https://' ) ? $generated : '';
	}

	public function webhook_signature_required(): bool {
		$default = $this->bool_constant( 'SOOCOOL_REQUIRE_WEBHOOK_SIGNATURE', true );

		/**
		 * Require HMAC verification for incoming SooCool webhooks.
		 *
		 * The production-safe default requires timestamp and signature headers. Legacy
		 * SooCool accounts that can only call the configured webhook URL must opt out
		 * explicitly and should do so only after accepting the replay/logging risk.
		 *
		 * @param bool $required Default true.
		 */
		return (bool) apply_filters( 'soocool_require_webhook_signature', $default );
	}

	public function query_token_fallback_enabled(): bool {
		$default = $this->bool_constant( 'SOOCOOL_ALLOW_QUERY_TOKEN_WEBHOOK_URL', false );

		/**
		 * Allow legacy webhook URLs that include the static token as a query parameter.
		 *
		 * Disabled by default so generated webhook URLs do not expose bearer tokens in
		 * logs or screenshots. Enable only for SooCool accounts that cannot send
		 * webhook token/HMAC headers yet.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'soocool_allow_query_token_webhook_url', $default );
	}

	private function bool_constant( string $name, bool $default ): bool {
		if ( ! defined( $name ) ) {
			return $default;
		}

		$value = constant( $name );
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			$normalized = filter_var( (string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null !== $normalized ) {
				return $normalized;
			}
		}

		return $default;
	}

	private function sanitize_webhook_secret( mixed $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return 1 === preg_match( '/^[A-Za-z0-9]{32,128}$/', $value ) ? $value : '';
	}

	private function generate_webhook_secret(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 48, false, false );
		}

		return bin2hex( random_bytes( 24 ) );
	}

	public function api_key_is_managed_by_constant(): bool {
		return '' !== $this->normalized_constant_api_key();
	}

	public function api_key_source(): string {
		if ( '' !== $this->normalized_constant_api_key() ) {
			return 'constant';
		}

		if ( '' !== $this->normalized_environment_api_key() ) {
			return $this->active_api_key_field();
		}

		if ( '' !== $this->normalized_stored_api_key() ) {
			return 'settings';
		}

		$raw_stored = trim( (string) $this->all()[ $this->active_api_key_field() ] );
		if ( '' === $raw_stored ) {
			$raw_stored = trim( (string) $this->all()['api_key'] );
		}
		return $this->is_masked_or_invalid_secret( $raw_stored ) ? 'masked-value-rejected' : 'none';
	}

	public function api_key_status(): string {
		if ( '' !== $this->api_key() ) {
			return 'valid';
		}

		return 'masked-value-rejected' === $this->api_key_source() ? 'invalid_masked_or_corrupt' : 'missing';
	}

	public function masked_api_key(): string {
		return '' === $this->api_key() ? '' : str_repeat( '•', 12 );
	}

	private function sanitize_secret( mixed $value, string $current ): string {
		$current = $this->normalize_secret( $current );
		if ( null === $value ) {
			return $current;
		}

		$raw = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $raw || self::MASK_PLACEHOLDER === $raw || $this->is_masked_or_invalid_secret( $raw ) ) {
			return $current;
		}

		$secret = $this->normalize_secret( $raw );
		return '' !== $secret ? $secret : $current;
	}

	private function normalized_constant_api_key(): string {
		if ( ! defined( 'SOOCOOL_API_KEY' ) ) {
			return '';
		}

		$constant_api_key = constant( 'SOOCOOL_API_KEY' );
		return is_string( $constant_api_key ) ? $this->normalize_secret( $constant_api_key ) : '';
	}

	private function normalized_environment_api_key(): string {
		return $this->normalized_stored_api_key_for_environment( (string) $this->all()['environment'] );
	}

	public function active_api_key_field(): string {
		return 'production' === (string) $this->all()['environment'] ? 'production_api_key' : 'test_api_key';
	}

	public function normalized_stored_api_key_for_environment( string $environment ): string {
		$settings = $this->all();
		$field    = 'production' === $environment ? 'production_api_key' : 'test_api_key';
		$key      = $this->normalize_secret( (string) ( $settings[ $field ] ?? '' ) );

		if ( '' !== $key ) {
			return $key;
		}

		return $this->normalize_secret( (string) ( $settings['api_key'] ?? '' ) );
	}

	private function normalized_stored_api_key(): string {
		return $this->normalize_secret( (string) $this->all()['api_key'] );
	}

	private function normalize_secret( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || $this->is_masked_or_invalid_secret( $value ) ) {
			return '';
		}

		if ( preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $matches ) ) {
			return strtolower( $matches[0] );
		}

		$value = preg_replace( '/^(?:api\s*key|x-api-key)\s*[:=]\s*/i', '', $value ) ?? $value;
		$value = preg_replace( '/\s+/', '', trim( $value ) ) ?? trim( $value );
		return $this->looks_like_api_key( $value ) ? $value : '';
	}

	private function is_masked_or_invalid_secret( string $value ): bool {
		$value = trim( $value );
		return str_contains( $value, '***' ) || str_contains( $value, '•' ) || str_contains( $value, '[redacted]' ) || str_contains( $value, self::MASK_PLACEHOLDER );
	}

	private function looks_like_api_key( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_.:-]{16,128}$/', $value );
	}

	private function sanitize_time( string $value, string $fallback ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
	}

	private function sanitize_country( string $value ): string {
		$country = strtoupper( sanitize_key( $value ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'NL';
	}

	private function sanitize_url( string $value, string $fallback ): string {
		$url = esc_url_raw( $value );
		if ( ! $this->is_allowed_api_url( $url ) ) {
			return $fallback;
		}

		return untrailingslashit( $url );
	}

	private function sanitize_url_or_empty( string $value ): string {
		$url = esc_url_raw( $value );
		if ( '' === $url ) {
			return '';
		}

		return str_starts_with( $url, 'https://' ) && false !== wp_http_validate_url( $url ) ? $url : '';
	}

	public function is_allowed_api_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'] ) : '';
		if ( '' !== $path && '/' !== $path ) {
			return false;
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return false;
		}

		$host          = strtolower( (string) $parts['host'] );
		$allowed_hosts = apply_filters( 'soocool_allowed_api_hosts', self::DEFAULT_ALLOWED_API_HOSTS );
		if ( ! is_array( $allowed_hosts ) ) {
			$allowed_hosts = self::DEFAULT_ALLOWED_API_HOSTS;
		}

		$allowed_hosts = array_values(
			array_filter(
				array_map(
					static fn ( mixed $allowed_host ): string => strtolower( trim( (string) $allowed_host ) ),
					$allowed_hosts
				)
			)
		);

		return in_array( $host, $allowed_hosts, true );
	}

	/** @param array<int, string> $allowed */
	private function one_of( mixed $value, array $allowed, string $fallback ): string {
		$value = (string) $value;
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private function to_bool( mixed $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) $value;
	}
}
