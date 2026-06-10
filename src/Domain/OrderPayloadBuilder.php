<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderPayloadBuilder {

	public function __construct( private readonly TaskFactory $task_factory, private readonly OptionRepository $options ) {}

	/** @return array<string, mixed> */
	public function build( WC_Order $order ): array {
		$settings = $this->options->all();
		$payload  = array(
			'orderReference' => $this->order_reference( $order, (string) $settings['order_reference_prefix'] ),
			'tasks'          => $this->task_factory->create_tasks( $order ),
			'goods'          => $this->build_goods( $order, (string) $settings['temperature_regime'], (string) $settings['goods_description_fallback'] ),
		);

		if ( ! empty( $settings['webhook_url'] ) ) {
			$payload['webhook'] = array( 'webhookUrl' => esc_url_raw( (string) $settings['webhook_url'] ) );
		}

		$this->validate_contract_minimums( $payload );

		return $payload;
	}

	/** @param array<string, mixed> $payload */
	private function validate_contract_minimums( array $payload ): void {
		if ( '' === trim( (string) ( $payload['orderReference'] ?? '' ) ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool order reference is missing.', 'soocool-for-woocommerce' ) );
		}

		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) || array() === $tasks ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload must contain at least one task.', 'soocool-for-woocommerce' ) );
		}

		$delivery_dates = array();
		$pickup_dates   = array();
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$type = (string) ( $task['type'] ?? '' );
			$date = (string) ( $task['date'] ?? '' );
			if ( 'delivery' === $type ) {
				$delivery_dates[] = $date;
			}
			if ( 'pickup' === $type ) {
				$pickup_dates[] = $date;
			}
		}

		if ( array() === $delivery_dates ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload must contain at least one delivery task.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $pickup_dates as $pickup_date ) {
			foreach ( $delivery_dates as $delivery_date ) {
				if ( '' !== $pickup_date && '' !== $delivery_date && $delivery_date <= $pickup_date ) {
					throw new PayloadValidationException( esc_html__( 'SooCool delivery date must be later than the pickup date when a pickup task is used.', 'soocool-for-woocommerce' ) );
				}
			}
		}

		$goods = $payload['goods'] ?? array();
		if ( ! is_array( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload must contain at least one good.', 'soocool-for-woocommerce' ) );
		}
	}

	private function order_reference( WC_Order $order, string $prefix ): string {
		$reference = sanitize_text_field( (string) $order->get_order_number() );
		$prefix    = sanitize_key( $prefix );

		return '' !== $prefix ? $prefix . '-' . $reference : $reference;
	}

	/** @return array<int, array<string, mixed>> */
	private function build_goods( WC_Order $order, string $temperature_regime, string $fallback_description ): array {
		$goods = array();
		foreach ( $order->get_items() as $item ) {
			$quantity    = max( 1, (int) $item->get_quantity() );
			$description = wp_strip_all_tags( $item->get_name() );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$goods[] = array_filter(
					array(
						'description'       => sanitize_text_field( '' !== $description ? $description : $fallback_description ),
						'barcode'           => '',
						'temperatureRegime' => sanitize_key( $temperature_regime ),
					),
					static fn ( $value ): bool => null !== $value
				);
			}
		}

		return $goods ? $goods : array(
			array(
				'description'       => sanitize_text_field( trim( $fallback_description . ' ' . $order->get_order_number() ) ),
				'barcode'           => '',
				'temperatureRegime' => sanitize_key( $temperature_regime ),
			),
		);
	}
}
