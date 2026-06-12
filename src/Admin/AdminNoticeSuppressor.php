<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the SooCool admin screens focused by hiding unrelated admin notices.
 */
final class AdminNoticeSuppressor {

	public function register(): void {
		add_action( 'current_screen', array( $this, 'maybe_suppress_notices' ), 100 );
	}

	public function maybe_suppress_notices( \WP_Screen $screen ): void {
		if ( ! $this->is_soocool_screen( $screen ) ) {
			return;
		}

		foreach ( array( 'admin_notices', 'all_admin_notices' ) as $hook_name ) {
			$this->remove_foreign_callbacks( $hook_name );
		}
	}

	private function is_soocool_screen( \WP_Screen $screen ): bool {
		$ids = array(
			'toplevel_page_' . AdminMenu::PAGE_SLUG,
			'admin_page_' . AdminMenu::MANUAL_TEST_PAGE_SLUG,
		);

		return in_array( $screen->id, $ids, true ) || str_ends_with( $screen->id, '_page_' . AdminMenu::MANUAL_TEST_PAGE_SLUG );
	}

	private function remove_foreign_callbacks( string $hook_name ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
			return;
		}

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback_id => $callback ) {
				if ( ! is_array( $callback ) || ! array_key_exists( 'function', $callback ) ) {
					continue;
				}

				if ( $this->is_own_callback( $callback['function'] ) ) {
					continue;
				}

				unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $callback_id ] );
			}

			if ( array() === $wp_filter[ $hook_name ]->callbacks[ $priority ] ) {
				unset( $wp_filter[ $hook_name ]->callbacks[ $priority ] );
			}
		}
	}

	private function is_own_callback( mixed $callback ): bool {
		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			$target = $callback[0];

			if ( is_object( $target ) ) {
				return str_starts_with( get_class( $target ), 'SooCool\\WooCommerce\\' );
			}

			if ( is_string( $target ) ) {
				return str_starts_with( $target, 'SooCool\\WooCommerce\\' );
			}
		}

		if ( is_string( $callback ) ) {
			return str_starts_with( $callback, 'SooCool\\WooCommerce\\' );
		}

		return false;
	}
}
