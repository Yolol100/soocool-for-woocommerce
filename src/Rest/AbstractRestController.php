<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use WP_REST_Controller;

defined( 'ABSPATH' ) || exit;

abstract class AbstractRestController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * Inherited WP_REST_Controller properties stay untyped. WordPress core
	 * declares these properties without native types; typed or readonly
	 * redeclarations cause fatal errors on modern PHP versions.
	 *
	 * @var string
	 */
	protected $namespace = 'soocool/v1';

	/** @var string */
	protected $rest_base = '';

	/** @var array<string, mixed>|null */
	protected $schema = null;

	public function can_manage(): bool {
		return current_user_can( $this->capability_for( 'manage' ) );
	}

	public function can_manage_secrets(): bool {
		return current_user_can( $this->capability_for( 'secrets' ) );
	}

	public function can_run_manual_tests(): bool {
		return current_user_can( $this->capability_for( 'manual_tests' ) );
	}

	private function capability_for( string $context ): string {
		$defaults = array(
			'manage'       => 'manage_woocommerce',
			'secrets'      => 'manage_woocommerce',
			'manual_tests' => 'manage_woocommerce',
		);

		$default = $defaults[ $context ] ?? $defaults['manage'];
		$capability = apply_filters( 'soocool_' . $context . '_capability', $default );

		return is_string( $capability ) && '' !== $capability ? $capability : $default;
	}
}
