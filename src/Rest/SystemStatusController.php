<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Blocks\DeliveryOptionsIntegration;
use SooCool\WooCommerce\Infrastructure\ConnectionStateRepository;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionDefaults;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Reports local configuration and operational state for the admin dashboard.
 * Remote availability is checked separately by the connection test.
 */
final class SystemStatusController extends AbstractRestController {

	public function __construct(
		private readonly OptionRepository $options,
		private readonly Logger $logger,
		private readonly ConnectionStateRepository $connection_state
	) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function get(): WP_REST_Response {
		$settings         = $this->options->public_settings();
		$log_summary      = $this->logger->summary();
		$failed_count     = $this->failed_order_count();
		$api_ready        = (bool) ( $settings['api_key_present'] ?? false ) && 'valid' === (string) ( $settings['api_key_status'] ?? '' );
		$webhook_url      = (string) ( $settings['effective_webhook_url'] ?? '' );
		$webhook_ready    = '' !== $webhook_url && str_starts_with( $webhook_url, 'https://' );
		$checkout_enabled = (bool) ( $settings['checkout_delivery_enabled'] ?? false );
		$checkout_mode    = $this->checkout_mode();
		$blocks_adapter_available = DeliveryOptionsIntegration::is_enabled_runtime();
		$blocks_supported         = DeliveryOptionsIntegration::compatibility_declared();
		$environment      = 'production' === (string) ( $settings['environment'] ?? 'test' ) ? 'production' : 'test';
		$connection_fingerprint = $this->connection_state->configuration_fingerprint( $environment, $this->options->base_url(), $this->options->api_key() );
		$connection_test        = $this->connection_state->current( $environment, $connection_fingerprint );
		$connection_ready = null !== $connection_test && 'success' === $connection_test['result'] && ! $connection_test['stale'];

		$checks = array(
			array(
				'id'       => 'api',
				'label'    => __( 'API-key geconfigureerd', 'soocool-for-woocommerce' ),
				'complete' => $api_ready,
				'required' => true,
			),
			array(
				'id'       => 'connection',
				'label'    => __( 'Recente geslaagde verbindingstest voor de actieve omgeving', 'soocool-for-woocommerce' ),
				'complete' => $connection_ready,
				'required' => true,
			),
			array(
				'id'       => 'webhook',
				'label'    => __( 'Webhook voor automatische statusupdates (optioneel)', 'soocool-for-woocommerce' ),
				'complete' => $webhook_ready,
				'required' => false,
			),
		);

		if ( $checkout_enabled ) {
			$checks[] = $this->delivery_check( $checkout_mode, $blocks_adapter_available, $blocks_supported );
			$checks[] = array(
				'id'       => 'schedule',
				'label'    => __( 'Minimaal één bezorgdag en dagdeel actief', 'soocool-for-woocommerce' ),
				'complete' => $this->has_active_delivery_rule( $settings ),
				'required' => true,
			);
		} else {
			$checks[] = array(
				'id'       => 'delivery',
				'label'    => __( 'Checkout-bezorgkeuze is uitgeschakeld (optioneel)', 'soocool-for-woocommerce' ),
				'complete' => true,
				'required' => false,
			);
		}

		$required_checks = array_values(
			array_filter(
				$checks,
				static fn ( array $check ): bool => (bool) ( $check['required'] ?? false )
			)
		);
		$completed = count(
			array_filter(
				$required_checks,
				static fn ( array $check ): bool => (bool) $check['complete']
			)
		);
		$completion = 0 < count( $required_checks ) ? (int) round( ( $completed / count( $required_checks ) ) * 100 ) : 100;
		$required_incomplete = $completed < count( $required_checks );
		$health = $required_incomplete ? 'setup' : ( 0 < $failed_count || 0 < (int) $log_summary['recent_errors'] ? 'attention' : 'ready' );

		return new WP_REST_Response(
			array(
				'version'     => defined( 'SOOCOOL_VERSION' ) ? (string) SOOCOOL_VERSION : '',
				'health'      => $health,
				'completion'  => $completion,
				'checks'      => $checks,
				'environment' => $environment,
				'connection_test' => $connection_test,
				'api'         => array(
					'configured' => $api_ready,
					'source'     => (string) ( $settings['api_key_source'] ?? 'database' ),
					'status'     => (string) ( $settings['api_key_status'] ?? 'missing' ),
					'base_url'   => (string) ( $settings['effective_base_url'] ?? '' ),
				),
				'webhook'     => array(
					'configured'         => $webhook_ready,
					'signature_required' => (bool) ( $settings['webhook_signature_required'] ?? true ),
					'legacy_query_token'  => (bool) ( $settings['query_token_fallback_enabled'] ?? false ),
				),
				'automation'  => array(
					'enabled' => OptionDefaults::AUTO_SUBMIT_ENABLED,
					'status'  => OptionDefaults::AUTO_SUBMIT_STATUS,
				),
				'checkout'    => array(
					'enabled'          => $checkout_enabled,
					'mode'             => $checkout_mode,
					'blocks_supported'         => $blocks_supported,
					'blocks_adapter_available' => $blocks_adapter_available,
					'days_ahead'       => absint( $settings['checkout_delivery_days_ahead'] ?? 0 ),
				),
				'labels'      => array(
					'format' => (string) ( $settings['label_output'] ?? 'a6' ),
				),
				'operations'  => array(
					'failed_orders'    => $failed_count,
					'logs_total'       => (int) $log_summary['total'],
					'log_errors'       => (int) $log_summary['recent_errors'],
					'log_errors_total' => (int) $log_summary['errors'],
					'last_activity'    => $log_summary['last_activity'],
				),
			)
		);
	}


	/** @return array{id:string,label:string,complete:bool,required:bool} */
	private function delivery_check( string $checkout_mode, bool $blocks_adapter_available, bool $blocks_supported ): array {
		return match ( $checkout_mode ) {
			'classic' => array(
				'id'       => 'delivery',
				'label'    => __( 'Bezorgkeuze in klassieke checkout actief', 'soocool-for-woocommerce' ),
				'complete' => true,
				'required' => true,
			),
			'blocks' => array(
				'id'       => 'delivery',
				'label'    => $blocks_supported
					? __( 'Checkout Blocks actief; SooCool-bezorgkeuze ondersteund', 'soocool-for-woocommerce' )
					: ( $blocks_adapter_available
						? __( 'Checkout Blocks actief; bezorgkeuze is nog niet volledig ondersteund', 'soocool-for-woocommerce' )
						: __( 'Checkout Blocks actief; SooCool-bezorgkeuze is in deze WooCommerce-runtime niet beschikbaar', 'soocool-for-woocommerce' ) ),
				'complete' => $blocks_supported,
				'required' => true,
			),
			default => array(
				'id'       => 'delivery',
				'label'    => __( 'Checkoutpagina of checkoutmodus kon niet worden vastgesteld', 'soocool-for-woocommerce' ),
				'complete' => false,
				'required' => true,
			),
		};
	}

	private function checkout_mode(): string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return 'unknown';
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );
		if ( 0 >= $checkout_page_id ) {
			return 'unknown';
		}

		return function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $checkout_page_id )
			? 'blocks'
			: 'classic';
	}

	/** @param array<string, mixed> $settings */
	private function has_active_delivery_rule( array $settings ): bool {
		$schedule = $settings['checkout_delivery_schedule'] ?? array();
		if ( ! is_array( $schedule ) ) {
			return false;
		}

		foreach ( $schedule as $rule ) {
			if ( ! is_array( $rule ) || ! (bool) ( $rule['enabled'] ?? false ) ) {
				continue;
			}

			$slots = is_array( $rule['slots'] ?? null ) ? $rule['slots'] : array();
			foreach ( $slots as $slot ) {
				if ( is_array( $slot ) && (bool) ( $slot['enabled'] ?? false ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function failed_order_count(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$query = wc_get_orders(
			array(
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded dashboard status query.
					array(
						'key'     => OrderMeta::SYNC_STATUS,
						'value'   => OrderMeta::failure_statuses(),
						'compare' => 'IN',
					),
				),
			)
		);

		if ( is_object( $query ) && isset( $query->total ) ) {
			return absint( $query->total );
		}

		return is_array( $query ) ? count( $query ) : 0;
	}
}
