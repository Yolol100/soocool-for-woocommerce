<?php

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );

	function __( string $message, string $domain = '' ): string {
		return $message;
	}

	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}

	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ?? '' );
	}

	function apply_filters( string $filter, mixed $value, mixed ...$args ): mixed {
		return $value;
	}

	function wc_get_weight( float $weight, string $unit ): float {
		if ( 'g' !== $unit ) {
			throw new \RuntimeException( 'Unexpected weight unit.' );
		}

		return $weight * 1000;
	}

	class WC_Order {
		public function __construct( private readonly array $items = array(), private readonly array $refunds = array() ) {}

		public function get_qty_refunded_for_item( int $item_id ): float {
			return (float) ( $this->refunds[ $item_id ] ?? 0 );
		}

		public function get_items(): array {
			return $this->items;
		}

		public function get_order_number(): string {
			return 'soocool-weight-test';
		}
	}

	final class SooCoolWeightTestProduct {
		public function __construct(
			private readonly array $attributes,
			private readonly string $weight = '',
			private readonly bool $virtual = false
		) {}

		public function get_variation_attributes( bool $formatted = false ): array {
			return $this->attributes;
		}

		public function get_attribute( string $key ): string {
			return (string) ( $this->attributes[ $key ] ?? '' );
		}

		public function get_weight( string $context = 'view' ): string {
			return $this->weight;
		}

		public function is_virtual(): bool {
			return $this->virtual;
		}

		public function get_sku(): string {
			return 'test-spice';
		}
	}

	final class SooCoolSimpleWeightTestProduct {
		public function __construct( private readonly string $weight ) {}

		public function get_weight( string $context = 'view' ): string {
			return $this->weight;
		}

		public function is_virtual(): bool {
			return false;
		}

		public function get_sku(): string {
			return 'simple-product';
		}
	}

	final class SooCoolWeightTestItem {
		public function __construct(
			private readonly object $product,
			private readonly int $quantity = 1,
			private readonly array $metadata = array(),
			private readonly int $id = 0,
			private readonly string $name = 'Kip kruiden'
		) {}

		public function get_product(): object {
			return $this->product;
		}

		public function get_quantity(): int {
			return $this->quantity;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_all_formatted_meta_data( string $hide_prefix = '', bool $include_all = false ): array {
			return $this->metadata;
		}
	}

	function soocool_weight_expect( mixed $actual, mixed $expected, string $message ): void {
		if ( $actual !== $expected ) {
			fwrite( STDERR, $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . PHP_EOL );
			exit( 1 );
		}
	}

	function soocool_weight_expect_rejected( callable $action, string $message ): void {
		try {
			$action();
		} catch ( \SooCool\WooCommerce\Domain\PayloadValidationException ) {
			return;
		}

		fwrite( STDERR, $message . ': expected PayloadValidationException.' . PHP_EOL );
		exit( 1 );
	}
}

namespace SooCool\WooCommerce\Infrastructure {
	final class NumericIdentifier {
		public static function positive_integer( mixed $value ): ?int {
			return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
		}
	}

	final class OptionRepository {}
}

namespace SooCool\WooCommerce\Domain {
	final class TaskFactory {}

	final class OrderPayloadValidator {}
}

namespace {
	require dirname( __DIR__ ) . '/src/Domain/PayloadValidationException.php';
	require dirname( __DIR__ ) . '/src/Domain/OrderPayloadBuilder.php';

	$builder = ( new \ReflectionClass( \SooCool\WooCommerce\Domain\OrderPayloadBuilder::class ) )->newInstanceWithoutConstructor();
	$weight_method = new \ReflectionMethod( \SooCool\WooCommerce\Domain\OrderPayloadBuilder::class, 'product_weight' );
	$goods_method = new \ReflectionMethod( \SooCool\WooCommerce\Domain\OrderPayloadBuilder::class, 'build_goods' );
	$settings = array(
		'goods_description_fallback' => 'Kruiden',
		'packaging_type' => 'box',
		'temperature_regime' => 'cooled',
		'package_weight' => 10000,
		'missing_product_weight' => 1000,
	);

	$cases = array(
		'80g one bag' => array( '80g', '1', 80.0 ),
		'80g ten bags' => array( '80g', '10', 800.0 ),
		'200g ten bags' => array( '200g', '10', 2000.0 ),
		'0.5kg two bags' => array( '0.5kg', '2', 1000.0 ),
	);

	foreach ( $cases as $label => [ $weight, $count, $expected ] ) {
		$product = new SooCoolWeightTestProduct(
			array(
				'attribute_pa_gewicht' => $weight,
				'attribute_pa_aantal'  => $count,
			),
			'1'
		);
		$item = new SooCoolWeightTestItem( $product );
		$actual = $weight_method->invoke( $builder, $product, 'Kip kruiden', 1000, $item, new WC_Order() );
		soocool_weight_expect( $actual, $expected, $label );
	}

	$ten_packs = new SooCoolWeightTestProduct(
		array( 'attribute_pa_gewicht' => '80g', 'attribute_pa_aantal' => '10' ),
		'0'
	);
	$two_variations = new WC_Order( array( new SooCoolWeightTestItem( $ten_packs, 2 ) ) );
	$goods = $goods_method->invoke( $builder, $two_variations, $settings );
	soocool_weight_expect( count( $goods ), 1, '2x ten bags should fit one box' );
	soocool_weight_expect( $goods[0]['weight'], 1600, '2x ten bags must weigh 1600 grams' );

	$heavy = new SooCoolWeightTestProduct( array( 'attribute_pa_gewicht' => '200g', 'attribute_pa_aantal' => '10' ) );
	$heavy_order = new WC_Order( array( new SooCoolWeightTestItem( $heavy, 6 ) ) );
	$goods = $goods_method->invoke( $builder, $heavy_order, $settings );
	soocool_weight_expect( count( $goods ), 2, '12kg must require two boxes' );
	soocool_weight_expect( $goods[0]['weight'], 10000, 'First box capacity' );
	soocool_weight_expect( $goods[1]['weight'], 2000, 'Second box remainder' );

	$conflicting = new SooCoolWeightTestProduct(
		array( 'attribute_pa_gewicht' => '80g', 'attribute_pa_aantal' => '10' )
	);
	$item = new SooCoolWeightTestItem( $conflicting, 1, array( (object) array( 'display_key' => 'Aantal', 'display_value' => '3' ) ) );
	try {
		$weight_method->invoke( $builder, $conflicting, 'Kip kruiden', 1000, $item, new WC_Order() );
		fwrite( STDERR, 'Conflicting Aantal must fail closed.' . PHP_EOL );
		exit( 1 );
	} catch ( \SooCool\WooCommerce\Domain\PayloadValidationException ) {
		// Expected: conflicting variation count must not silently undercharge.
	}


	// Variations use the selected pack attributes; WC line quantity multiplies the full pack.
	$packs_80g = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '80g', 'pa_aantal' => '10' ) );
	$weight_from_meta = new SooCoolWeightTestProduct( array() );
	$pack_from_meta_item = new SooCoolWeightTestItem(
		$weight_from_meta,
		1,
		array(
			(object) array( 'display_key' => 'Gewicht', 'display_value' => '80g' ),
			(object) array( 'display_key' => 'Aantal', 'display_value' => '10' ),
		)
	);
	soocool_weight_expect(
		$weight_method->invoke( $builder, $weight_from_meta, 'Kip kruiden', 1000, $pack_from_meta_item, new WC_Order() ),
		800.0,
		'Pack attributes stored in WooCommerce order item metadata'
	);

	$decimal_variant = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '1,5kg', 'pa_aantal' => '3' ) );
	$decimal_item = new SooCoolWeightTestItem( $decimal_variant );
	soocool_weight_expect(
		$weight_method->invoke( $builder, $decimal_variant, 'Kip kruiden', 1000, $decimal_item, new WC_Order() ),
		4500.0,
		'Localized decimal comma remains valid'
	);
	$taxonomy_slug = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '0-5kg', 'pa_aantal' => '2' ) );
	$slug_item = new SooCoolWeightTestItem( $taxonomy_slug );
	soocool_weight_expect(
		$weight_method->invoke( $builder, $taxonomy_slug, 'Kip kruiden', 1000, $slug_item, new WC_Order() ),
		1000.0,
		'Legacy taxonomy slug 0-5kg is 0.5kg'
	);

	// Regression: packed products and ordinary products share the same 10 kg capacity.
	$meat = new SooCoolSimpleWeightTestProduct( '9' );
	$under_limit_order = new WC_Order(
		array(
			new SooCoolWeightTestItem( $meat ),
			new SooCoolWeightTestItem( $packs_80g ),
		)
	);
	$goods = $goods_method->invoke( $builder, $under_limit_order, $settings );
	soocool_weight_expect( count( $goods ), 1, '9kg meat and 800g spices fit in one box' );
	soocool_weight_expect( $goods[0]['weight'], 9800, 'Mixed order must equal 9800 grams' );

	$exact_limit = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '1kg', 'pa_aantal' => '10' ) );
	$goods = $goods_method->invoke( $builder, new WC_Order( array( new SooCoolWeightTestItem( $exact_limit ) ) ), $settings );
	soocool_weight_expect( count( $goods ), 1, 'Exactly 10kg is still one box' );
	soocool_weight_expect( $goods[0]['weight'], 10000, 'Exact box capacity' );

	$one_gram_over = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '1g', 'pa_aantal' => '10001' ) );
	$goods = $goods_method->invoke( $builder, new WC_Order( array( new SooCoolWeightTestItem( $one_gram_over ) ) ), $settings );
	soocool_weight_expect( count( $goods ), 2, 'One gram over capacity must require two boxes' );
	soocool_weight_expect( $goods[1]['weight'], 1, 'Remainder is one gram' );

	$refunded_item = new SooCoolWeightTestItem( $packs_80g, 2, array(), 301 );
	$goods = $goods_method->invoke( $builder, new WC_Order( array( $refunded_item ), array( 301 => 1 ) ), $settings );
	soocool_weight_expect( $goods[0]['weight'], 800, 'One refunded pack must be excluded' );
	soocool_weight_expect_rejected(
		static fn () => $goods_method->invoke( $builder, new WC_Order( array( $refunded_item ), array( 301 => 2 ) ), $settings ),
		'Fully refunded order must not create boxes'
	);

	$virtual = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '10kg', 'pa_aantal' => '10' ), '', true );
	$goods = $goods_method->invoke(
		$builder,
		new WC_Order( array( new SooCoolWeightTestItem( $virtual ), new SooCoolWeightTestItem( $packs_80g ) ) ),
		$settings
	);
	soocool_weight_expect( $goods[0]['weight'], 800, 'Virtual products do not count towards shipping weight' );
	$goods = $goods_method->invoke(
		$builder,
		new WC_Order( array( new SooCoolWeightTestItem( $virtual, 0 ), new SooCoolWeightTestItem( $packs_80g ) ) ),
		$settings
	);
	soocool_weight_expect( $goods[0]['weight'], 800, 'Zero-quantity lines are ignored' );

	$missing_weight = new SooCoolSimpleWeightTestProduct( '' );
	$goods = $goods_method->invoke( $builder, new WC_Order( array( new SooCoolWeightTestItem( $missing_weight ) ) ), $settings );
	soocool_weight_expect( $goods[0]['weight'], 1000, 'Legacy configurable missing-weight fallback is preserved' );
	$name_weight = new SooCoolSimpleWeightTestProduct( '' );
	$goods = $goods_method->invoke(
		$builder,
		new WC_Order( array( new SooCoolWeightTestItem( $name_weight, 1, array(), 0, 'Kip kruiden - 80g' ) ) ),
		$settings
	);
	soocool_weight_expect( $goods[0]['weight'], 80, 'Legacy unambiguous product-name weight still works' );

	$bad_count_values = array( '0', '-1', '2.5', '1e2', '10 stuks', '1000000', '<b>0</b>' );
	foreach ( $bad_count_values as $bad_count ) {
		$invalid = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '80g', 'pa_aantal' => $bad_count ), '1' );
		$invalid_item = new SooCoolWeightTestItem( $invalid );
		soocool_weight_expect_rejected(
			static fn () => $weight_method->invoke( $builder, $invalid, 'Kip kruiden', 1000, $invalid_item, new WC_Order() ),
			'Invalid count ' . $bad_count
		);
	}

	$wrong_weight = new SooCoolWeightTestProduct( array( 'pa_gewicht' => 'unknown', 'pa_aantal' => '10' ), '1' );
	$wrong_item = new SooCoolWeightTestItem( $wrong_weight );
	soocool_weight_expect_rejected(
		static fn () => $weight_method->invoke( $builder, $wrong_weight, 'Kip kruiden', 1000, $wrong_item, new WC_Order() ),
		'Invalid explicit weight must not silently use the one kilogram fallback'
	);
	$ambiguous_range = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '10-20kg', 'pa_aantal' => '2' ) );
	$range_item = new SooCoolWeightTestItem( $ambiguous_range );
	soocool_weight_expect_rejected(
		static fn () => $weight_method->invoke( $builder, $ambiguous_range, 'Kip kruiden', 1000, $range_item, new WC_Order() ),
		'Ambiguous taxonomy range must not be interpreted as a decimal'
	);
	$conflicting_weight = new SooCoolWeightTestItem(
		$packs_80g,
		1,
		array( (object) array( 'display_key' => 'Gewicht', 'display_value' => '200g' ) )
	);
	soocool_weight_expect_rejected(
		static fn () => $weight_method->invoke( $builder, $packs_80g, 'Kip kruiden', 1000, $conflicting_weight, new WC_Order() ),
		'Conflicting historical and current weight attributes must not silently use fallback'
	);
	$ambiguous_counts = new SooCoolWeightTestProduct( array( 'pa_aantal' => '10' ), '1' );
	$ambiguous_item = new SooCoolWeightTestItem( $ambiguous_counts, 1, array( (object) array( 'display_key' => 'Aantal', 'display_value' => '2' ) ) );
	soocool_weight_expect_rejected(
		static fn () => $weight_method->invoke( $builder, $ambiguous_counts, 'Kip kruiden', 1000, $ambiguous_item, new WC_Order() ),
		'Conflicting counts must be rejected even when explicit weight is missing'
	);

	// An explicitly selected Aantal > 1 without usable Gewicht or a real product
	// weight must stop, rather than applying the 1kg fallback to an entire pack.
	$missing_multipack_weight = new SooCoolWeightTestProduct( array( 'pa_aantal' => '10' ), '' );
	$missing_pack_item = new SooCoolWeightTestItem( $missing_multipack_weight );
	soocool_weight_expect_rejected(
		static fn () => $weight_method->invoke( $builder, $missing_multipack_weight, 'Kip kruiden', 1000, $missing_pack_item, new WC_Order() ),
		'Multipack with no unit or official pack weight must not fall back to 1kg'
	);

	// A configured WooCommerce shipping weight can intentionally describe the
	// whole pack when no per-piece Gewicht attribute was supplied.
	$official_pack_weight = new SooCoolWeightTestProduct( array( 'pa_aantal' => '10' ), '1' );
	$official_item = new SooCoolWeightTestItem( $official_pack_weight );
	soocool_weight_expect(
		$weight_method->invoke( $builder, $official_pack_weight, 'Kip kruiden', 1000, $official_item, new WC_Order() ),
		1000.0,
		'Official pack weight remains a supported fallback'
	);

	$single_bag = new SooCoolWeightTestProduct( array( 'pa_gewicht' => '80g' ) );
	$single_item = new SooCoolWeightTestItem( $single_bag );
	soocool_weight_expect(
		$weight_method->invoke( $builder, $single_bag, 'Kip kruiden', 1000, $single_item, new WC_Order() ),
		80.0,
		'A variation with no Aantal attribute remains one bag'
	);

	$invalid_weight_meta = new SooCoolWeightTestItem(
		$packs_80g,
		1,
		array( (object) array( 'display_key' => 'Gewicht', 'display_value' => array() ) )
	);
	soocool_weight_expect_rejected(
		static fn () => $weight_method->invoke( $builder, $packs_80g, 'Kip kruiden', 1000, $invalid_weight_meta, new WC_Order() ),
		'Non-scalar weight metadata must not be accepted as fallback'
	);

	$over_limit_order = new WC_Order( array( new SooCoolWeightTestItem( $meat, 1200 ) ) );
	soocool_weight_expect_rejected(
		static fn () => $goods_method->invoke( $builder, $over_limit_order, $settings ),
		'Maximum box safety limit remains enforced'
	);
	
	echo "SooCool variation pack weight regression checks passed.\n";
}
