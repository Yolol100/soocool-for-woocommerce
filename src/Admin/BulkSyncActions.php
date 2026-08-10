<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\WooCommerce\OrderActions;

defined( 'ABSPATH' ) || exit;

/**
 * Queues bounded bulk synchronization for HPOS and legacy order lists.
 */
final class BulkSyncActions {

	private const ACTION            = 'soocool_send_to_soocool';
	private const MAX_BULK_ORDERS   = 50;
	private const MODE_PARAM        = 'soocool_bulk_mode';
	private const QUEUED_PARAM      = 'soocool_bulk_queued';
	private const SYNCED_PARAM      = 'soocool_bulk_synced';
	private const FAILED_PARAM      = 'soocool_bulk_failed';
	private const DUPLICATES_PARAM  = 'soocool_bulk_duplicates';
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

		$order_ids = NumericIdentifier::positive_list( $ids );
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

		$queued     = 0;
		$duplicates = 0;
		$failed     = 0;
		foreach ( $order_ids as $order_id ) {
			$result = $this->actions->schedule_send_to_soocool( (int) $order_id );
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

		return add_query_arg(
			array(
				self::MODE_PARAM       => 'scheduled',
				self::QUEUED_PARAM     => $queued,
				self::DUPLICATES_PARAM => $duplicates,
				self::FAILED_PARAM     => $failed,
			),
			$redirect_to
		);
	}

	public function render_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$mode = $this->query_key( self::MODE_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.

		if ( 'error' === $mode ) {
			$error = $this->query_key( self::ERROR_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.
			$total = $this->query_absint( self::QUEUED_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
			$queued     = $this->query_absint( self::QUEUED_PARAM );
			$duplicates = $this->query_absint( self::DUPLICATES_PARAM );
			$failed     = $this->query_absint( self::FAILED_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sprintf(
				/* translators: 1: queued orders, 2: already synchronized or queued orders, 3: scheduling failures. */
				__( '%1$d nieuwe SooCool-synchronisaties ingepland. %2$d waren al gesynchroniseerd of stonden al ingepland. %3$d konden niet worden ingepland.', 'soocool-for-woocommerce' ),
				$queued,
				$duplicates,
				$failed
			);

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $failed > 0 ? 'warning' : 'info' ),
				esc_html( $message )
			);
			return;
		}

		if ( 'inline' !== $mode ) {
			return;
		}

		$synced    = $this->query_absint( self::SYNCED_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.
		$failed    = $this->query_absint( self::FAILED_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.
		$remaining = $this->query_absint( self::REMAINING_PARAM ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.

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
	private function query_key( string $key ): string {
		$value = $this->query_scalar( $key );
		return '' === $value ? '' : sanitize_key( $value );
	}

	private function query_absint( string $key ): int {
		$value = $this->query_scalar( $key );
		return '' === $value ? 0 : absint( $value );
	}

	private function query_scalar( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.
			return '';
		}

		return wp_unslash( (string) $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-redirect notice.
	}

}
