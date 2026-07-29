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

$soocool_order_actions_file = __DIR__ . '/src/WooCommerce/OrderActions.php';
if ( is_readable( $soocool_order_actions_file ) ) {
	require_once $soocool_order_actions_file;
	SooCool\WooCommerce\WooCommerce\OrderActions::unschedule_all();
}

delete_option( 'soocool_settings' );
delete_option( 'soocool_logs' );
delete_option( 'soocool_daypart_label_migration_20260707' );
delete_option( 'soocool_daypart_label_migration_20260707_ochtend_middag' );
delete_option( 'soocool_package_weight_migration_20260729_10kg' );

// Remove leftover per-order sync locks, manual API-test results, and webhook replay transients.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-prefixed rows; caching is not useful during uninstall deletion.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'soocool_sync_lock_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_manual_test_order_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_manual_test_order_result_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_webhook_replay_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_webhook_replay_' ) . '%'
	)
);
