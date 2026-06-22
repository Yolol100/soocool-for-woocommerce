<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderEmailLabels {

	/** @var array<int, string> */
	private const ADMIN_EMAIL_IDS = array( 'new_order' );

	/** @var array<int, string> */
	private array $temporary_files = array();

	public function __construct( private readonly ShippingLabelService $labels, private readonly OptionRepository $options, private readonly OrderMeta $meta, private readonly Logger $logger ) {}

	public function register(): void {
		add_filter( 'woocommerce_email_attachments', array( $this, 'add_admin_label_attachments' ), 10, 4 );
		add_action( 'wp_mail_succeeded', array( $this, 'cleanup_temporary_files' ) );
		add_action( 'wp_mail_failed', array( $this, 'cleanup_temporary_files' ) );
		add_action( 'shutdown', array( $this, 'cleanup_temporary_files' ) );
	}

	/** @param array<int, string> $attachments @return array<int, string> */
	public function add_admin_label_attachments( array $attachments, string $email_id, mixed $object = null, mixed $email = null ): array {
		if ( ! $object instanceof WC_Order || ! $this->should_attach_to_email( $email_id, $object, $email ) ) {
			return $attachments;
		}

		$output = $this->label_output();

		if ( '' !== $this->meta->get_soocool_order_id( $object ) ) {
			$attachments = $this->attach_order_label( $attachments, $object, $output );
		}

		$good_ids = $this->meta->get_good_ids( $object );
		if ( array() !== $good_ids ) {
			$attachments = $this->attach_good_labels( $attachments, $object, $good_ids, $output );
		}

		return $attachments;
	}

	public function cleanup_temporary_files(): void {
		$temp_dir = realpath( get_temp_dir() );

		foreach ( $this->temporary_files as $file ) {
			$path = realpath( $file );
			if ( false === $temp_dir || false === $path || ! str_starts_with( $path, $temp_dir ) || ! is_file( $path ) ) {
				continue;
			}

			wp_delete_file( $path );
		}

		$this->temporary_files = array();
	}

	private function should_attach_to_email( string $email_id, WC_Order $order, mixed $email ): bool {
		if ( is_object( $email ) && method_exists( $email, 'is_customer_email' ) && $email->is_customer_email() ) {
			return false;
		}

		$email_ids = apply_filters( 'soocool_admin_label_email_ids', self::ADMIN_EMAIL_IDS, $order );
		if ( ! is_array( $email_ids ) ) {
			$email_ids = self::ADMIN_EMAIL_IDS;
		}

		$allowed = array_values( array_filter( array_map( 'sanitize_key', $email_ids ) ) );
		if ( ! in_array( sanitize_key( $email_id ), $allowed, true ) ) {
			return false;
		}

		return (bool) apply_filters( 'soocool_attach_labels_to_admin_emails', true, $order, $email_id );
	}

	private function label_output(): string {
		$settings = $this->options->all();
		return 'collated_a4' === (string) ( $settings['label_output'] ?? '' ) ? 'collated_a4' : 'a6';
	}

	/** @param array<int, string> $attachments @return array<int, string> */
	private function attach_order_label( array $attachments, WC_Order $order, string $output ): array {
		try {
			$pdf = $this->labels->get_label( $order, $output );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'SooCool admin email order label attachment skipped.',
				array(
					'orderId' => $order->get_id(),
					'error'   => $exception->getMessage(),
				)
			);
			return $attachments;
		}

		return $this->attach_pdf( $attachments, $pdf, 'soocool-order-label-' . absint( $order->get_id() ) . '.pdf' );
	}

	/** @param array<int, string> $attachments @param array<int, int> $good_ids @return array<int, string> */
	private function attach_good_labels( array $attachments, WC_Order $order, array $good_ids, string $output ): array {
		$good_ids = array_values( array_unique( array_filter( array_map( 'absint', $good_ids ) ) ) );
		if ( array() === $good_ids ) {
			return $attachments;
		}

		try {
			$pdf = 1 === count( $good_ids )
				? $this->labels->get_good_label( $order, $good_ids[0], $output )
				: $this->labels->get_bulk_good_labels( $good_ids, $output );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'SooCool admin email good label attachment skipped.',
				array(
					'orderId' => $order->get_id(),
					'error'   => $exception->getMessage(),
				)
			);
			return $attachments;
		}

		$filename = 1 === count( $good_ids ) ? 'soocool-good-label-' . absint( $good_ids[0] ) . '.pdf' : 'soocool-good-labels-' . absint( $order->get_id() ) . '.pdf';
		return $this->attach_pdf( $attachments, $pdf, $filename );
	}

	/** @param array<int, string> $attachments @return array<int, string> */
	private function attach_pdf( array $attachments, string $pdf, string $filename ): array {
		if ( '' === $pdf || ! str_starts_with( ltrim( $pdf ), '%PDF' ) ) {
			return $attachments;
		}

		$temp_dir = get_temp_dir();
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'soocool-label.pdf';
		}

		$path = trailingslashit( $temp_dir ) . wp_unique_filename( $temp_dir, $filename );
		if ( ! $this->write_temporary_pdf( $path, $pdf ) ) {
			return $attachments;
		}

		$this->temporary_files[] = $path;
		$attachments[]           = $path;

		return $attachments;
	}

	private function write_temporary_pdf( string $path, string $pdf ): bool {
		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			return false;
		}

		$filesystem = new \WP_Filesystem_Direct( array() );
		$chmod      = defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644;

		return $filesystem->put_contents( $path, $pdf, $chmod );
	}
}
