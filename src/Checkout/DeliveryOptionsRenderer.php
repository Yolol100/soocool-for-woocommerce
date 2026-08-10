<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

defined( 'ABSPATH' ) || exit;

final class DeliveryOptionsRenderer {

	private const FIELD_DATE = DeliveryCheckoutRequest::FIELD_DATE;
	private const FIELD_TIME_SLOT = DeliveryCheckoutRequest::FIELD_TIME_SLOT;

	public function __construct( private readonly DeliverySchedule $schedule ) {}

	public function render( array $settings, array $options, string $current_date, array $current_slot ): void {
		$has_date      = '' !== $current_date;
		$has_time_slot = '' !== $current_slot['time_from'] && '' !== $current_slot['time_to'];
		$root_classes  = 'soocool-delivery-options';
		if ( ! $has_date ) {
			$root_classes .= ' is-time-collapsed';
		}
		if ( $has_time_slot ) {
			$root_classes .= ' has-selection';
		}
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

	private function render_delivery_info( array $settings ): void {
		$cutoff_time = $this->checkout_cutoff_time_label( $settings );

		echo '<div class="soocool-delivery-options__intro">';
		echo '<p>' . esc_html__( 'Volg je bestelling met Track & Trace.', 'soocool-for-woocommerce' ) . '</p>';

		if ( '' !== $cutoff_time ) {
			echo '<p>' . sprintf(
				/* translators: %s: checkout delivery cut-off time, for example 13:00. */
				esc_html__( 'Bestel vóór %s voor de eerstvolgende bezorgdag.', 'soocool-for-woocommerce' ),
				esc_html( $cutoff_time )
			) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Kies je bezorgdag.', 'soocool-for-woocommerce' ) . '</p>';
		}

		$this->render_delivery_surcharge_info( $settings );

		echo '</div>';
	}

	private function render_delivery_surcharge_info( array $settings ): void {
		$netherlands_surcharge         = (float) ( $settings['checkout_delivery_netherlands_surcharge_amount'] ?? 0.00 );
		$netherlands_evening_surcharge = (float) ( $settings['checkout_delivery_netherlands_evening_surcharge_amount'] ?? 0.00 );
		$belgium_surcharge             = (float) ( $settings['checkout_delivery_belgium_surcharge_amount'] ?? 2.00 );
		$belgium_evening_surcharge     = (float) ( $settings['checkout_delivery_belgium_evening_surcharge_amount'] ?? 1.50 );

		if ( $netherlands_surcharge <= 0.0 && $netherlands_evening_surcharge <= 0.0 && $belgium_surcharge <= 0.0 && $belgium_evening_surcharge <= 0.0 ) {
			return;
		}

		$this->render_country_delivery_surcharge_info( __( 'Nederland', 'soocool-for-woocommerce' ), $netherlands_surcharge, $netherlands_evening_surcharge );
		$this->render_country_delivery_surcharge_info( __( 'België', 'soocool-for-woocommerce' ), $belgium_surcharge, $belgium_evening_surcharge );
	}

	private function render_country_delivery_surcharge_info( string $country_label, float $country_surcharge, float $evening_surcharge ): void {
		if ( $country_surcharge <= 0.0 && $evening_surcharge <= 0.0 ) {
			return;
		}

		$country_surcharge_label = $this->format_money_amount( $country_surcharge );
		$evening_surcharge_label = $this->format_money_amount( $evening_surcharge );

		if ( $country_surcharge > 0.0 && $evening_surcharge > 0.0 ) {
			echo '<p>' . sprintf(
				/* translators: 1: delivery country, 2: country delivery surcharge amount, 3: evening delivery surcharge amount. */
				esc_html__( 'Voor bezorging in %1$s geldt een bezorgtoeslag van %2$s. Kies je voor een avonddagdeel, dan komt daar %3$s avondtoeslag bij.', 'soocool-for-woocommerce' ),
				esc_html( $country_label ),
				esc_html( $country_surcharge_label ),
				esc_html( $evening_surcharge_label )
			) . '</p>';
			return;
		}

		if ( $country_surcharge > 0.0 ) {
			echo '<p>' . sprintf(
				/* translators: 1: delivery country, 2: country delivery surcharge amount. */
				esc_html__( 'Voor bezorging in %1$s geldt een bezorgtoeslag van %2$s.', 'soocool-for-woocommerce' ),
				esc_html( $country_label ),
				esc_html( $country_surcharge_label )
			) . '</p>';
			return;
		}

		echo '<p>' . sprintf(
			/* translators: 1: delivery country, 2: evening delivery surcharge amount. */
			esc_html__( 'Voor bezorging in %1$s geldt bij een avonddagdeel een toeslag van %2$s.', 'soocool-for-woocommerce' ),
			esc_html( $country_label ),
			esc_html( $evening_surcharge_label )
		) . '</p>';
	}

	private function format_money_amount( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		}

		if ( function_exists( 'number_format_i18n' ) ) {
			return '€' . number_format_i18n( $amount, 2 );
		}

		return '€' . number_format( $amount, 2, ',', '.' );
	}

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
		echo '<button type="button" class="soocool-delivery-options__change" data-soocool-delivery-change aria-expanded="' . esc_attr( '' === $label ? 'false' : 'true' ) . '" aria-controls="soocool-delivery-time-panel">' . esc_html__( 'Wijzigen', 'soocool-for-woocommerce' ) . '</button>';
		echo '</div>';
	}

	private function selected_delivery_moment_label( array $options, string $current_date, array $slot ): string {
		$date_label = $this->selected_delivery_label( $options, $current_date );
		if ( '' === $date_label ) {
			return '';
		}

		$time_label = '';
		if ( '' !== $slot['time_from'] && '' !== $slot['time_to'] ) {
			$time_label = $this->schedule->available_time_slot_label( $current_date, $slot['time_from'], $slot['time_to'] );
		}

		return '' !== $time_label ? $date_label . ', ' . $time_label : '';
	}

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

	private function render_date_picker( array $settings, array $options, string $current ): void {
		$available  = $this->available_options_by_date( $options );
		$days_ahead = max( 7, min( 92, absint( $settings['checkout_delivery_days_ahead'] ?? 92 ) ) );
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

		$available_dates       = array_keys( $available );
		sort( $available_dates, SORT_STRING );
		$first_available_date  = (string) ( $available_dates[0] ?? '' );
		$first_available_month = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $first_available_date ) ? substr( $first_available_date, 0, 7 ) : '';
		$active_month          = '' !== $current ? substr( $current, 0, 7 ) : $first_available_month;
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

		$picker_label_template = __( 'Beschikbare bezorgdagen voor %s', 'soocool-for-woocommerce' );
		$picker_label          = sprintf(
			/* translators: %s: delivery month label, for example juni 2026. */
			$picker_label_template,
			$months[ $active_month ] ?? ''
		);

		echo '<div class="soocool-delivery-options__picker" data-soocool-delivery-picker data-soocool-picker-label-template="' . esc_attr( $picker_label_template ) . '" role="radiogroup" aria-required="true" aria-label="' . esc_attr( $picker_label ) . '">';
		foreach ( $days as $day ) {
			$date_time   = $day['date_time'];
			$date        = (string) $day['date'];
			$enabled     = (bool) $day['enabled'];
			$label       = (string) $day['label'];
			$month_key   = (string) $day['month_key'];
			$month_label = (string) $day['month_label'];
			$visible     = $month_key === $active_month;
			$classes     = 'soocool-delivery-day' . ( $enabled ? ' is-available' : ' is-disabled' );
			$input_label = $label;
			if ( ! $enabled ) {
				$input_label = sprintf(
					/* translators: %s: delivery date label, for example donderdag 25 juni. */
					__( '%s - niet beschikbaar', 'soocool-for-woocommerce' ),
					$label
				);
			}

			echo '<label class="' . esc_attr( $classes ) . '" data-soocool-delivery-month="' . esc_attr( $month_key ) . '" data-soocool-delivery-month-label="' . esc_attr( $month_label ) . '" aria-hidden="' . esc_attr( $visible ? 'false' : 'true' ) . '"';
			if ( ! $enabled ) {
				echo ' aria-disabled="true"';
			}
			if ( ! $visible ) {
				echo ' hidden';
			}
			echo '>';
			echo '<input type="radio" name="' . esc_attr( self::FIELD_DATE ) . '" value="' . esc_attr( $date ) . '" data-delivery-label="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $input_label ) . '" ';
			if ( ! $enabled ) {
				echo 'disabled aria-disabled="true" ';
			}
			checked( $current, $date );
			echo ' />';
			echo '<span class="soocool-delivery-day__card">';
			echo '<span class="soocool-delivery-day__weekday">' . esc_html( $this->short_weekday( $date_time ) ) . '</span>';
			echo '<span class="soocool-delivery-day__day">' . esc_html( $date_time->format( 'j' ) ) . '</span>';
			echo '<span class="soocool-delivery-day__month">' . esc_html( $this->short_month( $date_time ) ) . '</span>';
			if ( ! $enabled ) {
				echo '<span class="soocool-delivery-options__screen-reader-text">' . esc_html__( 'Niet beschikbaar', 'soocool-for-woocommerce' ) . '</span>';
			}
			echo '</span>';
			echo '</label>';
		}
		echo '</div>';
	}

	private function render_time_slot_picker( array $settings, array $options, string $current_date, array $current_slot ): void {
		$include_unavailable = ! (bool) ( $settings['checkout_delivery_hide_unavailable_slots'] ?? true );
		$panel_expanded      = '' !== $current_date;

		echo '<div class="soocool-delivery-options__time" id="soocool-delivery-time-panel" data-soocool-time-slots aria-hidden="' . esc_attr( $panel_expanded ? 'false' : 'true' ) . '">';
		echo '<div class="soocool-delivery-options__section-label soocool-delivery-options__step"><span class="soocool-delivery-options__step-number" aria-hidden="true">2</span><span>' . esc_html__( 'Dagdeel', 'soocool-for-woocommerce' ) . '</span></div>';
		echo '<p class="soocool-delivery-options__time-help">' . esc_html__( 'Kies een beschikbaar dagdeel.', 'soocool-for-woocommerce' ) . '</p>';

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
				echo '<div class="soocool-delivery-time-empty">' . esc_html__( 'Geen dagdelen beschikbaar voor deze datum.', 'soocool-for-woocommerce' ) . '</div>';
				echo '</div>';
				continue;
			}

			$time_list_id = 'soocool-delivery-time-list-' . sanitize_key( $date );
			/* translators: %s: selected delivery date label. */
			echo '<div class="soocool-delivery-time-list" id="' . esc_attr( $time_list_id ) . '" role="radiogroup" aria-required="true" aria-label="' . esc_attr( sprintf( __( 'Dagdelen voor %s', 'soocool-for-woocommerce' ), $date_label ) ) . '">';
			$available_count = 0;
			foreach ( $slots as $slot ) {
				$time_from  = (string) $slot['time_from'];
				$time_to    = (string) $slot['time_to'];
				$value      = $time_from . '|' . $time_to;
				$label      = (string) $slot['display_label'];
				$slot_name  = trim( (string) ( $slot['label'] ?? '' ) );
				$time_range = $time_from . ' – ' . $time_to;
				$available  = (bool) $slot['available'];
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
				echo '<span class="soocool-delivery-time-slot__name">' . esc_html( '' !== $slot_name ? $slot_name : $label ) . '</span>';
				echo '<span class="soocool-delivery-time-slot__range">' . esc_html( $time_range ) . '</span>';
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
				echo '<button type="button" class="soocool-delivery-time-more" data-soocool-time-more data-more-label="' . esc_attr__( 'Meer dagdelen tonen', 'soocool-for-woocommerce' ) . '" data-less-label="' . esc_attr__( 'Minder tonen', 'soocool-for-woocommerce' ) . '" aria-expanded="false" aria-controls="' . esc_attr( $time_list_id ) . '">' . esc_html__( 'Meer dagdelen tonen', 'soocool-for-woocommerce' ) . '</button>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

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

	private function today(): \DateTimeImmutable {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		try {
			return new \DateTimeImmutable( 'today', $timezone );
		} catch ( \Exception ) {
			return new \DateTimeImmutable( gmdate( 'Y-m-d' ) . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		}
	}

	private function compact_date_label( \DateTimeImmutable $date ): string {
		return trim( $this->localized_date( 'D j M', $date ) );
	}

	private function short_weekday( \DateTimeImmutable $date ): string {
		return $this->localized_date( 'D', $date );
	}

	private function short_month( \DateTimeImmutable $date ): string {
		return $this->localized_date( 'M', $date );
	}

	private function month_label( \DateTimeImmutable $date ): string {
		return trim( $this->localized_date( 'F Y', $date ) );
	}

	private function localized_date( string $format, \DateTimeImmutable $date ): string {
		return function_exists( 'wp_date' )
			? wp_date( $format, $date->getTimestamp(), $date->getTimezone() )
			: $date->format( $format );
	}
}
