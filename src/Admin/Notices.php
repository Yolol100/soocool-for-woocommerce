<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\Requirements;

defined( 'ABSPATH' ) || exit;

final class Notices {

	public function __construct( private readonly Requirements $requirements ) {}

	public function render_requirements_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->requirements->get_missing_message() ) );
	}

	public function render_runtime_notices(): void {}

}
