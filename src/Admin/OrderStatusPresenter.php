<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for how a SooCool sync status is presented in the
 * admin: its human label, its CSS badge class (used where admin.css is loaded)
 * and a self-contained hex colour pair (used in the orders list where the
 * plugin stylesheet is not enqueued).
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

		return match ( $status ) {
			'pending'   => __( 'In wachtrij', 'soocool-for-woocommerce' ),
			'synced'    => __( 'Gesynchroniseerd', 'soocool-for-woocommerce' ),
			'cancelled',
			'soocool_cancelled' => __( 'Geannuleerd', 'soocool-for-woocommerce' ),
			'failed'    => __( 'Mislukt', 'soocool-for-woocommerce' ),
			''          => __( 'Niet gesynchroniseerd', 'soocool-for-woocommerce' ),
			default     => preg_replace( '/^soocool_/', '', str_replace( '_', ' ', $status ) ) ?: __( 'Onbekend', 'soocool-for-woocommerce' ),
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
		if ( in_array( $status, array( 'failed', 'cancelled', 'soocool_cancelled' ), true ) ) {
			return 'is-error';
		}
		if ( 'pending' === $status ) {
			return 'is-warning';
		}

		return 'is-neutral';
	}

	/**
	 * Self-contained colours for badges rendered outside the settings screen,
	 * where assets/build/admin.css is not loaded.
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
