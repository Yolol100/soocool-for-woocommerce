<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public const OPTION_NAME         = 'soocool_logs';
	private const WRITE_LOCK_KEY     = 'soocool_logs_write_lock';
	private const WRITE_LOCK_TTL     = 5;
	private const WRITE_LOCK_RETRIES = 5;
	private const DEFAULT_LEGACY_SUMMARY_LIMIT = 100;
	/** Log context is allow-listed and scrubbed before storage. */
	private const CONTEXT_ALLOW_LIST = array( 'action', 'attempt', 'content_type', 'delay_ms', 'error', 'errors', 'limit', 'method', 'path', 'status', 'traceId', 'orderId', 'orderReference', 'wcOrderId', 'api_key_present', 'api_key_source', 'api_key_status', 'api_key_length', 'header_name_sent', 'request_url_host', 'request_path' );

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
					'created_at' => sanitize_text_field( $this->scalar_string( $log['created_at'] ?? '' ) ),
					'level'      => sanitize_key( $this->scalar_string( $log['level'] ?? 'info', 'info' ) ),
					'message'    => $this->sanitizer->scrub_text( $this->scalar_string( $log['message'] ?? '' ) ),
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

	/**
	 * Filters the bounded in-memory log buffer.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( int $limit = 50, int $offset = 0, string $level = '', string $search = '', int $order_id = 0, string $date_from = '', string $date_to = '' ): array {
		$logs      = $this->recent();
		$level     = sanitize_key( $level );
		$search    = $this->lowercase( $this->truncate( trim( sanitize_text_field( $search ) ), 100 ) );
		$date_from = $this->valid_date( $date_from ) ? $date_from : '';
		$date_to   = $this->valid_date( $date_to ) ? $date_to : '';

		$filtered = array_values(
			array_filter(
				$logs,
				function ( array $log ) use ( $level, $search, $order_id, $date_from, $date_to ): bool {
					$context = isset( $log['context'] ) && is_array( $log['context'] ) ? $log['context'] : array();
					if ( '' !== $level && $level !== (string) ( $log['level'] ?? '' ) ) {
						return false;
					}

					if ( 0 < $order_id ) {
						$candidates = array( $context['wcOrderId'] ?? null, $context['orderId'] ?? null, $context['orderReference'] ?? null );
						$matches    = false;
						foreach ( $candidates as $candidate ) {
							if ( (string) $order_id === (string) $candidate ) {
								$matches = true;
								break;
							}
						}
						if ( ! $matches ) {
							return false;
						}
					}

					$log_date = substr( (string) ( $log['created_at'] ?? '' ), 0, 10 );
					if ( '' !== $date_from && $log_date < $date_from ) {
						return false;
					}
					if ( '' !== $date_to && $log_date > $date_to ) {
						return false;
					}

					if ( '' !== $search ) {
						$haystack = $this->lowercase( (string) ( $log['message'] ?? '' ) . ' ' . wp_json_encode( $context ) );
						if ( ! str_contains( $haystack, $search ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);

		$total  = count( $filtered );
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		return array(
			'items' => array_slice( $filtered, $offset, $limit ),
			'total' => $total,
		);
	}

	/** @return array{total: int, errors: int, recent_errors: int, last_activity: array<string, mixed>|null} */
	public function summary(): array {
		$logs          = $this->recent();
		$errors        = 0;
		$recent_errors = 0;
		$cutoff        = time() - DAY_IN_SECONDS;

		foreach ( $logs as $log ) {
			if ( 'error' !== (string) ( $log['level'] ?? '' ) ) {
				continue;
			}

			++$errors;
			$created = $this->created_at_timestamp( (string) ( $log['created_at'] ?? '' ) );
			if ( 0 < $created && $created >= $cutoff ) {
				++$recent_errors;
			}
		}

		return array(
			'total'         => count( $logs ),
			'errors'        => $errors,
			'recent_errors' => $recent_errors,
			'last_activity' => $logs[0] ?? null,
		);
	}

	public function clear(): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			try {
				$woocommerce_logger = wc_get_logger();
				if ( is_object( $woocommerce_logger ) && method_exists( $woocommerce_logger, 'clear' ) ) {
					$woocommerce_logger->clear( 'soocool' );
				}
			} catch ( \Throwable ) {
				// The bounded compatibility summary is still cleared below.
			}
		}

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

	private function valid_date( string $value ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	private function scalar_string( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private function lowercase( string $value ): string {
		$value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private function truncate( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length, 'UTF-8' );
		}

		$matched = preg_match_all( '/./us', $value, $characters );
		if ( false === $matched ) {
			return substr( $value, 0, $length );
		}

		return implode( '', array_slice( $characters[0], 0, $length ) );
	}

	private function created_at_timestamp( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}

		if ( function_exists( 'wp_timezone' ) ) {
			$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
			if ( $date instanceof \DateTimeImmutable ) {
				return $date->getTimestamp();
			}
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? 0 : $timestamp;
	}

	/** @param array<string, mixed> $context */
	private function write( string $level, string $message, array $context ): void {
		$level   = sanitize_key( $level );
		$message = $this->sanitizer->scrub_text( $message );
		$context = $this->sanitizer->scrub( $this->filter_context( $context ) );

		if ( function_exists( 'wc_get_logger' ) ) {
			try {
				$woocommerce_logger = wc_get_logger();
				if ( is_object( $woocommerce_logger ) && method_exists( $woocommerce_logger, 'log' ) ) {
					$woocommerce_logger->log(
						$level,
						$message,
						array(
							'source'  => 'soocool',
							'context' => $context,
						)
					);
				}
			} catch ( \Throwable ) {
				// Legacy summary below remains available when the WooCommerce logger fails.
			}
		}

		$this->write_legacy_summary( $level, $message, $context );
	}

	/** @param array<string, mixed> $context */
	private function write_legacy_summary( string $level, string $message, array $context ): void {
		$lock = $this->acquire_write_lock();
		if ( null === $lock ) {
			do_action( 'soocool_log_write_skipped', $level, $message );
			return;
		}

		try {
			$logs = $this->recent();
			array_unshift(
				$logs,
				array(
					'created_at' => current_time( 'mysql' ),
					'level'      => $level,
					'message'    => $message,
					'context'    => $context,
				)
			);
			update_option( self::OPTION_NAME, array_slice( $logs, 0, $this->legacy_summary_limit() ), false );
		} finally {
			OptionMutex::release( self::WRITE_LOCK_KEY, $lock );
		}
	}

	private function legacy_summary_limit(): int {
		$settings = $this->options->all();

		return max( 20, min( 500, absint( $settings['log_retention'] ?? self::DEFAULT_LEGACY_SUMMARY_LIMIT ) ) );
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
}
