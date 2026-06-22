<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

final class ApiResponse {

	/** @param mixed $body */
	public function __construct( private readonly int $status_code, private readonly mixed $body, private readonly array $headers = array() ) {}

	public function status_code(): int {
		return $this->status_code;
	}

	/** @return mixed */
	public function body() {
		return $this->body;
	}

	public function headers(): array {
		return $this->headers;
	}
}
