<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\WooCommerce\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Maps SooCool sync states to admin labels, badge classes and fallback colors.
 */
final class OrderStatusPresenter {

	/**
	 * Selectable sync states for the orders-list filter dropdown.
	 *
	 * @return array<string, string>
	 */
	public function filter_options(): array {
		return array(
			'synced'     => __( 'Gesynchroniseerd', 'soocool-for-woocommerce' ),
			'pending'    => __( 'In wachtrij', 'soocool-for-woocommerce' ),
			'failed'     => __( 'Mislukt', 'soocool-for-woocommerce' ),
			'cancelled'  => __( 'Geannuleerd', 'soocool-for-woocommerce' ),
			'not_synced' => __( 'Niet gesynchroniseerd', 'soocool-for-woocommerce' ),
		);
	}

	public function label( string $status ): string {
		$status = sanitize_key( $status );

		if ( in_array( $status, $this->pending_statuses(), true ) ) {
			return __( 'In wachtrij', 'soocool-for-woocommerce' );
		}
		if ( in_array( $status, $this->failed_statuses(), true ) ) {
			return __( 'Mislukt', 'soocool-for-woocommerce' );
		}
		if ( in_array( $status, $this->cancelled_statuses(), true ) ) {
			return __( 'Geannuleerd', 'soocool-for-woocommerce' );
		}

		return match ( $status ) {
			'synced'              => __( 'Gesynchroniseerd', 'soocool-for-woocommerce' ),
			'soocool_accepted'    => __( 'Geaccepteerd', 'soocool-for-woocommerce' ),
			'soocool_active'      => __( 'Actief', 'soocool-for-woocommerce' ),
			'soocool_completed'   => __( 'Afgerond', 'soocool-for-woocommerce' ),
			'soocool_created'     => __( 'Aangemaakt', 'soocool-for-woocommerce' ),
			'soocool_delivered'   => __( 'Bezorgd', 'soocool-for-woocommerce' ),
			'soocool_in_progress' => __( 'In uitvoering', 'soocool-for-woocommerce' ),
			'soocool_in_transit'  => __( 'Onderweg', 'soocool-for-woocommerce' ),
			'soocool_planned'     => __( 'Ingepland', 'soocool-for-woocommerce' ),
			'soocool_processing'  => __( 'In verwerking', 'soocool-for-woocommerce' ),
			'soocool_ready'       => __( 'Gereed', 'soocool-for-woocommerce' ),
			'soocool_shipped'     => __( 'Verzonden', 'soocool-for-woocommerce' ),
			'not_synced',
			''                    => __( 'Niet gesynchroniseerd', 'soocool-for-woocommerce' ),
			default               => __( 'Onbekende SooCool-status', 'soocool-for-woocommerce' ),
		};
	}

	public function badge_class( string $status ): string {
		return 'soocool-order-badge ' . $this->tone_class( $status );
	}

	public function tone_class( string $status ): string {
		$status = sanitize_key( $status );

		if ( in_array( $status, array( 'synced', 'soocool_delivered', 'soocool_completed' ), true ) ) {
			return 'is-success';
		}
		if ( in_array( $status, array_merge( $this->failed_statuses(), $this->cancelled_statuses() ), true ) ) {
			return 'is-error';
		}
		if ( in_array( $status, array_merge( $this->pending_statuses(), $this->in_progress_statuses() ), true ) ) {
			return 'is-warning';
		}

		return 'is-neutral';
	}

	/** @return array<int, string> */
	public function pending_statuses(): array {
		return array( 'pending', 'soocool_pending' );
	}

	/** @return array<int, string> */
	public function failed_statuses(): array {
		return OrderMeta::failure_statuses();
	}

	/** @return array<int, string> */
	public function cancelled_statuses(): array {
		return array( 'cancelled', 'soocool_cancelled' );
	}

	/** @return array<int, string> */
	private function in_progress_statuses(): array {
		return array(
			'soocool_accepted',
			'soocool_active',
			'soocool_created',
			'soocool_in_progress',
			'soocool_in_transit',
			'soocool_planned',
			'soocool_processing',
			'soocool_ready',
			'soocool_shipped',
		);
	}

	/** @return array<int, string> */
	public function non_synced_statuses(): array {
		return array_values( array_unique( array_merge( array( '' ), $this->pending_statuses(), $this->failed_statuses(), $this->cancelled_statuses() ) ) );
	}

	/**
	 * Self-contained colours for badges rendered in compact admin contexts.
	 *
	 * @return array{bg:string, fg:string}
	 */
	public function colors( string $status ): array {
		return match ( $this->tone_class( $status ) ) {
			'is-success' => array(
				'bg' => '#e6f4ea',
				'fg' => '#1e7e34',
			),
			'is-error' => array(
				'bg' => '#fbe9e7',
				'fg' => '#b32d2e',
			),
			'is-warning' => array(
				'bg' => '#fcf3e3',
				'fg' => '#8a6116',
			),
			default => array(
				'bg' => '#eef1f5',
				'fg' => '#50575e',
			),
		};
	}
}
