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

// Remove leftover per-order sync locks and manual API-Test result transients.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'soocool_sync_lock_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_manual_test_order_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_manual_test_order_result_' ) . '%'
	)
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall cleanup of plugin-prefixed rows.
