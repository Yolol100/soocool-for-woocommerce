<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

defined( 'ABSPATH' ) || exit;

final class AddressParser {

	/** @return array{street:string, houseNumber:string} */
	public function split( string $address_1, string $address_2 = '' ): array {
		$street       = trim( wp_strip_all_tags( $address_1 ) );
		$house_number = trim( wp_strip_all_tags( $address_2 ) );

		if ( '' !== $house_number || '' === $street ) {
			return array(
				'street'      => $street,
				'houseNumber' => $house_number,
			);
		}

		if ( preg_match( '/^(.*?)[\s,]+(\d+[\d\w\-\/ ]*)$/u', $street, $matches ) ) {
			$parsed_street = trim( (string) $matches[1] );
			$parsed_number = trim( (string) $matches[2] );
			if ( '' !== $parsed_street && '' !== $parsed_number ) {
				return array(
					'street'      => $parsed_street,
					'houseNumber' => $parsed_number,
				);
			}
		}

		return array(
			'street'      => $street,
			'houseNumber' => '',
		);
	}
}
