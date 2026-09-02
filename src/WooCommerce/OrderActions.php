<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Admin\OrderActionConfirmScript;
use SooCool\WooCommerce\Admin\OrderMetaBox;
use SooCool\WooCommerce\Domain\OrderSyncCoordinator;
use SooCool\WooCommerce\Infrastructure\ActionSchedulerRuntime;
use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\Infrastructure\ProviderContext;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderActions {

	private readonly OrderDeliveryEligibility $delivery_eligibility;
	private readonly ProviderContext $provider_context;

	public const SYNC_HOOK                = 'soocool_sync_order';
	public const RESYNC_HOOK              = 'soocool_resync_order';
	private const LEGACY_CANCEL_HOOK      = 'soocool_cancel_order';
	public const WATCHDOG_HOOK            = 'soocool_sync_watchdog';
	public const SCHEDULER_GROUP          = 'soocool';
	public const QUEUE_SCHEDULED          = 'scheduled';
	public const QUEUE_DUPLICATE          = 'duplicate';
	public const QUEUE_FAILED             = 'failed';
	public const QUEUE_MANUAL             = 'manual';
	public const MANUAL_SYNC_AJAX_ACTION   = 'soocool_manual_sync_order';
	public const RECONCILE_AJAX_ACTION     = 'soocool_reconcile_order_link';
	public const MANUAL_SYNC_NONCE_ACTION  = 'soocool_manual_sync_order_';

	private const RETRY_DELAYS_SECONDS   = array( 60, 300, 900 );
	private const WATCHDOG_DELAY_SECONDS = 120;
	private const MAX_PROVIDER_RETRY_DELAY_SECONDS = 86400;
	private const SCHEDULER_PRIORITY      = 1;

	public function __construct(
		private readonly OrderMeta $meta,
		private readonly OrderMetaBox $meta_box,
		private readonly OrderActionConfirmScript $confirm_script,
		private readonly OrderSyncCoordinator $coordinator,
		?ProviderContext $provider_context = null,
		?OrderDeliveryEligibility $delivery_eligibility = null
	) {
		$this->provider_context     = $provider_context ?? new ProviderContext();
		$this->delivery_eligibility = $delivery_eligibility ?? new OrderDeliveryEligibility();
	}

	public static function unschedule_all(): void {
		if ( ActionSchedulerRuntime::is_ready() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::SCHEDULER_GROUP );
		}

		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::SYNC_HOOK );
			wp_unschedule_hook( self::RESYNC_HOOK );
			wp_unschedule_hook( self::LEGACY_CANCEL_HOOK );
			wp_unschedule_hook( self::WATCHDOG_HOOK );
			wp_unschedule_hook( OrderEmailLabels::PREFETCH_HOOK );
			wp_unschedule_hook( OrderEmailLabels::CLEANUP_HOOK );
		}
	}

	public static function unschedule_legacy_remote_cancel(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::LEGACY_CANCEL_HOOK );
		}

		$action_scheduler_ready = ActionSchedulerRuntime::is_ready();
		if ( $action_scheduler_ready && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::LEGACY_CANCEL_HOOK, array(), self::SCHEDULER_GROUP );
		}
	}

	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_soocool_send_to_soocool', array( $this, 'send_to_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_update_at_soocool', array( $this, 'update_at_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_refresh_from_soocool', array( $this, 'refresh_from_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_cancel_at_soocool', array( $this, 'cancel_at_soocool' ) );
		add_action( 'add_meta_boxes', array( $this->meta_box, 'register' ) );
		add_action( 'admin_post_soocool_update_delivery_date', array( $this->meta_box, 'handle_update_delivery_date' ) );
		add_action( 'admin_notices', array( $this->meta_box, 'render_delivery_date_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this->confirm_script, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::MANUAL_SYNC_AJAX_ACTION, array( $this, 'handle_manual_sync' ) );
		add_action( 'wp_ajax_' . self::RECONCILE_AJAX_ACTION, array( $this, 'handle_reconcile_link' ) );
		add_action( self::SYNC_HOOK, array( $this, 'send_order_by_id' ), 10, 3 );
		add_action( self::RESYNC_HOOK, array( $this, 'resync_order_by_id' ), 10, 3 );
		add_action( self::WATCHDOG_HOOK, array( $this, 'run_sync_watchdog' ), 10, 4 );
	}

	public function send_order_by_id( int $order_id, int $attempt = 0, string $context_fingerprint = '' ): void {
		$this->run_scheduled_sync( $order_id, false, $attempt, self::SYNC_HOOK, $context_fingerprint );
	}

	public function resync_order_by_id( int $order_id, int $attempt = 0, string $context_fingerprint = '' ): void {
		$this->run_scheduled_sync( $order_id, true, $attempt, self::RESYNC_HOOK, $context_fingerprint );
	}

	public function schedule_send_to_soocool( int $order_id ): string {
		return $this->schedule_initial_order_action( self::SYNC_HOOK, $order_id, false );
	}

	public function schedule_failed_order_recovery( int $order_id ): string {
		return $this->schedule_initial_order_action( self::SYNC_HOOK, $order_id, true );
	}

	public function schedule_resync_order( int $order_id ): string {
		return $this->schedule_initial_order_action( self::RESYNC_HOOK, $order_id, true );
	}

	private function schedule_initial_order_action( string $hook, int $order_id, bool $manual_when_linked ): string {
		$order_id = NumericIdentifier::positive( $order_id ) ?? 0;
		$order    = 0 < $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return self::QUEUE_FAILED;
		}

		if ( $this->is_synced_in_current_provider( $order ) ) {
			$this->meta->restore_linked_status( $order );
			return $manual_when_linked ? self::QUEUE_MANUAL : self::QUEUE_DUPLICATE;
		}

		$result = $this->schedule_order_action( $hook, $order_id, 0, 0, $this->current_sync_context_fingerprint() );
		if ( in_array( $result, array( self::QUEUE_SCHEDULED, self::QUEUE_DUPLICATE ), true ) ) {
			$this->meta->save_pending( $order );
			return $result;
		}

		$this->meta->save_error(
			$order,
			__( 'SooCool-synchronisatie kon niet op de achtergrond worden ingepland. Gebruik de knop “Synchroniseer nu met SooCool” of controleer WooCommerce Action Scheduler en WP-Cron.', 'soocool-for-woocommerce' )
		);
		return $result;
	}

	private function run_scheduled_sync( int $order_id, bool $force, int $attempt, string $hook, string $context_fingerprint = '' ): void {
		$order_id = NumericIdentifier::positive( $order_id ) ?? 0;
		$order    = 0 < $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$attempt             = max( 0, $attempt );
		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		if ( '' !== $context_fingerprint && ! $this->sync_context_is_current( $context_fingerprint ) ) {
			$this->clear_watchdog( $hook, $order_id, $attempt, $context_fingerprint );
			if ( $this->is_synced_in_current_provider( $order ) ) {
				return;
			}

			$message = __( 'SooCool-synchronisatie overgeslagen omdat de API-omgeving of API-inloggegevens zijn gewijzigd nadat de taak werd ingepland. Plan de synchronisatie opnieuw.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
			return;
		}

		if ( $this->is_synced_in_current_provider( $order ) ) {
			$this->clear_watchdog( $hook, $order_id, $attempt, $context_fingerprint );
			$this->meta->restore_linked_status( $order );
			return;
		}

		if ( ! $this->delivery_eligibility->requires_delivery( $order ) ) {
			$this->clear_watchdog( $hook, $order_id, $attempt, $context_fingerprint );
			$this->meta->clear_pending( $order );
			$order->add_order_note( __( 'SooCool-synchronisatie overgeslagen omdat deze order bij uitvoering geen transport meer vereist.', 'soocool-for-woocommerce' ) );
			return;
		}

		$result = $this->sync_order_with_note( $order, $force, false );
		$this->clear_watchdog( $hook, $order_id, $attempt, $context_fingerprint );
		if ( (bool) ( $result['success'] ?? false ) ) {
			return;
		}

		$message   = sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-synchronisatie mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) );
		$retryable = (bool) ( $result['retryable'] ?? false );
		if ( ! $retryable || $attempt >= count( self::RETRY_DELAYS_SECONDS ) ) {
			$order->add_order_note( $message );
			return;
		}

		$delay        = max( $this->retry_delay( $attempt ), $this->provider_retry_delay( $result ) );
		$next_attempt = $attempt + 1;
		$queue_result = $this->schedule_order_action( $hook, $order_id, $next_attempt, $delay, $context_fingerprint );
		if ( in_array( $queue_result, array( self::QUEUE_SCHEDULED, self::QUEUE_DUPLICATE ), true ) ) {
			$this->meta->save_retry_pending( $order, $message );
			if ( self::QUEUE_DUPLICATE === $queue_result ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: retry number, 2: total retry count, 3: sanitized API error. */
						__( 'Automatische SooCool-herpoging %1$d van %2$d stond al ingepland. Laatste fout: %3$s', 'soocool-for-woocommerce' ),
						$next_attempt,
						count( self::RETRY_DELAYS_SECONDS ),
						$message
					)
				);
				return;
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: retry number, 2: total retry count, 3: delay in seconds, 4: sanitized API error. */
					__( 'Automatische SooCool-herpoging %1$d van %2$d is ingepland over ongeveer %3$d seconden. Laatste fout: %4$s', 'soocool-for-woocommerce' ),
					$next_attempt,
					count( self::RETRY_DELAYS_SECONDS ),
					$delay,
					$message
				)
			);
			return;
		}

		$order->add_order_note(
			$message . ' ' . __( 'De automatische SooCool-herpoging kon niet worden ingepland.', 'soocool-for-woocommerce' )
		);
	}

	private function retry_delay( int $attempt ): int {
		$base_delay = self::RETRY_DELAYS_SECONDS[ $attempt ] ?? 0;
		if ( 0 >= $base_delay || ! function_exists( 'wp_rand' ) ) {
			return max( 0, $base_delay );
		}

		$jitter = max( 1, min( 30, intdiv( $base_delay, 10 ) ) );
		return $base_delay + wp_rand( 0, $jitter );
	}


	/** @param array<string, mixed> $result */
	private function provider_retry_delay( array $result ): int {
		$retry_after = isset( $result['retry_after_seconds'] ) && is_numeric( $result['retry_after_seconds'] ) ? (int) $result['retry_after_seconds'] : 0;

		return max( 0, min( self::MAX_PROVIDER_RETRY_DELAY_SECONDS, $retry_after ) );
	}

	private function schedule_order_action( string $hook, int $order_id, int $attempt = 0, int $delay = 0, string $context_fingerprint = '' ): string {
		$order_id = NumericIdentifier::positive( $order_id ) ?? 0;
		if ( 0 === $order_id ) {
			return self::QUEUE_FAILED;
		}

		$attempt             = max( 0, $attempt );
		$delay               = max( 0, $delay );
		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		if ( '' === $context_fingerprint ) {
			$context_fingerprint = $this->current_sync_context_fingerprint();
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $context_fingerprint ) ) {
			return self::QUEUE_FAILED;
		}
		$args = array( $order_id, $attempt, $context_fingerprint );
		$action_scheduler_ready = ActionSchedulerRuntime::is_ready();

		if ( $action_scheduler_ready && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::SCHEDULER_GROUP ) ) {
			$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
			return self::QUEUE_DUPLICATE;
		}

		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
			return self::QUEUE_DUPLICATE;
		}

		if ( $action_scheduler_ready && 0 < $delay && function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action( time() + $delay, $hook, $args, self::SCHEDULER_GROUP, true, self::SCHEDULER_PRIORITY );
			if ( $action_id ) {
				$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
				return self::QUEUE_SCHEDULED;
			}

			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::SCHEDULER_GROUP ) ) {
				$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
				return self::QUEUE_DUPLICATE;
			}
		}

		if ( $action_scheduler_ready && 0 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( $hook, $args, self::SCHEDULER_GROUP, true, self::SCHEDULER_PRIORITY );
			if ( $action_id ) {
				$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, 0, $context_fingerprint );
				return self::QUEUE_SCHEDULED;
			}

			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::SCHEDULER_GROUP ) ) {
				$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, 0, $context_fingerprint );
				return self::QUEUE_DUPLICATE;
			}
		}

		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
			return self::QUEUE_DUPLICATE;
		}

		$timestamp = time() + max( 10, $delay );
		if ( ! wp_schedule_single_event( $timestamp, $hook, $args ) ) {
			if ( false !== wp_next_scheduled( $hook, $args ) ) {
				$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
				return self::QUEUE_DUPLICATE;
			}

			return self::QUEUE_FAILED;
		}

		$this->maybe_schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
		return self::QUEUE_SCHEDULED;
	}

	public function run_sync_watchdog( int $order_id, string $hook = self::SYNC_HOOK, int $attempt = 0, string $context_fingerprint = '' ): void {
		$order_id = NumericIdentifier::positive( $order_id ) ?? 0;
		$order    = 0 < $order_id ? wc_get_order( $order_id ) : null;
		$hook     = self::RESYNC_HOOK === $hook ? self::RESYNC_HOOK : self::SYNC_HOOK;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( self::SYNC_HOOK === $hook && $this->is_synced_in_current_provider( $order ) ) {
			$this->meta->restore_linked_status( $order );
			return;
		}

		$attempt             = max( 0, $attempt );
		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		$args                = '' !== $context_fingerprint ? array( $order_id, $attempt, $context_fingerprint ) : ( 0 < $attempt ? array( $order_id, $attempt ) : array( $order_id ) );

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( $hook, $args );
		}

		if ( ActionSchedulerRuntime::is_ready() && function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( $hook, $args, self::SCHEDULER_GROUP );
		}

		$this->run_scheduled_sync( $order_id, self::RESYNC_HOOK === $hook, $attempt, $hook, $context_fingerprint );
	}

	private function maybe_schedule_watchdog( string $hook, int $order_id, int $attempt, int $delay, string $context_fingerprint = '' ): void {
		if ( ! in_array( $hook, array( self::SYNC_HOOK, self::RESYNC_HOOK ), true ) ) {
			return;
		}

		$this->schedule_watchdog( $hook, $order_id, $attempt, $delay, $context_fingerprint );
	}

	private function schedule_watchdog( string $hook, int $order_id, int $attempt, int $delay, string $context_fingerprint = '' ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		$args                = array( $order_id, $hook, max( 0, $attempt ) );
		if ( '' !== $context_fingerprint ) {
			$args[] = $context_fingerprint;
		}
		if ( false !== wp_next_scheduled( self::WATCHDOG_HOOK, $args ) ) {
			return;
		}

		$timestamp = time() + max( self::WATCHDOG_DELAY_SECONDS, max( 0, $delay ) + self::WATCHDOG_DELAY_SECONDS );
		wp_schedule_single_event( $timestamp, self::WATCHDOG_HOOK, $args );
	}

	private function clear_watchdog( string $hook, int $order_id, int $attempt, string $context_fingerprint = '' ): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		$args                = array( $order_id, $hook, max( 0, $attempt ) );
		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		if ( '' !== $context_fingerprint ) {
			$args[] = $context_fingerprint;
		}
		wp_clear_scheduled_hook( self::WATCHDOG_HOOK, $args );
	}

	private function is_synced_in_current_provider( WC_Order $order ): bool {
		if ( ! $this->meta->is_synced( $order ) ) {
			return false;
		}

		$provider_context = $this->meta->get_provider_context( $order );
		return '' !== $provider_context && $this->provider_context->matches_provider( $provider_context );
	}

	private function current_sync_context_fingerprint(): string {
		return $this->provider_context->execution_fingerprint( 'order-sync' );
	}

	private function sync_context_is_current( string $context_fingerprint ): bool {
		return $this->provider_context->matches_execution( $context_fingerprint, 'order-sync' );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_order_action( array $actions ): array {
		$actions['soocool_send_to_soocool']      = __( 'SooCool: order aanmaken/versturen', 'soocool-for-woocommerce' );
		$actions['soocool_refresh_from_soocool'] = __( 'SooCool: status vernieuwen', 'soocool-for-woocommerce' );
		$actions['soocool_update_at_soocool']    = __( 'SooCool: bestaande order bijwerken', 'soocool-for-woocommerce' );
		$actions['soocool_cancel_at_soocool']    = __( 'SooCool: order annuleren', 'soocool-for-woocommerce' );
		return $actions;
	}

	public function send_to_soocool( WC_Order $order, bool $force = false ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$this->sync_order_with_note( $order, $force );
	}

	public function handle_manual_sync(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Order ID is needed to build the order-specific nonce action and is sanitized before the nonce check.
		$order_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? ( NumericIdentifier::positive( wp_unslash( (string) $_POST['order_id'] ) ) ?? 0 ) : 0;
		if ( 0 >= $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Ongeldige order.', 'soocool-for-woocommerce' ) ), 400 );
		}

		check_ajax_referer( self::MANUAL_SYNC_NONCE_ACTION . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Je mag deze order niet synchroniseren.', 'soocool-for-woocommerce' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order niet gevonden.', 'soocool-for-woocommerce' ) ), 404 );
		}

		$result  = $this->sync_order_with_note( $order, false );
		$success = (bool) ( $result['success'] ?? false );
		$message = sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-synchronisatie mislukt.', 'soocool-for-woocommerce' ) ) );
		$notice  = ! empty( $result['existing'] ) ? 'sync_existing' : ( $success ? 'sync_success' : 'sync_failed' );

		$data = array(
			'message' => $message,
			'notice'  => $notice,
		);

		if ( $success ) {
			wp_send_json_success( $data );
		}

		$status = isset( $result['status'] ) ? (int) $result['status'] : 400;
		wp_send_json_error( $data, $status >= 400 && $status <= 599 ? $status : 400 );
	}

	public function handle_reconcile_link(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Order ID is needed to build the order-specific nonce action and is sanitized before the nonce check.
		$order_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? ( NumericIdentifier::positive( wp_unslash( (string) $_POST['order_id'] ) ) ?? 0 ) : 0;
		if ( 0 >= $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Ongeldige order.', 'soocool-for-woocommerce' ) ), 400 );
		}

		check_ajax_referer( self::MANUAL_SYNC_NONCE_ACTION . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Je mag deze order niet synchroniseren.', 'soocool-for-woocommerce' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order niet gevonden.', 'soocool-for-woocommerce' ) ), 404 );
		}

		// Only repair a stale local link. A genuinely unsynchronised order is never sent from this endpoint.
		if ( ! $this->meta->is_synced( $order ) ) {
			wp_send_json_success( array( 'reconciled' => false ) );
		}

		if ( $this->is_synced_in_current_provider( $order ) ) {
			wp_send_json_success( array( 'reconciled' => true ) );
		}

		$result = $this->coordinator->refresh_order( $order );
		if ( method_exists( $order, 'read_meta_data' ) ) {
			$order->read_meta_data( true );
		}

		$success    = (bool) ( $result['success'] ?? false );
		$reconciled = $this->is_synced_in_current_provider( $order );
		$message    = sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-statusupdate mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) );

		if ( $success && $reconciled ) {
			wp_send_json_success(
				array(
					'reconciled' => true,
					'message'    => $message,
				)
			);
		}

		$status = isset( $result['status'] ) ? (int) $result['status'] : 409;
		wp_send_json_error(
			array(
				'reconciled' => false,
				'message'    => $message,
			),
			$status >= 400 && $status <= 599 ? $status : 409
		);
	}

	public function update_at_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->update_order( $order );
		$order->add_order_note(
			(bool) ( $result['success'] ?? false )
				? __( 'Resultaat: bestaande SooCool-order bijgewerkt. Volgende stap: vernieuw de status of controleer het SooCool-dashboard als fulfilment al is gestart.', 'soocool-for-woocommerce' )
				: sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-update mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) )
		);
	}

	public function refresh_from_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->refresh_order( $order );
		$order->add_order_note(
			(bool) ( $result['success'] ?? false )
				? sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-status vernieuwd.', 'soocool-for-woocommerce' ) ) )
				: sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-statusupdate mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) )
		);
	}

	public function cancel_at_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->cancel_order( $order );
		$order->add_order_note(
			(bool) ( $result['success'] ?? false )
				? __( 'Resultaat: SooCool-order geannuleerd. Volgende stap: controleer de fulfilmentstatus in SooCool voordat je terugbetaalt of de WooCommerce-orderstatus wijzigt.', 'soocool-for-woocommerce' )
				: sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-annulering mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) )
		);
	}

	/** @return array<string, mixed> */
	private function sync_order_with_note( WC_Order $order, bool $force, bool $add_failure_note = true ): array {
		$result = $this->coordinator->sync_order( $order, $force );
		if ( (bool) ( $result['success'] ?? false ) ) {
			$note = ! empty( $result['existing'] )
				? __( 'Bestaande SooCool-order gevonden op orderreferentie. WooCommerce-order gekoppeld zonder dubbele SooCool-order aan te maken.', 'soocool-for-woocommerce' )
				: __( 'Resultaat: order naar SooCool verstuurd. Volgende stap: download het label of wacht op de track & trace-webhook.', 'soocool-for-woocommerce' );
			$order->add_order_note( $note );
			return $result;
		}

		if ( $add_failure_note ) {
			$order->add_order_note( sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-synchronisatie mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) ) );
		}
		return $result;
	}

	private function authorize_manual_order_action( WC_Order $order ): bool {
		$current_filter = function_exists( 'current_filter' ) ? (string) current_filter() : '';
		if ( ! str_starts_with( $current_filter, 'woocommerce_order_action_soocool_' ) ) {
			return true;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$order->add_order_note( __( 'SooCool-actie overgeslagen omdat de huidige gebruiker WooCommerce niet mag beheren.', 'soocool-for-woocommerce' ) );
		return false;
	}
}
