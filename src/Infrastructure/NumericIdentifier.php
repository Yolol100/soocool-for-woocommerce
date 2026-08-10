<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class NumericIdentifier {

	public static function positive( mixed $value ): ?int {
		$id = self::non_zero( $value );
		return null !== $id && $id > 0 ? $id : null;
	}

	public static function positive_integer( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$digits = ltrim( $value, '0' );
			if ( '' === $digits ) {
				return null;
			}

			$validated = filter_var( $digits, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			return false !== $validated ? $validated : null;
		}

		if ( is_float( $value ) && is_finite( $value ) && $value > 0 && $value < PHP_INT_MAX && floor( $value ) === $value ) {
			return (int) $value;
		}

		return null;
	}

	/** @param array<mixed> $values @return array<int, int> */
	public static function positive_list( array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			$id = self::positive( $value );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public static function non_zero( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return 0 !== $value ? $value : null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			return null;
		}

		$negative = str_starts_with( $value, '-' );
		$digits   = ltrim( $negative ? substr( $value, 1 ) : $value, '0' );
		if ( '' === $digits ) {
			return null;
		}

		$canonical = $negative ? '-' . $digits : $digits;
		$id        = filter_var( $canonical, FILTER_VALIDATE_INT );
		return false !== $id && 0 !== $id ? $id : null;
	}
}
