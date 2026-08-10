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

$soocool_email_labels_file         = __DIR__ . '/src/WooCommerce/OrderEmailLabels.php';
$soocool_order_actions_file        = __DIR__ . '/src/WooCommerce/OrderActions.php';
$soocool_webhook_authenticator_file = __DIR__ . '/src/Rest/WebhookAuthenticator.php';
if ( is_readable( $soocool_email_labels_file ) && is_readable( $soocool_order_actions_file ) ) {
	require_once $soocool_email_labels_file;
	require_once $soocool_order_actions_file;
	SooCool\WooCommerce\WooCommerce\OrderActions::unschedule_all();
}
if ( is_readable( $soocool_webhook_authenticator_file ) ) {
	require_once $soocool_webhook_authenticator_file;
	SooCool\WooCommerce\Rest\WebhookAuthenticator::unschedule_cleanup();
}

delete_option( 'soocool_settings' );
delete_option( 'soocool_logs' );
delete_option( 'soocool_logs_write_lock' );
delete_option( 'soocool_connection_state' );
delete_option( 'soocool_daypart_label_migration_20260707' );
delete_option( 'soocool_daypart_label_migration_20260707_ochtend_middag' );
delete_option( 'soocool_package_weight_migration_20260729_10kg' );
delete_option( 'soocool_migration_version' );

// Remove cached email-label files before deleting their transient records.
global $wpdb;
$soocool_temp_root = realpath( get_temp_dir() );
$soocool_temp_root = is_string( $soocool_temp_root ) ? trailingslashit( $soocool_temp_root ) : '';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup must inspect plugin-owned transient values before deleting their temporary files.
$soocool_email_label_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_soocool_email_labels_' ) . '%'
	),
	ARRAY_A
);
if ( '' !== $soocool_temp_root && is_array( $soocool_email_label_rows ) ) {
	foreach ( $soocool_email_label_rows as $soocool_email_label_row ) {
		$soocool_paths = is_array( $soocool_email_label_row ) ? maybe_unserialize( $soocool_email_label_row['option_value'] ?? null ) : null;
		if ( ! is_array( $soocool_paths ) ) {
			continue;
		}
		foreach ( $soocool_paths as $soocool_path ) {
			if ( ! is_string( $soocool_path ) || '' === $soocool_path || is_link( $soocool_path ) ) {
				continue;
			}
			$soocool_real_path = realpath( $soocool_path );
			if ( false === $soocool_real_path || ! is_file( $soocool_real_path ) || ! str_starts_with( trailingslashit( dirname( $soocool_real_path ) ), $soocool_temp_root ) ) {
				continue;
			}
			wp_delete_file( $soocool_real_path );
		}
	}
}

// Remove leftover per-order sync locks, manual API-test results, webhook replay transients, and email-label transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-prefixed rows; caching is not useful during uninstall deletion.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'soocool_sync_lock_' ) . '%',
		$wpdb->esc_like( 'soocool_webhook_reservation_' ) . '%',
		$wpdb->esc_like( 'soocool_webhook_order_lock_' ) . '%',
		$wpdb->esc_like( 'soocool_webhook_event_' ) . '%',
		$wpdb->esc_like( 'soocool_bulk_label_lock_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_manual_test_order_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_manual_test_order_result_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_webhook_replay_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_webhook_replay_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_bulk_label_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_bulk_label_' ) . '%',
		$wpdb->esc_like( '_transient_soocool_email_labels_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_soocool_email_labels_' ) . '%'
	)
);
