<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operator maintenance actions. Currently exposes a "resync failed orders"
 * action that re-submits every order whose SooCool sync previously failed.
 * Work is queued through Action Scheduler when available so a large backlog
 * never blocks the request; a small synchronous batch is used as a fallback.
 */
final class MaintenanceController extends AbstractRestController {

	private const MAX_ORDERS         = 200;
	private const SYNC_FALLBACK_LIMIT = 5;

	public function __construct( private readonly OrderActions $actions ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/maintenance/resync-failed',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resync_failed' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function resync_failed(): WP_REST_Response {
		$order_ids = $this->failed_order_ids();
		$total     = count( $order_ids );

		if ( 0 === $total ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'queued'  => 0,
					'mode'    => 'none',
					'message' => __( 'No failed SooCool orders to resync.', 'soocool-for-woocommerce' ),
				)
			);
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			foreach ( $order_ids as $order_id ) {
				as_enqueue_async_action( OrderActions::RESYNC_HOOK, array( $order_id ), 'soocool' );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'queued'  => $total,
					'mode'    => 'scheduled',
					'message' => sprintf(
						/* translators: %d: number of orders queued for background resync. */
						_n( '%d failed order queued for background resync.', '%d failed orders queued for background resync.', $total, 'soocool-for-woocommerce' ),
						$total
					),
				)
			);
		}

		// Fallback: process a small batch inline so the action still works
		// without Action Scheduler, without risking a request timeout.
		$processed = 0;
		foreach ( array_slice( $order_ids, 0, self::SYNC_FALLBACK_LIMIT ) as $order_id ) {
			$this->actions->resync_order_by_id( (int) $order_id );
			++$processed;
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'queued'    => $processed,
				'remaining' => max( 0, $total - $processed ),
				'mode'      => 'inline',
				'message'   => sprintf(
					/* translators: 1: processed count, 2: remaining count. */
					__( 'Resynced %1$d failed orders now. %2$d remaining; run again to continue.', 'soocool-for-woocommerce' ),
					$processed,
					max( 0, $total - $processed )
				),
			)
		);
	}

	/** @return array<int, int> */
	private function failed_order_ids(): array {
		$orders = wc_get_orders(
			array(
				'limit'      => self::MAX_ORDERS,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance query.
					array(
						'key'   => OrderMeta::SYNC_STATUS,
						'value' => 'failed',
					),
				),
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $orders ) ) );
	}
}
