<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Builds SooCool API 1.2.1 create-order payloads from WooCommerce orders.
 */
final class OrderPayloadBuilder {

	private const DEFAULT_MAX_GOODS_PER_ORDER = 1000;

	public function __construct( private readonly TaskFactory $task_factory, private readonly OptionRepository $options, private readonly OrderPayloadValidator $validator ) {}

	/** @return array<string, mixed> */
	public function build( WC_Order $order ): array {
		$settings = $this->options->all();

		$goods       = $this->build_goods( $order, $settings );
		$defined_ids = $this->validator->validate_goods_manifest( $goods );
		$good_ids    = array_keys( $defined_ids );

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
		$missing_product_weight = $this->positive_int_setting( $settings['missing_product_weight'] ?? 1000, 1000 );

		$total_weight        = 0.0;
		$content_parts       = array();
		$representative_item = null;
		$single_unit_item    = null;
		$total_units         = 0;

		foreach ( $order->get_items() as $item ) {
			$quantity = max( 0, (int) $item->get_quantity() );
			if ( 0 < $quantity && method_exists( $order, 'get_qty_refunded_for_item' ) && is_object( $item ) && method_exists( $item, 'get_id' ) ) {
				$item_id = (int) $item->get_id();
				if ( 0 < $item_id ) {
					$refunded_quantity = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
					if ( is_finite( $refunded_quantity ) && 0 < $refunded_quantity ) {
						$quantity = max( 0, $quantity - min( $quantity, (int) round( $refunded_quantity ) ) );
					}
				}
			}

			if ( 0 === $quantity ) {
				continue;
			}

			$product = ( is_object( $item ) && method_exists( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( is_object( $product ) && method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
				continue;
			}

			$product_name = sanitize_text_field( wp_strip_all_tags( (string) $item->get_name() ) );
			$unit_weight  = $this->product_weight( $product, $product_name, $missing_product_weight, $item, $order );

			$line_weight = $unit_weight * $quantity;
			if ( ! is_finite( $line_weight ) || $line_weight <= 0 || $line_weight > PHP_INT_MAX - $total_weight ) {
				throw new PayloadValidationException( __( 'Het berekende SooCool-ordergewicht is te groot om veilig te verwerken.', 'soocool-for-woocommerce' ) );
			}

			$total_weight += $line_weight;
			$total_units   = $quantity > PHP_INT_MAX - $total_units ? PHP_INT_MAX : $total_units + $quantity;
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
			throw new PayloadValidationException( __( 'De order bevat geen verzendbare fysieke producten voor de SooCool-doosberekening.', 'soocool-for-woocommerce' ) );
		}

		$total_weight = round( $total_weight, 6 );
		if ( ! is_finite( $total_weight ) || $total_weight > PHP_INT_MAX ) {
			throw new PayloadValidationException( __( 'Het berekende SooCool-ordergewicht is te groot om veilig te verwerken.', 'soocool-for-woocommerce' ) );
		}
		$total_weight_grams = max( 1, (int) ceil( $total_weight ) );
		$box_count          = intdiv( $total_weight_grams - 1, $box_capacity ) + 1;
		$max_goods = $this->positive_int_setting(
			apply_filters( 'soocool_max_goods_per_order', self::DEFAULT_MAX_GOODS_PER_ORDER, $order ),
			self::DEFAULT_MAX_GOODS_PER_ORDER
		);
		$max_goods = min( 5000, $max_goods );
		if ( $box_count > $max_goods ) {
			throw new PayloadValidationException(
				sprintf(
					/* translators: 1: calculated box count, 2: safety limit. */
					__( 'De order zou %1$d SooCool-dozen aanmaken; de veiligheidslimiet is %2$d. Controleer productgewichten en het maximale gewicht per doos.', 'soocool-for-woocommerce' ),
					$box_count,
					$max_goods
				)
			);
		}
		$remaining_weight = $total_weight_grams;
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
			$filtered_good = apply_filters( 'soocool_order_good_payload', $good, $representative_item, $order, $box_number, $box_count );
			$goods[]      = is_array( $filtered_good ) ? array_replace( $good, $filtered_good ) : $good;
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
			return mb_substr( $contents, 0, 220, 'UTF-8' );
		}

		if ( 1 === preg_match( '/^.{0,220}/us', $contents, $matches ) ) {
			return $matches[0];
		}

		return substr( $contents, 0, 220 );
	}

	private function product_weight( mixed $product, string $product_name, int $fallback_grams, mixed $item, WC_Order $order ): float {
		$is_variation = is_object( $product ) && method_exists( $product, 'get_variation_attributes' );

		if ( $is_variation ) {
			$variation_owned_weight = $this->woocommerce_product_weight_grams( $product, 'edit' );
			if ( null !== $variation_owned_weight ) {
				return $variation_owned_weight;
			}

			$variation_weight = $this->weight_from_variation_context( $product, $item );
			if ( null !== $variation_weight ) {
				return $variation_weight;
			}
		}

		$official_weight = $this->woocommerce_product_weight_grams( $product );
		if ( null !== $official_weight ) {
			return $official_weight;
		}

		if ( ! $is_variation ) {
			$variation_weight = $this->weight_from_variation_context( $product, $item );
			if ( null !== $variation_weight ) {
				return $variation_weight;
			}
		}

		$name_weight = $this->weight_from_product_name( $product_name );
		if ( null !== $name_weight ) {
			return $name_weight;
		}

		/**
		 * Filters the per-unit fallback weight when WooCommerce, the selected variation and the product name provide no unambiguous weight.
		 *
		 * @param int      $fallback_grams Configured fallback weight in grams.
		 * @param mixed    $product        WooCommerce product object when available.
		 * @param mixed    $item           WooCommerce order item.
		 * @param WC_Order $order          WooCommerce order.
		 */
		$filtered_fallback = apply_filters( 'soocool_missing_product_weight_grams', $fallback_grams, $product, $item, $order );

		return (float) $this->positive_int_setting( $filtered_fallback, $fallback_grams );
	}

	private function woocommerce_product_weight_grams( mixed $product, string $context = 'view' ): ?float {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_weight' ) || ! function_exists( 'wc_get_weight' ) ) {
			return null;
		}

		$raw = $product->get_weight( $context );
		if ( ! is_scalar( $raw ) || '' === trim( (string) $raw ) || ! is_numeric( $raw ) ) {
			return null;
		}

		$weight = (float) $raw;
		$grams  = 0 < $weight ? (float) wc_get_weight( $weight, 'g' ) : 0.0;

		return is_finite( $grams ) && 0 < $grams && $grams <= PHP_INT_MAX ? $grams : null;
	}

	private function weight_from_variation_context( mixed $product, mixed $item ): ?float {
		$keyed_candidates   = array();
		$generic_candidates = array();

		if ( is_object( $product ) && method_exists( $product, 'get_variation_attributes' ) ) {
			$attributes = $product->get_variation_attributes( false );
			if ( is_array( $attributes ) ) {
				foreach ( $attributes as $key => $raw_value ) {
					$value = $raw_value;
					if ( method_exists( $product, 'get_attribute' ) ) {
						$resolved = $product->get_attribute( (string) $key );
						if ( is_scalar( $resolved ) && '' !== trim( (string) $resolved ) ) {
							$value = $resolved;
						}
					}
					$this->collect_weight_candidate( (string) $key, $value, $keyed_candidates, $generic_candidates );
				}
			}
		}

		$formatted_meta = array();
		if ( is_object( $item ) && method_exists( $item, 'get_all_formatted_meta_data' ) ) {
			$formatted_meta = $item->get_all_formatted_meta_data( '', true );
		} elseif ( is_object( $item ) && method_exists( $item, 'get_formatted_meta_data' ) ) {
			$formatted_meta = $item->get_formatted_meta_data( '', true );
		}

		if ( is_array( $formatted_meta ) ) {
			foreach ( $formatted_meta as $meta ) {
				if ( is_object( $meta ) ) {
					$key   = $meta->display_key ?? $meta->key ?? '';
					$value = $meta->display_value ?? $meta->value ?? '';
				} elseif ( is_array( $meta ) ) {
					$key   = $meta['display_key'] ?? $meta['key'] ?? '';
					$value = $meta['display_value'] ?? $meta['value'] ?? '';
				} else {
					continue;
				}

				$this->collect_weight_candidate( is_scalar( $key ) ? (string) $key : '', $value, $keyed_candidates, $generic_candidates );
			}
		}

		if ( array() !== $keyed_candidates ) {
			return $this->single_unique_weight( $keyed_candidates );
		}

		return $this->single_unique_weight( $generic_candidates );
	}

	/** @param array<int, float> $keyed_candidates @param array<int, float> $generic_candidates */
	private function collect_weight_candidate( string $key, mixed $raw_value, array &$keyed_candidates, array &$generic_candidates ): void {
		if ( ! is_scalar( $raw_value ) ) {
			return;
		}

		$value = html_entity_decode( wp_strip_all_tags( (string) $raw_value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$is_weight_key = $this->is_weight_attribute_key( $key );
		if ( $is_weight_key && $this->is_taxonomy_attribute_key( $key ) ) {
			$value = $this->normalize_weight_attribute_value( $value );
		}

		$weight = $this->weight_from_product_name( $value );
		if ( null === $weight ) {
			return;
		}

		if ( $is_weight_key ) {
			$keyed_candidates[] = $weight;
			return;
		}

		if ( preg_match( '/^\s*(?:(?:\d+)\s*[x×]\s*)?\d+(?:[.,]\d+)?\s*(?:kg|kilogram(?:men)?|kilo|g|gram(?:men)?)\s*$/iu', $value ) ) {
			$generic_candidates[] = $weight;
		}
	}

	private function is_taxonomy_attribute_key( string $key ): bool {
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $key ) ) : strtolower( trim( $key ) );
		return str_starts_with( $key, 'pa_' ) || str_starts_with( $key, 'attribute_pa_' );
	}

	private function normalize_weight_attribute_value( string $value ): string {
		if ( 1 !== preg_match( '/^\s*(\d+)-(\d+)\s*(kg|kilogram(?:men)?|kilo|g|gram(?:men)?)\s*$/iu', $value, $matches ) ) {
			return $value;
		}

		return $matches[1] . '.' . $matches[2] . $matches[3];
	}

	private function is_weight_attribute_key( string $key ): bool {
		$key = trim( wp_strip_all_tags( $key ) );
		if ( '' === $key ) {
			return false;
		}

		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $key ) : strtolower( $key );
		$key = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $key ) ?? $key;

		return 1 === preg_match( '/(?:^|\s)(?:gewicht|weight|massa|mass|poids|peso)(?:\s|$)/u', $key );
	}

	/** @param array<int, float> $candidates */
	private function single_unique_weight( array $candidates ): ?float {
		$unique = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_finite( $candidate ) || 0 >= $candidate ) {
				continue;
			}
			$unique[ sprintf( '%.6F', $candidate ) ] = $candidate;
		}

		return 1 === count( $unique ) ? (float) reset( $unique ) : null;
	}

	private function weight_from_product_name( string $product_name ): ?float {
		$product_name = trim( wp_strip_all_tags( $product_name ) );
		if ( '' === $product_name || 1 === preg_match( '/^[+-]\s*\d/u', $product_name ) ) {
			return null;
		}

		$matched = preg_match_all(
			'/(?<!\d-)(?<![\p{L}\p{N}])(?:(\d+)\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(kg|kilogram(?:men)?|kilo|g|gram(?:men)?)(?![\p{L}\p{N}])/iu',
			$product_name,
			$matches,
			PREG_SET_ORDER
		);
		if ( 1 !== $matched || ! isset( $matches[0][2], $matches[0][3] ) ) {
			return null;
		}

		$multiplier = isset( $matches[0][1] ) && '' !== (string) $matches[0][1] ? (int) $matches[0][1] : 1;
		$amount     = (float) str_replace( ',', '.', (string) $matches[0][2] );
		$unit       = strtolower( (string) $matches[0][3] );
		if ( 0 >= $multiplier || ! is_finite( $amount ) || 0 >= $amount ) {
			return null;
		}

		$grams = ( str_starts_with( $unit, 'k' ) ? $amount * 1000 : $amount ) * $multiplier;
		return is_finite( $grams ) && 0 < $grams && $grams <= PHP_INT_MAX ? $grams : null;
	}

	private function barcode_for_item( mixed $item ): string {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
			return '';
		}

		$product = $item->get_product();
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return '';
		}

		$raw_sku = $product->get_sku();
		$sku     = is_scalar( $raw_sku ) ? sanitize_text_field( (string) $raw_sku ) : '';

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
		$int = NumericIdentifier::positive_integer( $value );
		return null !== $int ? $int : $fallback;
	}

}
