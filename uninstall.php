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
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-prefixed rows; caching is not useful during uninstall deletion.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'soocool_sync_lock_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_manual_test_order_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_manual_test_order_result_' ) . '%'
	)
);
