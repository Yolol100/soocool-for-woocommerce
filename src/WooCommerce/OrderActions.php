<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\PayloadValidationException;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderActions {

	private const SYNC_LOCK_TTL_SECONDS = 120;

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderMeta $meta,
		private readonly OptionRepository $options
	) {}

	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_soocool_send_to_soocool', array( $this, 'send_to_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_update_at_soocool', array( $this, 'update_at_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_refresh_from_soocool', array( $this, 'refresh_from_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_cancel_at_soocool', array( $this, 'cancel_at_soocool' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_footer', array( $this, 'render_order_action_confirm_script' ) );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_order_action( array $actions ): array {
		$actions['soocool_send_to_soocool'] = __( 'SooCool: create/send order', 'soocool-for-woocommerce' );
		$actions['soocool_refresh_from_soocool'] = __( 'SooCool: refresh status', 'soocool-for-woocommerce' );
		$actions['soocool_update_at_soocool'] = __( 'SooCool: update existing order', 'soocool-for-woocommerce' );
		$actions['soocool_cancel_at_soocool'] = __( 'SooCool: cancel order', 'soocool-for-woocommerce' );
		return $actions;
	}

	public function send_to_soocool( WC_Order $order, bool $force = false ): void {
		$settings = $this->options->all();
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			$order->add_order_note( __( 'SooCool sync skipped because this order is already synced.', 'soocool-for-woocommerce' ) );
			return;
		}

		$order_id = (int) $order->get_id();
		if ( ! $this->acquire_sync_lock( $order_id ) ) {
			$order->add_order_note( __( 'SooCool sync skipped because another sync is already running for this order.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->meta->save_pending( $order );
			$payload = $this->builder->build( $order );

			$existing_order = $this->find_existing_soocool_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$this->meta->save_success( $order, $existing_order, (string) $payload['orderReference'] );
				$order->add_order_note( __( 'Existing SooCool order found by order reference. WooCommerce order linked without creating a duplicate SooCool order.', 'soocool-for-woocommerce' ) );
				return;
			}

			$response         = $this->client->create_order( $payload );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool did not return a valid order ID.', 'soocool-for-woocommerce' ) );
			}
			$this->meta->save_success( $order, $body, (string) $payload['orderReference'] );
			$order->add_order_note( __( 'Result: order sent to SooCool. Next step: download the label or wait for the track & trace webhook.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool validation failed: %s', 'soocool-for-woocommerce' ), $message ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_api_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool sync failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} finally {
			$this->release_sync_lock( $order_id );
		}
	}

	public function update_at_soocool( WC_Order $order ): void {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			$order->add_order_note( __( 'SooCool update skipped because this WooCommerce order is not linked to a SooCool order ID yet.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$payload = $this->builder->build( $order );
			$this->client->update_order( $soocool_order_id, $payload );
			$this->meta->save_updated( $order );
			$order->add_order_note( __( 'Result: existing SooCool order updated. Next step: refresh the status or check the SooCool dashboard if fulfilment already started.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool update validation failed: %s', 'soocool-for-woocommerce' ), $message ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_update_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool update failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}

	public function refresh_from_soocool( WC_Order $order ): void {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			$order->add_order_note( __( 'SooCool refresh skipped because this WooCommerce order is not linked to a SooCool order ID.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$response = $this->client->get_order( $soocool_order_id );
			$body     = is_array( $response->body() ) ? $response->body() : array();
			if ( array() === $body ) {
				throw new ApiException( esc_html__( 'SooCool did not return a valid order response.', 'soocool-for-woocommerce' ) );
			}

			if ( '' === $this->meta->extract_order_id( $body ) ) {
				$body['orderId'] = $soocool_order_id;
			}

			$this->meta->save_success( $order, $body, $this->meta->get_our_reference( $order ) );
			$remote_data = $this->remote_status_data( $body );
			$changed     = $this->has_remote_status_data( $remote_data ) ? $this->meta->save_webhook_update( $order, $remote_data, false ) : false;

			$order->add_order_note( $changed ? __( 'Result: SooCool status refreshed and local status/tracking data updated.', 'soocool-for-woocommerce' ) : __( 'Result: SooCool status refreshed. No status or tracking changes were returned.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_refresh_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool refresh failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}

	public function cancel_at_soocool( WC_Order $order ): void {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			$order->add_order_note( __( 'SooCool cancel skipped because this WooCommerce order is not linked to a SooCool order ID.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->client->cancel_order( $soocool_order_id );
			$this->meta->save_cancelled( $order );
			$order->add_order_note( __( 'Result: SooCool order cancelled. Next step: verify fulfilment status in SooCool before refunding or changing the WooCommerce order status.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_cancel_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool cancel failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}

	private function acquire_sync_lock( int $order_id ): bool {
		$key     = $this->sync_lock_key( $order_id );
		$expires = (int) get_option( $key, 0 );
		$now     = time();

		if ( $expires > $now ) {
			return false;
		}

		if ( $expires > 0 ) {
			delete_option( $key );
		}

		return add_option( $key, (string) ( $now + self::SYNC_LOCK_TTL_SECONDS ), '', false );
	}

	private function release_sync_lock( int $order_id ): void {
		delete_option( $this->sync_lock_key( $order_id ) );
	}

	private function sync_lock_key( int $order_id ): string {
		return 'soocool_sync_lock_' . absint( $order_id );
	}

	/** @return array<string, mixed> */
	private function find_existing_soocool_order( string $order_reference ): array {
		try {
			$response = $this->client->search_order_by_reference( $order_reference );
		} catch ( ApiException $exception ) {
			// A 404 on the search endpoint means no order with this reference exists yet.
			if ( 404 === $exception->status_code() ) {
				return array();
			}
			throw $exception;
		}
		$body = $response->body();

		if ( ! is_array( $body ) ) {
			return array();
		}

		$candidate = $body;
		if ( array_is_list( $body ) ) {
			$candidate = is_array( $body[0] ?? null ) ? $body[0] : array();
		}

		if ( ! is_array( $candidate ) || '' === $this->meta->extract_order_id( $candidate ) ) {
			return array();
		}

		return $candidate;
	}

	private function public_api_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();

		if ( '' === $message ) {
			return __( 'SooCool sync failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool sync failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	private function public_update_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();
		if ( '' === $message ) {
			return __( 'SooCool update failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool update failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	private function public_refresh_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();
		if ( '' === $message ) {
			return __( 'SooCool refresh failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool refresh failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	/** @param array<string, mixed> $body @return array<string, string> */
	private function remote_status_data( array $body ): array {
		return array(
			'status'        => $this->normalize_remote_status( $this->extract_text( $body, array( 'status', 'orderStatus', 'state', 'taskState' ) ) ),
			'tracking_code' => $this->extract_text( $body, array( 'trackingCode', 'trackAndTrace', 'trackingNumber', 'tracking', 'code' ) ),
			'tracking_url'  => $this->extract_url( $body, array( 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl', 'url', 'link' ) ),
		);
	}

	/** @param array<string, string> $data */
	private function has_remote_status_data( array $data ): bool {
		return '' !== ( $data['status'] ?? '' ) || '' !== ( $data['tracking_code'] ?? '' ) || '' !== ( $data['tracking_url'] ?? '' );
	}

	private function normalize_remote_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return '';
		}

		return str_starts_with( $status, 'soocool_' ) ? $status : 'soocool_' . $status;
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_text( array $payload, array $keys ): string {
		foreach ( $this->payload_candidates( $payload ) as $candidate ) {
			foreach ( $keys as $key ) {
				if ( isset( $candidate[ $key ] ) && ! is_array( $candidate[ $key ] ) && ! is_object( $candidate[ $key ] ) ) {
					$value = trim( sanitize_text_field( (string) $candidate[ $key ] ) );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_url( array $payload, array $keys ): string {
		foreach ( $this->payload_candidates( $payload ) as $candidate ) {
			foreach ( $keys as $key ) {
				if ( isset( $candidate[ $key ] ) && ! is_array( $candidate[ $key ] ) && ! is_object( $candidate[ $key ] ) ) {
					$value = esc_url_raw( (string) $candidate[ $key ] );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function payload_candidates( array $payload ): array {
		$candidates = array();
		$this->collect_payload_candidates( $payload, $candidates );

		return $candidates;
	}

	/**
	 * Recursively collect nested response objects because SooCool returns taskState
	 * and trackAndTraceLink inside task objects on get/refresh responses.
	 *
	 * @param array<string, mixed>              $payload
	 * @param array<int, array<string, mixed>> $candidates
	 */
	private function collect_payload_candidates( array $payload, array &$candidates ): void {
		$candidates[] = $payload;

		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_payload_candidates( $value, $candidates );
			}
		}
	}

	private function public_cancel_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();
		if ( '' === $message ) {
			return __( 'SooCool cancel failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool cancel failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	public function add_meta_box(): void {
		add_meta_box(
			'soocool-order-status',
			__( 'SooCool', 'soocool-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	public function render_meta_box( object $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		$status           = (string) $order->get_meta( OrderMeta::SYNC_STATUS, true );
		$error            = (string) $order->get_meta( OrderMeta::LAST_ERROR, true );
		$tracking_code    = (string) $order->get_meta( OrderMeta::TRACKING_CODE, true );
		$tracking_url     = (string) $order->get_meta( OrderMeta::TRACKING_URL, true );
		$good_ids         = $this->meta->get_good_ids( $order );

		echo '<div class="soocool-order-card">';
		echo '<div class="soocool-order-card__header"><span class="soocool-order-badge ' . esc_attr( $this->status_badge_class( $status ) ) . '">' . esc_html( $this->status_label( $status ) ) . '</span></div>';
		echo '<dl class="soocool-order-meta-list">';
		$this->render_meta_row( __( 'SooCool order ID', 'soocool-for-woocommerce' ), '' !== $soocool_order_id ? $soocool_order_id : __( 'Not linked yet', 'soocool-for-woocommerce' ) );
		if ( '' !== $tracking_code ) {
			$this->render_meta_row( __( 'Track & trace code', 'soocool-for-woocommerce' ), $tracking_code );
		}
		if ( array() !== $good_ids ) {
			$this->render_meta_row( __( 'SooCool good IDs', 'soocool-for-woocommerce' ), implode( ', ', $good_ids ) );
		}
		echo '</dl>';

		if ( '' !== $tracking_url ) {
			echo '<p class="soocool-order-actions"><a class="button" href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open track & trace', 'soocool-for-woocommerce' ) . '</a></p>';
		}

		if ( '' !== $error ) {
			echo '<div class="soocool-order-alert is-error"><strong>' . esc_html__( 'Last error', 'soocool-for-woocommerce' ) . '</strong><br />' . esc_html( $error ) . '</div>';
		}

		if ( '' !== $soocool_order_id ) {
			echo '<div class="soocool-order-action-group"><strong>' . esc_html__( 'Labels', 'soocool-for-woocommerce' ) . '</strong>';
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . absint( $order->get_id() ) ),
				'soocool_download_label_' . absint( $order->get_id() )
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Download order label', 'soocool-for-woocommerce' ) . '</a></p>';

			if ( array() !== $good_ids ) {
				$good_label_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=soocool_download_label&good_ids=' . rawurlencode( implode( ',', $good_ids ) ) ),
					'soocool_download_good_labels_bulk'
				);
				echo '<p><a class="button" href="' . esc_url( $good_label_url ) . '">' . esc_html__( 'Download good labels', 'soocool-for-woocommerce' ) . '</a></p>';
			}
			echo '</div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Use the order action “SooCool: create/send order” before downloading labels.', 'soocool-for-woocommerce' ) . '</p>';
		}
		echo '</div>';
	}

	private function render_meta_row( string $label, string $value ): void {
		echo '<div class="soocool-order-meta-row"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}

	private function status_label( string $status ): string {
		$status = sanitize_key( $status );
		return match ( $status ) {
			'pending' => __( 'Pending', 'soocool-for-woocommerce' ),
			'synced' => __( 'Synced', 'soocool-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'soocool-for-woocommerce' ),
			'failed' => __( 'Failed', 'soocool-for-woocommerce' ),
			'' => __( 'Not synced', 'soocool-for-woocommerce' ),
			default => preg_replace( '/^soocool_/', '', str_replace( '_', ' ', $status ) ) ?: __( 'Unknown', 'soocool-for-woocommerce' ),
		};
	}

	private function status_badge_class( string $status ): string {
		$status = sanitize_key( $status );
		if ( in_array( $status, array( 'synced', 'soocool_delivered', 'soocool_completed' ), true ) ) {
			return 'is-success';
		}
		if ( in_array( $status, array( 'failed', 'cancelled', 'soocool_cancelled' ), true ) ) {
			return 'is-error';
		}
		if ( 'pending' === $status ) {
			return 'is-warning';
		}
		return 'is-neutral';
	}

	public function render_order_action_confirm_script(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( wc_get_page_screen_id( 'shop-order' ), 'shop_order' ), true ) ) {
			return;
		}
		?>
		<script>
		(function(){
			var select = document.querySelector('select[name="wc_order_action"]');
			if (!select) { return; }
			var button = select.closest('.inside') ? select.closest('.inside').querySelector('button') : null;
			if (!button) { return; }
			button.addEventListener('click', function(event){
				var value = select.value;
				var message = '';
				if ('soocool_cancel_at_soocool' === value) {
					message = <?php echo wp_json_encode( __( 'This will cancel the linked order at SooCool. Continue only if fulfilment should be stopped.', 'soocool-for-woocommerce' ) ); ?>;
				} else if ('soocool_update_at_soocool' === value) {
					message = <?php echo wp_json_encode( __( 'This will update the existing SooCool order with the current WooCommerce order data. Continue only if the fulfilment data should change.', 'soocool-for-woocommerce' ) ); ?>;
				}
				if (message && ! window.confirm(message)) {
					event.preventDefault();
					event.stopPropagation();
				}
			});
		})();
		</script>
		<?php
	}
}
