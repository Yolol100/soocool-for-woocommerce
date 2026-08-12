<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Blocks;

use SooCool\WooCommerce\Checkout\DeliveryCheckoutRequest;
use SooCool\WooCommerce\Checkout\DeliverySchedule;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class DeliveryOptionsIntegration {

	public const FIELD_ID = 'soocool-for-woocommerce/delivery-moment';

	private const MINIMUM_CONDITIONAL_FIELDS_VERSION = '9.9.0';

	public function __construct(
		private readonly OptionRepository $options,
		private readonly DeliverySchedule $schedule,
		private readonly DeliveryCheckoutRequest $checkout_request
	) {}

	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_checkout_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_draft', array( $this, 'update_draft_from_request' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'update_order_from_request' ), 10, 2 );
	}

	public function register_checkout_field(): void {
		if ( ! $this->is_enabled() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			return;
		}

		$options = $this->field_options();
		if ( array() === $options ) {
			return;
		}

		$delivery_required = array(
			'type'       => 'object',
			'properties' => array(
				'cart' => array(
					'type'       => 'object',
					'properties' => array(
						'needs_shipping'    => array( 'const' => true ),
						'prefers_collection' => array( 'const' => false ),
					),
				),
			),
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'                => self::FIELD_ID,
				'label'             => __( 'Bezorgmoment', 'soocool-for-woocommerce' ),
				'optionalLabel'     => __( 'Bezorgmoment', 'soocool-for-woocommerce' ),
				'location'          => 'order',
				'type'              => 'select',
				'required'          => $delivery_required,
				'hidden'            => array( 'not' => $delivery_required ),
				'placeholder'       => __( 'Kies een bezorgmoment', 'soocool-for-woocommerce' ),
				'options'           => $options,
				'sanitize_callback' => array( $this, 'sanitize_value' ),
				'validate_callback' => array( $this, 'validate_value' ),
			)
		);
	}

	public function sanitize_value( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\|(?:[01]\d|2[0-3]):[0-5]\d\|(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	public function validate_value( mixed $value ): ?WP_Error {
		$selection = $this->parse_value( $value );
		if ( null === $selection || ! $this->schedule->is_valid_time_slot( $selection['date'], $selection['time_from'], $selection['time_to'] ) ) {
			return new WP_Error( 'soocool_delivery_moment_invalid', __( 'Dit bezorgmoment is niet meer beschikbaar. Kies een ander bezorgmoment.', 'soocool-for-woocommerce' ) );
		}

		return null;
	}


	public function update_draft_from_request( WP_REST_Request $request ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->persist_request_selection( $request );
	}

	public function update_order_from_request( WC_Order $order, WP_REST_Request $request ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$selection = $this->persist_request_selection( $request );
		if ( null === $selection ) {
			return;
		}
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_DATE, $selection['date'] );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_LABEL, $this->schedule->format_label( $selection['date'] ) );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, $selection['time_from'] );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_TO, $selection['time_to'] );
		$order->update_meta_data(
			OrderMeta::REQUESTED_DELIVERY_TIME_LABEL,
			$this->schedule->available_time_slot_label( $selection['date'], $selection['time_from'], $selection['time_to'] )
		);
	}

	/** @return array{date:string,time_from:string,time_to:string}|null */
	private function persist_request_selection( WP_REST_Request $request ): ?array {
		$fields = $request->get_param( 'additional_fields' );
		if ( ! is_array( $fields ) ) {
			$fallback = $request['additional_fields'] ?? null;
			$fields   = is_array( $fallback ) ? $fallback : null;
		}

		// Checkout update requests may omit additional_fields entirely. In that case this
		// request is unrelated to our field, so keep the previously persisted selection.
		if ( ! is_array( $fields ) || ! array_key_exists( self::FIELD_ID, $fields ) ) {
			return null;
		}

		$selection = $this->parse_value( $fields[ self::FIELD_ID ] );
		if ( null === $selection || ! $this->schedule->is_valid_time_slot( $selection['date'], $selection['time_from'], $selection['time_to'] ) ) {
			$this->checkout_request->clear_persisted_selection();
			return null;
		}

		$this->checkout_request->persist_selection( $selection['date'], $selection['time_from'], $selection['time_to'] );
		return $selection;
	}

	private static function is_supported_runtime(): bool {
		return defined( 'WC_VERSION' )
			&& version_compare( WC_VERSION, self::MINIMUM_CONDITIONAL_FIELDS_VERSION, '>=' )
			&& function_exists( 'woocommerce_register_additional_checkout_field' );
	}

	public static function is_enabled_runtime(): bool {
		return self::is_supported_runtime()
			&& (bool) apply_filters( 'soocool_enable_checkout_blocks_adapter', false );
	}

	public static function compatibility_declared(): bool {
		return false;
	}

	private function is_enabled(): bool {
		return self::is_enabled_runtime();
	}

	/** @return array<int, array{value:string,label:string}> */
	private function field_options(): array {
		$options = array();
		foreach ( $this->schedule->available_options() as $date_option ) {
			$date = (string) ( $date_option['date'] ?? '' );
			foreach ( $this->schedule->available_time_slots_for_date( $date ) as $slot ) {
				$time_from = (string) ( $slot['time_from'] ?? '' );
				$time_to   = (string) ( $slot['time_to'] ?? '' );
				if ( '' === $date || '' === $time_from || '' === $time_to ) {
					continue;
				}
				$options[] = array(
					'value' => $date . '|' . $time_from . '|' . $time_to,
					'label' => $this->schedule->format_label( $date ) . ' — ' . (string) ( $slot['display_label'] ?? '' ),
				);
			}
		}

		return $options;
	}


	/** @return array{date:string,time_from:string,time_to:string}|null */
	private function parse_value( mixed $value ): ?array {
		$value = $this->sanitize_value( $value );
		if ( '' === $value ) {
			return null;
		}

		$parts = explode( '|', $value, 3 );
		return array(
			'date'      => $parts[0],
			'time_from' => $parts[1],
			'time_to'   => $parts[2],
		);
	}
}
