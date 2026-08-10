<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

use SooCool\WooCommerce\Infrastructure\AssetResolver;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates delivery scheduling with the classic WooCommerce checkout.
 */
final class DeliveryOptions {

	private readonly DeliveryOptionsRenderer $renderer;

	public function __construct( private readonly OptionRepository $options, private readonly DeliverySchedule $schedule, private readonly DeliveryCheckoutRequest $request, private readonly DeliveryOrderDetails $order_details ) {
		$this->renderer = new DeliveryOptionsRenderer( $this->schedule );
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_field' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_delivery_surcharges' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'require_checkout_phone' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order' ), 10, 2 );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_order_meta_fields' ), 10, 3 );
		add_action( 'woocommerce_email_order_meta', array( $this, 'render_email_order_detail' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_order_detail' ) );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			return;
		}

		$css_file = AssetResolver::filename( 'assets/frontend', 'checkout-delivery', 'css' );
		if ( '' !== $css_file ) {
			wp_enqueue_style(
				'soocool-checkout-delivery',
				AssetResolver::url( 'assets/frontend', $css_file ),
				array(),
				AssetResolver::version( 'assets/frontend', $css_file )
			);
		}

		$js_file = AssetResolver::filename( 'assets/frontend', 'checkout-delivery', 'js' );
		if ( '' !== $js_file ) {
			wp_enqueue_script(
				'soocool-checkout-delivery',
				AssetResolver::url( 'assets/frontend', $js_file ),
				array(),
				AssetResolver::version( 'assets/frontend', $js_file ),
				true
			);
		}
	}

	/** @param array<string, mixed> $fields @return array<string, mixed> */
	public function require_checkout_phone( array $fields ): array {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) || ! $this->checkout_requires_delivery() ) {
			return $fields;
		}

		if ( isset( $fields['billing']['billing_phone'] ) && is_array( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['required'] = true;
		}

		return $fields;
	}

	public function apply_delivery_surcharges( mixed $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_fee' ) ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) || ! $this->checkout_requires_delivery() ) {
			return;
		}

		if ( $this->checkout_uses_free_shipping( $cart ) ) {
			return;
		}

		$country = $this->checkout_delivery_country();
		if ( ! in_array( $country, array( 'NL', 'BE' ), true ) ) {
			return;
		}

		if ( 'NL' === $country ) {
			$country_surcharge = $this->filtered_surcharge_amount(
				'soocool_netherlands_delivery_surcharge_amount',
				(float) ( $settings['checkout_delivery_netherlands_surcharge_amount'] ?? 0.00 ),
				$cart
			);
			if ( $country_surcharge > 0 ) {
				$this->add_delivery_fee( $cart, __( 'Nederland-toeslag bezorging', 'soocool-for-woocommerce' ), $country_surcharge, $settings, $country, array() );
			}

			$slot = $this->request->posted_time_slot();
			if ( ! $this->is_valid_evening_selection( $slot ) ) {
				return;
			}

			$evening_surcharge = $this->filtered_surcharge_amount(
				'soocool_netherlands_evening_delivery_surcharge_amount',
				(float) ( $settings['checkout_delivery_netherlands_evening_surcharge_amount'] ?? 0.00 ),
				$cart
			);
			if ( $evening_surcharge > 0 ) {
				$this->add_delivery_fee( $cart, __( 'Avondtoeslag Nederland', 'soocool-for-woocommerce' ), $evening_surcharge, $settings, $country, $slot );
			}
			return;
		}

		$country_surcharge = $this->filtered_surcharge_amount(
			'soocool_belgium_delivery_surcharge_amount',
			(float) ( $settings['checkout_delivery_belgium_surcharge_amount'] ?? 2.00 ),
			$cart
		);
		if ( $country_surcharge > 0 ) {
			$this->add_delivery_fee( $cart, __( 'België-toeslag bezorging', 'soocool-for-woocommerce' ), $country_surcharge, $settings, $country, array() );
		}

		$slot = $this->request->posted_time_slot();
		if ( ! $this->is_valid_evening_selection( $slot ) ) {
			return;
		}

		$evening_surcharge = $this->filtered_surcharge_amount(
			'soocool_belgium_evening_delivery_surcharge_amount',
			(float) ( $settings['checkout_delivery_belgium_evening_surcharge_amount'] ?? 1.50 ),
			$cart
		);
		if ( $evening_surcharge > 0 ) {
			$this->add_delivery_fee( $cart, __( 'Avondtoeslag België', 'soocool-for-woocommerce' ), $evening_surcharge, $settings, $country, $slot );
		}
	}

	public function render_checkout_field(): void {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) || ! $this->checkout_requires_delivery() ) {
			return;
		}

		$options      = $this->schedule->available_options();
		$current_date = $this->request->selected_delivery_date( $options );
		$current_slot = $this->request->selected_time_slot( $this->schedule, $current_date );

		$this->renderer->render( $settings, $options, $current_date, $current_slot );
	}

	/** @param array<string, mixed> $data */
	public function validate_checkout_field( array $data, WP_Error $errors ): void {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) || ! $this->checkout_requires_delivery( $data ) ) {
			return;
		}

		$date = $this->request->posted_delivery_date();
		if ( '' === $date ) {
			$errors->add( 'soocool_delivery_date_required', __( 'Kies een bezorgdag voordat je de bestelling plaatst.', 'soocool-for-woocommerce' ) );
			return;
		}

		if ( ! $this->schedule->is_valid_date( $date ) ) {
			$errors->add( 'soocool_delivery_date_invalid', __( 'Deze bezorgdag is niet meer beschikbaar. Kies een nieuwe bezorgdag.', 'soocool-for-woocommerce' ) );
			return;
		}

		$slot = $this->request->posted_time_slot();
		if ( '' === $slot['time_from'] || '' === $slot['time_to'] ) {
			$errors->add( 'soocool_delivery_time_slot_required', __( 'Kies een dagdeel voordat je de bestelling plaatst.', 'soocool-for-woocommerce' ) );
			return;
		}

		if ( ! $this->schedule->is_valid_time_slot( $date, $slot['time_from'], $slot['time_to'] ) ) {
			$errors->add( 'soocool_delivery_time_slot_invalid', __( 'Dit dagdeel is niet meer beschikbaar. Kies een ander dagdeel.', 'soocool-for-woocommerce' ) );
		}
	}

	/** @param array<string, mixed> $data */
	public function save_to_order( WC_Order $order, array $data ): void {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) || ! $this->checkout_requires_delivery( $data ) ) {
			return;
		}

		$date = $this->request->posted_delivery_date();
		$slot = $this->request->posted_time_slot();
		if ( '' === $date ) {
			return;
		}

		$slot_label = $this->schedule->available_time_slot_label( $date, $slot['time_from'], $slot['time_to'] );
		if ( '' === $slot_label ) {
			return;
		}

		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_DATE, $date );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_LABEL, $this->schedule->format_label( $date ) );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, $slot['time_from'] );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_TO, $slot['time_to'] );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_LABEL, $slot_label );
	}

	/** @param array<string, array<string, string>> $fields @return array<string, array<string, string>> */
	public function email_order_meta_fields( array $fields, bool $sent_to_admin, WC_Order $order ): array {
		return $this->order_details->email_order_meta_fields( $fields, $sent_to_admin, $order );
	}

	public function render_email_order_detail( WC_Order $order, bool $sent_to_admin, bool $plain_text, mixed $email = null ): void {
		$this->order_details->render_email_order_detail( $order, $sent_to_admin, $plain_text );
	}

	public function render_customer_order_detail( WC_Order $order ): void {
		$this->order_details->render_customer_order_detail( $order );
	}

	/** @param array<string, mixed> $checkout_data */
	private function checkout_requires_delivery( array $checkout_data = array() ): bool {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$cart        = is_object( $woocommerce ) && isset( $woocommerce->cart ) && is_object( $woocommerce->cart ) ? $woocommerce->cart : null;
		$required    = true;

		if ( is_object( $cart ) && method_exists( $cart, 'needs_shipping' ) && ! (bool) $cart->needs_shipping() ) {
			$required = false;
		}

		if ( $required ) {
			$shipping_methods = $this->selected_shipping_methods( $checkout_data, $woocommerce, $cart );
			if ( array() !== $shipping_methods ) {
				$required = false;
				foreach ( $shipping_methods as $method ) {
					if ( 'local_pickup' !== $method && ! str_starts_with( $method, 'local_pickup:' ) ) {
						$required = true;
						break;
					}
				}
			}
		}

		return (bool) apply_filters( 'soocool_checkout_requires_delivery_selection', $required, $checkout_data, $cart );
	}

	/** @param array<string, mixed> $checkout_data @return array<int, string> */
	private function selected_shipping_methods( array $checkout_data, mixed $woocommerce, mixed $cart = null ): array {
		$methods = $checkout_data['shipping_method'] ?? array();
		if ( ! is_array( $methods ) ) {
			$methods = is_scalar( $methods ) ? array( $methods ) : array();
		}

		$clean = $this->normalize_shipping_methods( $methods );
		if ( array() !== $clean ) {
			return $clean;
		}

		if ( null === $cart && is_object( $woocommerce ) && isset( $woocommerce->cart ) && is_object( $woocommerce->cart ) ) {
			$cart = $woocommerce->cart;
		}

		$shipping_calculated = is_object( $cart ) && method_exists( $cart, 'has_calculated_shipping' ) && (bool) $cart->has_calculated_shipping();
		if ( $shipping_calculated ) {
			$selected_rates = is_object( $cart ) && method_exists( $cart, 'get_shipping_methods' ) ? $cart->get_shipping_methods() : array();
			return is_iterable( $selected_rates ) ? $this->normalize_shipping_methods( $selected_rates ) : array();
		}

		if ( is_object( $woocommerce ) && isset( $woocommerce->session ) && is_object( $woocommerce->session ) && method_exists( $woocommerce->session, 'get' ) ) {
			$session_methods = $woocommerce->session->get( 'chosen_shipping_methods', array() );
			if ( is_array( $session_methods ) ) {
				return $this->normalize_shipping_methods( $session_methods );
			}
		}

		return array();
	}

	/** @param iterable<mixed> $methods @return array<int, string> */
	private function normalize_shipping_methods( iterable $methods ): array {
		$clean = array();
		foreach ( $methods as $method ) {
			if ( is_object( $method ) && method_exists( $method, 'get_method_id' ) ) {
				$method = $method->get_method_id();
			}
			if ( ! is_scalar( $method ) ) {
				continue;
			}

			$method = strtolower( trim( sanitize_text_field( (string) $method ) ) );
			if ( '' !== $method ) {
				$clean[] = $method;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function checkout_uses_free_shipping( mixed $cart ): bool {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$methods     = $this->selected_shipping_methods( array(), $woocommerce, $cart );

		$has_delivery_method = false;
		foreach ( array_values( array_unique( $methods ) ) as $method ) {
			if ( 'local_pickup' === $method || str_starts_with( $method, 'local_pickup:' ) ) {
				continue;
			}

			$has_delivery_method = true;
			if ( 'free_shipping' !== $method && ! str_starts_with( $method, 'free_shipping:' ) ) {
				return false;
			}
		}

		return $has_delivery_method;
	}

	private function checkout_delivery_country(): string {
		$billing_country  = $this->posted_country( 'billing_country' );
		$shipping_country = $this->posted_country( 'shipping_country' );
		if ( '' !== $billing_country || '' !== $shipping_country ) {
			$ship_to_different_address = $this->posted_boolean( 'ship_to_different_address' );
			if ( $ship_to_different_address ) {
				return '' !== $shipping_country ? $shipping_country : $billing_country;
			}

			return '' !== $billing_country ? $billing_country : $shipping_country;
		}

		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( $woocommerce && $woocommerce->customer ) {
			$shipping_country = strtoupper( sanitize_key( (string) $woocommerce->customer->get_shipping_country() ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $shipping_country ) ) {
				return $shipping_country;
			}

			$billing_country = strtoupper( sanitize_key( (string) $woocommerce->customer->get_billing_country() ) );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $billing_country ) ) {
				return $billing_country;
			}
		}

		return '';
	}

	private function posted_country( string $field ): string {
		$value   = $this->request->posted_value( $field );
		$country = strtoupper( sanitize_key( $value ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	private function posted_boolean( string $field ): bool {
		$value = strtolower( $this->request->posted_value( $field ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	private function filtered_surcharge_amount( string $filter, float $fallback, mixed $cart ): float {
		$value = apply_filters( $filter, $fallback, $cart );
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return $fallback;
		}

		$amount = (float) $value;
		if ( ! is_finite( $amount ) ) {
			return $fallback;
		}

		return max( 0.0, min( 999.0, round( $amount, 2 ) ) );
	}

	/** @param array{time_from:string,time_to:string} $slot */
	private function is_valid_evening_selection( array $slot ): bool {
		$date = $this->request->posted_delivery_date();
		if ( '' === $date || ! $this->schedule->is_valid_time_slot( $date, $slot['time_from'], $slot['time_to'] ) ) {
			return false;
		}

		$configured = $this->schedule->matching_time_slot( $date, $slot['time_from'], $slot['time_to'] );
		$type       = sanitize_key( (string) ( $configured['type'] ?? $configured['id'] ?? '' ) );
		if ( 'evening' === $type ) {
			return true;
		}

		return '17:00' === $slot['time_from'] && '22:00' === $slot['time_to'];
	}

	/** @param array<string, mixed> $settings @param array<string, mixed> $slot */
	private function add_delivery_fee( mixed $cart, string $label, float $amount, array $settings, string $country, array $slot ): void {
		$taxable = (bool) apply_filters(
			'soocool_delivery_fee_taxable',
			(bool) ( $settings['checkout_delivery_fee_taxable'] ?? false ),
			$country,
			$slot,
			$cart
		);
		$tax_class = sanitize_title(
			(string) apply_filters(
				'soocool_delivery_fee_tax_class',
				(string) ( $settings['checkout_delivery_fee_tax_class'] ?? '' ),
				$country,
				$slot,
				$cart
			)
		);

		$cart->add_fee( $label, $amount, $taxable, $tax_class );
	}

}
