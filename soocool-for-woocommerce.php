<?php
/**
 * Plugin Name: SooCool for WooCommerce
 * Description: Koppelt WooCommerce-orders aan de SooCool transport-API.
 * Version: 0.7.148
 * Author: Webactueel
 * Text Domain: soocool-for-woocommerce
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 11.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: false
 *
 * @package SooCool\WooCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SOOCOOL_PLUGIN_FILE', __FILE__ );
define( 'SOOCOOL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOOCOOL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOOCOOL_VERSION', '0.7.148' );

if ( ! function_exists( 'soocool_deactivate_legacy_duplicate_plugin' ) ) {
	function soocool_deactivate_legacy_duplicate_plugin( bool $require_capability = true ): void {
		if ( $require_capability && ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) ) {
			return;
		}

		if ( ! function_exists( 'deactivate_plugins' ) || ! function_exists( 'plugin_basename' ) || ! function_exists( 'is_plugin_active' ) ) {
			return;
		}

		$current_basename = plugin_basename( __FILE__ );
		$legacy_basename  = 'soocool-for-woocommerce-main/soocool-for-woocommerce.php';
		if ( 'soocool-for-woocommerce/soocool-for-woocommerce.php' !== $current_basename ) {
			return;
		}

		if ( is_plugin_active( $legacy_basename ) ) {
			deactivate_plugins( $legacy_basename, true );
		}
	}
}

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			$blocks_compatible = class_exists( \SooCool\WooCommerce\Blocks\DeliveryOptionsIntegration::class )
				? \SooCool\WooCommerce\Blocks\DeliveryOptionsIntegration::compatibility_declared()
				: false;
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, $blocks_compatible );
		}
	}
);

$soocool_autoload = SOOCOOL_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $soocool_autoload ) ) {
	require_once $soocool_autoload;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SooCool\\WooCommerce\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = SOOCOOL_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

\SooCool\WooCommerce\Plugin::boot();
