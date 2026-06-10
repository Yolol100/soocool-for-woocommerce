<?php
/**
 * Uninstall cleanup for SooCool for WooCommerce.
 *
 * @package SooCool\WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'soocool_settings' );
delete_option( 'soocool_logs' );
