<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operator maintenance actions. Currently exposes a "resync failed orders"
 * action that queues a bounded batch of orders whose SooCool sync previously
 * failed. Work is queued through Action Scheduler when available, with WP-Cron
 * as the fallback, so a large backlog never blocks the request.
 */
final class MaintenanceController extends AbstractRestController {

	private const MAX_ORDERS = 200;

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
		$batch       = $this->failed_order_batch();
		$order_ids   = $batch['order_ids'];
		$total_found = $batch['total_found'];
		$batch_total = count( $order_ids );
		$remaining   = max( 0, $total_found - $batch_total );
		$truncated   = $remaining > 0;

		if ( 0 === $total_found ) {
			return new WP_REST_Response(
				array(
					'success'     => true,
					'queued'      => 0,
					'limit'       => self::MAX_ORDERS,
					'total_found' => 0,
					'remaining'   => 0,
					'truncated'   => false,
					'mode'        => 'none',
					'message'     => __( 'Geen mislukte SooCool-orders om opnieuw te synchroniseren.', 'soocool-for-woocommerce' ),
				)
			);
		}

		$queued     = 0;
		$duplicates = 0;
		$failed     = 0;
		foreach ( $order_ids as $order_id ) {
			$result = $this->actions->schedule_resync_order( (int) $order_id );
			if ( OrderActions::QUEUE_SCHEDULED === $result ) {
				++$queued;
				continue;
			}

			if ( OrderActions::QUEUE_DUPLICATE === $result ) {
				++$duplicates;
				continue;
			}

			++$failed;
		}

		$message = sprintf(
			/* translators: 1: queued orders, 2: duplicate orders, 3: failed schedules, 4: remaining failed orders. */
			__( '%1$d mislukte orders ingepland voor hersynchronisatie op de achtergrond. %2$d orders stonden al ingepland, %3$d orders konden niet worden ingepland. Nog resterend na deze batch: %4$d.', 'soocool-for-woocommerce' ),
			$queued,
			$duplicates,
			$failed,
			$remaining
		);

		if ( $truncated ) {
			$message .= ' ' . __( 'Voer deze actie opnieuw uit om de volgende batch mislukte orders in te plannen.', 'soocool-for-woocommerce' );
		}

		return new WP_REST_Response(
			array(
				'success'     => 0 === $failed,
				'queued'      => $queued,
				'duplicates'  => $duplicates,
				'failed'      => $failed,
				'limit'       => self::MAX_ORDERS,
				'total_found' => $total_found,
				'remaining'   => $remaining,
				'truncated'   => $truncated,
				'mode'        => 'scheduled',
				'message'     => $message,
			)
		);
	}

	/** @return array{order_ids: array<int, int>, total_found: int} */
	private function failed_order_batch(): array {
		$query = wc_get_orders(
			array(
				'limit'      => self::MAX_ORDERS,
				'paginate'   => true,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded maintenance query.
					array(
						'key'   => OrderMeta::SYNC_STATUS,
						'value' => 'failed',
					),
				),
			)
		);

		$orders = ( is_object( $query ) && isset( $query->orders ) && is_array( $query->orders ) ) ? $query->orders : array();
		$total  = ( is_object( $query ) && isset( $query->total ) ) ? absint( $query->total ) : count( $orders );

		return array(
			'order_ids'   => array_values( array_filter( array_map( 'absint', $orders ) ) ),
			'total_found' => $total,
		);
	}
}
