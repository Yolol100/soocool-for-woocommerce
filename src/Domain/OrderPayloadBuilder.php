<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the SooCool create-order payload (SooCool API 1.2.1).
 *
 * The payload uses root-level goods and task-level references to those goods.
 * Dimensions and weight prefer product data when available and safely fall back
 * to the global SooCool package settings, so the admin settings remain active.
 */
final class OrderPayloadBuilder {

	public function __construct( private readonly TaskFactory $task_factory, private readonly OptionRepository $options, private readonly OrderPayloadValidator $validator ) {}

	/** @return array<string, mixed> */
	public function build( WC_Order $order ): array {
		$settings = $this->options->all();

		$goods    = $this->build_goods( $order, $settings );
		$good_ids = array_map( static fn ( array $good ): int => (int) $good['goodId'], $goods );

		$payload = array(
			'orderReference' => $this->order_reference( $order, (string) $settings['order_reference_prefix'] ),
			'tasks'          => $this->task_factory->create_tasks( $order, $good_ids ),
			'goods'          => $goods,
		);

		$webhook_url = $this->webhook_url_for_order( $order, (string) $payload['orderReference'] );
		if ( '' !== $webhook_url ) {
			$webhook_block = array(
				'webhookUrl'     => esc_url_raw( $webhook_url ),
				'webhookUpdates' => array( 'task_state', 'planned_time' ),
			);
			// SooCool validates webhookUpdates as an enum array inside the webhook object.
			$payload['webhook'] = $webhook_block;
		}

		$this->validator->validate_contract_minimums( $payload );

		return $payload;
	}

	private function webhook_url_for_order( WC_Order $order, string $order_reference ): string {
		$webhook_url = $this->options->effective_webhook_url();
		if ( '' === $webhook_url ) {
			return '';
		}

		return esc_url_raw(
			add_query_arg(
				array(
					'wc_order_id'     => (int) $order->get_id(),
					'order_reference' => sanitize_text_field( $order_reference ),
				),
				$webhook_url
			)
		);
	}

	private function order_reference( WC_Order $order, string $prefix ): string {
		$reference = sanitize_text_field( (string) $order->get_order_number() );
		$prefix    = sanitize_key( $prefix );

		return '' !== $prefix ? $prefix . '-' . $reference : $reference;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<int, array<string, mixed>>
	 */
	private function build_goods( WC_Order $order, array $settings ): array {
		$fallback_description = (string) $settings['goods_description_fallback'];
		$packaging_type       = sanitize_key( (string) ( $settings['packaging_type'] ?? 'box' ) );
		$packaging_type       = '' !== $packaging_type ? $packaging_type : 'box';
		$regime               = sanitize_key( (string) ( $settings['temperature_regime'] ?? 'cooled' ) );
		$regime               = in_array( $regime, array( 'cooled', 'frozen', 'ambient' ), true ) ? $regime : 'cooled';
		$fallback_dimensions  = $this->dimensions_from_settings( $settings );
		$fallback_weight      = $this->positive_int_setting( $settings['package_weight'] ?? 1600, 1600 );

		$goods        = array();
		$requested_id = 0; // Decrements to negative requested IDs (-1, -2, ...).

		foreach ( $order->get_items() as $item ) {
			$quantity   = max( 1, (int) $item->get_quantity() );
			$contents   = sanitize_text_field( wp_strip_all_tags( $item->get_name() ) );
			$contents   = '' !== $contents ? $contents : sanitize_text_field( $fallback_description );
			$product    = ( is_object( $item ) && method_exists( $item, 'get_product' ) ) ? $item->get_product() : null;
			$dimensions = $this->product_dimensions( $product ) ?? $fallback_dimensions;
			$weight     = $this->product_weight( $product ) ?? $fallback_weight;

			for ( $i = 0; $i < $quantity; $i++ ) {
				--$requested_id;

				$good = array(
					'goodId'                => $requested_id,
					'packagingType'         => $packaging_type,
					'dimensions'            => $dimensions,
					'weight'                => $weight,
					'contents'              => $contents,
					'transportRequirements' => array( $regime ),
				);

				$barcode = $this->barcode_for_item( $item );
				if ( '' !== $barcode ) {
					$good['barcode'] = $barcode;
				}

				/**
				 * Allows projects to adjust each SooCool good before it is sent.
				 *
				 * Required SooCool fields goodId, packagingType and contents must remain present.
				 * Dimensions, weight and transportRequirements are provided by default from
				 * product data or global package settings but can be adjusted per project.
				 *
				 * @param array<string, mixed> $good
				 * @param mixed                $item WooCommerce order item.
				 * @param WC_Order             $order
				 */
				$good = apply_filters( 'soocool_order_good_payload', $good, $item, $order );

				$goods[] = $good;
			}
		}

		if ( $goods ) {
			return $goods;
		}

		return array(
			array(
				'goodId'                => -1,
				'packagingType'         => $packaging_type,
				'dimensions'            => $fallback_dimensions,
				'weight'                => $fallback_weight,
				'contents'              => sanitize_text_field( trim( $fallback_description . ' ' . $order->get_order_number() ) ),
				'transportRequirements' => array( $regime ),
			),
		);
	}

	/**
	 * @param mixed $product
	 * @return array{width:int, depth:int, height:int}|null
	 */
	private function product_dimensions( mixed $product ): ?array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_width' ) || ! function_exists( 'wc_get_dimension' ) ) {
			return null;
		}

		$width  = (float) wc_get_dimension( (float) $product->get_width(), 'cm' );
		$length = (float) wc_get_dimension( (float) $product->get_length(), 'cm' );
		$height = (float) wc_get_dimension( (float) $product->get_height(), 'cm' );
		if ( $width <= 0 || $length <= 0 || $height <= 0 ) {
			return null;
		}

		return array(
			'width'  => max( 1, (int) round( $width ) ),
			'depth'  => max( 1, (int) round( $length ) ),
			'height' => max( 1, (int) round( $height ) ),
		);
	}

	private function product_weight( mixed $product ): ?int {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_weight' ) || ! function_exists( 'wc_get_weight' ) ) {
			return null;
		}

		$raw = (string) $product->get_weight();
		if ( '' === trim( $raw ) ) {
			return null;
		}

		$grams = (float) wc_get_weight( (float) $raw, 'g' );
		return $grams > 0 ? max( 1, (int) round( $grams ) ) : null;
	}

	private function barcode_for_item( mixed $item ): string {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
			return '';
		}

		$product = $item->get_product();
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return '';
		}

		$sku = sanitize_text_field( (string) $product->get_sku() );

		/**
		 * Controls whether a product SKU should be sent as SooCool barcode.
		 *
		 * Disabled by default because SooCool validates goods/0/barcode with a oneOf rule.
		 *
		 * @param bool   $send_barcode
		 * @param string $sku
		 * @param mixed  $item WooCommerce order item.
		 */
		$send_barcode = (bool) apply_filters( 'soocool_send_sku_as_barcode', false, $sku, $item );

		return $send_barcode ? $sku : '';
	}

	/** @param array<string, mixed> $settings @return array<string, int> */
	private function dimensions_from_settings( array $settings ): array {
		return array(
			'width'  => $this->positive_int_setting( $settings['package_width'] ?? 60, 60 ),
			'depth'  => $this->positive_int_setting( $settings['package_depth'] ?? 40, 40 ),
			'height' => $this->positive_int_setting( $settings['package_height'] ?? 11, 11 ),
		);
	}

	private function positive_int_setting( mixed $value, int $fallback ): int {
		$int = $this->positive_int_or_null( $value );
		return null !== $int ? $int : $fallback;
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
		if ( is_float( $value ) && $value > 0 && floor( $value ) === $value ) {
			return (int) $value;
		}
		return null;
	}

}
