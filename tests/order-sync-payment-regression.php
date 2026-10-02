<?php

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );

	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}

	class WC_Order {
		public function __construct( private readonly int $id, private readonly bool $paid ) {}

		public function get_id(): int {
			return $this->id;
		}

		public function is_paid(): bool {
			return $this->paid;
		}
	}
}

namespace SooCool\WooCommerce\Api {
	final class ApiClient {}
}

namespace SooCool\WooCommerce\Infrastructure {
	final class Logger {}

	final class OptionRepository {
		public function all(): array {
			return array( 'allow_resubmit' => false );
		}
	}

	final class ProviderContext {
		public function __construct( ?OptionRepository $options = null ) {
			unset( $options );
		}
	}

	final class SecretSanitizer {}
}

namespace SooCool\WooCommerce\WooCommerce {
	final class OrderMeta {}
	final class RemoteStatusMapper {}
}

namespace SooCool\WooCommerce\Domain {
	final class OrderPayloadBuilder {}

	final class OrderSyncService {
		public int $lock_calls = 0;

		public function acquire_lock( int $order_id ): bool {
			unset( $order_id );
			++$this->lock_calls;
			return true;
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Domain/OrderSyncCoordinator.php';

	$options = new \SooCool\WooCommerce\Infrastructure\OptionRepository();
	$sync    = new \SooCool\WooCommerce\Domain\OrderSyncService();
	$coordinator = new \SooCool\WooCommerce\Domain\OrderSyncCoordinator(
		new \SooCool\WooCommerce\Api\ApiClient(),
		new \SooCool\WooCommerce\Domain\OrderPayloadBuilder(),
		new \SooCool\WooCommerce\WooCommerce\OrderMeta(),
		$options,
		$sync,
		new \SooCool\WooCommerce\WooCommerce\RemoteStatusMapper(),
		new \SooCool\WooCommerce\Infrastructure\SecretSanitizer(),
		new \SooCool\WooCommerce\Infrastructure\Logger(),
		new \SooCool\WooCommerce\Infrastructure\ProviderContext( $options )
	);

	$result = $coordinator->sync_order( new WC_Order( 101, false ) );
	if ( false !== $result['success'] || 409 !== (int) $result['status'] || 0 !== $sync->lock_calls ) {
		fwrite( STDERR, 'Unpaid orders must be rejected before any SooCool synchronization lock or API path starts.' . PHP_EOL );
		exit( 1 );
	}

	echo "SooCool payment eligibility coordinator regression checks passed.\n";
}
