<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

use SooCool\WooCommerce\Infrastructure\AssetResolver;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic WooCommerce checkout integration.
 *
 * This class targets the classic WooCommerce checkout. A separate Checkout Blocks
 * integration is required before claiming block-checkout support.
 */
final class DeliveryOptions {

	private const FIELD_DATE      = 'soocool_requested_delivery_date';
	private const FIELD_TIME_SLOT = 'soocool_requested_delivery_time_slot';

	/** @var array<int, string> */
	private const SHORT_WEEKDAYS = array( 'zo', 'ma', 'di', 'wo', 'do', 'vr', 'za' );

	/** @var array<int, string> */
	private const SHORT_MONTHS = array(
		1  => 'jan',
		2  => 'feb',
		3  => 'mrt',
		4  => 'apr',
		5  => 'mei',
		6  => 'jun',
		7  => 'jul',
		8  => 'aug',
		9  => 'sep',
		10 => 'okt',
		11 => 'nov',
		12 => 'dec',
	);

	/** @var array<int, string> */
	private const MONTHS = array(
		1  => 'januari',
		2  => 'februari',
		3  => 'maart',
		4  => 'april',
		5  => 'mei',
		6  => 'juni',
		7  => 'juli',
		8  => 'augustus',
		9  => 'september',
		10 => 'oktober',
		11 => 'november',
		12 => 'december',
	);

	public function __construct( private readonly OptionRepository $options, private readonly DeliverySchedule $schedule ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_checkout_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_field' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order' ), 10, 2 );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_order_meta_fields' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_order_detail' ) );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
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

	public function render_checkout_field(): void {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			return;
		}

		$options      = $this->schedule->available_options();
		$current_date = $this->selected_delivery_date( $options );
		$current_slot = $this->selected_time_slot( $current_date );

		$root_classes = 'soocool-delivery-options is-time-collapsed' . ( '' !== $current_date && '' !== $current_slot['time_from'] && '' !== $current_slot['time_to'] ? ' has-selection' : '' );
		echo '<div class="' . esc_attr( $root_classes ) . '" id="soocool-delivery-options">';
		echo '<h3 class="soocool-delivery-options__title">' . esc_html__( 'Kies je bezorgmoment', 'soocool-for-woocommerce' ) . '</h3>';
		$this->render_delivery_info( $settings );

		if ( array() === $options ) {
			echo '<div class="soocool-delivery-options__notice" role="status">' . esc_html__( 'Er zijn momenteel geen bezorgmomenten beschikbaar. Neem contact met ons op voordat je bestelt.', 'soocool-for-woocommerce' ) . '</div>';
			echo '</div>';
			return;
		}

		$this->render_selected_delivery_notice( $options, $current_date, $current_slot );
		$this->render_date_picker( $settings, $options, $current_date );
		$this->render_time_slot_picker( $settings, $options, $current_date, $current_slot );
		echo '</div>';
	}

	/** @param array<string, mixed> $data */
	public function validate_checkout_field( array $data, WP_Error $errors ): void {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			return;
		}

		$date = $this->posted_delivery_date();
		if ( '' === $date ) {
			$errors->add( 'soocool_delivery_date_required', __( 'Kies een bezorgdag voordat je de bestelling plaatst.', 'soocool-for-woocommerce' ) );
			return;
		}

		if ( ! $this->schedule->is_valid_date( $date ) ) {
			$errors->add( 'soocool_delivery_date_invalid', __( 'Deze bezorgdag is niet meer beschikbaar. Kies een nieuwe bezorgdag.', 'soocool-for-woocommerce' ) );
			return;
		}

		$slot = $this->posted_time_slot();
		if ( '' === $slot['time_from'] || '' === $slot['time_to'] ) {
			$errors->add( 'soocool_delivery_time_slot_required', __( 'Kies een tijdslot voordat je de bestelling plaatst.', 'soocool-for-woocommerce' ) );
			return;
		}

		if ( ! $this->schedule->is_valid_time_slot( $date, $slot['time_from'], $slot['time_to'] ) ) {
			$errors->add( 'soocool_delivery_time_slot_invalid', __( 'Dit tijdslot is niet meer beschikbaar. Kies een ander tijdslot.', 'soocool-for-woocommerce' ) );
		}
	}

	/** @param array<string, mixed> $data */
	public function save_to_order( WC_Order $order, array $data ): void {
		$settings = $this->options->all();
		if ( ! (bool) ( $settings['checkout_delivery_enabled'] ?? true ) ) {
			return;
		}

		$date = $this->posted_delivery_date();
		$slot = $this->posted_time_slot();
		if ( '' === $date || ! $this->schedule->is_valid_date( $date ) || ! $this->schedule->is_valid_time_slot( $date, $slot['time_from'], $slot['time_to'] ) ) {
			return;
		}

		$slot_label = $this->schedule->format_time_slot_label( $slot['time_from'], $slot['time_to'] );

		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_DATE, $date );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_LABEL, $this->schedule->format_label( $date ) );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, $slot['time_from'] );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_TO, $slot['time_to'] );
		$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_LABEL, $slot_label );
	}

	/** @param array<string, array<string, string>> $fields @return array<string, array<string, string>> */
	public function email_order_meta_fields( array $fields, bool $sent_to_admin, WC_Order $order ): array {
		$label = $this->order_delivery_moment_label( $order );
		if ( '' !== $label ) {
			$fields['soocool_requested_delivery_moment'] = array(
				'label' => __( 'Bezorgmoment', 'soocool-for-woocommerce' ),
				'value' => $label,
			);
		}

		return $fields;
	}

	public function render_customer_order_detail( WC_Order $order ): void {
		$label = $this->order_delivery_moment_label( $order );
		if ( '' === $label ) {
			return;
		}

		echo '<section class="woocommerce-order-details soocool-order-delivery-detail">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Bezorging', 'soocool-for-woocommerce' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Bezorgmoment:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( $label ) . '</p>';
		echo '</section>';
	}

	/** @param array<string, mixed> $settings */
	private function render_delivery_info( array $settings ): void {
		$cutoff_time = $this->checkout_cutoff_time_label( $settings );

		echo '<div class="soocool-delivery-options__intro">';
		echo '<p>' . esc_html__( 'Kies een beschikbare bezorgdag en daarna een tijdslot.', 'soocool-for-woocommerce' ) . '</p>';

		if ( '' !== $cutoff_time ) {
			echo '<p>' . sprintf(
				/* translators: %s: checkout delivery cut-off time, for example 13:00. */
				esc_html__( 'Bestel vóór %s om de eerstvolgende beschikbare bezorgdag te kunnen kiezen.', 'soocool-for-woocommerce' ),
				esc_html( $cutoff_time )
			) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Kies hieronder een bezorgdag; je ziet daarbij tot welk moment je voor die dag kunt bestellen.', 'soocool-for-woocommerce' ) . '</p>';
		}

		echo '</div>';
	}

	/** @param array<string, mixed> $settings */
	private function checkout_cutoff_time_label( array $settings ): string {
		$rules = is_array( $settings['checkout_delivery_rules'] ?? null ) ? $settings['checkout_delivery_rules'] : array();
		$times = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['enabled'] ) ) {
				continue;
			}

			$time = (string) ( $rule['cutoff_time'] ?? '' );
			if ( 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
				$times[ $time ] = true;
			}
		}

		if ( 1 === count( $times ) ) {
			return (string) array_key_first( $times );
		}

		return '';
	}

	/** @param array<int, array<string, mixed>> $options @param array{time_from:string,time_to:string} $current_slot */
	private function render_selected_delivery_notice( array $options, string $current_date, array $current_slot ): void {
		$label     = $this->selected_delivery_moment_label( $options, $current_date, $current_slot );
		$aria_text = '' === $label ? 'true' : 'false';

		echo '<div class="soocool-delivery-options__alert" role="status" aria-live="polite" aria-atomic="true" aria-hidden="' . esc_attr( $aria_text ) . '"';
		if ( '' === $label ) {
			echo ' hidden';
		}
		echo '>';
		echo '<span class="soocool-delivery-options__alert-text"><strong>' . esc_html__( 'Gekozen bezorgmoment:', 'soocool-for-woocommerce' ) . '</strong> ';
		echo '<span data-soocool-delivery-selected>' . esc_html( $label ) . '</span></span>';
		echo '<button type="button" class="soocool-delivery-options__change" data-soocool-delivery-change aria-expanded="false" aria-controls="soocool-delivery-time-panel">' . esc_html__( 'Wijzigen', 'soocool-for-woocommerce' ) . '</button>';
		echo '</div>';
	}

	/** @param array<int, array<string, mixed>> $options @param array{time_from:string,time_to:string} $slot */
	private function selected_delivery_moment_label( array $options, string $current_date, array $slot ): string {
		$date_label = $this->selected_delivery_label( $options, $current_date );
		if ( '' === $date_label ) {
			return '';
		}

		$time_label = '';
		if ( '' !== $slot['time_from'] && '' !== $slot['time_to'] ) {
			$time_label = $this->schedule->format_time_slot_label( $slot['time_from'], $slot['time_to'] );
		}

		return '' !== $time_label ? $date_label . ', ' . $time_label : $date_label;
	}

	/** @param array<int, array<string, mixed>> $options */
	private function selected_delivery_label( array $options, string $current ): string {
		if ( '' === $current ) {
			return '';
		}

		foreach ( $options as $option ) {
			if ( $current === (string) $option['date'] ) {
				return (string) $option['label'];
			}
		}

		return '';
	}

	/** @param array<string, mixed> $settings @param array<int, array<string, mixed>> $options */
	private function render_date_picker( array $settings, array $options, string $current ): void {
		$available  = $this->available_options_by_date( $options );
		$days_ahead = max( 7, min( 60, absint( $settings['checkout_delivery_days_ahead'] ?? 14 ) ) );
		$today      = $this->today();
		$days       = array();
		$months     = array();

		for ( $offset = 0; $offset <= $days_ahead; $offset++ ) {
			$date_time   = $today->modify( '+' . $offset . ' days' );
			$date        = $date_time->format( 'Y-m-d' );
			$month_key   = $date_time->format( 'Y-m' );
			$month_label = $this->month_label( $date_time );
			$option      = $available[ $date ] ?? null;
			$enabled     = is_array( $option );
			$label       = $enabled ? (string) $option['label'] : $this->compact_date_label( $date_time );

			$months[ $month_key ] = $month_label;
			$days[] = array(
				'date_time'   => $date_time,
				'date'        => $date,
				'enabled'     => $enabled,
				'label'       => $label,
				'month_key'   => $month_key,
				'month_label' => $month_label,
			);
		}

		$active_month = '' !== $current ? substr( $current, 0, 7 ) : (string) array_key_first( $months );
		if ( '' === $active_month || ! isset( $months[ $active_month ] ) ) {
			$active_month = (string) array_key_first( $months );
		}

		echo '<div class="soocool-delivery-options__section-label soocool-delivery-options__step"><span class="soocool-delivery-options__step-number" aria-hidden="true">1</span><span>' . esc_html__( 'Bezorgdatum', 'soocool-for-woocommerce' ) . '</span></div>';

		if ( count( $months ) > 1 ) {
			$month_keys         = array_keys( $months );
			$active_month_index = array_search( $active_month, $month_keys, true );
			$active_month_index = false === $active_month_index ? 0 : (int) $active_month_index;

			echo '<div class="soocool-delivery-options__month-nav" data-soocool-month-nav aria-label="' . esc_attr__( 'Bezorgmaand kiezen', 'soocool-for-woocommerce' ) . '">';
			echo '<button type="button" class="soocool-delivery-options__month-button" data-soocool-month-prev aria-label="' . esc_attr__( 'Vorige maand', 'soocool-for-woocommerce' ) . '"' . ( 0 === $active_month_index ? ' disabled' : '' ) . '>&lsaquo;</button>';
			echo '<span class="soocool-delivery-options__month-label" data-soocool-month-label aria-live="polite">' . esc_html( $months[ $active_month ] ?? '' ) . '</span>';
			echo '<button type="button" class="soocool-delivery-options__month-button" data-soocool-month-next aria-label="' . esc_attr__( 'Volgende maand', 'soocool-for-woocommerce' ) . '"' . ( count( $month_keys ) - 1 === $active_month_index ? ' disabled' : '' ) . '>&rsaquo;</button>';
			echo '</div>';
		}

		$picker_label = sprintf(
			/* translators: %s: delivery month label, for example juni 2026. */
			__( 'Beschikbare bezorgdagen voor %s', 'soocool-for-woocommerce' ),
			$months[ $active_month ] ?? ''
		);

		echo '<div class="soocool-delivery-options__picker" data-soocool-delivery-picker role="radiogroup" aria-required="true" aria-label="' . esc_attr( $picker_label ) . '">';
		foreach ( $days as $day ) {
			$date_time   = $day['date_time'];
			$date        = (string) $day['date'];
			$enabled     = (bool) $day['enabled'];
			$label       = (string) $day['label'];
			$month_key   = (string) $day['month_key'];
			$month_label = (string) $day['month_label'];
			$visible     = $month_key === $active_month;
			$classes     = 'soocool-delivery-day' . ( $enabled ? ' is-available' : ' is-disabled' );

			echo '<label class="' . esc_attr( $classes ) . '" data-soocool-delivery-month="' . esc_attr( $month_key ) . '" data-soocool-delivery-month-label="' . esc_attr( $month_label ) . '" aria-hidden="' . esc_attr( $visible ? 'false' : 'true' ) . '"';
			if ( ! $visible ) {
				echo ' hidden';
			}
			echo '>';
			echo '<input type="radio" name="' . esc_attr( self::FIELD_DATE ) . '" value="' . esc_attr( $date ) . '" data-delivery-label="' . esc_attr( $label ) . '" ';
			if ( ! $enabled ) {
				echo 'disabled aria-disabled="true" ';
			}
			checked( $current, $date );
			echo ' />';
			echo '<span class="soocool-delivery-day__card">';
			echo '<span class="soocool-delivery-day__weekday">' . esc_html( $this->short_weekday( $date_time ) ) . '</span>';
			echo '<span class="soocool-delivery-day__day">' . esc_html( $date_time->format( 'j' ) ) . '</span>';
			echo '<span class="soocool-delivery-day__month">' . esc_html( $this->short_month( $date_time ) ) . '</span>';
			echo '</span>';
			echo '</label>';
		}
		echo '</div>';
	}

	/** @param array<string, mixed> $settings @param array<int, array<string, mixed>> $options @param array{time_from:string,time_to:string} $current_slot */
	private function render_time_slot_picker( array $settings, array $options, string $current_date, array $current_slot ): void {
		$include_unavailable = ! (bool) ( $settings['checkout_delivery_hide_unavailable_slots'] ?? true );

		echo '<div class="soocool-delivery-options__time" id="soocool-delivery-time-panel" data-soocool-time-slots aria-hidden="true">';
		echo '<div class="soocool-delivery-options__section-label soocool-delivery-options__step"><span class="soocool-delivery-options__step-number" aria-hidden="true">2</span><span>' . esc_html__( 'Tijdslot', 'soocool-for-woocommerce' ) . '</span></div>';
		echo '<p class="soocool-delivery-options__time-help">' . esc_html__( 'Kies een beschikbaar tijdslot voor de geselecteerde bezorgdatum.', 'soocool-for-woocommerce' ) . '</p>';

		foreach ( $options as $option ) {
			$date       = (string) ( $option['date'] ?? '' );
			$date_label = (string) ( $option['label'] ?? $date );
			if ( '' === $date ) {
				continue;
			}

			$slots = $this->schedule->available_time_slots_for_date( $date, $include_unavailable );
			echo '<div class="soocool-delivery-time-group" data-soocool-time-date="' . esc_attr( $date ) . '" data-soocool-time-date-label="' . esc_attr( $date_label ) . '"';
			if ( $current_date !== $date ) {
				echo ' hidden';
			}
			echo '>';

			if ( array() === $slots ) {
				echo '<div class="soocool-delivery-time-empty">' . esc_html__( 'Geen tijdsloten beschikbaar voor deze datum.', 'soocool-for-woocommerce' ) . '</div>';
				echo '</div>';
				continue;
			}

			$time_list_id = 'soocool-delivery-time-list-' . sanitize_key( $date );
			/* translators: %s: selected delivery date label. */
			echo '<div class="soocool-delivery-time-list" id="' . esc_attr( $time_list_id ) . '" role="radiogroup" aria-required="true" aria-label="' . esc_attr( sprintf( __( 'Tijdsloten voor %s', 'soocool-for-woocommerce' ), $date_label ) ) . '">';
			$available_count = 0;
			foreach ( $slots as $slot ) {
				$time_from = (string) $slot['time_from'];
				$time_to   = (string) $slot['time_to'];
				$value     = $time_from . '|' . $time_to;
				$label     = (string) $slot['display_label'];
				$available = (bool) $slot['available'];
				$classes   = 'soocool-delivery-time-slot' . ( $available ? ' is-available' : ' is-disabled' );
				if ( $available ) {
					$available_count++;
					if ( $available_count > 4 ) {
						$classes .= ' is-extra';
					}
				}

				echo '<label class="' . esc_attr( $classes ) . '" data-soocool-time-slot>';
				echo '<input type="radio" name="' . esc_attr( self::FIELD_TIME_SLOT ) . '" value="' . esc_attr( $value ) . '" data-time-label="' . esc_attr( $label ) . '" data-time-date="' . esc_attr( $date ) . '" ';
				if ( ! $available ) {
					echo 'disabled aria-disabled="true" ';
				}
				if ( $available ) {
					checked( $current_date === $date && $current_slot['time_from'] === $time_from && $current_slot['time_to'] === $time_to );
				}
				echo ' />';
				echo '<span class="soocool-delivery-time-slot__card">';
				echo '<span class="soocool-delivery-time-slot__main">';
				echo '<span class="soocool-delivery-time-slot__time">' . esc_html( $label ) . '</span>';
				if ( ! $available ) {
					echo '<span class="soocool-delivery-time-slot__status">' . esc_html( (string) $slot['status_label'] ) . '</span>';
				}
				echo '</span>';
				echo '<span class="soocool-delivery-time-slot__check" aria-hidden="true"></span>';
				echo '</span>';
				echo '</label>';
			}
			echo '</div>';
			if ( $available_count > 4 ) {
				echo '<button type="button" class="soocool-delivery-time-more" data-soocool-time-more data-more-label="' . esc_attr__( 'Meer tijdsloten tonen', 'soocool-for-woocommerce' ) . '" data-less-label="' . esc_attr__( 'Minder tonen', 'soocool-for-woocommerce' ) . '" aria-expanded="false" aria-controls="' . esc_attr( $time_list_id ) . '">' . esc_html__( 'Meer tijdsloten tonen', 'soocool-for-woocommerce' ) . '</button>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/** @param array<int, array<string, mixed>> $options @return array<string, array<string, mixed>> */
	private function available_options_by_date( array $options ): array {
		$available = array();
		foreach ( $options as $option ) {
			$date = (string) ( $option['date'] ?? '' );
			if ( '' !== $date ) {
				$available[ $date ] = $option;
			}
		}

		return $available;
	}

	/** @param array<int, array<string, mixed>> $options */
	private function selected_delivery_date( array $options ): string {
		$posted = $this->posted_delivery_date();
		foreach ( $options as $option ) {
			$date = (string) $option['date'];
			if ( '' !== $posted && $posted === $date ) {
				return $posted;
			}
		}

		return '';
	}

	private function posted_delivery_date(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce validates the checkout nonce before processing checkout fields; value is sanitized immediately on this line.
		$value = isset( $_POST[ self::FIELD_DATE ] ) && is_scalar( $_POST[ self::FIELD_DATE ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_DATE ] ) ) : '';

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/** @return array{time_from:string,time_to:string} */
	private function posted_time_slot(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce validates the checkout nonce before processing checkout fields; value is sanitized immediately on this line.
		$value = isset( $_POST[ self::FIELD_TIME_SLOT ] ) && is_scalar( $_POST[ self::FIELD_TIME_SLOT ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_TIME_SLOT ] ) ) : '';
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d\|([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return array( 'time_from' => '', 'time_to' => '' );
		}

		$parts = explode( '|', $value, 2 );
		return array(
			'time_from' => (string) ( $parts[0] ?? '' ),
			'time_to'   => (string) ( $parts[1] ?? '' ),
		);
	}

	/** @return array{time_from:string,time_to:string} */
	private function selected_time_slot( string $current_date ): array {
		$empty = array( 'time_from' => '', 'time_to' => '' );
		if ( '' === $current_date ) {
			return $empty;
		}

		$slot = $this->posted_time_slot();
		if ( '' !== $slot['time_from'] && '' !== $slot['time_to'] && $this->schedule->is_valid_time_slot( $current_date, $slot['time_from'], $slot['time_to'] ) ) {
			return $slot;
		}

		return $empty;
	}

	private function order_delivery_label( WC_Order $order ): string {
		$raw_label = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_LABEL, true );
		$label     = is_scalar( $raw_label ) ? sanitize_text_field( (string) $raw_label ) : '';
		if ( '' !== $label ) {
			return $label;
		}

		$raw_date = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_DATE, true );
		$date     = is_scalar( $raw_date ) ? sanitize_text_field( (string) $raw_date ) : '';

		return '' !== $date ? $this->schedule->format_label( $date ) : '';
	}

	private function order_delivery_time_label( WC_Order $order ): string {
		$raw_label = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_TIME_LABEL, true );
		$label     = is_scalar( $raw_label ) ? sanitize_text_field( (string) $raw_label ) : '';
		if ( '' !== $label ) {
			return $label;
		}

		$time_from = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, true );
		$time_to   = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_TIME_TO, true );
		$time_from = is_scalar( $time_from ) ? sanitize_text_field( (string) $time_from ) : '';
		$time_to   = is_scalar( $time_to ) ? sanitize_text_field( (string) $time_to ) : '';

		return '' !== $time_from && '' !== $time_to ? $this->schedule->format_time_slot_label( $time_from, $time_to ) : '';
	}

	private function order_delivery_moment_label( WC_Order $order ): string {
		$date_label = $this->order_delivery_label( $order );
		$time_label = $this->order_delivery_time_label( $order );
		if ( '' === $date_label ) {
			return '';
		}

		return '' !== $time_label ? $date_label . ', ' . $time_label : $date_label;
	}

	private function today(): \DateTimeImmutable {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		try {
			return new \DateTimeImmutable( 'today', $timezone );
		} catch ( \Exception ) {
			return new \DateTimeImmutable( gmdate( 'Y-m-d' ) . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		}
	}

	private function compact_date_label( \DateTimeImmutable $date ): string {
		return trim( $this->short_weekday( $date ) . ' ' . $date->format( 'j' ) . ' ' . $this->short_month( $date ) );
	}

	private function short_weekday( \DateTimeImmutable $date ): string {
		return self::SHORT_WEEKDAYS[ (int) $date->format( 'w' ) ] ?? $date->format( 'D' );
	}

	private function short_month( \DateTimeImmutable $date ): string {
		return self::SHORT_MONTHS[ (int) $date->format( 'n' ) ] ?? $date->format( 'M' );
	}

	private function month_label( \DateTimeImmutable $date ): string {
		$month = self::MONTHS[ (int) $date->format( 'n' ) ] ?? $date->format( 'F' );

		return ucfirst( $month ) . ' ' . $date->format( 'Y' );
	}
}
