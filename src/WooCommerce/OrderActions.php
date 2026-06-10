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

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderMeta $meta,
		private readonly OptionRepository $options
	) {}

	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_soocool_send_to_soocool', array( $this, 'send_to_soocool' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_order_action( array $actions ): array {
		$actions['soocool_send_to_soocool'] = __( 'Send order to SooCool', 'soocool-for-woocommerce' );
		return $actions;
	}

	public function send_to_soocool( WC_Order $order, bool $force = false ): void {
		$settings = $this->options->all();
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			$order->add_order_note( __( 'SooCool sync skipped because this order is already synced.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->meta->save_pending( $order );
			$payload = $this->builder->build( $order );

			$existing_order = $this->find_existing_soocool_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$this->meta->save_success( $order, $existing_order );
				$order->add_order_note( __( 'Existing SooCool order found by order reference. WooCommerce order linked without creating a duplicate SooCool order.', 'soocool-for-woocommerce' ) );
				return;
			}

			$response         = $this->client->create_order( $payload );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool did not return a valid order ID.', 'soocool-for-woocommerce' ) );
			}
			$this->meta->save_success( $order, $body );
			$order->add_order_note( __( 'Order sent to SooCool.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$this->meta->save_error( $order, $exception->getMessage() );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool validation failed: %s', 'soocool-for-woocommerce' ), $exception->getMessage() ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_api_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}


	/** @return array<string, mixed> */
	private function find_existing_soocool_order( string $order_reference ): array {
		$response = $this->client->search_order_by_reference( $order_reference );
		$body     = $response->body();

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

		echo '<p><strong>' . esc_html__( 'SooCool status:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( $status ?: __( 'Not synced', 'soocool-for-woocommerce' ) ) . '</p>';
		if ( '' !== $soocool_order_id ) {
			echo '<p><strong>' . esc_html__( 'SooCool order ID:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( (string) $soocool_order_id ) . '</p>';
		}
		if ( '' !== $error ) {
			echo '<p><strong>' . esc_html__( 'Last error:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		if ( '' !== $soocool_order_id ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . absint( $order->get_id() ) ),
				'soocool_download_label_' . absint( $order->get_id() )
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Download SooCool label', 'soocool-for-woocommerce' ) . '</a></p>';
		}
	}
}
