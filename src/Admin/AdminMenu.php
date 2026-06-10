<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {

	public const PAGE_SLUG = 'soocool-for-woocommerce';

	public function register(): void {
		add_menu_page(
			__( 'SooCool for WooCommerce', 'soocool-for-woocommerce' ),
			__( 'SooCool', 'soocool-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-location-alt',
			56
		);
	}

	public function render(): void {
		echo '<div class="wrap"><div id="soocool-admin-app"></div></div>';
	}
}
