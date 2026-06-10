<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Infrastructure\OptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {

	public const PAGE_SLUG             = 'soocool-for-woocommerce';
	public const MANUAL_TEST_PAGE_SLUG = 'soocool-manual-order-test';
	private const ACTION               = 'soocool_manual_test_order';
	private const NONCE_ACTION         = 'soocool_manual_test_order';
	private const RESULT_TRANSIENT     = 'soocool_manual_test_order_result_';

	public function __construct( private readonly ApiClient $client, private readonly OptionRepository $options ) {}

	public function register(): void {
		add_menu_page(
			__( 'SooCool for WooCommerce', 'soocool-for-woocommerce' ),
			__( 'SooCool', 'soocool-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			'dashicons-location-alt',
			56
		);

		// Keep the API-Test page available, but remove it from the WordPress left submenu.
		// The page is linked from the SooCool settings tab navigation instead.
		add_submenu_page(
			null,
			__( 'SooCool API-Test', 'soocool-for-woocommerce' ),
			__( 'API-Test', 'soocool-for-woocommerce' ),
			'manage_woocommerce',
			self::MANUAL_TEST_PAGE_SLUG,
			array( $this, 'render_manual_test_page' )
		);
	}

	public function register_post_handlers(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_manual_test_order' ) );
	}

	public function render(): void {
		echo '<div class="wrap"><div id="soocool-admin-app"></div></div>';
	}

	public function render_manual_test_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this SooCool test page.', 'soocool-for-woocommerce' ) );
		}

		$settings = $this->options->all();
		$result   = $this->consume_result();
		$values   = $this->default_form_values();

		?>
		<div class="wrap soocool-shell soocool-manual-test-page">
			<section class="soocool-panel soocool-tabs" aria-label="<?php echo esc_attr__( 'SooCool API-Test', 'soocool-for-woocommerce' ); ?>">
				<?php $this->render_tab_navigation( 'api_test' ); ?>
				<div class="components-tab-panel__tab-content">
				<article class="soocool-card">
					<header class="soocool-card-header">
						<div>
							<h2><?php echo esc_html__( 'SooCool API-Test', 'soocool-for-woocommerce' ); ?></h2>
							<p class="soocool-muted"><?php echo esc_html__( 'Stuur een handmatige testpayload naar SooCool met dezelfde API-key en omgeving als de hoofdinstellingen. Gebruik dit voor stagingvalidatie en API-contractcontrole.', 'soocool-for-woocommerce' ); ?></p>
						</div>
						<span class="soocool-pill is-subtle"><?php echo esc_html__( 'API-Test', 'soocool-for-woocommerce' ); ?></span>
					</header>

					<div class="soocool-fields">
						<?php $this->render_environment_notice( $settings ); ?>
						<?php $this->render_result( $result ); ?>

						<?php if ( array() !== $result ) : ?>
							<div class="soocool-actions soocool-manual-back">
								<a class="button button-primary soocool-manual-submit" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MANUAL_TEST_PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Vorige', 'soocool-for-woocommerce' ); ?></a>
							</div>
						<?php else : ?>
						<form class="soocool-manual-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>

							<section class="soocool-settings-card is-soft">
								<h3><?php echo esc_html__( 'Ordergegevens', 'soocool-for-woocommerce' ); ?></h3>
								<div class="soocool-field-grid two">
									<?php $this->text_row( 'orderReference', __( 'Orderreferentie', 'soocool-for-woocommerce' ), $values['orderReference'], __( 'Verplicht volgens SooCool. Gebruik een unieke referentie per test.', 'soocool-for-woocommerce' ) ); ?>
								</div>
							</section>

							<section class="soocool-settings-card">
								<h3><?php echo esc_html__( 'Taak', 'soocool-for-woocommerce' ); ?></h3>
								<div class="soocool-field-grid two">
									<div class="soocool-manual-field">
										<label for="taskType_display"><?php echo esc_html__( 'taskType', 'soocool-for-woocommerce' ); ?></label>
										<input id="taskType_display" type="text" value="delivery" readonly disabled />
										<input type="hidden" name="taskType" value="delivery" />
										<p class="soocool-field-help"><?php echo esc_html__( 'Deze API-Test verstuurt één delivery task. Pickup + delivery test je via een echte WooCommerce order met pickup ingeschakeld.', 'soocool-for-woocommerce' ); ?></p>
									</div>
									<?php $this->text_row( 'instructions', __( 'instructions', 'soocool-for-woocommerce' ), $values['instructions'], __( 'Optioneel. Aflever-instructies.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->datetime_row( 'startTime', __( 'timeWindow.startTime', 'soocool-for-woocommerce' ), $values['startTime'] ); ?>
									<?php $this->datetime_row( 'endTime', __( 'timeWindow.endTime', 'soocool-for-woocommerce' ), $values['endTime'] ); ?>
								</div>
							</section>

							<section class="soocool-settings-card">
								<h3><?php echo esc_html__( 'Adres', 'soocool-for-woocommerce' ); ?></h3>
								<div class="soocool-field-grid three">
									<?php $this->text_row( 'person', __( 'person', 'soocool-for-woocommerce' ), $values['person'], __( 'Verplicht. Naam van de ontvanger.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'street', __( 'street', 'soocool-for-woocommerce' ), $values['street'], __( 'Verplicht.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'houseNumber', __( 'houseNumber', 'soocool-for-woocommerce' ), $values['houseNumber'], __( 'Verplicht.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'postCode', __( 'postCode', 'soocool-for-woocommerce' ), $values['postCode'], __( 'Verplicht. Spaties worden verwijderd.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'city', __( 'city', 'soocool-for-woocommerce' ), $values['city'], __( 'Verplicht.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'country', __( 'country', 'soocool-for-woocommerce' ), $values['country'], __( 'Verplicht. ISO 3166-1 alpha-2.', 'soocool-for-woocommerce' ) ); ?>
								</div>
							</section>

							<section class="soocool-settings-card">
								<h3><?php echo esc_html__( 'Contact', 'soocool-for-woocommerce' ); ?></h3>
								<div class="soocool-field-grid three">
									<?php $this->text_row( 'email', __( 'email', 'soocool-for-woocommerce' ), $values['email'], __( 'Optioneel.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'phone', __( 'phone', 'soocool-for-woocommerce' ), $values['phone'], __( 'Optioneel.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'mobile', __( 'mobile', 'soocool-for-woocommerce' ), $values['mobile'], __( 'Optioneel. Gebruikt voor SMS track & trace.', 'soocool-for-woocommerce' ) ); ?>
								</div>
							</section>

							<section class="soocool-settings-card">
								<h3><?php echo esc_html__( 'Good / pakket', 'soocool-for-woocommerce' ); ?></h3>
								<div class="soocool-field-grid three">
									<?php $this->text_row( 'goodId', __( 'goodId', 'soocool-for-woocommerce' ), $values['goodId'], __( 'Verplicht. Negatieve requested ID, bijvoorbeeld -1.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'packagingType', __( 'packagingType', 'soocool-for-woocommerce' ), $values['packagingType'], __( 'Verplicht, bijvoorbeeld box.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'contents', __( 'contents', 'soocool-for-woocommerce' ), $values['contents'], __( 'Verplicht.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'transportRequirements', __( 'transportRequirements', 'soocool-for-woocommerce' ), $values['transportRequirements'], __( 'Optioneel: cooled, frozen of ambient.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'weight', __( 'weight (gram)', 'soocool-for-woocommerce' ), $values['weight'], __( 'Optioneel. Geheel getal in gram.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'barcode', __( 'barcode', 'soocool-for-woocommerce' ), $values['barcode'], __( 'Optioneel.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'dimWidth', __( 'dimensions.width (cm)', 'soocool-for-woocommerce' ), $values['dimWidth'], __( 'Optioneel. Vul width, depth én height samen in.', 'soocool-for-woocommerce' ) ); ?>
									<?php $this->text_row( 'dimDepth', __( 'dimensions.depth (cm)', 'soocool-for-woocommerce' ), $values['dimDepth'], '' ); ?>
									<?php $this->text_row( 'dimHeight', __( 'dimensions.height (cm)', 'soocool-for-woocommerce' ), $values['dimHeight'], '' ); ?>
								</div>
							</section>

							<section class="soocool-settings-card">
								<h3><?php echo esc_html__( 'Extra JSON', 'soocool-for-woocommerce' ); ?></h3>
								<div class="soocool-manual-field is-full">
									<label for="extra_json"><?php echo esc_html__( 'Extra rootvelden', 'soocool-for-woocommerce' ); ?></label>
									<textarea name="extra_json" id="extra_json" rows="8" class="large-text code" spellcheck="false"><?php echo esc_textarea( (string) $values['extra_json'] ); ?></textarea>
									<p class="soocool-field-help"><?php echo esc_html__( 'Optioneel: voeg alleen geldige JSON toe voor rootvelden die SooCool verwacht.', 'soocool-for-woocommerce' ); ?></p>
								</div>
							</section>

							<div class="soocool-actions">
								<button type="submit" class="button button-primary soocool-manual-submit"><?php echo esc_html__( 'Verstuur test naar SooCool', 'soocool-for-woocommerce' ); ?></button>
							</div>
						</form>
						<?php endif; ?>
					</div>
				</article>
				</div>
			</section>
		</div>
		<?php
	}

	private function render_tab_navigation( string $active ): void {
		$tabs = array(
			'connection' => array(
				'label' => __( 'API connection', 'soocool-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG ),
			),
			'mapping' => array(
				'label' => __( 'Pickup & delivery', 'soocool-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#mapping' ),
			),
			'automation' => array(
				'label' => __( 'Automation', 'soocool-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#automation' ),
			),
			'labels' => array(
				'label' => __( 'Shipping labels', 'soocool-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#labels' ),
			),
			'api_test' => array(
				'label' => __( 'API-Test', 'soocool-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=' . self::MANUAL_TEST_PAGE_SLUG ),
			),
			'logs' => array(
				'label' => __( 'Activity logs', 'soocool-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#logs' ),
			),
		);
		?>
		<nav class="components-tab-panel__tabs" aria-label="<?php echo esc_attr__( 'SooCool settings sections', 'soocool-for-woocommerce' ); ?>">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<a class="components-button soocool-tab soocool-tab-link<?php echo $active === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tab['url'] ); ?>"><?php echo esc_html( $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	public function handle_manual_test_order(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to send SooCool test orders.', 'soocool-for-woocommerce' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$values = $this->read_form_values();
		$result = array(
			'success' => false,
		);

		try {
			$payload  = $this->payload_from_values( $values );
			$response = $this->client->create_order( $payload );
			$result   = array_merge(
				$result,
				array(
					'success' => true,
					'status'  => $response->status_code(),
					'message' => __( 'SooCool heeft de testaanvraag geaccepteerd.', 'soocool-for-woocommerce' ),
					'payload' => $this->redact_debug_data( $payload ),
					'body'    => $this->redact_debug_data( $response->body() ),
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			$result['message'] = sanitize_text_field( $exception->getMessage() );
		} catch ( ApiException $exception ) {
			$result['status']  = $exception->status_code();
			$result['message'] = sanitize_text_field( $exception->getMessage() );
			$result['errors']  = array_map( 'sanitize_text_field', $exception->errors() );
			if ( isset( $payload ) && is_array( $payload ) ) {
				$result['payload'] = $this->redact_debug_data( $payload );
			}
		} catch ( \Throwable $exception ) {
			$result['message'] = __( 'De handmatige SooCool test is mislukt. Controleer de logs voor details.', 'soocool-for-woocommerce' );
		}

		set_transient( $this->result_transient_key(), $result, MINUTE_IN_SECONDS * 10 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MANUAL_TEST_PAGE_SLUG ) );
		exit;
	}

	/** @return array<string, string> */
	private function default_form_values(): array {
		$settings           = $this->options->all();
		$delivery_time_from = $this->valid_time( (string) ( $settings['delivery_time_from'] ?? '' ), '08:00' );
		$delivery_time_to   = $this->valid_time( (string) ( $settings['delivery_time_to'] ?? '' ), '18:00' );
		if ( $delivery_time_to <= $delivery_time_from ) {
			$delivery_time_from = '08:00';
			$delivery_time_to   = '18:00';
		}

		try {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$base     = function_exists( 'current_datetime' ) ? current_datetime() : new \DateTimeImmutable( 'now', $timezone );
			$start    = $this->date_time_with_time( $base->modify( '+1 day' ), $delivery_time_from );
			$end      = $this->date_time_with_time( $base->modify( '+1 day' ), $delivery_time_to );
		} catch ( \Exception ) {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$start    = new \DateTimeImmutable( 'tomorrow ' . $delivery_time_from, $timezone );
			$end      = new \DateTimeImmutable( 'tomorrow ' . $delivery_time_to, $timezone );
		}

		return array(
			'orderReference'        => 'SOOCOOL-MANUAL-' . wp_date( 'YmdHis' ),
			'taskType'              => 'delivery',
			'startTime'             => $start->format( 'Y-m-d\TH:i' ),
			'endTime'               => $end->format( 'Y-m-d\TH:i' ),
			'instructions'          => '',
			'person'                => 'Test Klant',
			'street'                => 'Teststraat',
			'houseNumber'           => '1',
			'postCode'              => '1234AB',
			'city'                  => 'Amsterdam',
			'country'               => 'NL',
			'email'                 => 'test@example.com',
			'phone'                 => '0612345678',
			'mobile'                => '0612345678',
			'goodId'                => '-1',
			'packagingType'         => (string) ( $settings['packaging_type'] ?? 'box' ),
			'contents'              => 'SooCool testpakket',
			'transportRequirements' => (string) ( $settings['temperature_regime'] ?? '' ),
			'weight'                => (string) ( $settings['package_weight'] ?? '1600' ),
			'dimWidth'              => (string) ( $settings['package_width'] ?? '60' ),
			'dimDepth'              => (string) ( $settings['package_depth'] ?? '40' ),
			'dimHeight'             => (string) ( $settings['package_height'] ?? '11' ),
			'barcode'               => '',
			'extra_json'            => '',
		);
	}

	private function valid_time( string $time, string $fallback ): string {
		$time = trim( $time );
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : $fallback;
	}

	private function date_time_with_time( \DateTimeImmutable $date, string $time ): \DateTimeImmutable {
		$parts = explode( ':', $time );
		return $date->setTime( (int) $parts[0], (int) $parts[1] );
	}

	private function has_time_window_agreement_error( array $result ): bool {
		$haystack = (string) ( $result['message'] ?? '' );
		if ( isset( $result['errors'] ) && is_array( $result['errors'] ) ) {
			$haystack .= ' ' . implode( ' ', array_map( 'strval', $result['errors'] ) );
		}

		return false !== stripos( $haystack, 'time window' ) && false !== stripos( $haystack, 'agreement' );
	}

	/** @return array<string, string> */
	private function read_form_values(): array {
		$defaults = $this->default_form_values();
		$values   = array();
		foreach ( $defaults as $key => $default ) {
			$raw            = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$values[ $key ] = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : $default;
		}

		if ( isset( $_POST['extra_json'] ) ) {
			$raw_extra_json       = wp_unslash( $_POST['extra_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserved until json_decode; output is escaped and decoded payload is validated.
			$values['extra_json'] = is_scalar( $raw_extra_json ) ? trim( (string) $raw_extra_json ) : '';
		}

		return $values;
	}

	/** @param array<string, string> $values @return array<string, mixed> */
	private function payload_from_values( array $values ): array {
		foreach ( array( 'orderReference', 'taskType', 'startTime', 'endTime', 'person', 'street', 'houseNumber', 'postCode', 'city', 'country', 'goodId', 'packagingType', 'contents' ) as $required ) {
			if ( '' === trim( $values[ $required ] ?? '' ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %s: field name. */
						__( '%s is verplicht voor deze SooCool test.', 'soocool-for-woocommerce' ),
						$required
					)
				);
			}
		}

		$task_type = sanitize_key( $values['taskType'] );
		if ( ! in_array( $task_type, array( 'delivery', 'pickup' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'taskType moet delivery of pickup zijn.', 'soocool-for-woocommerce' ) );
		}

		$good_id = $this->parse_non_zero_int( $values['goodId'] );
		if ( null === $good_id ) {
			throw new \InvalidArgumentException( esc_html__( 'goodId moet een niet-nul geheel getal zijn (gebruik bijv. -1).', 'soocool-for-woocommerce' ) );
		}

		$start_time = $this->normalize_datetime( $values['startTime'] );
		$end_time   = $this->normalize_datetime( $values['endTime'] );
		if ( strtotime( $end_time ) <= strtotime( $start_time ) ) {
			throw new \InvalidArgumentException( esc_html__( 'endTime moet later zijn dan startTime.', 'soocool-for-woocommerce' ) );
		}

		$task = $this->compact_array(
			array(
				'taskType'     => $task_type,
				'timeWindow'   => array(
					'startTime' => $start_time,
					'endTime'   => $end_time,
				),
				'instructions' => sanitize_text_field( $values['instructions'] ),
				'address'      => array(
					'person'      => sanitize_text_field( $values['person'] ),
					'street'      => sanitize_text_field( $values['street'] ),
					'houseNumber' => sanitize_text_field( $values['houseNumber'] ),
					'postCode'    => $this->postal_code( $values['postCode'] ),
					'city'        => sanitize_text_field( $values['city'] ),
					'country'     => $this->country_code( $values['country'] ),
				),
				'contactInfo'  => $this->compact_array(
					array(
						'email'  => sanitize_email( $values['email'] ),
						'phone'  => sanitize_text_field( $values['phone'] ),
						'mobile' => sanitize_text_field( $values['mobile'] ),
					)
				),
				'goods'        => array( $good_id ),
			)
		);

		$good = $this->compact_array(
			array(
				'goodId'        => $good_id,
				'packagingType' => sanitize_text_field( $values['packagingType'] ),
				'contents'      => sanitize_text_field( $values['contents'] ),
				'barcode'       => sanitize_text_field( $values['barcode'] ),
			)
		);

		$regime = sanitize_key( $values['transportRequirements'] );
		if ( '' !== $regime ) {
			$good['transportRequirements'] = array( $regime );
		}

		$dimensions = $this->parse_dimensions( $values['dimWidth'], $values['dimDepth'], $values['dimHeight'] );
		if ( null !== $dimensions ) {
			$good['dimensions'] = $dimensions;
		}

		$weight = $this->parse_positive_int( $values['weight'] );
		if ( null !== $weight ) {
			$good['weight'] = $weight;
		}

		$payload = array(
			'orderReference' => sanitize_text_field( $values['orderReference'] ),
			'tasks'          => array( $task ),
			'goods'          => array( $good ),
		);

		$extra_json = trim( $values['extra_json'] ?? '' );
		if ( '' !== $extra_json ) {
			$decoded = json_decode( $extra_json, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Extra JSON moet een geldig JSON-object zijn.', 'soocool-for-woocommerce' ) );
			}
			$payload = array_replace_recursive( $payload, $decoded );
		}

		$this->validate_payload_minimums( $payload );

		return $payload;
	}

	/** @param array<string, mixed> $payload */
	private function validate_payload_minimums( array $payload ): void {
		if ( '' === trim( (string) ( $payload['orderReference'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( esc_html__( 'orderReference is verplicht in de SooCool payload.', 'soocool-for-woocommerce' ) );
		}

		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) || array() === $tasks ) {
			throw new \InvalidArgumentException( esc_html__( 'tasks moet minimaal één task-object bevatten.', 'soocool-for-woocommerce' ) );
		}

		$defined_ids = $this->validate_goods_manifest( $payload['goods'] ?? array() );

		$delivery_starts = array();
		$pickup_starts   = array();

		foreach ( $tasks as $index => $task ) {
			if ( ! is_array( $task ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Elke task in tasks moet een object zijn.', 'soocool-for-woocommerce' ) );
			}

			$task_type = sanitize_key( (string) ( $task['taskType'] ?? '' ) );
			if ( ! in_array( $task_type, array( 'delivery', 'pickup' ), true ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Elke taskType moet delivery of pickup zijn.', 'soocool-for-woocommerce' ) );
			}

			$time_window = $task['timeWindow'] ?? null;
			if ( ! is_array( $time_window ) || '' === trim( (string) ( $time_window['startTime'] ?? '' ) ) || '' === trim( (string) ( $time_window['endTime'] ?? '' ) ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %d: task index. */
						esc_html__( 'tasks[%d].timeWindow (startTime/endTime) is verplicht.', 'soocool-for-woocommerce' ),
						absint( $index )
					)
				);
			}

			$start = strtotime( (string) $time_window['startTime'] );
			$end   = strtotime( (string) $time_window['endTime'] );
			if ( false === $start || false === $end || $end <= $start ) {
				throw new \InvalidArgumentException( esc_html__( 'Elke timeWindow.endTime moet later zijn dan startTime.', 'soocool-for-woocommerce' ) );
			}

			$address = $task['address'] ?? null;
			if ( ! is_array( $address ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %d: task index. */
						esc_html__( 'tasks[%d].address is verplicht.', 'soocool-for-woocommerce' ),
						absint( $index )
					)
				);
			}
			foreach ( array( 'person', 'street', 'houseNumber', 'postCode', 'city', 'country' ) as $field ) {
				if ( '' === trim( (string) ( $address[ $field ] ?? '' ) ) ) {
					throw new \InvalidArgumentException(
						sprintf(
							/* translators: 1: task index, 2: address field name. */
							esc_html__( 'tasks[%1$d].address.%2$s is verplicht.', 'soocool-for-woocommerce' ),
							absint( $index ),
							$field
						)
					);
				}
			}

			$task_goods = $task['goods'] ?? null;
			if ( ! is_array( $task_goods ) || array() === $task_goods ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %d: task index. */
						esc_html__( 'tasks[%d].goods moet minimaal één good-ID bevatten.', 'soocool-for-woocommerce' ),
						absint( $index )
					)
				);
			}
			foreach ( $task_goods as $good_id ) {
				if ( ! is_int( $good_id ) || 0 === $good_id || ! isset( $defined_ids[ $good_id ] ) ) {
					throw new \InvalidArgumentException( esc_html__( 'tasks[].goods verwijst naar een good-ID die niet in goods staat.', 'soocool-for-woocommerce' ) );
				}
			}

			if ( 'delivery' === $task_type ) {
				$delivery_starts[] = (string) $time_window['startTime'];
			}
			if ( 'pickup' === $task_type ) {
				$pickup_starts[] = (string) $time_window['startTime'];
			}
		}

		if ( array() === $delivery_starts ) {
			throw new \InvalidArgumentException( esc_html__( 'tasks moet minimaal één delivery task bevatten.', 'soocool-for-woocommerce' ) );
		}

		foreach ( $pickup_starts as $pickup_start ) {
			foreach ( $delivery_starts as $delivery_start ) {
				if ( ! $this->delivery_is_on_later_date_than_pickup( $delivery_start, $pickup_start ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Een delivery task moet op een latere datum staan dan een pickup task.', 'soocool-for-woocommerce' ) );
				}
			}
		}
	}

	/** @param mixed $goods @return array<int, true> */
	private function validate_goods_manifest( mixed $goods ): array {
		if ( ! is_array( $goods ) || array() === $goods ) {
			throw new \InvalidArgumentException( esc_html__( 'goods moet minimaal één good-object bevatten.', 'soocool-for-woocommerce' ) );
		}

		$ids = array();
		foreach ( $goods as $index => $good ) {
			if ( ! is_array( $good ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Elke good in goods moet een object zijn.', 'soocool-for-woocommerce' ) );
			}

			foreach ( array( 'packagingType', 'contents' ) as $field ) {
				if ( '' === trim( (string) ( $good[ $field ] ?? '' ) ) ) {
					throw new \InvalidArgumentException(
						sprintf(
							/* translators: 1: good index, 2: SooCool field name. */
							esc_html__( 'goods[%1$d].%2$s is verplicht in de SooCool payload.', 'soocool-for-woocommerce' ),
							absint( $index ),
							$field
						)
					);
				}
			}

			$good_id = $good['goodId'] ?? null;
			if ( ! is_int( $good_id ) || 0 === $good_id ) {
				throw new \InvalidArgumentException( esc_html__( 'Elke goodId moet een niet-nul geheel getal zijn.', 'soocool-for-woocommerce' ) );
			}

			$ids[ $good_id ] = true;
		}

		return $ids;
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

	private function normalize_datetime( string $value ): string {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		try {
			$date_time = new \DateTimeImmutable( sanitize_text_field( $value ), $timezone );
		} catch ( \Exception ) {
			throw new \InvalidArgumentException( esc_html__( 'startTime en endTime moeten geldige datum/tijd waarden zijn.', 'soocool-for-woocommerce' ) );
		}

		return $date_time->format( DATE_ATOM );
	}

	private function postal_code( string $value ): string {
		$value = strtoupper( sanitize_text_field( trim( $value ) ) );
		return (string) preg_replace( '/\s+/', '', $value );
	}

	private function country_code( string $value ): string {
		$value = strtoupper( sanitize_key( $value ) );
		return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : 'NL';
	}

	private function parse_non_zero_int( string $value ): ?int {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^-?\d+$/', $value ) ) {
			return null;
		}
		$int = (int) $value;
		return 0 !== $int ? $int : null;
	}

	private function parse_positive_int( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value || 1 !== preg_match( '/^\d+$/', $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	/** @return array{width:int, depth:int, height:int}|null */
	private function parse_dimensions( string $width, string $depth, string $height ): ?array {
		$w = $this->parse_positive_int( $width );
		$d = $this->parse_positive_int( $depth );
		$h = $this->parse_positive_int( $height );
		if ( null === $w || null === $d || null === $h ) {
			return null;
		}

		return array(
			'width'  => $w,
			'depth'  => $d,
			'height' => $h,
		);
	}

	/**
	 * Drop null/empty-string/empty-array values without reindexing keys.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function compact_array( array $values ): array {
		return array_filter(
			$values,
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);
	}

	/** @return array<string, mixed> */
	private function consume_result(): array {
		$key    = $this->result_transient_key();
		$result = get_transient( $key );
		delete_transient( $key );

		return is_array( $result ) ? $result : array();
	}

	private function result_transient_key(): string {
		return self::RESULT_TRANSIENT . get_current_user_id();
	}


	private function redact_debug_data( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$key_string = is_scalar( $key ) ? (string) $key : '';
				if ( $this->is_sensitive_debug_key( $key_string ) ) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}
				$redacted[ $key ] = $this->redact_debug_data( $item );
			}

			return $redacted;
		}

		return $value;
	}

	private function is_sensitive_debug_key( string $key ): bool {
		$key = strtolower( $key );

		return in_array(
			$key,
			array(
				'email',
				'phone',
				'mobile',
				'person',
				'firstname',
				'first_name',
				'lastname',
				'last_name',
				'name',
				'contactname',
				'contact_name',
				'street',
				'housenumber',
				'house_number',
				'address',
				'postcode',
				'post_code',
				'postalcode',
				'postal_code',
				'city',
				'company',
				'api_key',
				'apikey',
				'token',
				'authorization',
			),
			true
		);
	}

	/** @param array<string, mixed> $settings */
	private function render_environment_notice( array $settings ): void {
		$environment = (string) ( $settings['environment'] ?? '' );
		$base_url    = $this->options->base_url();
		?>
		<div class="soocool-note soocool-manual-environment">
			<strong><?php echo esc_html__( 'Actieve SooCool omgeving:', 'soocool-for-woocommerce' ); ?></strong>
			<span><?php echo esc_html( $environment ?: '-' ); ?> — <?php echo esc_html( $base_url ?: '-' ); ?></span>
		</div>
		<?php
	}

	/** @param array<string, mixed> $result */
	private function render_result( array $result ): void {
		if ( array() === $result ) {
			return;
		}

		$class = ! empty( $result['success'] ) ? 'soocool-status is-success' : 'soocool-status is-error';
		?>
		<div class="<?php echo esc_attr( $class ); ?> soocool-manual-result">
			<div>
				<strong><?php echo ! empty( $result['success'] ) ? esc_html__( 'Gelukt', 'soocool-for-woocommerce' ) : esc_html__( 'Niet gelukt', 'soocool-for-woocommerce' ); ?></strong>
				<?php if ( isset( $result['status'] ) ) : ?>
					<span><?php echo esc_html( sprintf( 'HTTP status: %s', (string) $result['status'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( isset( $result['message'] ) ) : ?>
					<p><?php echo esc_html( (string) $result['message'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( isset( $result['errors'] ) && is_array( $result['errors'] ) && array() !== $result['errors'] ) : ?>
			<div class="soocool-settings-card">
				<h3><?php echo esc_html__( 'SooCool errors', 'soocool-for-woocommerce' ); ?></h3>
				<ul class="soocool-manual-errors">
					<?php foreach ( $result['errors'] as $error ) : ?>
						<li><?php echo esc_html( (string) $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<?php if ( $this->has_time_window_agreement_error( $result ) ) : ?>
			<div class="soocool-settings-card soocool-manual-result-help">
				<h3><?php echo esc_html__( 'Tijdslot niet toegestaan', 'soocool-for-woocommerce' ); ?></h3>
				<p><?php echo esc_html__( 'SooCool heeft deze aanvraag bereikt, maar het gekozen start- en eindtijdslot valt buiten de afspraak voor deze API-key. Gebruik het afgesproken bezorgvenster in de SooCool instellingen of vraag SooCool welk timeWindow voor deze testomgeving is toegestaan.', 'soocool-for-woocommerce' ); ?></p>
			</div>
		<?php endif; ?>
		<?php

		foreach ( array( 'payload' => __( 'Verzonden payload', 'soocool-for-woocommerce' ), 'body' => __( 'SooCool response', 'soocool-for-woocommerce' ) ) as $key => $heading ) {
			if ( ! array_key_exists( $key, $result ) ) {
				continue;
			}
			$json = wp_json_encode( $result[ $key ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			?>
			<section class="soocool-settings-card soocool-manual-json">
				<h3><?php echo esc_html( $heading ); ?></h3>
				<pre><?php echo esc_html( false !== $json ? $json : '' ); ?></pre>
			</section>
			<?php
		}
	}

	private function text_row( string $name, string $label, string $value, string $description = '' ): void {
		$field_class = 'soocool-manual-field';
		if ( 'orderReference' === $name ) {
			$field_class .= ' is-full';
		}
		?>
		<div class="<?php echo esc_attr( $field_class ); ?>">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<input name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" type="text" value="<?php echo esc_attr( $value ); ?>" />
			<?php if ( '' !== $description ) : ?><p class="soocool-field-help"><?php echo esc_html( $description ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private function datetime_row( string $name, string $label, string $value ): void {
		?>
		<div class="soocool-manual-field">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<input name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" type="datetime-local" value="<?php echo esc_attr( $value ); ?>" />
		</div>
		<?php
	}
}

