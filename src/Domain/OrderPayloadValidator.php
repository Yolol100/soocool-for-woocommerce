<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\HttpsUrl;
use SooCool\WooCommerce\Infrastructure\NumericIdentifier;

defined( 'ABSPATH' ) || exit;

final class OrderPayloadValidator {

	private const MAX_TASKS = 2;
	private const MAX_GOODS = 5000;
	private const ALLOWED_WEBHOOK_UPDATES = array( 'task_state', 'planned_time' );

	/** @param array<string, mixed> $payload */
	public function validate_contract_minimums( array $payload ): void {
		if ( ! isset( $payload['orderReference'] ) || ! is_string( $payload['orderReference'] ) || '' === trim( $payload['orderReference'] ) ) {
			throw new PayloadValidationException( __( 'SooCool orderreferentie ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		// The optional webhook block must use HTTPS and supported update types when present.
		if ( array_key_exists( 'webhook', $payload ) ) {
			$webhook = $payload['webhook'];
			$url     = is_array( $webhook ) ? $this->validated_webhook_url( $webhook['webhookUrl'] ?? null ) : '';
			$updates = is_array( $webhook ) ? ( $webhook['webhookUpdates'] ?? null ) : null;
			$updates_are_list = is_array( $updates ) && array_is_list( $updates );
			$updates = $updates_are_list ? array_values( array_filter( array_map( static fn ( mixed $value ): string => is_string( $value ) ? trim( $value ) : '', $updates ) ) ) : array();
			$updates_are_valid = $updates_are_list
				&& array() !== $updates
				&& count( $updates ) === count( array_unique( $updates ) )
				&& array() === array_diff( $updates, self::ALLOWED_WEBHOOK_UPDATES );
			if ( ! is_array( $webhook ) || '' === $url || ! $updates_are_valid ) {
				throw new PayloadValidationException( __( 'SooCool webhookblok is aanwezig maar ongeldig. Vul een geldige HTTPS webhook.webhookUrl en een lijst met webhook.webhookUpdates in, of verwijder het webhookblok.', 'soocool-for-woocommerce' ) );
			}
		}

		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) || ! array_is_list( $tasks ) || array() === $tasks ) {
			throw new PayloadValidationException( __( 'SooCool payload moet minimaal één taak bevatten.', 'soocool-for-woocommerce' ) );
		}
		if ( count( $tasks ) > self::MAX_TASKS ) {
			throw new PayloadValidationException( __( 'SooCool payload mag maximaal één ophaaltaak en één bezorgtaak bevatten.', 'soocool-for-woocommerce' ) );
		}

		$defined_ids = $this->validate_goods_manifest( $payload['goods'] ?? null );

		$delivery_starts = array();
		$pickup_starts   = array();
		$task_counts     = array(
			'delivery' => 0,
			'pickup'   => 0,
		);
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				throw new PayloadValidationException( __( 'Elke SooCool-taak moet een object zijn.', 'soocool-for-woocommerce' ) );
			}

			$task_type = sanitize_key( $this->scalar_string( $task['taskType'] ?? null ) );
			if ( ! in_array( $task_type, array( 'delivery', 'pickup' ), true ) ) {
				throw new PayloadValidationException( __( 'SooCool taskType moet delivery of pickup zijn.', 'soocool-for-woocommerce' ) );
			}

			++$task_counts[ $task_type ];
			if ( $task_counts[ $task_type ] > 1 ) {
				throw new PayloadValidationException( __( 'SooCool payload mag maximaal één ophaaltaak en één bezorgtaak bevatten.', 'soocool-for-woocommerce' ) );
			}

			$start = $this->validate_time_window( $task['timeWindow'] ?? null );
			$this->validate_task_address( $task['address'] ?? null );
			$this->validate_task_contact_info( $task['contactInfo'] ?? null );
			$this->validate_task_goods( $task['goods'] ?? null, $defined_ids );

			if ( 'delivery' === $task_type ) {
				$delivery_starts[] = $start;
			}
			if ( 'pickup' === $task_type ) {
				$pickup_starts[] = $start;
			}
		}

		if ( array() === $delivery_starts ) {
			throw new PayloadValidationException( __( 'SooCool payload moet minimaal één bezorgtaak bevatten.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $pickup_starts as $pickup_start ) {
			foreach ( $delivery_starts as $delivery_start ) {
				if ( ! $this->delivery_is_on_later_date_than_pickup( $delivery_start, $pickup_start ) ) {
					throw new PayloadValidationException( __( 'De SooCool bezorgdatum moet later zijn dan de ophaaldatum wanneer een ophaaltaak wordt gebruikt.', 'soocool-for-woocommerce' ) );
				}
			}
		}
	}

	private function validated_webhook_url( mixed $value ): string {
		$url = HttpsUrl::sanitize( $value );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		return is_array( $parts ) && ! isset( $parts['fragment'] ) ? $url : '';
	}

	/** @param mixed $time_window @return string startTime */
	private function validate_time_window( mixed $time_window ): string {
		if ( ! is_array( $time_window ) ) {
			throw new PayloadValidationException( __( 'SooCool taakveld timeWindow ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		$start = $this->scalar_string( $time_window['startTime'] ?? null );
		$end   = $this->scalar_string( $time_window['endTime'] ?? null );
		if ( '' === trim( $start ) || '' === trim( $end ) ) {
			throw new PayloadValidationException( __( 'SooCool timeWindow moet een startTime en endTime bevatten.', 'soocool-for-woocommerce' ) );
		}

		$start_date_time = $this->parse_iso_8601_date_time( $start );
		$end_date_time   = $this->parse_iso_8601_date_time( $end );
		if ( null === $start_date_time || null === $end_date_time ) {
			throw new PayloadValidationException( __( 'SooCool timeWindow moet geldige ISO-8601 startTime- en endTime-waarden met tijdzone bevatten.', 'soocool-for-woocommerce' ) );
		}
		if ( $end_date_time <= $start_date_time ) {
			throw new PayloadValidationException( __( 'SooCool timeWindow endTime moet later zijn dan startTime.', 'soocool-for-woocommerce' ) );
		}

		return $start;
	}

	/** Parse the strict timestamp format accepted by the SooCool payload contract. */
	private function parse_iso_8601_date_time( string $value ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/', $value ) ) {
			return null;
		}

		try {
			$date_time = new \DateTimeImmutable( $value );
		} catch ( \Exception ) {
			return null;
		}

		return $date_time->format( 'Y-m-d\TH:i:sP' ) === str_replace( 'Z', '+00:00', $value ) ? $date_time : null;
	}

	/** @param mixed $address */
	private function validate_task_address( mixed $address ): void {
		if ( ! is_array( $address ) ) {
			throw new PayloadValidationException( __( 'SooCool taakadres ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'person', 'street', 'houseNumber', 'postCode', 'city', 'country' ) as $field ) {
			if ( ! isset( $address[ $field ] ) || ! is_string( $address[ $field ] ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool address field name. */
						__( 'SooCool adresveld %s ontbreekt.', 'soocool-for-woocommerce' ),
						sanitize_text_field( $field )
					)
				);
			}

			$value = trim( $address[ $field ] );
			if ( '' === $value || ( 'country' === $field && 1 !== preg_match( '/^[A-Za-z]{2}$/', $value ) ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool address field name. */
						__( 'SooCool adresveld %s ontbreekt.', 'soocool-for-woocommerce' ),
						sanitize_text_field( $field )
					)
				);
			}
		}
	}

	/** @param mixed $contact */
	private function validate_task_contact_info( mixed $contact ): void {
		if ( ! is_array( $contact ) ) {
			throw new PayloadValidationException( __( 'SooCool taakveld contactInfo ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'email', 'phone', 'mobile' ) as $field ) {
			if ( array_key_exists( $field, $contact ) && ! is_string( $contact[ $field ] ) ) {
				throw new PayloadValidationException( __( 'SooCool taakveld contactInfo moet tekstwaarden bevatten.', 'soocool-for-woocommerce' ) );
			}
		}

		$email       = isset( $contact['email'] ) ? trim( $contact['email'] ) : '';
		$phone       = isset( $contact['phone'] ) ? trim( $contact['phone'] ) : '';
		$mobile      = isset( $contact['mobile'] ) ? trim( $contact['mobile'] ) : '';
		$has_email   = '' !== $email;
		$has_phone   = '' !== $phone;
		$has_mobile  = '' !== $mobile;
		$valid_email = $has_email && false !== is_email( $email );
		$valid_phone = $has_phone && 1 === preg_match( '/^\+?\d{10,15}$/', $phone );
		$valid_mobile = $has_mobile && 1 === preg_match( '/^\+?\d{10,15}$/', $mobile );

		if ( ( $has_email && ! $valid_email ) || ( $has_phone && ! $valid_phone ) || ( $has_mobile && ! $valid_mobile ) ) {
			throw new PayloadValidationException( __( 'SooCool taakveld contactInfo moet minimaal een e-mailadres, telefoonnummer of mobiel nummer bevatten.', 'soocool-for-woocommerce' ) );
		}

		if ( ! $valid_email && ! $valid_phone && ! $valid_mobile ) {
			throw new PayloadValidationException( __( 'SooCool taakveld contactInfo moet minimaal een e-mailadres, telefoonnummer of mobiel nummer bevatten.', 'soocool-for-woocommerce' ) );
		}
	}

	/**
	 * @param mixed            $goods       Task goods (array of good IDs).
	 * @param array<int, true> $defined_ids Map of good IDs present in the manifest.
	 */
	private function validate_task_goods( mixed $goods, array $defined_ids ): void {
		if ( ! is_array( $goods ) || ! array_is_list( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( __( 'Elke SooCool-taak moet minimaal één good refereren.', 'soocool-for-woocommerce' ) );
		}

		$seen = array();
		foreach ( $goods as $good_id ) {
			$normalized_id = $this->signed_int_or_null( $good_id );
			if ( null === $normalized_id || 0 === $normalized_id ) {
				throw new PayloadValidationException( __( 'SooCool taakveld goods moet een lijst met niet-nul goederen-ID’s zijn.', 'soocool-for-woocommerce' ) );
			}

			if ( ! isset( $defined_ids[ $normalized_id ] ) ) {
				throw new PayloadValidationException( __( 'SooCool-taak verwijst naar een good dat niet in de goods-lijst staat.', 'soocool-for-woocommerce' ) );
			}
			if ( isset( $seen[ $normalized_id ] ) ) {
				throw new PayloadValidationException( __( 'SooCool taakveld goods mag geen dubbele goederen-ID’s bevatten.', 'soocool-for-woocommerce' ) );
			}
			$seen[ $normalized_id ] = true;
		}
	}

	/**
	 * @param mixed $goods
	 * @return array<int, true> Map of defined good IDs.
	 */
	public function validate_goods_manifest( mixed $goods ): array {
		if ( ! is_array( $goods ) || ! array_is_list( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( __( 'SooCool payload moet minimaal één good bevatten.', 'soocool-for-woocommerce' ) );
		}
		if ( count( $goods ) > self::MAX_GOODS ) {
			throw new PayloadValidationException( __( 'SooCool payload bevat meer goederen dan de veiligheidslimiet toestaat.', 'soocool-for-woocommerce' ) );
		}

		$ids = array();
		foreach ( $goods as $good ) {
			if ( ! is_array( $good ) ) {
				throw new PayloadValidationException( __( 'Elke SooCool-good moet een object zijn.', 'soocool-for-woocommerce' ) );
			}

			foreach ( array( 'packagingType', 'contents' ) as $field ) {
				if ( ! isset( $good[ $field ] ) || ! is_string( $good[ $field ] ) || '' === trim( $good[ $field ] ) ) {
					throw new PayloadValidationException(
						sprintf(
							/* translators: %s: SooCool field name. */
							__( 'SooCool-good-veld %s ontbreekt.', 'soocool-for-woocommerce' ),
							sanitize_text_field( $field )
						)
					);
				}
			}

			$good_id = $this->signed_int_or_null( $good['goodId'] ?? null );
			if ( null === $good_id || 0 === $good_id ) {
				throw new PayloadValidationException( __( 'SooCool goodId moet een niet-nul geheel getal zijn.', 'soocool-for-woocommerce' ) );
			}

			if ( isset( $ids[ $good_id ] ) ) {
				throw new PayloadValidationException( __( 'SooCool-goederen-ID’s moeten uniek zijn.', 'soocool-for-woocommerce' ) );
			}

			$this->validate_optional_dimensions( $good['dimensions'] ?? null );
			$this->validate_optional_positive_int( $good['weight'] ?? null, 'weight' );
			$this->validate_optional_transport_requirements( $good['transportRequirements'] ?? null );

			$ids[ $good_id ] = true;
		}

		return $ids;
	}

	/** @param mixed $dimensions */
	private function validate_optional_dimensions( mixed $dimensions ): void {
		if ( null === $dimensions ) {
			return;
		}

		if ( ! is_array( $dimensions ) ) {
			throw new PayloadValidationException( __( 'SooCool-good-dimensions moet een object zijn wanneer dit veld is ingevuld.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'width', 'depth', 'height' ) as $field ) {
			if ( null === NumericIdentifier::positive_integer( $dimensions[ $field ] ?? null ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool field name. */
						__( 'SooCool-good dimensions.%s moet een positief geheel getal zijn.', 'soocool-for-woocommerce' ),
						sanitize_text_field( $field )
					)
				);
			}
		}
	}

	private function validate_optional_positive_int( mixed $value, string $field ): void {
		if ( null === $value ) {
			return;
		}
		if ( null === NumericIdentifier::positive_integer( $value ) ) {
			throw new PayloadValidationException(
				sprintf(
					/* translators: %s: SooCool field name. */
					__( 'SooCool-good %s moet een positief geheel getal zijn.', 'soocool-for-woocommerce' ),
					sanitize_text_field( $field )
				)
			);
		}
	}

	/** @param mixed $requirements */
	private function validate_optional_transport_requirements( mixed $requirements ): void {
		if ( null === $requirements ) {
			return;
		}
		if ( ! is_array( $requirements ) || ! array_is_list( $requirements ) ) {
			throw new PayloadValidationException( __( 'SooCool-good transportRequirements moet een lijst met tekstwaarden zijn wanneer dit veld is ingevuld.', 'soocool-for-woocommerce' ) );
		}

		$valid_values = array_filter(
			$requirements,
			static fn ( mixed $value ): bool => is_string( $value ) && '' !== trim( $value )
		);
		if ( count( $valid_values ) !== count( $requirements ) || array() === $valid_values ) {
			throw new PayloadValidationException( __( 'SooCool-good transportRequirements moet minimaal één niet-lege tekstwaarde bevatten.', 'soocool-for-woocommerce' ) );
		}
	}

	private function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function delivery_is_on_later_date_than_pickup( string $delivery_start, string $pickup_start ): bool {
		try {
			$delivery = new \DateTimeImmutable( $delivery_start );
			$pickup   = new \DateTimeImmutable( $pickup_start );
		} catch ( \Exception ) {
			return false;
		}

		return $delivery->format( 'Y-m-d' ) > $pickup->format( 'Y-m-d' );
	}

	private function signed_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			$validated = filter_var( $value, FILTER_VALIDATE_INT );
			return false !== $validated ? $validated : null;
		}

		return null;
	}
}
