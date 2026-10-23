<?php

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['soocool_checkout'] = true;
	$GLOBALS['soocool_enqueue_calls'] = array();

	function is_checkout(): bool {
		return (bool) $GLOBALS['soocool_checkout'];
	}

	function wp_enqueue_style( string $handle, string $url, array $dependencies, string $version ): void {
		$GLOBALS['soocool_enqueue_calls'][] = array( 'style', $handle, $dependencies );
	}

	function wp_enqueue_script( string $handle, string $url, array $dependencies, string $version, bool $in_footer ): void {
		$GLOBALS['soocool_enqueue_calls'][] = array( 'script', $handle, $dependencies );
	}
}

namespace SooCool\WooCommerce\Infrastructure {
	final class OptionRepository {
		public bool $enabled = true;

		public function all(): array {
			return array( 'checkout_delivery_enabled' => $this->enabled );
		}
	}

	final class AssetResolver {
		public static function filename( string $directory, string $base, string $extension ): string {
			return $base . '.' . $extension;
		}

		public static function url( string $directory, string $filename ): string {
			return '/' . $directory . '/' . $filename;
		}

		public static function version( string $directory, string $filename ): string {
			return 'test';
		}
	}
}

namespace SooCool\WooCommerce\Checkout {
	final class DeliverySchedule {}
	final class DeliveryCheckoutRequest {}
	final class DeliveryOrderDetails {}
	final class DeliveryOptionsRenderer {
		public function __construct( DeliverySchedule $schedule ) {}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Checkout/DeliveryOptions.php';

	$options = new \SooCool\WooCommerce\Infrastructure\OptionRepository();
	$assets = new \SooCool\WooCommerce\Checkout\DeliveryOptions(
		$options,
		new \SooCool\WooCommerce\Checkout\DeliverySchedule(),
		new \SooCool\WooCommerce\Checkout\DeliveryCheckoutRequest(),
		new \SooCool\WooCommerce\Checkout\DeliveryOrderDetails()
	);

	$assets->enqueue_assets();
	$scripts = array_values(
		array_filter(
			$GLOBALS['soocool_enqueue_calls'],
			static fn ( array $call ): bool => 'script' === $call[0] && 'soocool-checkout-delivery' === $call[1]
		)
	);
	if ( 1 !== count( $scripts ) || ! in_array( 'jquery', $scripts[0][2], true ) ) {
		fwrite( STDERR, 'Checkout script must declare jQuery because it listens for WooCommerce updated_checkout events.' . PHP_EOL );
		exit( 1 );
	}

	$GLOBALS['soocool_enqueue_calls'] = array();
	$GLOBALS['soocool_checkout'] = false;
	$assets->enqueue_assets();
	if ( array() !== $GLOBALS['soocool_enqueue_calls'] ) {
		fwrite( STDERR, 'Checkout assets must not be enqueued on other screens.' . PHP_EOL );
		exit( 1 );
	}

	$GLOBALS['soocool_checkout'] = true;
	$options->enabled = false;
	$assets->enqueue_assets();
	if ( array() !== $GLOBALS['soocool_enqueue_calls'] ) {
		fwrite( STDERR, 'Disabled checkout delivery assets must not be enqueued.' . PHP_EOL );
		exit( 1 );
	}

	echo "SooCool checkout script dependencies and enqueue scope regression checks passed.\n";
}
