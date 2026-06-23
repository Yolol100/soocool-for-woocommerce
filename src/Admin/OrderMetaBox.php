<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Checkout\DeliverySchedule;
use SooCool\WooCommerce\Domain\OrderSyncCoordinator;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderMetaBox {

	public function __construct(
		private readonly OrderMeta $meta,
		private readonly OrderStatusPresenter $presenter,
		private readonly DeliverySchedule $schedule,
		private readonly OrderSyncCoordinator $coordinator
	) {}

	public function register(): void {
		add_meta_box(
			'soocool-order-status',
			__( 'SooCool', 'soocool-for-woocommerce' ),
			array( $this, 'render' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	public function render( object $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		$status           = (string) $order->get_meta( OrderMeta::SYNC_STATUS, true );
		$error            = (string) $order->get_meta( OrderMeta::LAST_ERROR, true );
		$tracking_code    = (string) $order->get_meta( OrderMeta::TRACKING_CODE, true );
		$good_ids         = $this->meta->get_good_ids( $order );
		$delivery_label  = $this->meta->get_requested_delivery_label( $order );
		$delivery_date   = $this->meta->get_requested_delivery_date( $order );
		$time_label      = $this->meta->get_requested_delivery_time_label( $order );

		echo '<div class="soocool-order-card">';
		echo '<div class="soocool-order-card__header"><span class="soocool-order-card__kicker">' . esc_html__( 'SooCool-status', 'soocool-for-woocommerce' ) . '</span><span class="' . esc_attr( $this->presenter->badge_class( $status ) ) . '">' . esc_html( $this->presenter->label( $status ) ) . '</span></div>';
		echo '<div class="soocool-order-section-title">' . esc_html__( 'Status & koppeling', 'soocool-for-woocommerce' ) . '</div>';
		echo '<dl class="soocool-order-meta-list">';
		$this->render_meta_row( __( 'SooCool order-ID', 'soocool-for-woocommerce' ), '' !== $soocool_order_id ? $soocool_order_id : __( 'Nog niet gekoppeld', 'soocool-for-woocommerce' ) );
		if ( '' !== $delivery_label || '' !== $delivery_date ) {
			$delivery_moment = '' !== $delivery_label ? $delivery_label : $delivery_date;
			if ( '' !== $time_label ) {
				$delivery_moment .= ', ' . $time_label;
			}
			$this->render_meta_row( __( 'Gekozen bezorgmoment', 'soocool-for-woocommerce' ), $delivery_moment );
		}
		if ( '' !== $tracking_code ) {
			$this->render_meta_row( __( 'Track & trace-code', 'soocool-for-woocommerce' ), $tracking_code );
		}
		if ( array() !== $good_ids ) {
			$this->render_meta_row( __( 'SooCool-goederen-ID’s', 'soocool-for-woocommerce' ), implode( ', ', $good_ids ) );
		}
		echo '</dl>';

		if ( current_user_can( 'manage_woocommerce' ) ) {
			$this->render_label_actions( $order, $good_ids );
		}

		if ( '' !== $error ) {
			echo '<div class="soocool-order-alert is-error"><strong>' . esc_html__( 'Laatste fout', 'soocool-for-woocommerce' ) . '</strong><br />' . esc_html( $error ) . '</div>';
		}
		echo '</div>';
	}


	/** @param array<int, int> $good_ids */
	private function render_label_actions( WC_Order $order, array $good_ids ): void {
		if ( ! $this->meta->is_synced( $order ) ) {
			return;
		}

		$order_id = absint( $order->get_id() );
		if ( 0 >= $order_id ) {
			return;
		}

		$order_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . $order_id ),
			'soocool_download_label_' . $order_id
		);

		echo '<div class="soocool-order-action-group">';
		echo '<div class="soocool-order-button-stack">';
		echo '<a class="button button-secondary soocool-order-button" href="' . esc_url( $order_url ) . '">' . esc_html__( 'Download orderlabel', 'soocool-for-woocommerce' ) . '</a>';

		if ( array() !== $good_ids ) {
			$good_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=soocool_download_label&order_id=' . $order_id . '&good_ids=' . rawurlencode( implode( ',', $good_ids ) ) ),
				'soocool_download_good_labels_' . $order_id
			);
			echo '<a class="button button-secondary soocool-order-button" href="' . esc_url( $good_url ) . '">' . esc_html__( 'Download goederenlabel', 'soocool-for-woocommerce' ) . '</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	public function render_delivery_date_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This read-only notice flag is set after a nonce-checked admin redirect and does not mutate data; value is sanitized immediately on this line.
		$notice = isset( $_GET['soocool_notice'] ) && is_scalar( $_GET['soocool_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['soocool_notice'] ) ) : '';
		if ( 'delivery_date_updated' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'SooCool bezorgmoment bijgewerkt.', 'soocool-for-woocommerce' ) . '</p></div>';
		} elseif ( 'invalid_delivery_date' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Kies een geldig beschikbaar SooCool bezorgmoment.', 'soocool-for-woocommerce' ) . '</p></div>';
		}
	}

	public function handle_update_delivery_date(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Order ID is needed to build the nonce action and is sanitized before use; nonce is checked immediately below.
		$order_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] ) ? absint( wp_unslash( (string) $_POST['order_id'] ) ) : 0;
		if ( 0 >= $order_id ) {
			wp_die( esc_html__( 'Ongeldige order.', 'soocool-for-woocommerce' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'soocool_update_delivery_date_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je mag deze order niet bijwerken.', 'soocool-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Ongeldige order.', 'soocool-for-woocommerce' ), '', array( 'response' => 400 ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked above; value is sanitized immediately on this line.
		$moment = isset( $_POST['soocool_requested_delivery_moment'] ) && is_scalar( $_POST['soocool_requested_delivery_moment'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['soocool_requested_delivery_moment'] ) ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}\|([01]\d|2[0-3]):[0-5]\d\|([01]\d|2[0-3]):[0-5]\d$/', $moment ) ) {
			$this->redirect_with_notice( $order, 'invalid_delivery_date' );
		}

		$parts     = explode( '|', $moment, 3 );
		$date      = (string) ( $parts[0] ?? '' );
		$time_from = (string) ( $parts[1] ?? '' );
		$time_to   = (string) ( $parts[2] ?? '' );

		if ( ! $this->schedule->is_valid_date( $date ) || ! $this->schedule->is_valid_time_slot( $date, $time_from, $time_to ) ) {
			$this->redirect_with_notice( $order, 'invalid_delivery_date' );
		}

		$current_date  = $this->meta->get_requested_delivery_date( $order );
		$current_label = $this->meta->get_requested_delivery_label( $order );
		$current_time  = $this->meta->get_requested_delivery_time_label( $order );
		$new_label     = $this->schedule->format_label( $date );
		$new_time      = $this->schedule->format_time_slot_label( $time_from, $time_to );

		$changed = $date !== $current_date || $time_from !== $this->meta->get_requested_delivery_time_from( $order ) || $time_to !== $this->meta->get_requested_delivery_time_to( $order );
		if ( $changed ) {
			$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_DATE, $date );
			$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_LABEL, $new_label );
			$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, $time_from );
			$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_TO, $time_to );
			$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_LABEL, $new_time );
			$order->save();

			$old_value = '' !== $current_label ? $current_label : ( '' !== $current_date ? $current_date : __( 'geen', 'soocool-for-woocommerce' ) );
			if ( '' !== $current_time ) {
				$old_value .= ', ' . $current_time;
			}
			$order->add_order_note(
				sprintf(
					/* translators: 1: old delivery moment label, 2: new delivery moment label. */
					__( 'SooCool bezorgmoment gewijzigd van %1$s naar %2$s.', 'soocool-for-woocommerce' ),
					$old_value,
					$new_label . ', ' . $new_time
				)
			);
		}

		$this->sync_delivery_moment_to_soocool( $order );
		$this->redirect_with_notice( $order, 'delivery_date_updated' );
	}

	private function sync_delivery_moment_to_soocool( WC_Order $order ): void {
		if ( '' === $this->meta->get_soocool_order_id( $order ) ) {
			return;
		}

		$result = $this->coordinator->update_order( $order );
		if ( (bool) ( $result['success'] ?? false ) ) {
			$order->add_order_note( __( 'SooCool-order bijgewerkt met het gekozen bezorgmoment.', 'soocool-for-woocommerce' ) );
			return;
		}

		$order->add_order_note( sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-update na wijziging van het bezorgmoment is mislukt.', 'soocool-for-woocommerce' ) ) ) );
	}

	private function render_delivery_date_editor( WC_Order $order, string $current_date, string $current_label, string $current_time_from, string $current_time_to ): void {
		$options = $this->schedule->available_options();
		if ( array() === $options ) {
			echo '<div class="soocool-order-action-group soocool-order-delivery-editor">';
			echo '<div class="soocool-order-action-group__title">' . esc_html__( 'Bezorgmoment', 'soocool-for-woocommerce' ) . '</div>';
			echo '<p class="description">' . esc_html__( 'Er zijn momenteel geen geldige bezorgmomenten beschikbaar in het checkoutschema.', 'soocool-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<form class="soocool-order-action-group soocool-order-delivery-editor" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<div class="soocool-order-action-group__title">' . esc_html__( 'Bezorgmoment', 'soocool-for-woocommerce' ) . '</div>';
		echo '<input type="hidden" name="action" value="soocool_update_delivery_date" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		wp_nonce_field( 'soocool_update_delivery_date_' . (int) $order->get_id() );
		echo '<label class="screen-reader-text" for="soocool-requested-delivery-moment">' . esc_html__( 'Kies bezorgmoment', 'soocool-for-woocommerce' ) . '</label>';
		echo '<select id="soocool-requested-delivery-moment" class="soocool-order-delivery-editor__select" name="soocool_requested_delivery_moment">';

		$has_current = false;
		foreach ( $options as $option ) {
			$date  = (string) ( $option['date'] ?? '' );
			$label = (string) ( $option['label'] ?? '' );
			if ( '' === $date || '' === $label ) {
				continue;
			}

			foreach ( $this->schedule->available_time_slots_for_date( $date ) as $slot ) {
				$time_from  = (string) $slot['time_from'];
				$time_to    = (string) $slot['time_to'];
				$time_label = (string) $slot['display_label'];
				$value      = $date . '|' . $time_from . '|' . $time_to;
				$selected   = $date === $current_date && $time_from === $current_time_from && $time_to === $current_time_to;
				if ( $selected ) {
					$has_current = true;
				}

				echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $label . ', ' . $time_label ) . '</option>';
			}
		}

		if ( '' !== $current_date && ! $has_current ) {
			$label = '' !== $current_label ? $current_label : $current_date;
			$current_time_label = $this->meta->get_requested_delivery_time_label( $order );
			if ( '' !== $current_time_label ) {
				$label .= ', ' . $current_time_label;
			}
			echo '<option value="" selected disabled>' . esc_html( $label . ' (' . __( 'niet meer beschikbaar', 'soocool-for-woocommerce' ) . ')' ) . '</option>';
		}

		echo '</select>';
		echo '<button type="submit" class="button button-secondary soocool-order-button">' . esc_html__( 'Bezorgmoment bijwerken', 'soocool-for-woocommerce' ) . '</button>';
		echo '<p class="description soocool-order-action-help">' . esc_html__( 'Alleen geldige bezorgmomenten met ochtend of avond kunnen worden opgeslagen. Opnieuw verzonden WooCommerce e-mails gebruiken het bijgewerkte moment.', 'soocool-for-woocommerce' ) . '</p>';
		echo '</form>';
	}

	private function redirect_with_notice( WC_Order $order, string $notice ): void {
		$redirect = wp_get_referer();
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = $order->get_edit_order_url();
		}

		wp_safe_redirect( add_query_arg( 'soocool_notice', sanitize_key( $notice ), $redirect ) );
		exit;
	}

	private function render_meta_row( string $label, string $value ): void {
		echo '<div class="soocool-order-meta-row"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}
}
