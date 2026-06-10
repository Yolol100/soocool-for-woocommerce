<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the SooCool create-order payload (SooCool API 1.2.1).
 *
 * The payload uses root-level goods and task-level references to those goods.
 * Dimensions and weight prefer product data when available and safely fall back
 * to the global SooCool package settings, so the admin settings remain active.
 */
final class OrderPayloadBuilder {

	public function __construct( private readonly TaskFactory $task_factory, private readonly OptionRepository $options ) {}

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

		if ( ! empty( $settings['webhook_url'] ) ) {
			$payload['webhook'] = array( 'webhookUrl' => esc_url_raw( (string) $settings['webhook_url'] ) );
		}

		$this->validate_contract_minimums( $payload );

		return $payload;
	}

	/** @param array<string, mixed> $payload */
	public function validate_contract_minimums( array $payload ): void {
		if ( '' === trim( (string) ( $payload['orderReference'] ?? '' ) ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool order reference is missing.', 'soocool-for-woocommerce' ) );
		}

		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) || array() === $tasks ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload must contain at least one task.', 'soocool-for-woocommerce' ) );
		}

		$defined_ids = $this->validate_goods_manifest( $payload['goods'] ?? null );

		$delivery_starts = array();
		$pickup_starts   = array();
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				throw new PayloadValidationException( esc_html__( 'Every SooCool task must be an object.', 'soocool-for-woocommerce' ) );
			}

			$task_type = sanitize_key( (string) ( $task['taskType'] ?? '' ) );
			if ( ! in_array( $task_type, array( 'delivery', 'pickup' ), true ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool taskType must be delivery or pickup.', 'soocool-for-woocommerce' ) );
			}

			$start = $this->validate_time_window( $task['timeWindow'] ?? null );
			$this->validate_task_address( $task['address'] ?? null );
			$this->validate_task_contact_info( $task['contactInfo'] ?? null );
			$this->validate_task_goods( $task['goods'] ?? null, $defined_ids );

			if ( 'delivery' === $task_type ) {
				$delivery_starts[] = $start;
			}
			if ( 'pickup' === $task_type ) {
				$pickup_starts[] = $start;
			}
		}

		if ( array() === $delivery_starts ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload must contain at least one delivery task.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $pickup_starts as $pickup_start ) {
			foreach ( $delivery_starts as $delivery_start ) {
				if ( ! $this->delivery_is_on_later_date_than_pickup( $delivery_start, $pickup_start ) ) {
					throw new PayloadValidationException( esc_html__( 'SooCool delivery date must be later than the pickup date when a pickup task is used.', 'soocool-for-woocommerce' ) );
				}
			}
		}
	}

	/** @param mixed $time_window @return string startTime */
	private function validate_time_window( mixed $time_window ): string {
		if ( ! is_array( $time_window ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool task timeWindow is missing.', 'soocool-for-woocommerce' ) );
		}

		$start = (string) ( $time_window['startTime'] ?? '' );
		$end   = (string) ( $time_window['endTime'] ?? '' );
		if ( '' === trim( $start ) || '' === trim( $end ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool timeWindow must contain a startTime and endTime.', 'soocool-for-woocommerce' ) );
		}

		$start_timestamp = strtotime( $start );
		$end_timestamp   = strtotime( $end );
		if ( false === $start_timestamp || false === $end_timestamp || $end_timestamp <= $start_timestamp ) {
			throw new PayloadValidationException( esc_html__( 'SooCool timeWindow endTime must be later than startTime.', 'soocool-for-woocommerce' ) );
		}

		return $start;
	}

	/** @param mixed $address */
	private function validate_task_address( mixed $address ): void {
		if ( ! is_array( $address ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool task address is missing.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'person', 'street', 'houseNumber', 'postCode', 'city', 'country' ) as $field ) {
			if ( '' === trim( (string) ( $address[ $field ] ?? '' ) ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool address field name. */
						esc_html__( 'SooCool address field %s is missing.', 'soocool-for-woocommerce' ),
						$field
					)
				);
			}
		}
	}

	/** @param mixed $contact */
	private function validate_task_contact_info( mixed $contact ): void {
		if ( ! is_array( $contact ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool task contactInfo is missing.', 'soocool-for-woocommerce' ) );
		}

		if ( '' === trim( (string) ( $contact['email'] ?? '' ) ) && '' === trim( (string) ( $contact['phone'] ?? '' ) ) && '' === trim( (string) ( $contact['mobile'] ?? '' ) ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool task contactInfo must contain at least an email address, phone number or mobile number.', 'soocool-for-woocommerce' ) );
		}
	}

	/**
	 * @param mixed            $goods       Task goods (array of good IDs).
	 * @param array<int, true> $defined_ids Map of good IDs present in the manifest.
	 */
	private function validate_task_goods( mixed $goods, array $defined_ids ): void {
		if ( ! is_array( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( esc_html__( 'Every SooCool task must reference at least one good.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $goods as $good_id ) {
			$normalized_id = $this->signed_int_or_null( $good_id );
			if ( null === $normalized_id || 0 === $normalized_id ) {
				throw new PayloadValidationException( esc_html__( 'SooCool task goods must be a list of non-zero good IDs.', 'soocool-for-woocommerce' ) );
			}

			if ( ! isset( $defined_ids[ $normalized_id ] ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool task references a good that is not in the goods list.', 'soocool-for-woocommerce' ) );
			}
		}
	}

	/**
	 * @param mixed $goods
	 * @return array<int, true> Map of defined good IDs.
	 */
	private function validate_goods_manifest( mixed $goods ): array {
		if ( ! is_array( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload must contain at least one good.', 'soocool-for-woocommerce' ) );
		}

		$ids = array();
		foreach ( $goods as $good ) {
			if ( ! is_array( $good ) ) {
				throw new PayloadValidationException( esc_html__( 'Every SooCool good must be an object.', 'soocool-for-woocommerce' ) );
			}

			foreach ( array( 'packagingType', 'contents' ) as $field ) {
				if ( '' === trim( (string) ( $good[ $field ] ?? '' ) ) ) {
					throw new PayloadValidationException(
						sprintf(
							/* translators: %s: SooCool field name. */
							esc_html__( 'SooCool good field %s is missing.', 'soocool-for-woocommerce' ),
							$field
						)
					);
				}
			}

			$good_id = $this->signed_int_or_null( $good['goodId'] ?? null );
			if ( null === $good_id || 0 === $good_id ) {
				throw new PayloadValidationException( esc_html__( 'SooCool goodId must be a non-zero integer.', 'soocool-for-woocommerce' ) );
			}

			if ( isset( $ids[ $good_id ] ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool good IDs must be unique.', 'soocool-for-woocommerce' ) );
			}

			$this->validate_optional_dimensions( $good['dimensions'] ?? null );
			$this->validate_optional_positive_int( $good['weight'] ?? null, 'weight' );
			$this->validate_optional_transport_requirements( $good['transportRequirements'] ?? null );

			$ids[ $good_id ] = true;
		}

		return $ids;
	}

	/** @param mixed $dimensions */
	private function validate_optional_dimensions( mixed $dimensions ): void {
		if ( null === $dimensions ) {
			return;
		}

		if ( ! is_array( $dimensions ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool good dimensions must be an object when provided.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'width', 'depth', 'height' ) as $field ) {
			if ( null === $this->positive_int_or_null( $dimensions[ $field ] ?? null ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool field name. */
						esc_html__( 'SooCool good dimensions.%s must be a positive integer.', 'soocool-for-woocommerce' ),
						$field
					)
				);
			}
		}
	}

	private function validate_optional_positive_int( mixed $value, string $field ): void {
		if ( null === $value ) {
			return;
		}
		if ( null === $this->positive_int_or_null( $value ) ) {
			throw new PayloadValidationException(
				sprintf(
					/* translators: %s: SooCool field name. */
					esc_html__( 'SooCool good %s must be a positive integer.', 'soocool-for-woocommerce' ),
					$field
				)
			);
		}
	}

	/** @param mixed $requirements */
	private function validate_optional_transport_requirements( mixed $requirements ): void {
		if ( null === $requirements ) {
			return;
		}
		if ( ! is_array( $requirements ) || array() === array_values( array_filter( $requirements, static fn ( mixed $value ): bool => '' !== trim( (string) $value ) ) ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool good transportRequirements must contain at least one value when provided.', 'soocool-for-woocommerce' ) );
		}
	}

	private function delivery_is_on_later_date_than_pickup( string $delivery_start, string $pickup_start ): bool {
		try {
			$delivery = new \DateTimeImmutable( $delivery_start );
			$pickup   = new \DateTimeImmutable( $pickup_start );
		} catch ( \Exception ) {
			return false;
		}

		return $delivery->format( 'Y-m-d' ) > $pickup->format( 'Y-m-d' );
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
				 * Keep required SooCool fields goodId, packagingType and contents present.
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

	private function signed_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}
}
