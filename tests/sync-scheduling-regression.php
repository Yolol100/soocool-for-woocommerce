<?php

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ );

	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}

	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
	}

	function absint( mixed $value ): int {
		return abs( (int) $value );
	}

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $hook, $callback, $priority, $accepted_args );
	}

	function register_rest_route( string $namespace, string $route, array $args ): void {
		unset( $namespace, $route, $args );
	}

	function wc_get_order( int $order_id ): ?WC_Order {
		return $GLOBALS['soocool_test_orders'][ $order_id ] ?? null;
	}

	function wc_get_orders( array $args ): object {
		unset( $args );
		return (object) array(
			'orders' => array( 101 ),
			'total'  => 1,
		);
	}

	class WC_Order {
		public array $notes = array();
		public int $reloads = 0;

		public function __construct( private readonly int $id, private string $status = 'pending' ) {}

		public function get_id(): int {
			return $this->id;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function read_meta_data( bool $force = false ): void {
			unset( $force );
			++$this->reloads;
		}

		public function add_order_note( string $note ): void {
			$this->notes[] = $note;
		}
	}

	class WP_REST_Response {
		public function __construct( public mixed $data = null, public int $status = 200 ) {}
	}

	class WP_REST_Server {
		public const CREATABLE = 'POST';
	}
}

namespace SooCool\WooCommerce\Infrastructure {
	final class OptionDefaults {
		public const AUTO_SUBMIT_STATUS = 'pending';
	}

	final class OptionRepository {}

	final class NumericIdentifier {
		public static function positive_list( array $values ): array {
			return array_values( array_filter( array_map( 'intval', $values ), static fn ( int $value ): bool => $value > 0 ) );
		}
	}
}

namespace SooCool\WooCommerce\WooCommerce {
	use WC_Order;

	final class OrderMeta {
		public const SYNC_STATUS = '_soocool_sync_status';

		/** @var array<int, string> */
		private array $statuses = array();

		public function get_sync_status( WC_Order $order ): string {
			return $this->statuses[ $order->get_id() ] ?? '';
		}

		public function set_sync_status( int $order_id, string $status ): void {
			$this->statuses[ $order_id ] = $status;
		}
	}

	final class OrderDeliveryEligibility {
		public function requires_delivery( WC_Order $order ): bool {
			unset( $order );
			return true;
		}
	}

	final class OrderActions {
		public const QUEUE_SCHEDULED = 'scheduled';
		public const QUEUE_DUPLICATE = 'duplicate';
		public const QUEUE_FAILED    = 'failed';
		public const QUEUE_MANUAL    = 'manual';

		public int $send_calls = 0;
		public int $recovery_calls = 0;
		public int $resync_calls = 0;
		public string $recovery_result = self::QUEUE_SCHEDULED;

		public function __construct( private readonly OrderMeta $meta ) {}

		public function schedule_send_to_soocool( int $order_id ): string {
			++$this->send_calls;
			$this->meta->set_sync_status( $order_id, 'pending' );
			return self::QUEUE_SCHEDULED;
		}

		public function schedule_failed_order_recovery( int $order_id ): string {
			++$this->recovery_calls;
			if ( in_array( $this->recovery_result, array( self::QUEUE_SCHEDULED, self::QUEUE_DUPLICATE ), true ) ) {
				$this->meta->set_sync_status( $order_id, 'pending' );
			}
			return $this->recovery_result;
		}

		public function schedule_resync_order( int $order_id ): string {
			unset( $order_id );
			++$this->resync_calls;
			return self::QUEUE_SCHEDULED;
		}
	}
}

namespace SooCool\WooCommerce\Rest {
	abstract class AbstractRestController {
		protected string $namespace = 'soocool/v1';

		public function can_manage(): bool {
			return true;
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/WooCommerce/OrderStatusHooks.php';
	require dirname( __DIR__ ) . '/src/Rest/MaintenanceController.php';

	$meta        = new \SooCool\WooCommerce\WooCommerce\OrderMeta();
	$actions     = new \SooCool\WooCommerce\WooCommerce\OrderActions( $meta );
	$eligibility = new \SooCool\WooCommerce\WooCommerce\OrderDeliveryEligibility();
	$options     = new \SooCool\WooCommerce\Infrastructure\OptionRepository();
	$hooks       = new \SooCool\WooCommerce\WooCommerce\OrderStatusHooks( $options, $actions, $meta, $eligibility );
	$order       = new WC_Order( 101, 'pending' );
	$GLOBALS['soocool_test_orders'][101] = $order;

	$hooks->maybe_auto_submit_created_order( $order );
	$hooks->maybe_auto_submit_processed_order( 101, array(), $order );
	$hooks->maybe_auto_submit( 101, 'pending', 'processing', $order );

	if ( 1 !== $actions->send_calls ) {
		fwrite( STDERR, "Expected one automatic queue request, got {$actions->send_calls}.\n" );
		exit( 1 );
	}

	if ( 1 !== count( $order->notes ) ) {
		fwrite( STDERR, 'Expected exactly one scheduling note after overlapping WooCommerce hooks.' . PHP_EOL );
		exit( 1 );
	}

	foreach ( $order->notes as $note ) {
		if ( str_contains( $note, 'overgeslagen omdat deze order al op de achtergrond ingepland staat' ) ) {
			fwrite( STDERR, 'Duplicate scheduling note must not be emitted.' . PHP_EOL );
			exit( 1 );
		}
	}

	$meta->set_sync_status( 101, 'failed' );
	$maintenance = new \SooCool\WooCommerce\Rest\MaintenanceController( $actions );
	$response    = $maintenance->resync_failed();

	if ( 1 !== $actions->send_calls || 1 !== $actions->recovery_calls || 0 !== $actions->resync_calls ) {
		fwrite( STDERR, 'Failed-order maintenance must use the canonical recovery queue without reopening the legacy resync queue.' . PHP_EOL );
		exit( 1 );
	}

	if ( ! is_array( $response->data ) || 1 !== (int) ( $response->data['queued'] ?? 0 ) || 0 !== (int) ( $response->data['manual'] ?? 0 ) ) {
		fwrite( STDERR, 'Maintenance response did not report the queued recovery correctly.' . PHP_EOL );
		exit( 1 );
	}

	$meta->set_sync_status( 101, 'failed' );
	$actions->recovery_result = \SooCool\WooCommerce\WooCommerce\OrderActions::QUEUE_MANUAL;
	$response = $maintenance->resync_failed();

	if ( 2 !== $actions->recovery_calls ) {
		fwrite( STDERR, 'Maintenance did not use the recovery path for the linked-order case.' . PHP_EOL );
		exit( 1 );
	}

	if (
		! is_array( $response->data )
		|| 1 !== (int) ( $response->data['manual'] ?? 0 )
		|| 0 !== (int) ( $response->data['duplicates'] ?? 0 )
		|| 0 !== (int) ( $response->data['queued'] ?? 0 )
	) {
		fwrite( STDERR, 'Linked failed orders must remain classified as manual recovery, not queue duplicates.' . PHP_EOL );
		exit( 1 );
	}

	echo "SooCool sync scheduling regression checks passed.\n";
}
