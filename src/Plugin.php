<?php

declare(strict_types=1);

namespace SooCool\WooCommerce;

use SooCool\WooCommerce\Admin\AdminMenu;
use SooCool\WooCommerce\Admin\Assets;
use SooCool\WooCommerce\Admin\Notices;
use SooCool\WooCommerce\Admin\PrivacyPolicy;
use SooCool\WooCommerce\Checkout\DeliveryOptions;
use SooCool\WooCommerce\Infrastructure\Requirements;
use SooCool\WooCommerce\Rest\ConnectionController;
use SooCool\WooCommerce\Rest\LogsController;
use SooCool\WooCommerce\Rest\ManualTestController;
use SooCool\WooCommerce\Rest\OrderSyncController;
use SooCool\WooCommerce\Rest\SettingsController;
use SooCool\WooCommerce\Rest\WebhookController;
use SooCool\WooCommerce\Rest\WebhookSecretController;
use SooCool\WooCommerce\Rest\MaintenanceController;
use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderEmailLabels;
use SooCool\WooCommerce\WooCommerce\OrderStatusHooks;
use SooCool\WooCommerce\WooCommerce\ShippingLabelActions;
use SooCool\WooCommerce\Admin\OrderListColumn;
use SooCool\WooCommerce\Admin\BulkSyncActions;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}

		return self::$instance;
	}

	private function register(): void {
		$provider     = new ServiceProvider();
		$requirements = $provider->get( Requirements::class );

		if ( ! $requirements->is_supported() ) {
			add_action( 'admin_notices', array( $provider->get( Notices::class ), 'render_requirements_notice' ) );
			return;
		}

		$admin_menu = $provider->get( AdminMenu::class );
		add_action( 'admin_menu', array( $admin_menu, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $provider->get( Assets::class ), 'enqueue' ) );
		add_action( 'admin_init', array( $provider->get( PrivacyPolicy::class ), 'register' ) );
		add_action( 'admin_notices', array( $provider->get( Notices::class ), 'render_runtime_notices' ) );
		add_action( 'rest_api_init', array( $provider->get( SettingsController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( ConnectionController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( LogsController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( ManualTestController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( OrderSyncController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( WebhookController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( WebhookSecretController::class ), 'register_routes' ) );
		add_action( 'rest_api_init', array( $provider->get( MaintenanceController::class ), 'register_routes' ) );

		$provider->get( OrderActions::class )->register();
		$provider->get( OrderStatusHooks::class )->register();
		$provider->get( ShippingLabelActions::class )->register();
		$provider->get( OrderEmailLabels::class )->register();
		$provider->get( DeliveryOptions::class )->register();
		$provider->get( OrderListColumn::class )->register();
		$provider->get( BulkSyncActions::class )->register();
	}

}
