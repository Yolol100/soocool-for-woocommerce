<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public const OPTION_NAME         = 'soocool_logs';
	/**
	 * Log context stays small. SecretSanitizer still scrubs values
	 * because upstream API errors may include secrets or customer data.
	 */
	private const CONTEXT_ALLOW_LIST = array( 'attempt', 'error', 'errors', 'method', 'path', 'status', 'traceId', 'orderId', 'orderReference', 'wcOrderId', 'api_key_present', 'api_key_source', 'api_key_status', 'api_key_length', 'header_name_sent', 'request_url_host', 'request_path' );

	public function __construct( private readonly SecretSanitizer $sanitizer, private readonly OptionRepository $options ) {}

	/** @param array<string, mixed> $context */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/** @return array<int, array<string, mixed>> */
	public function recent( int $limit = 0, int $offset = 0 ): array {
		$logs = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}

		$offset = max( 0, $offset );
		if ( 0 < $offset || 0 < $limit ) {
			$logs = array_slice( $logs, $offset, 0 < $limit ? $limit : null );
		}

		return array_map(
			function ( $log ): array {
				$log     = is_array( $log ) ? $log : array();
				$context = isset( $log['context'] ) && is_array( $log['context'] ) ? $log['context'] : array();

				return array(
					'created_at' => sanitize_text_field( (string) ( $log['created_at'] ?? '' ) ),
					'level'      => sanitize_key( (string) ( $log['level'] ?? 'info' ) ),
					'message'    => sanitize_text_field( (string) ( $log['message'] ?? '' ) ),
					'context'    => $this->sanitizer->scrub( $this->filter_context( $context ) ),
				);
			},
			$logs
		);
	}

	public function count(): int {
		$logs = get_option( self::OPTION_NAME, array() );

		return is_array( $logs ) ? count( $logs ) : 0;
	}

	public function clear(): void {
		delete_option( self::OPTION_NAME );
	}

	/** @param array<string, mixed> $context @return array<string, mixed> */
	private function filter_context( array $context ): array {
		$filtered = array();
		foreach ( self::CONTEXT_ALLOW_LIST as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$filtered[ $key ] = $context[ $key ];
			}
		}

		return $filtered;
	}

	/** @param array<string, mixed> $context */
	private function write( string $level, string $message, array $context ): void {
		$logs      = $this->recent();
		$settings  = $this->options->all();
		$retention = max( 20, min( 500, (int) $settings['log_retention'] ) );

		array_unshift(
			$logs,
			array(
				'created_at' => current_time( 'mysql' ),
				'level'      => sanitize_key( $level ),
				'message'    => sanitize_text_field( $message ),
				'context'    => $this->sanitizer->scrub( $this->filter_context( $context ) ),
			)
		);

		update_option( self::OPTION_NAME, array_slice( $logs, 0, $retention ), false );
	}
}
