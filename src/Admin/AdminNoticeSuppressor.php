<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Hides unrelated admin notices on SooCool settings screens.
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
		return 'toplevel_page_' . AdminMenu::PAGE_SLUG === $screen->id;
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
