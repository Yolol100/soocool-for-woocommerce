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
 * WooCommerce product weights are combined into configured transport boxes.
 * Every started box capacity becomes one root-level good referenced by each task.
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

		$order_id = (int) $order->get_id();
		$webhook_url = $this->order_specific_webhook_url( $webhook_url, $order_id );

		return esc_url_raw(
			add_query_arg(
				array(
					'wc_order_id'     => $order_id,
					'order_reference' => sanitize_text_field( $order_reference ),
				),
				$webhook_url
			)
		);
	}

	private function order_specific_webhook_url( string $webhook_url, int $order_id ): string {
		if ( 0 >= $order_id || ! str_contains( $webhook_url, '/soocool/v1/webhook' ) ) {
			return $webhook_url;
		}

		$query = '';
		$hash = '';
		$base = $webhook_url;
		$hash_position = strpos( $base, '#' );
		if ( false !== $hash_position ) {
			$hash = substr( $base, $hash_position );
			$base = substr( $base, 0, $hash_position );
		}

		$query_position = strpos( $base, '?' );
		if ( false !== $query_position ) {
			$query = substr( $base, $query_position );
			$base = substr( $base, 0, $query_position );
		}

		$base = untrailingslashit( $base );
		if ( ! str_ends_with( $base, '/soocool/v1/webhook' ) ) {
			return $webhook_url;
		}

		return $base . '/' . rawurlencode( (string) $order_id ) . $query . $hash;
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
		$fallback_description = sanitize_text_field( (string) $settings['goods_description_fallback'] );
		$packaging_type       = sanitize_key( (string) ( $settings['packaging_type'] ?? 'box' ) );
		$packaging_type       = '' !== $packaging_type ? $packaging_type : 'box';
		$regime               = sanitize_key( (string) ( $settings['temperature_regime'] ?? 'cooled' ) );
		$regime               = in_array( $regime, array( 'cooled', 'frozen', 'ambient' ), true ) ? $regime : 'cooled';
		$dimensions           = $this->dimensions_from_settings( $settings );
		$box_capacity         = $this->positive_int_setting( $settings['package_weight'] ?? 10000, 10000 );
		$box_capacity         = $this->positive_int_setting(
			apply_filters( 'soocool_box_capacity_grams', $box_capacity, $order ),
			10000
		);

		$total_weight        = 0;
		$content_parts       = array();
		$representative_item = null;
		$single_unit_item    = null;
		$total_units         = 0;

		foreach ( $order->get_items() as $item ) {
			$quantity = max( 0, (int) $item->get_quantity() );
			if ( 0 === $quantity ) {
				continue;
			}

			$product = ( is_object( $item ) && method_exists( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( is_object( $product ) && method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
				continue;
			}

			$unit_weight = $this->product_weight( $product );
			if ( null === $unit_weight ) {
				$product_name = sanitize_text_field( wp_strip_all_tags( (string) $item->get_name() ) );
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: WooCommerce product name. */
						esc_html__( 'Product “%s” heeft geen geldig gewicht. Vul het productgewicht in WooCommerce in voordat de order naar SooCool wordt gestuurd.', 'soocool-for-woocommerce' ),
						esc_html( $product_name )
					)
				);
			}

			$total_weight += $unit_weight * $quantity;
			$total_units  += $quantity;
			$representative_item ??= $item;
			if ( 1 === $quantity && null === $single_unit_item ) {
				$single_unit_item = $item;
			} else {
				$single_unit_item = false;
			}

			$contents = sanitize_text_field( wp_strip_all_tags( (string) $item->get_name() ) );
			if ( '' !== $contents ) {
				$content_parts[] = sprintf( '%d× %s', $quantity, $contents );
			}
		}

		if ( 0 >= $total_weight || null === $representative_item ) {
			throw new PayloadValidationException( esc_html__( 'De order bevat geen verzendbare producten met een geldig gewicht voor de SooCool-doosberekening.', 'soocool-for-woocommerce' ) );
		}

		$box_count        = (int) ceil( $total_weight / $box_capacity );
		$remaining_weight = $total_weight;
		$goods           = array();
		$contents        = $this->box_contents( $content_parts, $fallback_description, $order );
		$barcode         = 1 === $total_units && is_object( $single_unit_item ) ? $this->barcode_for_item( $single_unit_item ) : '';

		for ( $box_number = 1; $box_number <= $box_count; $box_number++ ) {
			$box_weight = min( $box_capacity, $remaining_weight );
			$remaining_weight -= $box_weight;

			$good = array(
				'goodId'                => -$box_number,
				'packagingType'         => $packaging_type,
				'dimensions'            => $dimensions,
				'weight'                => $box_weight,
				'contents'              => 1 < $box_count ? sprintf( '%s - doos %d/%d', $contents, $box_number, $box_count ) : $contents,
				'transportRequirements' => array( $regime ),
			);

			if ( '' !== $barcode ) {
				$good['barcode'] = $barcode;
			}

			/**
			 * Allows projects to adjust each calculated SooCool box before it is sent.
			 *
			 * The second argument remains a WooCommerce order item for backward compatibility.
			 * Box number and total box count are available as the fourth and fifth arguments.
			 *
			 * @param array<string, mixed> $good
			 * @param mixed                $item Representative WooCommerce order item.
			 * @param WC_Order             $order
			 * @param int                  $box_number
			 * @param int                  $box_count
			 */
			$good = apply_filters( 'soocool_order_good_payload', $good, $representative_item, $order, $box_number, $box_count );

			$goods[] = $good;
		}

		return $goods;
	}

	/** @param array<int, string> $content_parts */
	private function box_contents( array $content_parts, string $fallback_description, WC_Order $order ): string {
		$contents = sanitize_text_field( implode( ', ', $content_parts ) );
		if ( '' === $contents ) {
			$contents = sanitize_text_field( trim( $fallback_description . ' ' . $order->get_order_number() ) );
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $contents, 0, 220 );
		}

		return substr( $contents, 0, 220 );
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
