<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

defined( 'ABSPATH' ) || exit;

final class OrderPayloadValidator {

	/** @param array<string, mixed> $payload */
	public function validate_contract_minimums( array $payload ): void {
		if ( '' === trim( (string) ( $payload['orderReference'] ?? '' ) ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool orderreferentie ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		// Per the SooCool OpenAPI contract (1.2.1) only orderReference, tasks and goods
		// are required; the webhook block is optional. Sites without a public HTTPS
		// callback (local/staging or HTTP installs) must still be able to create orders.
		// Status/track & trace can then be pulled with the "refresh status" order action.
		// The webhook is sent automatically whenever an HTTPS URL is available, so when it
		// is present we still validate its shape to avoid sending a malformed block.
		if ( array_key_exists( 'webhook', $payload ) ) {
			$webhook = $payload['webhook'];
			if ( ! is_array( $webhook ) || empty( $webhook['webhookUrl'] ) || empty( $webhook['webhookUpdates'] ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool webhookblok is aanwezig maar onvolledig. Vul een HTTPS webhook.webhookUrl en webhook.webhookUpdates in, of verwijder het webhookblok.', 'soocool-for-woocommerce' ) );
			}
		}

		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) || array() === $tasks ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload moet minimaal één taak bevatten.', 'soocool-for-woocommerce' ) );
		}

		$defined_ids = $this->validate_goods_manifest( $payload['goods'] ?? null );

		$delivery_starts = array();
		$pickup_starts   = array();
		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				throw new PayloadValidationException( esc_html__( 'Elke SooCool-taak moet een object zijn.', 'soocool-for-woocommerce' ) );
			}

			$task_type = sanitize_key( (string) ( $task['taskType'] ?? '' ) );
			if ( ! in_array( $task_type, array( 'delivery', 'pickup' ), true ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool taskType moet delivery of pickup zijn.', 'soocool-for-woocommerce' ) );
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
			throw new PayloadValidationException( esc_html__( 'SooCool payload moet minimaal één bezorgtaak bevatten.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $pickup_starts as $pickup_start ) {
			foreach ( $delivery_starts as $delivery_start ) {
				if ( ! $this->delivery_is_on_later_date_than_pickup( $delivery_start, $pickup_start ) ) {
					throw new PayloadValidationException( esc_html__( 'De SooCool bezorgdatum moet later zijn dan de ophaaldatum wanneer een ophaaltaak wordt gebruikt.', 'soocool-for-woocommerce' ) );
				}
			}
		}
	}

	/** @param mixed $time_window @return string startTime */
	private function validate_time_window( mixed $time_window ): string {
		if ( ! is_array( $time_window ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool taakveld timeWindow ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		$start = (string) ( $time_window['startTime'] ?? '' );
		$end   = (string) ( $time_window['endTime'] ?? '' );
		if ( '' === trim( $start ) || '' === trim( $end ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool timeWindow moet een startTime en endTime bevatten.', 'soocool-for-woocommerce' ) );
		}

		$start_timestamp = strtotime( $start );
		$end_timestamp   = strtotime( $end );
		if ( false === $start_timestamp || false === $end_timestamp || $end_timestamp <= $start_timestamp ) {
			throw new PayloadValidationException( esc_html__( 'SooCool timeWindow endTime moet later zijn dan startTime.', 'soocool-for-woocommerce' ) );
		}

		return $start;
	}

	/** @param mixed $address */
	private function validate_task_address( mixed $address ): void {
		if ( ! is_array( $address ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool taakadres ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'person', 'street', 'houseNumber', 'postCode', 'city', 'country' ) as $field ) {
			if ( '' === trim( (string) ( $address[ $field ] ?? '' ) ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool address field name. */
						esc_html__( 'SooCool adresveld %s ontbreekt.', 'soocool-for-woocommerce' ),
						esc_html( $field )
					)
				);
			}
		}
	}

	/** @param mixed $contact */
	private function validate_task_contact_info( mixed $contact ): void {
		if ( ! is_array( $contact ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool taakveld contactInfo ontbreekt.', 'soocool-for-woocommerce' ) );
		}

		$has_email  = '' !== trim( (string) ( $contact['email'] ?? '' ) );
		$has_phone  = '' !== trim( (string) ( $contact['phone'] ?? '' ) );
		$has_mobile = '' !== trim( (string) ( $contact['mobile'] ?? '' ) );

		if ( ! $has_email && ! $has_phone && ! $has_mobile ) {
			throw new PayloadValidationException( esc_html__( 'SooCool taakveld contactInfo moet minimaal een e-mailadres, telefoonnummer of mobiel nummer bevatten.', 'soocool-for-woocommerce' ) );
		}
	}

	/**
	 * @param mixed            $goods       Task goods (array of good IDs).
	 * @param array<int, true> $defined_ids Map of good IDs present in the manifest.
	 */
	private function validate_task_goods( mixed $goods, array $defined_ids ): void {
		if ( ! is_array( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( esc_html__( 'Elke SooCool-taak moet minimaal één good refereren.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $goods as $good_id ) {
			$normalized_id = $this->signed_int_or_null( $good_id );
			if ( null === $normalized_id || 0 === $normalized_id ) {
				throw new PayloadValidationException( esc_html__( 'SooCool taakveld goods moet een lijst met niet-nul goederen-ID’s zijn.', 'soocool-for-woocommerce' ) );
			}

			if ( ! isset( $defined_ids[ $normalized_id ] ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool-taak verwijst naar een good dat niet in de goods-lijst staat.', 'soocool-for-woocommerce' ) );
			}
		}
	}

	/**
	 * @param mixed $goods
	 * @return array<int, true> Map of defined good IDs.
	 */
	private function validate_goods_manifest( mixed $goods ): array {
		if ( ! is_array( $goods ) || array() === $goods ) {
			throw new PayloadValidationException( esc_html__( 'SooCool payload moet minimaal één good bevatten.', 'soocool-for-woocommerce' ) );
		}

		$ids = array();
		foreach ( $goods as $good ) {
			if ( ! is_array( $good ) ) {
				throw new PayloadValidationException( esc_html__( 'Elke SooCool-good moet een object zijn.', 'soocool-for-woocommerce' ) );
			}

			foreach ( array( 'packagingType', 'contents' ) as $field ) {
				if ( '' === trim( (string) ( $good[ $field ] ?? '' ) ) ) {
					throw new PayloadValidationException(
						sprintf(
							/* translators: %s: SooCool field name. */
							esc_html__( 'SooCool-good-veld %s ontbreekt.', 'soocool-for-woocommerce' ),
							esc_html( $field )
						)
					);
				}
			}

			$good_id = $this->signed_int_or_null( $good['goodId'] ?? null );
			if ( null === $good_id || 0 === $good_id ) {
				throw new PayloadValidationException( esc_html__( 'SooCool goodId moet een niet-nul geheel getal zijn.', 'soocool-for-woocommerce' ) );
			}

			if ( isset( $ids[ $good_id ] ) ) {
				throw new PayloadValidationException( esc_html__( 'SooCool-goederen-ID’s moeten uniek zijn.', 'soocool-for-woocommerce' ) );
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
			throw new PayloadValidationException( esc_html__( 'SooCool-good-dimensions moet een object zijn wanneer dit veld is ingevuld.', 'soocool-for-woocommerce' ) );
		}

		foreach ( array( 'width', 'depth', 'height' ) as $field ) {
			if ( null === $this->positive_int_or_null( $dimensions[ $field ] ?? null ) ) {
				throw new PayloadValidationException(
					sprintf(
						/* translators: %s: SooCool field name. */
						esc_html__( 'SooCool-good dimensions.%s moet een positief geheel getal zijn.', 'soocool-for-woocommerce' ),
						esc_html( $field )
					)
				);
			}
		}
	}

	private function validate_optional_positive_int( mixed $value, string $field ): void {
		if ( null === $value ) {
			return;
		}
		if ( null === $this->positive_int_or_null( $value ) ) {
			throw new PayloadValidationException(
				sprintf(
					/* translators: %s: SooCool field name. */
					esc_html__( 'SooCool-good %s moet een positief geheel getal zijn.', 'soocool-for-woocommerce' ),
					esc_html( $field )
				)
			);
		}
	}

	/** @param mixed $requirements */
	private function validate_optional_transport_requirements( mixed $requirements ): void {
		if ( null === $requirements ) {
			return;
		}
		if ( ! is_array( $requirements ) || array() === array_values( array_filter( $requirements, static fn ( mixed $value ): bool => '' !== trim( (string) $value ) ) ) ) {
			throw new PayloadValidationException( esc_html__( 'SooCool-good transportRequirements moet minimaal één waarde bevatten wanneer dit veld is ingevuld.', 'soocool-for-woocommerce' ) );
		}
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


	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}
		if ( is_float( $value ) && $value > 0 && floor( $value ) === $value ) {
			return (int) $value;
		}
		return null;
	}

	private function signed_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}
}
