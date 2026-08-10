<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\AssetResolver;
use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public function __construct( private readonly OptionRepository $options ) {}

	public function enqueue( string $hook ): void {
		$is_settings_page = 'woocommerce_page_' . AdminMenu::PAGE_SLUG === $hook;
		$is_order_screen  = $this->is_order_screen( $hook );

		if ( ! $is_settings_page && ! $is_order_screen ) {
			return;
		}

		$style_base = $is_settings_page ? 'admin-settings' : 'admin-orders';
		$style_file = AssetResolver::filename( 'assets/build', $style_base, 'css' );

		if ( '' !== $style_file ) {
			$style_dependencies = $is_settings_page ? array( 'wp-components' ) : array();

			wp_enqueue_style(
				'soocool-admin',
				AssetResolver::url( 'assets/build', $style_file ),
				$style_dependencies,
				AssetResolver::version( 'assets/build', $style_file )
			);
		}

		if ( ! $is_settings_page ) {
			return;
		}

		$script_file = AssetResolver::filename( 'assets/build', 'admin', 'js' );
		if ( '' === $script_file ) {
			return;
		}

		$asset_file = SOOCOOL_PLUGIN_DIR . 'assets/build/admin.asset.php';
		$asset      = is_readable( $asset_file ) ? require $asset_file : array();
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

		$settings = $this->options->all();
		$locale   = function_exists( 'get_user_locale' )
			? get_user_locale()
			: ( function_exists( 'get_locale' ) ? get_locale() : 'en_US' );

		wp_enqueue_script(
			'soocool-admin',
			AssetResolver::url( 'assets/build', $script_file ),
			$asset['dependencies'],
			AssetResolver::version( 'assets/build', $script_file, (string) $asset['version'] ),
			true
		);

		wp_set_script_translations( 'soocool-admin', 'soocool-for-woocommerce', SOOCOOL_PLUGIN_DIR . 'languages' );

		wp_add_inline_script(
			'soocool-admin',
			'window.sooCoolAdmin=' . wp_json_encode(
				array(
					'restUrl'            => esc_url_raw( rest_url( 'soocool/v1' ) ),
					'nonce'              => wp_create_nonce( 'wp_rest' ),
					'environment'         => (string) ( $settings['environment'] ?? 'test' ),
					'ordersUrl'           => esc_url_raw( admin_url( 'admin.php?page=wc-orders' ) ),
					'testPortalUrl'       => esc_url_raw( (string) apply_filters( 'soocool_test_portal_url', '' ) ),
					'pluginVersion'       => (string) SOOCOOL_VERSION,
					'locale'              => str_replace( '_', '-', $locale ),
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

}
