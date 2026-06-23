<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class OptionDefaults {

	/** @return array<string, mixed> */
	public function settings(): array {
		return array(
			'environment'                => 'test',
			'test_base_url'              => 'https://api.staging.soocool.nl',
			'production_base_url'        => 'https://api.soocool.nl',
			'api_key'                    => '',
			'test_api_key'               => '',
			'production_api_key'         => '',
			'enable_pickup'              => false,
			'order_reference_prefix'     => '',
			'pickup_company'             => get_bloginfo( 'name' ),
			'pickup_contact_name'        => '',
			'pickup_email'               => get_option( 'admin_email' ),
			'pickup_phone'               => '',
			'pickup_street'              => '',
			'pickup_house_number'        => '',
			'pickup_postal_code'         => '',
			'pickup_city'                => '',
			'pickup_country'             => 'NL',
			'pickup_days_offset'         => 1,
			'pickup_time_from'           => '08:00',
			'pickup_time_to'             => '18:00',
			'delivery_time_from'         => '08:00',
			'delivery_time_to'           => '18:00',
			'delivery_days_offset'       => 1,
			'checkout_delivery_enabled'  => true,
			'checkout_delivery_days_ahead' => 92,
			'checkout_delivery_holidays' => '',
			'checkout_delivery_rules'    => $this->delivery_rules(),
			'checkout_delivery_time_slots' => $this->delivery_time_slots(),
			'checkout_delivery_schedule' => $this->delivery_schedule(),
			'checkout_delivery_hide_unavailable_slots' => true,
			'auto_submit_enabled'        => false,
			'auto_submit_status'         => 'processing',
			'allow_resubmit'             => false,
			'label_output'               => 'a6',
			'webhook_url'                => '',
			'webhook_secret'             => '',
			'goods_description_fallback' => 'WooCommerce order',
			'packaging_type'             => 'box',
			'temperature_regime'         => 'cooled',
			'package_width'              => 60,
			'package_depth'              => 40,
			'package_height'             => 11,
			'package_weight'             => 1600,
			'log_retention'              => 100,
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function delivery_rules(): array {
		return array(
			array(
				'enabled'          => true,
				'delivery_weekday' => 'monday',
				'cutoff_weekday'   => 'saturday',
				'cutoff_time'      => '13:00',
			),
			array(
				'enabled'          => true,
				'delivery_weekday' => 'thursday',
				'cutoff_weekday'   => 'wednesday',
				'cutoff_time'      => '13:00',
			),
			array(
				'enabled'          => true,
				'delivery_weekday' => 'saturday',
				'cutoff_weekday'   => 'friday',
				'cutoff_time'      => '13:00',
			),
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function delivery_time_slots( ?array $weekdays = null ): array {
		$weekdays = $weekdays ?? $this->allowed_weekdays();

		return array(
			array(
				'enabled'     => true,
				'label'       => 'Ochtend',
				'time_from'   => '08:00',
				'time_to'     => '18:00',
				'cutoff_time' => '08:00',
				'weekdays'    => $weekdays,
				'sort_order'  => 10,
			),
			array(
				'enabled'     => true,
				'label'       => 'Avond',
				'time_from'   => '17:00',
				'time_to'     => '22:00',
				'cutoff_time' => '17:00',
				'weekdays'    => $weekdays,
				'sort_order'  => 20,
			),
		);
	}

	/** @return array<int, array<string, mixed>> */
	public function delivery_schedule(): array {
		$schedule = array();
		foreach ( $this->delivery_rules() as $index => $rule ) {
			$delivery_weekday = (string) $rule['delivery_weekday'];
			$schedule[] = array(
				'enabled'          => (bool) $rule['enabled'],
				'delivery_weekday' => $delivery_weekday,
				'cutoff_weekday'   => (string) $rule['cutoff_weekday'],
				'cutoff_time'      => (string) $rule['cutoff_time'],
				'sort_order'       => ( (int) $index + 1 ) * 10,
				'slots'            => $this->delivery_time_slots( array( $delivery_weekday ) ),
			);
		}

		return $schedule;
	}

	/** @return array<int, string> */
	private function allowed_weekdays(): array {
		return array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
	}
}
