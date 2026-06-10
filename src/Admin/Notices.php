<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notices {

	public function __construct( private readonly Requirements $requirements ) {}

	public function render_requirements_notice(): void {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->requirements->get_missing_message() ) );
	}
}
