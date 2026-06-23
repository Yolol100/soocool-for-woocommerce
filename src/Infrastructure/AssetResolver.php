<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class AssetResolver {

	public static function filename( string $relative_directory, string $base, string $extension ): string {
		$suffixes = self::asset_suffixes( $extension );

		foreach ( $suffixes as $suffix ) {
			$file = $base . $suffix . '.' . $extension;
			if ( is_readable( self::path( $relative_directory, $file ) ) ) {
				return $file;
			}
		}

		return '';
	}

	/** @return array<int, string> */
	private static function asset_suffixes( string $extension ): array {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return array( '' );
		}

		if ( 'css' === $extension ) {
			return array( '', '.min' );
		}

		return array( '.min', '' );
	}

	public static function path( string $relative_directory, string $file ): string {
		return trailingslashit( SOOCOOL_PLUGIN_DIR . trim( $relative_directory, '/' ) ) . ltrim( $file, '/' );
	}

	public static function url( string $relative_directory, string $file ): string {
		return trailingslashit( SOOCOOL_PLUGIN_URL . trim( $relative_directory, '/' ) ) . ltrim( $file, '/' );
	}

	public static function version( string $relative_directory, string $file, string $fallback = SOOCOOL_VERSION ): string {
		$mtime = filemtime( self::path( $relative_directory, $file ) );

		return false !== $mtime ? (string) $mtime : $fallback;
	}
}
