<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class ConnectionStateRepository {

	public const OPTION_NAME = 'soocool_connection_state';
	private const MAX_AGE_SECONDS = 604800;

	public function record( string $environment, string $result, int $status ): bool {
		$environment = $this->normalize_environment( $environment );
		$result      = in_array( $result, array( 'success', 'warning', 'failure' ), true ) ? $result : 'failure';
		$status      = max( 0, min( 599, $status ) );

		return update_option(
			self::OPTION_NAME,
			array(
				'environment' => $environment,
				'result'      => $result,
				'status'      => $status,
				'tested_at'   => gmdate( 'c' ),
				'version'     => defined( 'SOOCOOL_VERSION' ) ? (string) SOOCOOL_VERSION : '',
			),
			false
		) || $this->matches( $environment, $result, $status );
	}

	/** @return array{environment:string,result:string,status:int,tested_at:string,version:string,stale:bool}|null */
	public function current( string $environment ): ?array {
		$environment = $this->normalize_environment( $environment );
		$state       = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $state ) || $environment !== $this->normalize_environment( (string) ( $state['environment'] ?? '' ) ) ) {
			return null;
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

	private function matches( string $environment, string $result, int $status ): bool {
		$state = $this->current( $environment );
		return null !== $state && $result === $state['result'] && $status === $state['status'];
	}

	private function normalize_environment( string $environment ): string {
		return 'production' === sanitize_key( $environment ) ? 'production' : 'test';
	}
}
