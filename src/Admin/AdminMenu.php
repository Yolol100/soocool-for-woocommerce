<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\PayloadValidationException;
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

	public function __construct(
		private readonly ApiClient $client,
		private readonly OptionRepository $options,
		private readonly OrderPayloadBuilder $builder,
		private readonly DummyOrderFactory $dummy_orders,
		private readonly DebugRedactor $redactor
	) {}

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
		// Empty string parent (not null): null triggers a PHP 8.1+ deprecation inside core.
		add_submenu_page(
			'',
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
							<p class="soocool-muted"><?php echo esc_html__( 'Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt in beide gevallen dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce' ); ?></p>
						</div>
							<span class="soocool-pill is-subtle"><?php echo esc_html__( 'API-Test', 'soocool-for-woocommerce' ); ?></span>
						</header>

						<div class="soocool-fields">
							<?php $this->render_environment_notice( $settings ); ?>
							<?php $this->render_result( $result ); ?>

							<?php if ( array() !== $result ) : ?>
								<div class="soocool-actions soocool-manual-back">
									<a class="button button-primary soocool-manual-submit" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MANUAL_TEST_PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Nieuwe API-test starten', 'soocool-for-woocommerce' ); ?></a>
								</div>
							<?php else : ?>
							<form class="soocool-manual-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>

								<section class="soocool-settings-card soocool-simple-test-card">
									<h3><?php echo esc_html__( 'Welke order wil je testen?', 'soocool-for-woocommerce' ); ?></h3>
									<p class="soocool-field-help"><?php echo esc_html__( 'Gebruik bij voorkeur een echte WooCommerce order. Kies testorder alleen om snel te controleren of de SooCool API de standaardpayload accepteert.', 'soocool-for-woocommerce' ); ?></p>
									<div class="soocool-test-choice-list" role="radiogroup" aria-label="<?php echo esc_attr__( 'Type API-test', 'soocool-for-woocommerce' ); ?>">
										<label class="soocool-test-choice" for="soocool_test_mode_real">
											<input type="radio" name="test_mode" id="soocool_test_mode_real" value="real" <?php checked( 'real', $values['test_mode'] ); ?> />
											<span><strong><?php echo esc_html__( 'Echte WooCommerce order', 'soocool-for-woocommerce' ); ?></strong><?php echo esc_html__( 'Vul hieronder een WooCommerce order-ID in. Dit is de beste stagingtest.', 'soocool-for-woocommerce' ); ?></span>
										</label>
										<label class="soocool-test-choice" for="soocool_test_mode_dummy">
											<input type="radio" name="test_mode" id="soocool_test_mode_dummy" value="dummy" <?php checked( 'dummy', $values['test_mode'] ); ?> />
											<span><strong><?php echo esc_html__( 'Testorder', 'soocool-for-woocommerce' ); ?></strong><?php echo esc_html__( 'Gebruikt een niet-opgeslagen dummy WooCommerce order. Er wordt geen order in WordPress aangemaakt.', 'soocool-for-woocommerce' ); ?></span>
										</label>
									</div>
									<div class="soocool-field-grid two soocool-real-order-fields">
										<?php $this->text_row( 'woocommerce_order_id', __( 'WooCommerce order-ID', 'soocool-for-woocommerce' ), $values['woocommerce_order_id'], __( 'Alleen nodig bij “Echte WooCommerce order”. De plugin haalt deze order op via wc_get_order() en bouwt de normale SooCool payload.', 'soocool-for-woocommerce' ) ); ?>
									</div>
								</section>

								<div class="soocool-actions">
									<button type="submit" class="button button-primary soocool-manual-submit"><?php echo esc_html__( 'Start API-test naar SooCool', 'soocool-for-woocommerce' ); ?></button>
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
			$mode = sanitize_key( (string) ( $values['test_mode'] ?? 'real' ) );
			if ( 'dummy' === $mode ) {
				$payload = $this->builder->build( $this->dummy_orders->create() );
				$mode    = 'dummy_woocommerce_order';
			} else {
				if ( ! $this->has_woocommerce_order_id( $values ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Vul een WooCommerce order-ID in of kies Testorder.', 'soocool-for-woocommerce' ) );
				}

				$order_id = absint( $values['woocommerce_order_id'] );
				$order    = wc_get_order( $order_id );
				if ( ! $order ) {
					throw new \InvalidArgumentException( esc_html__( 'WooCommerce order niet gevonden.', 'soocool-for-woocommerce' ) );
				}

				$payload = $this->builder->build( $order );
				$mode    = 'woocommerce_order';
			}

			$response = $this->client->create_order( $payload );
			$result   = array_merge(
				$result,
				array(
					'success' => true,
					'status'  => $response->status_code(),
					'message' => __( 'SooCool heeft de testaanvraag geaccepteerd.', 'soocool-for-woocommerce' ),
					'mode'    => $mode,
					'payload' => $this->redactor->redact( $payload ),
					'body'    => $this->redactor->redact( $response->body() ),
				)
			);
		} catch ( PayloadValidationException|\InvalidArgumentException $exception ) {
			$result['message'] = sanitize_text_field( $exception->getMessage() );
		} catch ( ApiException $exception ) {
			$result['status']  = $exception->status_code();
			$result['message'] = sanitize_text_field( $exception->getMessage() );
			$result['errors']  = $this->redactor->redact_error_list( $exception->errors() );
			if ( isset( $payload ) && is_array( $payload ) ) {
				$result['payload'] = $this->redactor->redact( $payload );
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
		return array(
			'woocommerce_order_id' => '',
			'test_mode'            => 'real',
		);
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
			// Nonce is verified in handle_manual_test_order() before this helper reads submitted values.
			$raw            = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$values[ $key ] = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : $default;
		}

		return $values;
	}

	/** @param array<string, string> $values */
	private function has_woocommerce_order_id( array $values ): bool {
		return 0 < absint( $values['woocommerce_order_id'] ?? 0 );
	}

	private function consume_result(): array {
		$key    = $this->result_transient_key();
		$result = get_transient( $key );
		delete_transient( $key );

		return is_array( $result ) ? $result : array();
	}

	private function result_transient_key(): string {
		return self::RESULT_TRANSIENT . get_current_user_id();
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
				<strong><?php echo ! empty( $result['success'] ) ? esc_html__( 'Resultaat: testorder verstuurd naar SooCool', 'soocool-for-woocommerce' ) : esc_html__( 'Resultaat: testorder niet verstuurd', 'soocool-for-woocommerce' ); ?></strong>
				<?php if ( isset( $result['status'] ) ) : ?>
					<span><?php echo esc_html( sprintf( 'HTTP status: %s', (string) $result['status'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( isset( $result['message'] ) ) : ?>
					<p><strong><?php echo esc_html__( 'Details:', 'soocool-for-woocommerce' ); ?></strong> <?php echo esc_html( (string) $result['message'] ); ?></p>
				<?php endif; ?>
				<?php if ( isset( $result['mode'] ) ) : ?>
					<span><?php echo esc_html( 'Testmodus: ' . ( 'woocommerce_order' === (string) $result['mode'] ? __( 'WooCommerce orderpayload', 'soocool-for-woocommerce' ) : ( 'dummy_woocommerce_order' === (string) $result['mode'] ? __( 'Dummy WooCommerce orderpayload', 'soocool-for-woocommerce' ) : __( 'Handmatige payload', 'soocool-for-woocommerce' ) ) ) ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $result['success'] ) ) : ?>
				<p class="soocool-next-step"><?php echo esc_html__( 'Volgende stap: controleer de verzonden payload, download daarna een label of wacht op webhook/track & trace als deze test een echte order heeft aangemaakt.', 'soocool-for-woocommerce' ); ?></p>
			<?php else : ?>
				<p class="soocool-next-step"><?php echo esc_html__( 'Volgende stap: controleer de foutdetails en pas orderdata, API-key, timeWindow of payload aan voordat je opnieuw test.', 'soocool-for-woocommerce' ); ?></p>
			<?php endif; ?>
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
		?>
		<div class="<?php echo esc_attr( $field_class ); ?>">
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			<input name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" type="text" value="<?php echo esc_attr( $value ); ?>" />
			<?php if ( '' !== $description ) : ?><p class="soocool-field-help"><?php echo esc_html( $description ); ?></p><?php endif; ?>
		</div>
		<?php
	}

}

