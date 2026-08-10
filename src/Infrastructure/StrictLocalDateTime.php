<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class StrictLocalDateTime {

	public static function from_date_and_time( string $date, string $time, \DateTimeZone $timezone ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $date_parts )
			|| ! checkdate( (int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1] )
			|| 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return null;
		}

		try {
			$date_time = new \DateTimeImmutable( $date . ' ' . $time, $timezone );
		} catch ( \Exception ) {
			return null;
		}

		return $date_time->format( 'Y-m-d H:i' ) === $date . ' ' . $time ? $date_time : null;
	}
}
