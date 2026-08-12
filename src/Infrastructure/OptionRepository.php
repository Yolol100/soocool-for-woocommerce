<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class OptionRepository {

	private readonly OptionDefaults $defaults;

	private readonly DeliverySettingsNormalizer $delivery_settings;

	private readonly ApiCredentialResolver $credentials;

	/** @var array<string, mixed>|null */
	private ?array $cache = null;

	public function __construct( ?OptionDefaults $defaults = null ) {
		$this->defaults          = $defaults ?? new OptionDefaults();
		$this->delivery_settings = new DeliverySettingsNormalizer( $this->defaults );
		$this->credentials       = new ApiCredentialResolver();
	}

	public const OPTION_NAME                       = 'soocool_settings';
	private const DAYPART_LABEL_MIGRATION_OPTION   = 'soocool_daypart_label_migration_20260707_ochtend_middag';
	private const PACKAGE_WEIGHT_MIGRATION_OPTION = 'soocool_package_weight_migration_20260729_10kg';
	private const AUTO_SUBMIT_MIGRATION_OPTION    = 'soocool_auto_submit_migration_20260811';
	private const MIGRATION_VERSION_OPTION        = 'soocool_migration_version';
	private const MIGRATION_VERSION_FALLBACK      = '0.7.147';
	private const WRITE_LOCK_KEY                  = 'soocool_settings_write_lock';
	private const WRITE_LOCK_TTL                  = 10;
	private const WRITE_LOCK_RETRIES              = 5;

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
		$migration_version = $this->migration_version();
		$stored_version    = get_option( self::MIGRATION_VERSION_OPTION, '' );
		if ( is_scalar( $stored_version ) && version_compare( trim( (string) $stored_version ), $migration_version, '>=' ) ) {
			return;
		}

		$lock = $this->acquire_write_lock();
		if ( null === $lock ) {
			return;
		}

		try {
			$stored_version = get_option( self::MIGRATION_VERSION_OPTION, '' );
			if ( is_scalar( $stored_version ) && version_compare( trim( (string) $stored_version ), $migration_version, '>=' ) ) {
				return;
			}

			$stored = get_option( self::OPTION_NAME, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			$settings = wp_parse_args( $stored, $this->defaults() );

			if ( 'https://api-test.soocool.nl' === untrailingslashit( $this->scalar_string( $settings['test_base_url'] ?? null ) ) ) {
				$settings['test_base_url'] = 'https://api.staging.soocool.nl';
			}

			$settings['pickup_time_from'] = OptionDefaults::PICKUP_TIME_FROM;
			$settings['pickup_time_to']   = OptionDefaults::PICKUP_TIME_TO;

			// Keep the legacy fallback delivery window predictable for orders without a selected checkout daypart.
			if ( OptionDefaults::DELIVERY_TIME_FROM !== $this->scalar_string( $settings['delivery_time_from'] ?? null ) || OptionDefaults::DELIVERY_TIME_TO !== $this->scalar_string( $settings['delivery_time_to'] ?? null ) ) {
				$settings['delivery_time_from'] = OptionDefaults::DELIVERY_TIME_FROM;
				$settings['delivery_time_to']   = OptionDefaults::DELIVERY_TIME_TO;
			}

			if ( empty( $settings['webhook_secret'] ) ) {
				$settings['webhook_secret'] = $this->credentials->generate_webhook_secret();
			}

			$mark_daypart_migration = ! get_option( self::DAYPART_LABEL_MIGRATION_OPTION, false );
			if ( $mark_daypart_migration ) {
				$settings = $this->delivery_settings->rename_legacy_daypart_labels( $settings );
			}

			$mark_weight_migration = ! get_option( self::PACKAGE_WEIGHT_MIGRATION_OPTION, false );
			if ( $mark_weight_migration ) {
				$stored_package_weight = $stored['package_weight'] ?? null;
				if ( null === $stored_package_weight || 1600 === absint( $stored_package_weight ) ) {
					$settings['package_weight'] = 10000;
				}
			}

			$mark_auto_submit_migration = ! get_option( self::AUTO_SUBMIT_MIGRATION_OPTION, false );
			if ( $mark_auto_submit_migration ) {
				$settings['auto_submit_enabled'] = OptionDefaults::AUTO_SUBMIT_ENABLED;
				$settings['auto_submit_status']  = OptionDefaults::AUTO_SUBMIT_STATUS;
			}

			$settings = $this->delivery_settings->migrate_slot_identities( $settings );

			if ( ! is_array( $settings['checkout_delivery_schedule'] ?? null ) || array() === $settings['checkout_delivery_schedule'] ) {
				$settings['checkout_delivery_schedule'] = $this->delivery_settings->schedule_from_legacy(
					is_array( $settings['checkout_delivery_rules'] ?? null ) ? $settings['checkout_delivery_rules'] : $this->default_delivery_rules(),
					is_array( $settings['checkout_delivery_time_slots'] ?? null ) ? $settings['checkout_delivery_time_slots'] : $this->default_delivery_time_slots()
				);
			}

			$settings       = $this->sanitize_settings( $settings, $this->defaults() );
			$settings_saved = $settings === $stored || update_option( self::OPTION_NAME, $settings, false );
			if ( ! $settings_saved ) {
				return;
			}

			$this->cache = null;
			if ( $settings !== $this->all() ) {
				return;
			}

			$daypart_marker_saved    = ! $mark_daypart_migration || update_option( self::DAYPART_LABEL_MIGRATION_OPTION, '1', false ) || '1' === get_option( self::DAYPART_LABEL_MIGRATION_OPTION, '' );
			$weight_marker_saved     = ! $mark_weight_migration || update_option( self::PACKAGE_WEIGHT_MIGRATION_OPTION, '1', false ) || '1' === get_option( self::PACKAGE_WEIGHT_MIGRATION_OPTION, '' );
			$auto_submit_marker_saved = ! $mark_auto_submit_migration || update_option( self::AUTO_SUBMIT_MIGRATION_OPTION, '1', false ) || '1' === get_option( self::AUTO_SUBMIT_MIGRATION_OPTION, '' );
			if ( $daypart_marker_saved && $weight_marker_saved && $auto_submit_marker_saved ) {
				update_option( self::MIGRATION_VERSION_OPTION, $migration_version, false );
			}
		} finally {
			OptionMutex::release( self::WRITE_LOCK_KEY, $lock );
		}
	}

	public function refresh_cache(): void {
		$this->cache = null;
	}

	/** @return array<string, mixed> */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored   = get_option( self::OPTION_NAME, array() );
		$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );

		if ( 'https://api-test.soocool.nl' === untrailingslashit( $this->scalar_string( $settings['test_base_url'] ?? null ) ) ) {
			$settings['test_base_url'] = 'https://api.staging.soocool.nl';
		}

		$settings['delivery_time_from'] = OptionDefaults::DELIVERY_TIME_FROM;
		$settings['delivery_time_to']   = OptionDefaults::DELIVERY_TIME_TO;

		if ( ! is_array( $settings['checkout_delivery_schedule'] ?? null ) || array() === $settings['checkout_delivery_schedule'] ) {
			$settings['checkout_delivery_schedule'] = $this->delivery_settings->schedule_from_legacy(
				is_array( $settings['checkout_delivery_rules'] ?? null ) ? $settings['checkout_delivery_rules'] : $this->default_delivery_rules(),
				is_array( $settings['checkout_delivery_time_slots'] ?? null ) ? $settings['checkout_delivery_time_slots'] : $this->default_delivery_time_slots()
			);
		}

		$settings    = $this->delivery_settings->migrate_slot_identities( $settings );
		$this->cache = $this->sanitize_settings( $settings, $this->defaults() );

		return $this->cache;
	}

	/** @param array<string, mixed> $settings */
	public function update( array $settings ): bool {
		$lock = $this->acquire_write_lock();
		if ( null === $lock ) {
			return false;
		}

		try {
			// Another request can update the same option after this repository has cached it.
			// Re-read under the write lock so a partial update never restores stale fields.
			$this->cache = null;
			$current     = $this->all();
			$clean       = $this->sanitize_settings( $settings, $current );

			if ( $clean === $current ) {
				return true;
			}

			$updated     = update_option( self::OPTION_NAME, $clean, false );
			$this->cache = null;

			if ( ! $updated && $clean !== $this->all() ) {
				return false;
			}

			return $clean === $this->all();
		} finally {
			OptionMutex::release( self::WRITE_LOCK_KEY, $lock );
		}
	}

	/** @param array<string, mixed> $settings @return array<string, mixed> */
	public function preview_update( array $settings ): array {
		return $this->sanitize_settings( $settings, $this->all() );
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current @return array<string, mixed> */
	private function sanitize_settings( array $settings, array $current ): array {
		$defaults     = $this->defaults();
		$array_fields = array( 'checkout_delivery_rules', 'checkout_delivery_time_slots', 'checkout_delivery_schedule' );
		foreach ( $defaults as $key => $default ) {
			$is_array_field = in_array( $key, $array_fields, true );
			if ( ! array_key_exists( $key, $current ) || ( $is_array_field ? ! is_array( $current[ $key ] ) : ! is_scalar( $current[ $key ] ) ) ) {
				$current[ $key ] = $default;
			}
			if ( array_key_exists( $key, $settings ) && ( $is_array_field ? ! is_array( $settings[ $key ] ) : ! is_scalar( $settings[ $key ] ) ) ) {
				unset( $settings[ $key ] );
			}
		}

		return array_merge(
			$this->sanitize_connection_settings( $settings, $current, $defaults ),
			$this->sanitize_pickup_settings( $settings, $current ),
			$this->sanitize_checkout_settings( $settings, $current, $defaults ),
			$this->sanitize_operational_settings( $settings, $current, $defaults )
		);
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current */
	private function setting_value( array $settings, array $current, string $key, mixed $fallback = null ): mixed {
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return array_key_exists( $key, $current ) ? $current[ $key ] : $fallback;
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current @param array<string, mixed> $defaults @return array<string, mixed> */
	private function sanitize_connection_settings( array $settings, array $current, array $defaults ): array {
		$clean = array(
			'environment'         => $this->one_of( $this->setting_value( $settings, $current, 'environment' ), array( 'test', 'production' ), 'test' ),
			'test_base_url'       => $this->credentials->sanitize_url( (string) $this->setting_value( $settings, $current, 'test_base_url' ), (string) $defaults['test_base_url'] ),
			'production_base_url' => $this->credentials->sanitize_url( (string) $this->setting_value( $settings, $current, 'production_base_url' ), (string) $defaults['production_base_url'] ),
		);

		$managed_by_constant = $this->api_key_is_managed_by_constant();
		$legacy_api_key      = $managed_by_constant ? $this->credentials->sanitize_secret( null, (string) $current['api_key'] ) : $this->credentials->sanitize_secret( $this->setting_value( $settings, array(), 'api_key' ), (string) $current['api_key'] );
		$test_api_key        = $managed_by_constant ? $this->credentials->sanitize_secret( null, (string) $current['test_api_key'] ) : $this->credentials->sanitize_secret( $this->setting_value( $settings, array(), 'test_api_key' ), (string) $current['test_api_key'] );
		$production_api_key  = $managed_by_constant ? $this->credentials->sanitize_secret( null, (string) $current['production_api_key'] ) : $this->credentials->sanitize_secret( $this->setting_value( $settings, array(), 'production_api_key' ), (string) $current['production_api_key'] );

		if ( $this->to_bool( $this->setting_value( $settings, array(), 'clear_active_api_key', false ) ) ) {
			if ( 'production' === $clean['environment'] ) {
				if ( '' === $test_api_key && '' !== $legacy_api_key ) {
					$test_api_key = $legacy_api_key;
				}
				$production_api_key = '';
			} else {
				if ( '' === $production_api_key && '' !== $legacy_api_key ) {
					$production_api_key = $legacy_api_key;
				}
				$test_api_key = '';
			}
			$legacy_api_key = '';
		}

		$clean['api_key']            = $legacy_api_key;
		$clean['test_api_key']       = $test_api_key;
		$clean['production_api_key'] = $production_api_key;
		return $clean;
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current @return array<string, mixed> */
	private function sanitize_pickup_settings( array $settings, array $current ): array {
		$clean = array(
			'enable_pickup'          => OptionDefaults::PICKUP_ENABLED,
			'order_reference_prefix' => $this->delivery_settings->truncate( trim( sanitize_key( (string) $this->setting_value( $settings, $current, 'order_reference_prefix' ) ), '-' ), 32 ),
			'pickup_company'         => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_company' ) ), 200 ),
			'pickup_contact_name'    => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_contact_name' ) ), 200 ),
			'pickup_email'           => $this->delivery_settings->truncate( sanitize_email( (string) $this->setting_value( $settings, $current, 'pickup_email' ) ), 254 ),
			'pickup_phone'           => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_phone' ) ), 40 ),
			'pickup_street'          => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_street' ) ), 200 ),
			'pickup_house_number'    => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_house_number' ) ), 32 ),
			'pickup_postal_code'     => $this->delivery_settings->truncate( strtoupper( (string) preg_replace( '/\s+/', '', sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_postal_code' ) ) ) ), 32 ),
			'pickup_city'            => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'pickup_city' ) ), 100 ),
			'pickup_country'         => $this->sanitize_country( (string) $this->setting_value( $settings, $current, 'pickup_country' ) ),
			'pickup_time_from'       => OptionDefaults::PICKUP_TIME_FROM,
			'pickup_time_to'         => OptionDefaults::PICKUP_TIME_TO,
			// Fallback only; selected checkout dayparts override this in the SooCool payload.
			'delivery_time_from'     => OptionDefaults::DELIVERY_TIME_FROM,
			'delivery_time_to'       => OptionDefaults::DELIVERY_TIME_TO,
		);


		$delivery_days_offset          = max( 1, min( 30, absint( $this->setting_value( $settings, $current, 'delivery_days_offset' ) ) ) );
		$clean['delivery_days_offset'] = $delivery_days_offset;
		return $clean;
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current @param array<string, mixed> $defaults @return array<string, mixed> */
	private function sanitize_checkout_settings( array $settings, array $current, array $defaults ): array {
		$current_schedule = $current['checkout_delivery_schedule'];
		if ( array_key_exists( 'checkout_delivery_schedule', $settings ) ) {
			$delivery_schedule   = $this->delivery_settings->sanitize_delivery_schedule( $settings['checkout_delivery_schedule'], $current_schedule );
			$delivery_rules      = $this->delivery_settings->delivery_rules_from_schedule( $delivery_schedule );
			$delivery_time_slots = $this->delivery_settings->delivery_time_slots_from_schedule( $delivery_schedule );
		} elseif ( array_key_exists( 'checkout_delivery_rules', $settings ) || array_key_exists( 'checkout_delivery_time_slots', $settings ) ) {
			$delivery_rules      = $this->delivery_settings->sanitize_delivery_rules( $this->setting_value( $settings, $current, 'checkout_delivery_rules' ), $current['checkout_delivery_rules'] );
			$delivery_time_slots = $this->delivery_settings->sanitize_delivery_time_slots( $this->setting_value( $settings, $current, 'checkout_delivery_time_slots' ), $current['checkout_delivery_time_slots'] );
			$delivery_schedule   = $this->delivery_settings->schedule_from_legacy( $delivery_rules, $delivery_time_slots );
		} else {
			$delivery_schedule   = $this->delivery_settings->sanitize_delivery_schedule( $current_schedule, $this->default_delivery_schedule() );
			$delivery_rules      = $this->delivery_settings->delivery_rules_from_schedule( $delivery_schedule );
			$delivery_time_slots = $this->delivery_settings->delivery_time_slots_from_schedule( $delivery_schedule );
		}

		return array(
			'checkout_delivery_enabled'    => $this->to_bool( $this->setting_value( $settings, $current, 'checkout_delivery_enabled' ) ),
			'checkout_delivery_days_ahead' => max( 7, min( 92, absint( $this->setting_value( $settings, $current, 'checkout_delivery_days_ahead' ) ) ) ),
			'checkout_delivery_holidays'   => $this->delivery_settings->sanitize_holidays( $this->setting_value( $settings, $current, 'checkout_delivery_holidays' ) ),
			'checkout_delivery_rules'      => $delivery_rules,
			'checkout_delivery_time_slots' => $delivery_time_slots,
			'checkout_delivery_schedule'   => $delivery_schedule,
			'checkout_delivery_hide_unavailable_slots'               => $this->to_bool( $this->setting_value( $settings, $current, 'checkout_delivery_hide_unavailable_slots' ) ),
			'checkout_delivery_netherlands_surcharge_amount'         => $this->delivery_settings->money_amount( $this->setting_value( $settings, $current, 'checkout_delivery_netherlands_surcharge_amount' ), 0.0, 999.0, (float) $defaults['checkout_delivery_netherlands_surcharge_amount'] ),
			'checkout_delivery_netherlands_evening_surcharge_amount' => $this->delivery_settings->money_amount( $this->setting_value( $settings, $current, 'checkout_delivery_netherlands_evening_surcharge_amount' ), 0.0, 999.0, (float) $defaults['checkout_delivery_netherlands_evening_surcharge_amount'] ),
			'checkout_delivery_belgium_surcharge_amount'             => $this->delivery_settings->money_amount( $this->setting_value( $settings, $current, 'checkout_delivery_belgium_surcharge_amount' ), 0.0, 999.0, (float) $defaults['checkout_delivery_belgium_surcharge_amount'] ),
			'checkout_delivery_belgium_evening_surcharge_amount'     => $this->delivery_settings->money_amount( $this->setting_value( $settings, $current, 'checkout_delivery_belgium_evening_surcharge_amount' ), 0.0, 999.0, (float) $defaults['checkout_delivery_belgium_evening_surcharge_amount'] ),
			'checkout_delivery_fee_taxable'                            => $this->to_bool( $this->setting_value( $settings, $current, 'checkout_delivery_fee_taxable' ) ),
			'checkout_delivery_fee_tax_class'                          => $this->delivery_settings->truncate( sanitize_title( $this->scalar_string( $this->setting_value( $settings, $current, 'checkout_delivery_fee_tax_class' ) ) ), 200 ),
		);
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $current @param array<string, mixed> $defaults @return array<string, mixed> */
	private function sanitize_operational_settings( array $settings, array $current, array $defaults ): array {
		return array(
			'auto_submit_enabled'        => OptionDefaults::AUTO_SUBMIT_ENABLED,
			'auto_submit_status'         => OptionDefaults::AUTO_SUBMIT_STATUS,
			'allow_resubmit'             => $this->to_bool( $this->setting_value( $settings, $current, 'allow_resubmit' ) ),
			'label_output'               => $this->one_of( $this->setting_value( $settings, $current, 'label_output' ), array( 'a6', 'collated_a4' ), 'a6' ),
			'webhook_url'                => $this->credentials->sanitize_url_or_empty( (string) $this->setting_value( $settings, $current, 'webhook_url' ) ),
			'webhook_secret'             => $this->credentials->sanitize_webhook_secret( $this->setting_value( $settings, $current, 'webhook_secret' ) ),
			'goods_description_fallback' => $this->delivery_settings->truncate( sanitize_text_field( (string) $this->setting_value( $settings, $current, 'goods_description_fallback' ) ), 255 ),
			'packaging_type'             => $this->delivery_settings->sanitize_packaging_type( $this->setting_value( $settings, $current, 'packaging_type' ) ),
			'temperature_regime'         => $this->one_of( $this->setting_value( $settings, $current, 'temperature_regime' ), array( 'cooled', 'frozen', 'ambient' ), 'cooled' ),
			'package_width'              => $this->delivery_settings->positive_int_between( $this->setting_value( $settings, $current, 'package_width' ), 1, 9999, (int) $defaults['package_width'] ),
			'package_depth'              => $this->delivery_settings->positive_int_between( $this->setting_value( $settings, $current, 'package_depth' ), 1, 9999, (int) $defaults['package_depth'] ),
			'package_height'             => $this->delivery_settings->positive_int_between( $this->setting_value( $settings, $current, 'package_height' ), 1, 9999, (int) $defaults['package_height'] ),
			'package_weight'             => $this->delivery_settings->positive_int_between( $this->setting_value( $settings, $current, 'package_weight' ), 1, 999999, (int) $defaults['package_weight'] ),
			'missing_product_weight'     => $this->delivery_settings->positive_int_between( $this->setting_value( $settings, $current, 'missing_product_weight' ), 1, 999999, (int) $defaults['missing_product_weight'] ),
			'log_retention'              => max( 20, min( 500, absint( $this->setting_value( $settings, $current, 'log_retention' ) ) ) ),
		);
	}

	private function migration_version(): string {
		if ( defined( 'SOOCOOL_VERSION' ) && is_string( SOOCOOL_VERSION ) && '' !== trim( SOOCOOL_VERSION ) ) {
			return trim( SOOCOOL_VERSION );
		}

		return self::MIGRATION_VERSION_FALLBACK;
	}

	public function api_key(): string {
		$constant_api_key = $this->credentials->normalized_constant_api_key();
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
		$is_production = 'production' === $this->scalar_string( $settings['environment'] ?? null, 'test' );
		$base = $is_production ? $this->scalar_string( $settings['production_base_url'] ?? null ) : $this->scalar_string( $settings['test_base_url'] ?? null );
		$fallback = $is_production ? (string) $defaults['production_base_url'] : (string) $defaults['test_base_url'];

		return untrailingslashit( $this->credentials->sanitize_url( $base, $fallback ) );
	}

	/** @return array<string, mixed> */
	public function public_settings(): array {
		$settings                    = $this->all();
		$settings['api_key_present']       = '' !== $this->api_key();
		$settings['api_key_source']        = $this->api_key_source();
		$settings['api_key_length']        = $this->api_key_length();
		$settings['api_key_status']        = $this->api_key_status();
		$settings['api_key_masked']        = $this->masked_api_key();
		$settings['test_api_key_present']       = '' !== $this->normalized_exact_api_key_for_environment( 'test' );
		$settings['production_api_key_present'] = '' !== $this->normalized_exact_api_key_for_environment( 'production' );
		$settings['api_key']               = $settings['api_key_present'] ? $settings['api_key_masked'] : '';
		$settings['test_api_key']          = $settings['test_api_key_present'] ? ApiCredentialResolver::MASK_PLACEHOLDER : '';
		$settings['production_api_key']    = $settings['production_api_key_present'] ? ApiCredentialResolver::MASK_PLACEHOLDER : '';
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
		return $this->credentials->sanitize_webhook_secret( $settings['webhook_secret'] ?? '' );
	}

	public function webhook_secret(): string {
		$secret = $this->existing_webhook_secret();
		if ( '' !== $secret ) {
			return $secret;
		}

		$secret = $this->credentials->generate_webhook_secret();
		if ( ! $this->update( array( 'webhook_secret' => $secret ) ) ) {
			return '';
		}

		return hash_equals( $secret, $this->existing_webhook_secret() ) ? $secret : '';
	}

	/**
	 * Force a brand-new webhook secret and persist it. Returns the new secret so
	 * the operator can reconfigure SooCool with the rotated token.
	 */
	public function regenerate_webhook_secret(): string {
		$secret = $this->credentials->generate_webhook_secret();
		if ( ! $this->update( array( 'webhook_secret' => $secret ) ) ) {
			return '';
		}

		return hash_equals( $secret, $this->existing_webhook_secret() ) ? $secret : '';
	}

	public function generated_webhook_url(): string {
		$url = rest_url( 'soocool/v1/webhook' );

		return esc_url_raw( $url );
	}

	public function signed_webhook_url(): string {
		return $this->generated_webhook_url();
	}

	public function legacy_webhook_url(): string {
		return $this->generated_webhook_url();
	}

	public function effective_webhook_url(): string {
		$settings = $this->all();
		$custom   = $this->credentials->sanitize_url_or_empty( $this->scalar_string( $settings['webhook_url'] ?? null ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$generated = $this->generated_webhook_url();
		return str_starts_with( $generated, 'https://' ) ? $generated : '';
	}

	public function webhook_signature_required(): bool {
		return true;
	}

	public function query_token_fallback_enabled(): bool {
		return false;
	}

	public function api_key_is_managed_by_constant(): bool {
		return '' !== $this->credentials->normalized_constant_api_key();
	}

	public function api_key_source(): string {
		if ( '' !== $this->credentials->normalized_constant_api_key() ) {
			return 'constant';
		}

		if ( '' !== $this->normalized_exact_api_key_for_environment( (string) $this->all()['environment'] ) ) {
			return $this->active_api_key_field();
		}

		if ( '' !== $this->normalized_stored_api_key() ) {
			return 'settings';
		}

		$raw_stored = trim( (string) $this->all()[ $this->active_api_key_field() ] );
		if ( '' === $raw_stored ) {
			$raw_stored = trim( (string) $this->all()['api_key'] );
		}
		return $this->credentials->is_masked_or_invalid_secret( $raw_stored ) ? 'masked-value-rejected' : 'none';
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

	private function normalized_environment_api_key(): string {
		return $this->normalized_stored_api_key_for_environment( (string) $this->all()['environment'] );
	}

	public function active_api_key_field(): string {
		return 'production' === (string) $this->all()['environment'] ? 'production_api_key' : 'test_api_key';
	}

	public function normalized_stored_api_key_for_environment( string $environment ): string {
		$key = $this->normalized_exact_api_key_for_environment( $environment );
		if ( '' !== $key ) {
			return $key;
		}

		return $this->normalized_stored_api_key();
	}

	private function normalized_exact_api_key_for_environment( string $environment ): string {
		$settings = $this->all();
		$field    = 'production' === $environment ? 'production_api_key' : 'test_api_key';

		return $this->credentials->normalize_secret( $this->scalar_string( $settings[ $field ] ?? null ) );
	}

	private function normalized_stored_api_key(): string {
		return $this->credentials->normalize_secret( (string) $this->all()['api_key'] );
	}

	private function acquire_write_lock(): ?string {
		for ( $attempt = 0; $attempt < self::WRITE_LOCK_RETRIES; ++$attempt ) {
			$lock = OptionMutex::acquire( self::WRITE_LOCK_KEY, self::WRITE_LOCK_TTL );
			if ( null !== $lock ) {
				return $lock;
			}

			usleep( 20000 );
		}

		return null;
	}

	private function scalar_string( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private function sanitize_country( string $value ): string {
		$country = strtoupper( sanitize_key( $value ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'NL';
	}

	public function is_allowed_api_url( string $url ): bool {
		return $this->credentials->is_allowed_api_url( $url );
	}

	/** @param array<int, string> $allowed */
	private function one_of( mixed $value, array $allowed, string $fallback ): string {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}
		$value = (string) $value;
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private function to_bool( mixed $value ): bool {
		if ( ! is_scalar( $value ) && ! is_bool( $value ) ) {
			return false;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}
}
