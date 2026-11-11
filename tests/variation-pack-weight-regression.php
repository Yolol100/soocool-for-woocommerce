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
		public function __construct( private readonly array $items = array() ) {}

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
			private readonly string $weight = ''
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
			return false;
		}

		public function get_sku(): string {
			return 'test-spice';
		}
	}

	final class SooCoolWeightTestItem {
		public function __construct(
			private readonly SooCoolWeightTestProduct $product,
			private readonly int $quantity = 1,
			private readonly array $metadata = array()
		) {}

		public function get_product(): SooCoolWeightTestProduct {
			return $this->product;
		}

		public function get_quantity(): int {
			return $this->quantity;
		}

		public function get_name(): string {
			return 'Kip kruiden';
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

	echo "SooCool variation pack weight regression checks passed.\n";
}
