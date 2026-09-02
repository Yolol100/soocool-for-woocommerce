<?php

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );

	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}

	function wc_get_order( int $order_id ): ?WC_Order {
		return $GLOBALS['soocool_recovery_orders'][ $order_id ] ?? null;
	}

	function wp_next_scheduled( string $hook, array $args = array() ): int|false {
		unset( $hook, $args );
		return false;
	}

	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['soocool_recovery_scheduled'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
		);
		return true;
	}

	function wp_clear_scheduled_hook( string $hook, array $args = array() ): int {
		unset( $hook, $args );
		return 0;
	}

	function wp_rand( int $min, int $max ): int {
		unset( $max );
		return $min;
	}

	class WC_Order {
		public function __construct( private readonly int $id ) {}

		public function get_id(): int {
			return $this->id;
		}
	}
}

namespace SooCool\WooCommerce\Admin {
	final class OrderActionConfirmScript {}
	final class OrderMetaBox {}
}

namespace SooCool\WooCommerce\Domain {
	final class OrderSyncCoordinator {}
}

namespace SooCool\WooCommerce\Infrastructure {
	final class ActionSchedulerRuntime {
		public static function is_ready(): bool {
			return false;
		}
	}

	final class NumericIdentifier {
		public static function positive( mixed $value ): ?int {
			$value = (int) $value;
			return 0 < $value ? $value : null;
		}
	}

	final class ProviderContext {
		public function matches_provider( string $context ): bool {
			return 'current' === $context;
		}

		public function execution_fingerprint( string $operation ): string {
			unset( $operation );
			return str_repeat( 'a', 64 );
		}

		public function matches_execution( string $fingerprint, string $operation ): bool {
			unset( $operation );
			return str_repeat( 'a', 64 ) === $fingerprint;
		}
	}
}

namespace SooCool\WooCommerce\WooCommerce {
	use WC_Order;

	final class OrderEmailLabels {
		public const PREFETCH_HOOK = 'soocool_prefetch_labels';
		public const CLEANUP_HOOK  = 'soocool_cleanup_labels';
	}

	final class OrderDeliveryEligibility {
		public function requires_delivery( WC_Order $order ): bool {
			unset( $order );
			return true;
		}
	}

	final class OrderMeta {
		/** @var array<int, bool> */
		private array $synced = array();

		/** @var array<int, string> */
		private array $contexts = array();

		/** @var array<int, int> */
		private array $restored = array();

		/** @var array<int, int> */
		private array $pending = array();

		public function set_synced( int $order_id, bool $synced, string $context = '' ): void {
			$this->synced[ $order_id ]   = $synced;
			$this->contexts[ $order_id ] = $context;
		}

		public function is_synced( WC_Order $order ): bool {
			return $this->synced[ $order->get_id() ] ?? false;
		}

		public function get_provider_context( WC_Order $order ): string {
			return $this->contexts[ $order->get_id() ] ?? '';
		}

		public function restore_linked_status( WC_Order $order ): void {
			$order_id = $order->get_id();
			$this->restored[ $order_id ] = ( $this->restored[ $order_id ] ?? 0 ) + 1;
		}

		public function save_pending( WC_Order $order ): void {
			$order_id = $order->get_id();
			$this->pending[ $order_id ] = ( $this->pending[ $order_id ] ?? 0 ) + 1;
		}

		public function save_error( WC_Order $order, string $message ): void {
			unset( $order, $message );
		}

		public function restored_count( int $order_id ): int {
			return $this->restored[ $order_id ] ?? 0;
		}

		public function pending_count( int $order_id ): int {
			return $this->pending[ $order_id ] ?? 0;
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/WooCommerce/OrderActions.php';

	$meta        = new \SooCool\WooCommerce\WooCommerce\OrderMeta();
	$meta_box    = new \SooCool\WooCommerce\Admin\OrderMetaBox();
	$confirm     = new \SooCool\WooCommerce\Admin\OrderActionConfirmScript();
	$coordinator = new \SooCool\WooCommerce\Domain\OrderSyncCoordinator();
	$provider    = new \SooCool\WooCommerce\Infrastructure\ProviderContext();
	$eligibility = new \SooCool\WooCommerce\WooCommerce\OrderDeliveryEligibility();
	$actions     = new \SooCool\WooCommerce\WooCommerce\OrderActions( $meta, $meta_box, $confirm, $coordinator, $provider, $eligibility );

	$linked   = new WC_Order( 101 );
	$unlinked = new WC_Order( 202 );
	$GLOBALS['soocool_recovery_orders'][101] = $linked;
	$GLOBALS['soocool_recovery_orders'][202] = $unlinked;
	$GLOBALS['soocool_recovery_scheduled']   = array();
	$meta->set_synced( 101, true, 'current' );
	$meta->set_synced( 202, false );

	$result = $actions->schedule_failed_order_recovery( 101 );
	if ( \SooCool\WooCommerce\WooCommerce\OrderActions::QUEUE_MANUAL !== $result ) {
		fwrite( STDERR, 'Linked failed orders must be classified as manual recovery.' . PHP_EOL );
		exit( 1 );
	}

	if ( 1 !== $meta->restored_count( 101 ) ) {
		fwrite( STDERR, 'Linked recovery must restore the local linked status exactly once.' . PHP_EOL );
		exit( 1 );
	}

	$result = $actions->schedule_send_to_soocool( 101 );
	if ( \SooCool\WooCommerce\WooCommerce\OrderActions::QUEUE_DUPLICATE !== $result ) {
		fwrite( STDERR, 'Normal sync must keep linked orders classified as duplicates.' . PHP_EOL );
		exit( 1 );
	}

	$result = $actions->schedule_failed_order_recovery( 202 );
	if ( \SooCool\WooCommerce\WooCommerce\OrderActions::QUEUE_SCHEDULED !== $result ) {
		fwrite( STDERR, 'Unlinked failed orders must be scheduled for recovery.' . PHP_EOL );
		exit( 1 );
	}

	if ( 1 !== $meta->pending_count( 202 ) ) {
		fwrite( STDERR, 'Scheduled recovery must persist pending state.' . PHP_EOL );
		exit( 1 );
	}

	$scheduled_hooks = array_column( $GLOBALS['soocool_recovery_scheduled'], 'hook' );
	if ( ! in_array( \SooCool\WooCommerce\WooCommerce\OrderActions::SYNC_HOOK, $scheduled_hooks, true ) ) {
		fwrite( STDERR, 'Failed-order recovery must use the canonical sync hook.' . PHP_EOL );
		exit( 1 );
	}

	if ( in_array( \SooCool\WooCommerce\WooCommerce\OrderActions::RESYNC_HOOK, $scheduled_hooks, true ) ) {
		fwrite( STDERR, 'Failed-order recovery must not create a parallel resync queue.' . PHP_EOL );
		exit( 1 );
	}

	echo "SooCool maintenance recovery regression checks passed.\n";
}
