<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OptionRepository {

	public const OPTION_NAME                = 'soocool_settings';
	private const MASK_PLACEHOLDER          = '__SOOCOOL_KEEP_CURRENT_SECRET__';
	private const DEFAULT_ALLOWED_API_HOSTS = array( 'api.staging.soocool.nl', 'api.soocool.nl' );

	/** @return array<string, mixed> */
	public function defaults(): array {
		return array(
			'environment'                => 'test',
			'test_base_url'              => 'https://api.staging.soocool.nl',
			'production_base_url'        => 'https://api.soocool.nl',
			'api_key'                    => '',
			'enable_pickup'              => true,
			'order_reference_prefix'     => '',
			'pickup_company'             => get_bloginfo( 'name' ),
			'pickup_contact_name'        => '',
			'pickup_email'               => get_option( 'admin_email' ),
			'pickup_phone'               => '',
			'pickup_street'              => '',
			'pickup_house_number'        => '',
			'pickup_postal_code'         => '',
			'pickup_city'                => '',
			'pickup_country'             => 'NL',
			'pickup_days_offset'         => 0,
			'pickup_time_from'           => '08:00',
			'pickup_time_to'             => '18:00',
			'delivery_time_from'         => '08:00',
			'delivery_time_to'           => '18:00',
			'delivery_days_offset'       => 1,
			'auto_submit_enabled'        => false,
			'auto_submit_status'         => 'processing',
			'allow_resubmit'             => false,
			'label_output'               => 'a6',
			'webhook_url'                => '',
			'goods_description_fallback' => 'WooCommerce order',
			'temperature_regime'         => 'cooled',
			'log_retention'              => 100,
		);
	}

	/** @return array<string, mixed> */
	public function all(): array {
		$stored   = get_option( self::OPTION_NAME, array() );
		$settings = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );

		// Older builds used an undocumented test host. Normalize it at read time so
		// existing installations follow the official SooCool OpenAPI staging server.
		if ( 'https://api-test.soocool.nl' === untrailingslashit( (string) $settings['test_base_url'] ) ) {
			$settings['test_base_url'] = 'https://api.staging.soocool.nl';
		}

		return $settings;
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

		$clean['environment']         = $this->one_of( $settings['environment'] ?? $current['environment'], array( 'test', 'production' ), 'test' );
		$clean['test_base_url']       = $this->sanitize_url( (string) ( $settings['test_base_url'] ?? $current['test_base_url'] ), (string) $current['test_base_url'] );
		$clean['production_base_url'] = $this->sanitize_url( (string) ( $settings['production_base_url'] ?? $current['production_base_url'] ), (string) $current['production_base_url'] );
		$clean['api_key']             = $this->sanitize_secret( $settings['api_key'] ?? null, (string) $current['api_key'] );

		$clean['enable_pickup']          = $this->to_bool( $settings['enable_pickup'] ?? $current['enable_pickup'] );
		$clean['order_reference_prefix'] = sanitize_key( (string) ( $settings['order_reference_prefix'] ?? $current['order_reference_prefix'] ) );
		$clean['pickup_company']         = sanitize_text_field( (string) ( $settings['pickup_company'] ?? $current['pickup_company'] ) );
		$clean['pickup_contact_name']    = sanitize_text_field( (string) ( $settings['pickup_contact_name'] ?? $current['pickup_contact_name'] ) );
		$clean['pickup_email']           = sanitize_email( (string) ( $settings['pickup_email'] ?? $current['pickup_email'] ) );
		$clean['pickup_phone']           = sanitize_text_field( (string) ( $settings['pickup_phone'] ?? $current['pickup_phone'] ) );
		$clean['pickup_street']          = sanitize_text_field( (string) ( $settings['pickup_street'] ?? $current['pickup_street'] ) );
		$clean['pickup_house_number']    = sanitize_text_field( (string) ( $settings['pickup_house_number'] ?? $current['pickup_house_number'] ) );
		$clean['pickup_postal_code']     = strtoupper( sanitize_text_field( (string) ( $settings['pickup_postal_code'] ?? $current['pickup_postal_code'] ) ) );
		$clean['pickup_city']            = sanitize_text_field( (string) ( $settings['pickup_city'] ?? $current['pickup_city'] ) );
		$clean['pickup_country']         = $this->sanitize_country( (string) ( $settings['pickup_country'] ?? $current['pickup_country'] ) );

		$clean['pickup_days_offset'] = max( 0, min( 30, absint( $settings['pickup_days_offset'] ?? $current['pickup_days_offset'] ) ) );
		$clean['pickup_time_from']   = $this->sanitize_time( (string) ( $settings['pickup_time_from'] ?? $current['pickup_time_from'] ), '08:00' );
		$clean['pickup_time_to']     = $this->sanitize_time( (string) ( $settings['pickup_time_to'] ?? $current['pickup_time_to'] ), '18:00' );
		$clean['delivery_time_from'] = '08:00';
		$clean['delivery_time_to']   = '18:00';
		$delivery_days_offset        = max( 0, min( 30, absint( $settings['delivery_days_offset'] ?? $current['delivery_days_offset'] ) ) );
		if ( (bool) $clean['enable_pickup'] && $delivery_days_offset < 1 ) {
			$delivery_days_offset = 1;
		}
		$clean['delivery_days_offset'] = $delivery_days_offset;

		$clean['auto_submit_enabled']        = $this->to_bool( $settings['auto_submit_enabled'] ?? $current['auto_submit_enabled'] );
		$clean['auto_submit_status']         = $this->one_of( $settings['auto_submit_status'] ?? $current['auto_submit_status'], array( 'processing', 'completed', 'on-hold' ), 'processing' );
		$clean['allow_resubmit']             = $this->to_bool( $settings['allow_resubmit'] ?? $current['allow_resubmit'] );
		$clean['label_output']               = $this->one_of( $settings['label_output'] ?? $current['label_output'], array( 'a6', 'collated_a4' ), 'a6' );
		$clean['webhook_url']                = $this->sanitize_url_or_empty( (string) ( $settings['webhook_url'] ?? $current['webhook_url'] ) );
		$clean['goods_description_fallback'] = sanitize_text_field( (string) ( $settings['goods_description_fallback'] ?? $current['goods_description_fallback'] ) );
		$clean['temperature_regime']         = $this->one_of( $settings['temperature_regime'] ?? $current['temperature_regime'], array( 'cooled', 'frozen', 'ambient' ), 'cooled' );
		$clean['log_retention']              = max( 20, min( 500, absint( $settings['log_retention'] ?? $current['log_retention'] ) ) );

		return $clean;
	}

	public function api_key(): string {
		$constant_api_key = $this->normalized_constant_api_key();
		if ( '' !== $constant_api_key ) {
			return $constant_api_key;
		}

		return $this->normalized_stored_api_key();
	}

	public function api_key_length(): int {
		return strlen( $this->api_key() );
	}

	public function api_key_first4(): string {
		$api_key = $this->api_key();
		return strlen( $api_key ) >= 8 ? substr( $api_key, 0, 4 ) : '';
	}

	public function api_key_last4(): string {
		$api_key = $this->api_key();
		return strlen( $api_key ) >= 8 ? substr( $api_key, -4 ) : '';
	}

	public function base_url(): string {
		$settings = $this->all();
		$base     = 'production' === $settings['environment'] ? (string) $settings['production_base_url'] : (string) $settings['test_base_url'];
		return untrailingslashit( $base );
	}

	/** @return array<string, mixed> */
	public function public_settings(): array {
		$settings                    = $this->all();
		$settings['api_key_present']  = '' !== $this->api_key();
		$settings['api_key_source']   = $this->api_key_source();
		$settings['api_key_length']   = $this->api_key_length();
		$settings['api_key_first4']   = $this->api_key_first4();
		$settings['api_key_last4']    = $this->api_key_last4();
		$settings['api_key_status']   = $this->api_key_status();
		$settings['api_key_masked']   = $this->masked_api_key();
		$settings['api_key']          = $settings['api_key_present'] ? $settings['api_key_masked'] : '';
		return $settings;
	}

	public function api_key_source(): string {
		if ( '' !== $this->normalized_constant_api_key() ) {
			return 'constant';
		}

		if ( '' !== $this->normalized_stored_api_key() ) {
			return 'settings';
		}

		$raw_stored = trim( (string) $this->all()['api_key'] );
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

		return str_starts_with( $url, 'https://' ) ? $url : '';
	}

	public function is_allowed_api_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
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
