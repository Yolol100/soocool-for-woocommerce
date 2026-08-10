<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderEmailLabels {

	public const PREFETCH_HOOK = 'soocool_prefetch_email_labels';
	public const CLEANUP_HOOK  = 'soocool_cleanup_email_labels';

	/** @var array<int, string> */
	private const ADMIN_EMAIL_IDS = array( 'new_order' );
	private const CACHE_TTL       = 21600;

	/** @var array<int, string> */
	private array $temporary_files = array();
	private bool $prefetching = false;

	public function __construct( private readonly ShippingLabelService $labels, private readonly OptionRepository $options, private readonly OrderMeta $meta, private readonly Logger $logger ) {}

	public function register(): void {
		add_filter( 'woocommerce_email_attachments', array( $this, 'add_admin_label_attachments' ), 10, 4 );
		add_action( 'soocool_order_synced', array( $this, 'schedule_prefetch' ) );
		add_action( self::PREFETCH_HOOK, array( $this, 'prefetch_for_order' ), 10, 2 );
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

		$args = array( $order_id, 0 );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::PREFETCH_HOOK, $args, OrderActions::SCHEDULER_GROUP ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) && as_enqueue_async_action( self::PREFETCH_HOOK, $args, OrderActions::SCHEDULER_GROUP, true ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::PREFETCH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 10, self::PREFETCH_HOOK, $args );
		}
	}

	public function prefetch_for_order( int $order_id, int $attempt = 0 ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
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
					: $this->labels->download_bulk_good_labels( $good_ids, $output );
			}
		} catch ( \Throwable $exception ) {
			$this->delete_paths( $paths );
			$this->logger->error(
				'SooCool email label prefetch failed.',
				array( 'wcOrderId' => $order_id, 'attempt' => $attempt, 'error' => $exception->getMessage() )
			);
			if ( $attempt < 2 ) {
				$this->schedule_prefetch_retry( $order_id, $attempt + 1 );
			}
			return;
		} finally {
			$this->prefetching = false;
		}

		$paths = array_values( array_filter( $paths, array( $this, 'is_valid_cached_pdf' ) ) );
		if ( array() === $paths ) {
			return;
		}
		$this->cleanup_current_cache( $order_id );
		$generation = wp_generate_uuid4();
		$cache      = array(
			'generation' => $generation,
			'paths'      => $paths,
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
		$paths  = array_values( array_filter( $cached['paths'], array( $this, 'is_valid_cached_pdf' ) ) );
		if ( array() === $paths ) {
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

	/** @return array{generation: string, paths: array<int, string>} */
	private function cache_payload( mixed $cached ): array {
		if ( ! is_array( $cached ) ) {
			return array( 'generation' => '', 'paths' => array() );
		}

		if ( isset( $cached['paths'] ) && is_array( $cached['paths'] ) ) {
			$generation = isset( $cached['generation'] ) && is_scalar( $cached['generation'] )
				? sanitize_text_field( (string) $cached['generation'] )
				: '';
			return array(
				'generation' => $generation,
				'paths'      => $this->valid_path_strings( $cached['paths'] ),
			);
		}

		// Backwards compatibility for transients created before cache generations were
		// stored explicitly. Existing two-argument cleanup events must remain safe after
		// updating the plugin.
		return array( 'generation' => '', 'paths' => $this->valid_path_strings( $cached ) );
	}

	/** @param array<int, mixed> $paths @return array<int, string> */
	private function valid_path_strings( array $paths ): array {
		return array_values( array_filter( $paths, static fn ( mixed $path ): bool => is_string( $path ) && '' !== $path ) );
	}

	public function cleanup_temporary_files(): void {
		$this->delete_paths( $this->temporary_files );
		$this->temporary_files = array();
	}

	private function schedule_prefetch_retry( int $order_id, int $attempt ): void {
		$args  = array( $order_id, $attempt );
		$delay = 60 * ( 2 ** max( 0, $attempt - 1 ) );
		if ( function_exists( 'as_schedule_single_action' ) && as_schedule_single_action( time() + $delay, self::PREFETCH_HOOK, $args, OrderActions::SCHEDULER_GROUP, true ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::PREFETCH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::PREFETCH_HOOK, $args );
		}
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

	private function label_output(): string {
		$settings = $this->options->all();
		return 'collated_a4' === (string) ( $settings['label_output'] ?? '' ) ? 'collated_a4' : 'a6';
	}

	private function cache_key( int $order_id ): string {
		return 'soocool_email_labels_' . absint( $order_id );
	}

	private function is_valid_cached_pdf( mixed $path ): bool {
		if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size < 8 || $size > 26214400 ) {
			return false;
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$valid = '%PDF-' === fread( $handle, 5 );
		if ( $valid ) {
			fseek( $handle, max( 0, (int) $size - 1024 ) );
			$tail  = stream_get_contents( $handle );
			$valid = is_string( $tail ) && false !== strpos( $tail, '%%EOF' );
		}
		fclose( $handle );
		return $valid;
	}

	/** @param array<int, mixed> $paths */
	private function delete_paths( array $paths ): void {
		$temp_dir = realpath( get_temp_dir() );
		$temp_dir = is_string( $temp_dir ) ? trailingslashit( $temp_dir ) : false;
		foreach ( $paths as $file ) {
			if ( ! is_string( $file ) || '' === $file ) {
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
