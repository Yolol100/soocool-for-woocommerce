<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	public function enqueue( string $hook ): void {
		$is_settings_page = 'toplevel_page_' . AdminMenu::PAGE_SLUG === $hook;
		$is_order_screen  = $this->is_order_screen( $hook );

		if ( ! $is_settings_page && ! $is_order_screen ) {
			return;
		}

		$asset_file = SOOCOOL_PLUGIN_DIR . 'assets/build/admin.asset.php';

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

		$script_file = $this->asset_path( 'admin', 'js' );
		$style_file  = $this->asset_path( 'admin', 'css' );

		if ( '' !== $style_file && is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $style_file ) ) {
			$style_mtime        = filemtime( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $style_file );
			$style_dependencies = $is_settings_page ? array( 'wp-components' ) : array();

			wp_enqueue_style(
				'soocool-admin',
				SOOCOOL_PLUGIN_URL . 'assets/build/' . $style_file,
				$style_dependencies,
				false !== $style_mtime ? (string) $style_mtime : SOOCOOL_VERSION
			);
		}

		if ( ! $is_settings_page ) {
			return;
		}

		if ( '' === $script_file || ! is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $script_file ) ) {
			return;
		}

		$script_mtime   = filemtime( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $script_file );
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
					'restUrl' => esc_url_raw( rest_url( 'soocool/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
	}

	private function is_order_screen( string $hook ): bool {
		if ( 'woocommerce_page_wc-orders' === $hook ) {
			return true;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		$screen_id = (string) $screen->id;
		$post_type = (string) $screen->post_type;

		return in_array( $screen_id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true )
			|| 'shop_order' === $post_type;
	}

	private function asset_path( string $base, string $extension ): string {
		$suffixes = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? array( '' ) : array( '.min', '' );

		foreach ( $suffixes as $suffix ) {
			$file = $base . $suffix . '.' . $extension;
			if ( is_readable( SOOCOOL_PLUGIN_DIR . 'assets/build/' . $file ) ) {
				return $file;
			}
		}

		return '';
	}
}
