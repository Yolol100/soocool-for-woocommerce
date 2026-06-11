<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	public function enqueue( string $hook ): void {
		$is_settings_page = 'toplevel_page_' . AdminMenu::PAGE_SLUG === $hook;
		$is_manual_page   = str_ends_with( $hook, '_page_' . AdminMenu::MANUAL_TEST_PAGE_SLUG ) || 'admin_page_' . AdminMenu::MANUAL_TEST_PAGE_SLUG === $hook;

		if ( ! $is_settings_page && ! $is_manual_page ) {
			return;
		}

		$asset_file = SOOCOOL_PLUGIN_DIR . 'assets/build/app.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			$asset_file = SOOCOOL_PLUGIN_DIR . 'assets/build/admin.asset.php';
		}

		$asset = is_readable( $asset_file ) ? require $asset_file : array();
		if ( ! is_array( $asset ) ) {
			$asset = array();
		}
		$asset = wp_parse_args(
			$asset,
			array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
				'version'      => SOOCOOL_VERSION,
			)
		);
		if ( ! is_array( $asset['dependencies'] ) ) {
			$asset['dependencies'] = array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' );
		}

		$script_file = is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/app.js' ) ? 'app.js' : 'admin.js';
		$style_file  = is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/app.css' ) ? 'app.css' : 'admin.css';

		if ( is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $style_file ) ) {
			$style_mtime = filemtime( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $style_file );
			wp_enqueue_style(
				'soocool-admin',
				SOOCOOL_PLUGIN_URL . 'assets/build/' . $style_file,
				array( 'wp-components' ),
				false !== $style_mtime ? (string) $style_mtime : SOOCOOL_VERSION
			);
		}

		if ( ! $is_settings_page ) {
			return;
		}

		$script_mtime = filemtime( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $script_file );
		$script_version = false !== $script_mtime ? (string) $script_mtime : (string) $asset['version'];

		wp_enqueue_script(
			'soocool-admin',
			SOOCOOL_PLUGIN_URL . 'assets/build/' . $script_file,
			$asset['dependencies'],
			$script_version,
			true
		);

		wp_add_inline_script(
			'soocool-admin',
			'window.sooCoolAdmin=' . wp_json_encode(
				array(
					'restUrl'       => esc_url_raw( rest_url( 'soocool/v1' ) ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'manualTestUrl' => esc_url_raw( admin_url( 'admin.php?page=' . AdminMenu::MANUAL_TEST_PAGE_SLUG ) ),
				)
			) . ';',
			'before'
		);
	}
}
