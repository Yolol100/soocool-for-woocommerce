<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	public const OPTION_NAME         = 'soocool_logs';
	/**
	 * Keep log context intentionally small. Values are still scrubbed by SecretSanitizer
	 * because upstream API errors may include secrets or customer data.
	 */
	private const CONTEXT_ALLOW_LIST = array( 'attempt', 'error', 'errors', 'method', 'path', 'status', 'traceId', 'api_key_present', 'api_key_source', 'api_key_status', 'api_key_length', 'api_key_first4', 'api_key_last4', 'header_name_sent', 'request_url_host', 'request_path' );

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
	public function recent(): array {
		$logs = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $logs ) ) {
			return array();
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
