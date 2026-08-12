<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

final class ApiException extends \RuntimeException {

	private const RETRYABLE_STATUS_CODES = array( 408, 425, 429, 500, 502, 503, 504 );

	private readonly bool $retryable;

	/** @param array<int, string> $errors */
	public function __construct(
		string $message,
		private readonly int $status_code = 0,
		private readonly array $errors = array(),
		?bool $retryable = null,
		private readonly int $retry_after_seconds = 0
	) {
		parent::__construct( $message, $status_code );
		$this->retryable = $retryable ?? in_array( $status_code, self::RETRYABLE_STATUS_CODES, true );
	}

	public function status_code(): int {
		return $this->status_code;
	}

	/** @return array<int, string> */
	public function errors(): array {
		return $this->errors;
	}

	public function is_retryable(): bool {
		return $this->retryable;
	}

	public function retry_after_seconds(): int {
		return max( 0, $this->retry_after_seconds );
	}
}
