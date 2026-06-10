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
	 * Keep this untyped because WP_REST_Controller defines the same
	 * inherited property without a native type. Adding a type here causes
	 * a fatal error on activation in PHP.
	 *
	 * @var string
	 */
	protected $namespace = 'soocool/v1';

	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' );
	}
}
