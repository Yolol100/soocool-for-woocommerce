<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Send to SooCool" bulk action to the HPOS and legacy orders lists.
 * Work is queued through Action Scheduler when available so a large selection
 * never blocks the admin request on a slow SooCool API; a small synchronous
 * batch is used as a fallback. Mirrors the resync-failed maintenance flow.
 */
final class BulkSyncActions {

	private const ACTION            = 'soocool_send_to_soocool';
	private const MAX_BULK_ORDERS   = 50;
	private const SYNC_FALLBACK_LIMIT = 5;
	private const MODE_PARAM        = 'soocool_bulk_mode';
	private const QUEUED_PARAM      = 'soocool_bulk_queued';
	private const SYNCED_PARAM      = 'soocool_bulk_synced';
	private const FAILED_PARAM      = 'soocool_bulk_failed';
	private const REMAINING_PARAM   = 'soocool_bulk_remaining';
	private const ERROR_PARAM       = 'soocool_bulk_error';

	public function __construct( private readonly OrderActions $actions, private readonly OrderMeta $meta ) {}

	public function register(): void {
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function add_bulk_action( array $actions ): array {
		$actions[ self::ACTION ] = __( 'Send to SooCool', 'soocool-for-woocommerce' );
		return $actions;
	}

	/**
	 * @param array<int, int|string> $ids
	 */
	public function handle( string $redirect_to, string $action, array $ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$total     = count( $order_ids );

		if ( 0 === $total ) {
			return $redirect_to;
		}

		if ( $total > self::MAX_BULK_ORDERS ) {
			return add_query_arg(
				array(
					self::MODE_PARAM   => 'error',
					self::ERROR_PARAM  => 'too_many',
					self::QUEUED_PARAM => $total,
				),
				$redirect_to
			);
		}

		// Prefer Action Scheduler so a large selection never blocks the request
		// on a slow SooCool API. Reuses the same background hook as the
		// normal single-order send path, so already-synced orders still respect the resubmission setting.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$queued = 0;
			foreach ( $order_ids as $order_id ) {
				if ( $this->enqueue_sync_once( (int) $order_id ) ) {
					++$queued;
				}
			}

			return add_query_arg(
				array(
					self::MODE_PARAM   => 'scheduled',
					self::QUEUED_PARAM => $queued,
				),
				$redirect_to
			);
		}

		// Fallback: process a small batch inline so the action still works
		// without Action Scheduler, without risking a request timeout.
		$synced = 0;
		$failed = 0;
		foreach ( array_slice( $order_ids, 0, self::SYNC_FALLBACK_LIMIT ) as $order_id ) {
			$this->actions->send_order_by_id( (int) $order_id );

			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && $this->meta->is_synced( $order ) ) {
				++$synced;
			} else {
				++$failed;
			}
		}

		return add_query_arg(
			array(
				self::MODE_PARAM      => 'inline',
				self::SYNCED_PARAM    => $synced,
				self::FAILED_PARAM    => $failed,
				self::REMAINING_PARAM => max( 0, $total - $synced - $failed ),
			),
			$redirect_to
		);
	}

	private function enqueue_sync_once( int $order_id ): bool {
		$args = array( absint( $order_id ) );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( OrderActions::SYNC_HOOK, $args, 'soocool' ) ) {
			return false;
		}

		as_enqueue_async_action( OrderActions::SYNC_HOOK, $args, 'soocool' );
		return true;
	}

	public function render_notice(): void {
		$mode = isset( $_GET[ self::MODE_PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::MODE_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.

		if ( 'error' === $mode ) {
			$error = isset( $_GET[ self::ERROR_PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::ERROR_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.
			$total = isset( $_GET[ self::QUEUED_PARAM ] ) ? absint( wp_unslash( $_GET[ self::QUEUED_PARAM ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'too_many' === $error && $total > self::MAX_BULK_ORDERS ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of orders selected for the SooCool bulk send action. */
							_n( 'Select 50 or fewer orders for one SooCool bulk send. You selected %d order.', 'Select 50 or fewer orders for one SooCool bulk send. You selected %d orders.', $total, 'soocool-for-woocommerce' ),
							$total
						)
					)
				);
			}
			return;
		}

		if ( 'scheduled' === $mode ) {
			$queued = isset( $_GET[ self::QUEUED_PARAM ] ) ? absint( wp_unslash( $_GET[ self::QUEUED_PARAM ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = 0 === $queued
				? __( 'No new SooCool background sync jobs were queued. The selected orders are already scheduled.', 'soocool-for-woocommerce' )
				: sprintf(
					/* translators: %d: number of orders queued for background sync to SooCool. */
					_n( '%d new order queued for background sync to SooCool. Already queued orders were skipped.', '%d new orders queued for background sync to SooCool. Already queued orders were skipped.', $queued, 'soocool-for-woocommerce' ),
					$queued
				);

			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
			return;
		}

		if ( 'inline' !== $mode ) {
			return;
		}

		$synced    = isset( $_GET[ self::SYNCED_PARAM ] ) ? absint( wp_unslash( $_GET[ self::SYNCED_PARAM ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$failed    = isset( $_GET[ self::FAILED_PARAM ] ) ? absint( wp_unslash( $_GET[ self::FAILED_PARAM ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$remaining = isset( $_GET[ self::REMAINING_PARAM ] ) ? absint( wp_unslash( $_GET[ self::REMAINING_PARAM ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$message = sprintf(
			/* translators: %d: number of orders sent to SooCool. */
			_n( '%d order sent to SooCool.', '%d orders sent to SooCool.', $synced, 'soocool-for-woocommerce' ),
			$synced
		);

		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders that could not be sent. */
				_n( '%d order could not be sent; check its SooCool notes.', '%d orders could not be sent; check their SooCool notes.', $failed, 'soocool-for-woocommerce' ),
				$failed
			);
		}

		if ( $remaining > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders still waiting to be sent. */
				_n( '%d order remaining; run the bulk action again to continue.', '%d orders remaining; run the bulk action again to continue.', $remaining, 'soocool-for-woocommerce' ),
				$remaining
			);
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $failed > 0 ? 'warning' : 'success' ),
			esc_html( $message )
		);
	}
}
