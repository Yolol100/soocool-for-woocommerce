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
		$path = self::path( $relative_directory, $file );
		if ( '' === $file || ! is_readable( $path ) ) {
			return $fallback;
		}

		$base_version = '' !== trim( $fallback ) ? trim( $fallback ) : SOOCOOL_VERSION;
		$content_hash = hash_file( 'sha256', $path );

		if ( is_string( $content_hash ) && '' !== $content_hash ) {
			return $base_version . '-' . substr( $content_hash, 0, 12 );
		}

		$mtime = filemtime( $path );

		return false !== $mtime ? $base_version . '-' . (string) $mtime : $base_version;
	}
}
