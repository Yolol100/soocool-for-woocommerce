<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class ProviderContext {

	private readonly OptionRepository $options;
	private readonly ConnectionStateRepository $connection_state;

	public function __construct( ?OptionRepository $options = null, ?ConnectionStateRepository $connection_state = null ) {
		$this->options          = $options ?? new OptionRepository();
		$this->connection_state = $connection_state ?? new ConnectionStateRepository();
	}

	public function provider_fingerprint(): string {
		$settings    = $this->options->all();
		$environment = 'production' === (string) ( $settings['environment'] ?? 'test' ) ? 'production' : 'test';

		return $this->connection_state->provider_fingerprint( $environment, $this->options->base_url(), $this->options->api_key() );
	}

	public function fresh_provider_fingerprint(): string {
		$this->options->refresh_cache();
		return $this->provider_fingerprint();
	}

	public function execution_fingerprint( string $scope = '' ): string {
		$fingerprint = $this->provider_fingerprint();
		$scope       = trim( $scope );
		if ( '' === $scope ) {
			return $fingerprint;
		}

		return hash_hmac( 'sha256', $fingerprint . "\0" . $scope, wp_salt( 'auth' ) );
	}

	public function matches_provider( string $fingerprint ): bool {
		$fingerprint = $this->normalize_fingerprint( $fingerprint );
		return '' !== $fingerprint && hash_equals( $this->provider_fingerprint(), $fingerprint );
	}

	public function matches_execution( string $fingerprint, string $scope = '' ): bool {
		$fingerprint = $this->normalize_fingerprint( $fingerprint );
		return '' !== $fingerprint && hash_equals( $this->execution_fingerprint( $scope ), $fingerprint );
	}

	private function normalize_fingerprint( string $fingerprint ): string {
		$fingerprint = strtolower( trim( $fingerprint ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? $fingerprint : '';
	}
}
