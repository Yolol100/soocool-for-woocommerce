<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class ConnectionStateRepository {

	public const OPTION_NAME = 'soocool_connection_state';
	private const MAX_AGE_SECONDS = 604800;

	public function record( string $environment, string $result, int $status, string $configuration_fingerprint = '' ): bool {
		$environment               = $this->normalize_environment( $environment );
		$result                    = in_array( $result, array( 'success', 'warning', 'failure' ), true ) ? $result : 'failure';
		$status                    = max( 0, min( 599, $status ) );
		$configuration_fingerprint = $this->normalize_fingerprint( $configuration_fingerprint );

		return update_option(
			self::OPTION_NAME,
			array(
				'environment'               => $environment,
				'result'                    => $result,
				'status'                    => $status,
				'tested_at'                 => gmdate( 'c' ),
				'version'                   => defined( 'SOOCOOL_VERSION' ) ? (string) SOOCOOL_VERSION : '',
				'configuration_fingerprint' => $configuration_fingerprint,
			),
			false
		) || $this->matches( $environment, $result, $status, $configuration_fingerprint );
	}

	/** @return array{environment:string,result:string,status:int,tested_at:string,version:string,stale:bool}|null */
	public function current( string $environment, string $configuration_fingerprint = '' ): ?array {
		$environment               = $this->normalize_environment( $environment );
		$configuration_fingerprint = $this->normalize_fingerprint( $configuration_fingerprint );
		$state                     = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $state ) || $environment !== $this->normalize_environment( (string) ( $state['environment'] ?? '' ) ) ) {
			return null;
		}

		if ( '' !== $configuration_fingerprint ) {
			$stored_fingerprint = $this->normalize_fingerprint( (string) ( $state['configuration_fingerprint'] ?? '' ) );
			if ( '' === $stored_fingerprint || ! hash_equals( $configuration_fingerprint, $stored_fingerprint ) ) {
				return null;
			}
		}

		$result    = (string) ( $state['result'] ?? '' );
		$tested_at = (string) ( $state['tested_at'] ?? '' );
		$timestamp = '' !== $tested_at ? strtotime( $tested_at ) : false;
		if ( ! in_array( $result, array( 'success', 'warning', 'failure' ), true ) || false === $timestamp ) {
			return null;
		}

		$max_age = (int) apply_filters( 'soocool_connection_test_max_age', self::MAX_AGE_SECONDS );
		$max_age = max( HOUR_IN_SECONDS, min( 30 * DAY_IN_SECONDS, $max_age ) );

		return array(
			'environment' => $environment,
			'result'      => $result,
			'status'      => absint( $state['status'] ?? 0 ),
			'tested_at'   => gmdate( 'c', $timestamp ),
			'version'     => sanitize_text_field( (string) ( $state['version'] ?? '' ) ),
			'stale'       => time() - $timestamp > $max_age,
		);
	}

	public function provider_fingerprint( string $environment, string $base_url, string $api_key ): string {
		$payload = implode(
			"\0",
			array(
				$this->normalize_environment( $environment ),
				untrailingslashit( trim( $base_url ) ),
				trim( $api_key ),
			)
		);

		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	public function configuration_fingerprint( string $environment, string $base_url, string $api_key ): string {
		$payload = $this->provider_fingerprint( $environment, $base_url, $api_key ) . "\0" . ( defined( 'SOOCOOL_VERSION' ) ? (string) SOOCOOL_VERSION : '' );

		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	private function matches( string $environment, string $result, int $status, string $configuration_fingerprint ): bool {
		$state = $this->current( $environment, $configuration_fingerprint );
		return null !== $state && $result === $state['result'] && $status === $state['status'];
	}

	private function normalize_environment( string $environment ): string {
		return 'production' === sanitize_key( $environment ) ? 'production' : 'test';
	}

	private function normalize_fingerprint( string $fingerprint ): string {
		$fingerprint = strtolower( trim( $fingerprint ) );
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ? $fingerprint : '';
	}
}
