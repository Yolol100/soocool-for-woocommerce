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
			'enable_pickup'              => false,
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
			'pickup_days_offset'         => 1,
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
			'webhook_secret'             => '',
			'goods_description_fallback' => 'WooCommerce order',
			'packaging_type'             => 'box',
			'temperature_regime'         => 'cooled',
			'package_width'              => 60,
			'package_depth'              => 40,
			'package_height'             => 11,
			'package_weight'             => 1600,
			'log_retention'              => 100,
		);
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

		// SooCool confirmed delivery tasks must use the exact 08:00-18:00 window for this connection.
		// Normalize legacy or manually changed delivery windows before new orders are sent.
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

		if ( $changed ) {
			update_option( self::OPTION_NAME, $this->sanitize_settings( $settings, $this->defaults() ), false );
		}
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

		// SooCool requires delivery tasks for this connection to use the exact 08:00-18:00 window.
		$settings['delivery_time_from'] = '08:00';
		$settings['delivery_time_to']   = '18:00';

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

		$defaults = $this->defaults();

		$clean['environment']         = $this->one_of( $settings['environment'] ?? $current['environment'], array( 'test', 'production' ), 'test' );
		$clean['test_base_url']       = $this->sanitize_url( (string) ( $settings['test_base_url'] ?? $current['test_base_url'] ), (string) $defaults['test_base_url'] );
		$clean['production_base_url'] = $this->sanitize_url( (string) ( $settings['production_base_url'] ?? $current['production_base_url'] ), (string) $defaults['production_base_url'] );
		$clean['api_key']             = $this->api_key_is_managed_by_constant() ? (string) $current['api_key'] : $this->sanitize_secret( $settings['api_key'] ?? null, (string) $current['api_key'] );

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
		// SooCool requires delivery tasks for this connection to be sent with exactly 08:00-18:00.
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
		$settings['api_key_present']  = '' !== $this->api_key();
		$settings['api_key_source']   = $this->api_key_source();
		$settings['api_key_length']   = $this->api_key_length();
		$settings['api_key_status']   = $this->api_key_status();
		$settings['api_key_masked']   = $this->masked_api_key();
		$settings['api_key']          = $settings['api_key_present'] ? $settings['api_key_masked'] : '';
		$settings['generated_webhook_url']        = $this->generated_webhook_url();
		$settings['webhook_header_name']          = 'X-SooCool-Webhook-Token';
		$settings['query_token_fallback_enabled'] = $this->query_token_fallback_enabled();
		$settings['effective_webhook_url']        = $this->effective_webhook_url();
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

	public function legacy_webhook_url(): string {
		$url = add_query_arg(
			'token',
			$this->webhook_secret(),
			$this->generated_webhook_url()
		);

		return esc_url_raw( $url );
	}

	public function effective_webhook_url(): string {
		$settings = $this->all();
		$custom   = $this->sanitize_url_or_empty( (string) ( $settings['webhook_url'] ?? '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$generated = $this->query_token_fallback_enabled() ? $this->legacy_webhook_url() : $this->generated_webhook_url();
		return str_starts_with( $generated, 'https://' ) ? $generated : '';
	}

	public function query_token_fallback_enabled(): bool {
		/**
		 * Controls whether generated webhook URLs include the shared token as a query
		 * parameter. Header-token authentication is the safer default because URL
		 * tokens can end up in logs, browser history, analytics or screenshots. Enable
		 * this fallback only when the remote SooCool callback configuration cannot send
		 * the X-SooCool-Webhook-Token header.
		 *
		 * @param bool $enabled Default false.
		 */
		return (bool) apply_filters( 'soocool_allow_query_token_webhook_url', false );
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
