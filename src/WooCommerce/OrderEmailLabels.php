<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Api\ApiTransport;
use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Infrastructure\ActionSchedulerRuntime;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\ProviderContext;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderEmailLabels {

	private readonly ProviderContext $provider_context;

	public const PREFETCH_HOOK = 'soocool_prefetch_email_labels';
	public const CLEANUP_HOOK  = 'soocool_cleanup_email_labels';

	/** @var array<int, string> */
	private const ADMIN_EMAIL_IDS = array( 'new_order' );
	private const CACHE_TTL       = 21600;

	/** @var array<int, string> */
	private array $temporary_files = array();
	private bool $prefetching = false;

	public function __construct(
		private readonly ShippingLabelService $labels,
		private readonly OptionRepository $options,
		private readonly OrderMeta $meta,
		private readonly Logger $logger,
		?ProviderContext $provider_context = null
	) {
		$this->provider_context = $provider_context ?? new ProviderContext();
	}

	public function register(): void {
		add_filter( 'woocommerce_email_attachments', array( $this, 'add_admin_label_attachments' ), 10, 4 );
		add_action( 'soocool_order_synced', array( $this, 'schedule_prefetch' ) );
		add_action( self::PREFETCH_HOOK, array( $this, 'prefetch_for_order' ), 10, 3 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_cached_files' ), 10, 3 );
		add_action( 'wp_mail_succeeded', array( $this, 'cleanup_temporary_files' ) );
		add_action( 'wp_mail_failed', array( $this, 'cleanup_temporary_files' ) );
		add_action( 'shutdown', array( $this, 'cleanup_temporary_files' ) );
	}

	public function schedule_prefetch( WC_Order $order ): void {
		if ( $this->prefetching ) {
			return;
		}

		$order_id = (int) $order->get_id();
		if ( 0 >= $order_id ) {
			return;
		}

		$context_fingerprint    = $this->current_prefetch_context_fingerprint();
		$args                   = array( $order_id, 0, $context_fingerprint );
		$action_scheduler_ready = ActionSchedulerRuntime::is_ready();
		if ( $action_scheduler_ready && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::PREFETCH_HOOK, $args, OrderActions::SCHEDULER_GROUP ) ) {
			return;
		}
		if ( false !== wp_next_scheduled( self::PREFETCH_HOOK, $args ) ) {
			return;
		}
		if ( $action_scheduler_ready && function_exists( 'as_enqueue_async_action' ) && as_enqueue_async_action( self::PREFETCH_HOOK, $args, OrderActions::SCHEDULER_GROUP, true ) ) {
			return;
		}
		wp_schedule_single_event( time() + 10, self::PREFETCH_HOOK, $args );
	}

	public function prefetch_for_order( int $order_id, int $attempt = 0, string $context_fingerprint = '' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		if ( '' !== $context_fingerprint && ! $this->prefetch_context_is_current( $context_fingerprint ) ) {
			$this->logger->info( 'SooCool email label prefetch skipped because its provider context is stale.', array( 'wcOrderId' => $order_id, 'attempt' => $attempt ) );
			return;
		}
		if ( '' === $context_fingerprint ) {
			$context_fingerprint = $this->current_prefetch_context_fingerprint();
		}

		$output            = $this->label_output();
		$paths             = array();
		$this->prefetching = true;
		try {
			if ( '' !== $this->meta->get_soocool_order_id( $order ) ) {
				$paths[] = $this->labels->download_label( $order, $output );
			}
			$good_ids = $this->meta->get_good_ids( $order );
			if ( array() !== $good_ids ) {
				$paths[] = 1 === count( $good_ids )
					? $this->labels->download_good_label( $order, $good_ids[0], $output )
					: $this->labels->download_bulk_good_labels_for_orders( array( $order ), $good_ids, $output );
			}
		} catch ( \Throwable $exception ) {
			$this->delete_paths( $paths );
			$this->logger->error(
				'SooCool email label prefetch failed.',
				array( 'wcOrderId' => $order_id, 'attempt' => $attempt, 'error' => $exception->getMessage() )
			);
			if ( $attempt < 2 ) {
				$provider_delay = $exception instanceof ApiException ? $exception->retry_after_seconds() : 0;
				$this->schedule_prefetch_retry( $order_id, $attempt + 1, $context_fingerprint, $provider_delay );
			}
			return;
		} finally {
			$this->prefetching = false;
		}

		$downloaded_paths = $paths;
		$paths            = array_values( array_filter( $downloaded_paths, array( $this, 'is_valid_cached_pdf' ) ) );
		$this->delete_paths( array_values( array_diff( $downloaded_paths, $paths ) ) );
		if ( array() === $paths ) {
			return;
		}
		$this->cleanup_current_cache( $order_id );
		$generation = wp_generate_uuid4();
		$cache      = array(
			'generation'          => $generation,
			'context_fingerprint' => $context_fingerprint,
			'paths'               => $paths,
		);
		if ( ! set_transient( $this->cache_key( $order_id ), $cache, self::CACHE_TTL ) ) {
			$this->delete_paths( $paths );
			$this->logger->error( 'SooCool email label cache could not be persisted.', array( 'wcOrderId' => $order_id ) );
			return;
		}
		if ( ! wp_schedule_single_event( time() + self::CACHE_TTL, self::CLEANUP_HOOK, array( $order_id, $paths, $generation ) ) ) {
			delete_transient( $this->cache_key( $order_id ) );
			$this->delete_paths( $paths );
			$this->logger->error( 'SooCool email label cleanup could not be scheduled.', array( 'wcOrderId' => $order_id ) );
		}
	}

	/** @param array<int, string> $attachments @return array<int, string> */
	public function add_admin_label_attachments( array $attachments, string $email_id, mixed $object = null, mixed $email = null ): array {
		if ( ! $object instanceof WC_Order || ! $this->should_attach_to_email( $email_id, $object, $email ) ) {
			return $attachments;
		}

		$cached = $this->cache_payload( get_transient( $this->cache_key( (int) $object->get_id() ) ) );
		if ( array() !== $cached['paths'] && ( '' === $cached['context_fingerprint'] || ! $this->prefetch_context_is_current( $cached['context_fingerprint'] ) ) ) {
			$this->delete_paths( $cached['paths'] );
			delete_transient( $this->cache_key( (int) $object->get_id() ) );
			$this->logger->info( 'SooCool admin email label cache discarded because its provider context is missing or stale.', array( 'wcOrderId' => $object->get_id() ) );
			return $attachments;
		}

		$paths = array_values( array_filter( $cached['paths'], array( $this, 'is_valid_cached_pdf' ) ) );
		$this->delete_paths( array_values( array_diff( $cached['paths'], $paths ) ) );
		if ( array() === $paths ) {
			delete_transient( $this->cache_key( (int) $object->get_id() ) );
			$this->logger->info( 'SooCool admin email sent without labels because the asynchronous cache was empty.', array( 'wcOrderId' => $object->get_id() ) );
			return $attachments;
		}

		foreach ( $paths as $path ) {
			$attachments[]         = $path;
			$this->temporary_files[] = $path;
		}
		delete_transient( $this->cache_key( (int) $object->get_id() ) );
		return array_values( array_unique( $attachments ) );
	}

	/** @param array<int, mixed> $expected_paths */
	public function cleanup_cached_files( int $order_id, array $expected_paths = array(), string $expected_generation = '' ): void {
		$expected_paths = $this->valid_path_strings( $expected_paths );
		if ( array() === $expected_paths ) {
			return;
		}

		$cached            = $this->cache_payload( get_transient( $this->cache_key( $order_id ) ) );
		$cached_paths      = $cached['paths'];
		$cached_generation = $cached['generation'];
		$same_generation   = '' !== $expected_generation
			? '' !== $cached_generation && hash_equals( $cached_generation, $expected_generation )
			: '' === $cached_generation;
		if ( $same_generation && $cached_paths === $expected_paths ) {
			$this->delete_paths( $expected_paths );
			delete_transient( $this->cache_key( $order_id ) );
			return;
		}

		// A stale cleanup task may outlive a newer cache generation. Never delete a
		// path that is currently referenced by that newer cache, even if a temporary
		// filename happens to be reused.
		$current_paths = array_fill_keys( $cached_paths, true );
		$this->delete_paths(
			array_values(
				array_filter(
					$expected_paths,
					static fn ( string $path ): bool => ! isset( $current_paths[ $path ] )
				)
			)
		);
	}

	private function cleanup_current_cache( int $order_id ): void {
		$cached = $this->cache_payload( get_transient( $this->cache_key( $order_id ) ) );
		$this->delete_paths( $cached['paths'] );
		delete_transient( $this->cache_key( $order_id ) );
	}

	/** @return array{generation: string, context_fingerprint: string, paths: array<int, string>} */
	private function cache_payload( mixed $cached ): array {
		if ( ! is_array( $cached ) ) {
			return array( 'generation' => '', 'context_fingerprint' => '', 'paths' => array() );
		}

		if ( isset( $cached['paths'] ) && is_array( $cached['paths'] ) ) {
			$generation = isset( $cached['generation'] ) && is_scalar( $cached['generation'] )
				? sanitize_text_field( (string) $cached['generation'] )
				: '';
			$context_fingerprint = isset( $cached['context_fingerprint'] ) && is_scalar( $cached['context_fingerprint'] )
				? strtolower( trim( (string) $cached['context_fingerprint'] ) )
				: '';
			$context_fingerprint = 1 === preg_match( '/^[a-f0-9]{64}$/', $context_fingerprint ) ? $context_fingerprint : '';
			return array(
				'generation'          => $generation,
				'context_fingerprint' => $context_fingerprint,
				'paths'               => $this->valid_path_strings( $cached['paths'] ),
			);
		}

		// Backwards compatibility for transients created before cache generations were
		// stored explicitly. Existing two-argument cleanup events must remain safe after
		// updating the plugin.
		return array( 'generation' => '', 'context_fingerprint' => '', 'paths' => $this->valid_path_strings( $cached ) );
	}

	/** @param array<int, mixed> $paths @return array<int, string> */
	private function valid_path_strings( array $paths ): array {
		return array_values( array_filter( $paths, static fn ( mixed $path ): bool => is_string( $path ) && '' !== $path ) );
	}

	public function cleanup_temporary_files(): void {
		$this->delete_paths( $this->temporary_files );
		$this->temporary_files = array();
	}

	private function schedule_prefetch_retry( int $order_id, int $attempt, string $context_fingerprint, int $provider_delay = 0 ): void {
		$context_fingerprint = strtolower( trim( $context_fingerprint ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $context_fingerprint ) ) {
			return;
		}

		$args           = array( $order_id, $attempt, $context_fingerprint );
		$local_delay    = 60 * ( 2 ** max( 0, $attempt - 1 ) );
		$provider_delay = max( 0, min( 86400, $provider_delay ) );
		$delay          = max( $local_delay, $provider_delay );
		if ( false !== wp_next_scheduled( self::PREFETCH_HOOK, $args ) ) {
			return;
		}
		if ( ActionSchedulerRuntime::is_ready() && function_exists( 'as_schedule_single_action' ) && as_schedule_single_action( time() + $delay, self::PREFETCH_HOOK, $args, OrderActions::SCHEDULER_GROUP, true ) ) {
			return;
		}
		wp_schedule_single_event( time() + $delay, self::PREFETCH_HOOK, $args );
	}

	private function should_attach_to_email( string $email_id, WC_Order $order, mixed $email ): bool {
		if ( is_object( $email ) && method_exists( $email, 'is_customer_email' ) && $email->is_customer_email() ) {
			return false;
		}
		$email_ids = apply_filters( 'soocool_admin_label_email_ids', self::ADMIN_EMAIL_IDS, $order );
		$email_ids = is_array( $email_ids ) ? $email_ids : self::ADMIN_EMAIL_IDS;
		$allowed   = array_values( array_unique( array_filter( array_map( static fn ( mixed $value ): string => is_scalar( $value ) ? sanitize_key( (string) $value ) : '', $email_ids ) ) ) );
		return in_array( sanitize_key( $email_id ), $allowed, true ) && (bool) apply_filters( 'soocool_attach_labels_to_admin_emails', true, $order, $email_id );
	}

	private function current_prefetch_context_fingerprint(): string {
		return $this->provider_context->execution_fingerprint( 'email-label-' . $this->label_output() );
	}

	private function prefetch_context_is_current( string $context_fingerprint ): bool {
		return $this->provider_context->matches_execution( $context_fingerprint, 'email-label-' . $this->label_output() );
	}

	private function label_output(): string {
		$settings = $this->options->all();
		return 'collated_a4' === (string) ( $settings['label_output'] ?? '' ) ? 'collated_a4' : 'a6';
	}

	private function cache_key( int $order_id ): string {
		return 'soocool_email_labels_' . absint( $order_id );
	}

	private function is_valid_cached_pdf( mixed $file ): bool {
		if ( ! is_string( $file ) || '' === $file || is_link( $file ) ) {
			return false;
		}

		$temp_dir = realpath( get_temp_dir() );
		$path     = realpath( $file );
		if ( false === $temp_dir || false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$temp_dir = trailingslashit( $temp_dir );
		if ( ! str_starts_with( trailingslashit( dirname( $path ) ), $temp_dir ) ) {
			return false;
		}

		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}

		$valid = false;
		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
			if ( $size >= 8 && $size <= ApiTransport::MAX_PDF_RESPONSE_BYTES && '%PDF-' === fread( $handle, 5 ) ) {
				fseek( $handle, max( 0, $size - 1024 ) );
				$tail  = stream_get_contents( $handle );
				$valid = is_string( $tail ) && false !== strpos( $tail, '%%EOF' );
			}
		} finally {
			fclose( $handle );
		}

		return $valid;
	}

	/** @param array<int, mixed> $paths */
	private function delete_paths( array $paths ): void {
		$temp_dir = realpath( get_temp_dir() );
		$temp_dir = is_string( $temp_dir ) ? trailingslashit( $temp_dir ) : false;
		foreach ( $paths as $file ) {
			if ( ! is_string( $file ) || '' === $file || is_link( $file ) ) {
				continue;
			}
			$path = realpath( $file );
			if ( false === $temp_dir || false === $path || ! str_starts_with( trailingslashit( dirname( $path ) ), $temp_dir ) || ! is_file( $path ) ) {
				continue;
			}
			wp_delete_file( $path );
		}
	}
}
