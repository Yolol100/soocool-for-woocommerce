<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

final class ApiException extends \RuntimeException {

	/** @param array<int, string> $errors */
	public function __construct( string $message, private readonly int $status_code = 0, private readonly array $errors = array() ) {
		parent::__construct( $message, $status_code );
	}

	public function status_code(): int {
		return $this->status_code;
	}

	/** @return array<int, string> */
	public function errors(): array {
		return $this->errors;
	}
}
