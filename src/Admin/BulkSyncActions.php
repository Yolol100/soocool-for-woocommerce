<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\WooCommerce\OrderActions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Naar SooCool versturen" bulk action to the HPOS and legacy orders lists.
 * Work is queued through Action Scheduler when available, with WP-Cron as the
 * fallback, so a large selection never blocks the admin request on a slow SooCool API.
 */
final class BulkSyncActions {

	private const ACTION            = 'soocool_send_to_soocool';
	private const MAX_BULK_ORDERS   = 50;
	private const MODE_PARAM        = 'soocool_bulk_mode';
	private const QUEUED_PARAM      = 'soocool_bulk_queued';
	private const SYNCED_PARAM      = 'soocool_bulk_synced';
	private const FAILED_PARAM      = 'soocool_bulk_failed';
	private const REMAINING_PARAM   = 'soocool_bulk_remaining';
	private const ERROR_PARAM       = 'soocool_bulk_error';

	public function __construct( private readonly OrderActions $actions ) {}

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
		$actions[ self::ACTION ] = __( 'Naar SooCool versturen', 'soocool-for-woocommerce' );
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

		$queued = 0;
		foreach ( $order_ids as $order_id ) {
			if ( OrderActions::QUEUE_SCHEDULED === $this->actions->schedule_send_to_soocool( (int) $order_id ) ) {
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
							_n( 'Selecteer maximaal 50 orders voor één SooCool bulkverzending. Je hebt %d order geselecteerd.', 'Selecteer maximaal 50 orders voor één SooCool bulkverzending. Je hebt %d orders geselecteerd.', $total, 'soocool-for-woocommerce' ),
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
				? __( 'Er zijn geen nieuwe SooCool achtergrondsynchronisaties ingepland. De geselecteerde orders staan al ingepland.', 'soocool-for-woocommerce' )
				: sprintf(
					/* translators: %d: number of orders queued for background sync to SooCool. */
					_n( '%d nieuwe order is ingepland voor SooCool-synchronisatie op de achtergrond. Orders die al ingepland waren, zijn overgeslagen.', '%d nieuwe orders zijn ingepland voor SooCool-synchronisatie op de achtergrond. Orders die al ingepland waren, zijn overgeslagen.', $queued, 'soocool-for-woocommerce' ),
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
			_n( '%d order is naar SooCool verstuurd.', '%d orders zijn naar SooCool verstuurd.', $synced, 'soocool-for-woocommerce' ),
			$synced
		);

		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders that could not be sent. */
				_n( '%d order kon niet worden verstuurd; controleer de SooCool-notities bij deze order.', '%d orders konden niet worden verstuurd; controleer de SooCool-notities bij deze orders.', $failed, 'soocool-for-woocommerce' ),
				$failed
			);
		}

		if ( $remaining > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders still waiting to be sent. */
				_n( '%d order staat nog open; voer de bulkactie opnieuw uit om door te gaan.', '%d orders staan nog open; voer de bulkactie opnieuw uit om door te gaan.', $remaining, 'soocool-for-woocommerce' ),
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
