<?php
/**
 * Plugin Name: SooCool for WooCommerce
 * Description: Connect WooCommerce orders with the SooCool transport API.
 * Version: 0.3.87
 * Author: Webactueel
 * Text Domain: soocool-for-woocommerce
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.9
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SooCool\WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOOCOOL_PLUGIN_FILE', __FILE__ );
define( 'SOOCOOL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOOCOOL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOOCOOL_VERSION', '0.3.87' );

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
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


add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=soocool-for-woocommerce' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'soocool-for-woocommerce' ) . '</a>'
		);

		return $links;
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( PHP_VERSION_ID < 80100 ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'SooCool for WooCommerce requires PHP 8.1 or higher.', 'soocool-for-woocommerce' ) );
		}

		( new SooCool\WooCommerce\Infrastructure\OptionRepository() )->migrate_for_current_version();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'soocool-for-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		( new SooCool\WooCommerce\Infrastructure\OptionRepository() )->migrate_for_current_version();
		SooCool\WooCommerce\Plugin::boot();
	}
);
