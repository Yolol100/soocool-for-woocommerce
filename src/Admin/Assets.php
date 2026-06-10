<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . AdminMenu::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = SOOCOOL_PLUGIN_DIR . 'assets/build/app.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			$asset_file = SOOCOOL_PLUGIN_DIR . 'assets/build/admin.asset.php';
		}

		$asset = is_readable( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => SOOCOOL_VERSION,
		);

		$script_file = is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/app.js' ) ? 'app.js' : 'admin.js';
		$style_file  = is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/app.css' ) ? 'app.css' : 'admin.css';

		wp_enqueue_script(
			'soocool-admin',
			SOOCOOL_PLUGIN_URL . 'assets/build/' . $script_file,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $style_file ) ) {
			wp_enqueue_style(
				'soocool-admin',
				SOOCOOL_PLUGIN_URL . 'assets/build/' . $style_file,
				array( 'wp-components' ),
				(string) filemtime( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $style_file )
			);
		}

		wp_add_inline_script(
			'soocool-admin',
			'window.sooCoolAdmin=' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'soocool/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}
}
