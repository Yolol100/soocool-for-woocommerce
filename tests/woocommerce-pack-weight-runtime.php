<?php

declare(strict_types=1);

use SooCool\WooCommerce\Domain\OrderPayloadBuilder;

if ( ! defined( 'SOOCOOL_VERSION' )
	|| ! class_exists( \WC_Product_Variable::class )
	|| ! class_exists( \WC_Product_Variation::class )
	|| ! class_exists( \WC_Order_Item_Product::class )
	|| ! function_exists( 'wc_create_order' )
) {
	fwrite( STDERR, "WooCommerce and SooCool must be loaded for the variation runtime test.\n" );
	exit( 1 );
}

function soocool_weight_runtime_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}

// Reflection is limited to the pure payload builder: no API call, shipment or paid order.
$builder = ( new \ReflectionClass( OrderPayloadBuilder::class ) )->newInstanceWithoutConstructor();
$product_weight = new \ReflectionMethod( OrderPayloadBuilder::class, 'product_weight' );
$build_goods = new \ReflectionMethod( OrderPayloadBuilder::class, 'build_goods' );
$settings = array(
	'goods_description_fallback' => 'Testproducten',
	'packaging_type' => 'box',
	'temperature_regime' => 'cooled',
	'package_width' => 60,
	'package_depth' => 40,
	'package_height' => 11,
	'package_weight' => 10000,
	'missing_product_weight' => 1000,
);

$variation = new \WC_Product_Variation();
$variation->set_attributes( array( 'gewicht' => '80g', 'aantal' => '10' ) );
$variation->set_weight( '1' );
$item = new \WC_Order_Item_Product();
$item->set_name( 'Kip kruiden - 80g, 10' );
$item->set_quantity( 1 );
$item->set_variation( $variation->get_variation_attributes() );

$actual = $product_weight->invoke( $builder, $variation, $item->get_name(), 1000, $item, new \WC_Order() );
soocool_weight_runtime_assert( abs( $actual - 800.0 ) < 0.00001, 'Real WooCommerce attributes must override an inaccurate 1kg variation weight.' );

$stored_unit = get_option( 'woocommerce_weight_unit', 'kg' );
$parent = null;
$saved_variation = null;
$meat = null;
$order = null;

try {
	update_option( 'woocommerce_weight_unit', 'kg' );

	$parent = new \WC_Product_Variable();
	$parent->set_name( 'SooCool runtime package test' );
	$parent->set_status( 'publish' );

	$weight_attribute = new \WC_Product_Attribute();
	$weight_attribute->set_name( 'Gewicht' );
	$weight_attribute->set_options( array( '80g' ) );
	$weight_attribute->set_variation( true );
	$weight_attribute->set_visible( true );

	$count_attribute = new \WC_Product_Attribute();
	$count_attribute->set_name( 'Aantal' );
	$count_attribute->set_options( array( '10' ) );
	$count_attribute->set_variation( true );
	$count_attribute->set_visible( true );

	$parent->set_attributes( array( $weight_attribute, $count_attribute ) );
	$parent->save();

	$saved_variation = new \WC_Product_Variation();
	$saved_variation->set_parent_id( $parent->get_id() );
	$saved_variation->set_status( 'publish' );
	$saved_variation->set_attributes( array( 'gewicht' => '80g', 'aantal' => '10' ) );
	$saved_variation->set_regular_price( '4.50' );
	$saved_variation->set_weight( '1' );
	$saved_variation->save();

	$meat = new \WC_Product_Simple();
	$meat->set_name( 'SooCool runtime meat test' );
	$meat->set_regular_price( '15.00' );
	$meat->set_weight( '9' );
	$meat->save();

	$order = wc_create_order();
	soocool_weight_runtime_assert( $order instanceof \WC_Order, 'Could not create isolated WooCommerce test order.' );
	$order->add_product( $saved_variation, 2 );
	$order->add_product( $meat, 1 );
	$order->save();

	$reloaded = wc_get_order( $order->get_id() );
	soocool_weight_runtime_assert( $reloaded instanceof \WC_Order, 'WooCommerce order reload failed.' );

	$goods = $build_goods->invoke( $builder, $reloaded, $settings );
	soocool_weight_runtime_assert( 2 === count( $goods ), '1.6kg spices plus 9kg meat should create two boxes.' );
	soocool_weight_runtime_assert( 10000 === $goods[0]['weight'], 'The first box should weigh 10,000g.' );
	soocool_weight_runtime_assert( 600 === $goods[1]['weight'], 'The second box should weigh 600g.' );
	soocool_weight_runtime_assert( $goods[0]['goodId'] !== $goods[1]['goodId'], 'Box goodIds must remain distinct.' );

	echo "WooCommerce variable-product weight and shipping boxes runtime passed.\n";
} catch ( \Throwable $e ) {
	fwrite( STDERR, 'WooCommerce weight runtime failed: ' . $e->getMessage() . PHP_EOL );
	exit( 1 );
} finally {
	if ( $order instanceof \WC_Order && $order->get_id() ) {
		$order->delete( true );
	}
	if ( $saved_variation instanceof \WC_Product_Variation && $saved_variation->get_id() ) {
		$saved_variation->delete( true );
	}
	if ( $meat instanceof \WC_Product_Simple && $meat->get_id() ) {
		$meat->delete( true );
	}
	if ( $parent instanceof \WC_Product_Variable && $parent->get_id() ) {
		$parent->delete( true );
	}
	update_option( 'woocommerce_weight_unit', $stored_unit );
}
