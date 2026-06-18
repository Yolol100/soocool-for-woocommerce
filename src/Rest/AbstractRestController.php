<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use WP_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AbstractRestController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * Keep inherited WP_REST_Controller properties untyped. WordPress core
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
		return current_user_can( 'manage_woocommerce' );
	}
}
