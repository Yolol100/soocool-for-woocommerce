<?php

declare(strict_types=1);

namespace SooCool\WooCommerce;

use SooCool\WooCommerce\Admin\AdminMenu;
use SooCool\WooCommerce\Admin\DebugRedactor;
use SooCool\WooCommerce\Admin\DummyOrderFactory;
use SooCool\WooCommerce\Admin\Assets;
use SooCool\WooCommerce\Admin\AdminNoticeSuppressor;
use SooCool\WooCommerce\Admin\Notices;
use SooCool\WooCommerce\Admin\PrivacyPolicy;
use SooCool\WooCommerce\Admin\OrderActionConfirmScript;
use SooCool\WooCommerce\Admin\OrderMetaBox;
use SooCool\WooCommerce\Admin\OrderStatusPresenter;
use SooCool\WooCommerce\Admin\OrderListColumn;
use SooCool\WooCommerce\Admin\BulkSyncActions;
use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiErrorMapper;
use SooCool\WooCommerce\Checkout\DeliveryOptions;
use SooCool\WooCommerce\Checkout\DeliverySchedule;
use SooCool\WooCommerce\Domain\AddressParser;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\OrderPayloadValidator;
use SooCool\WooCommerce\Domain\OrderSyncService;
use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Domain\TaskFactory;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\Requirements;
use SooCool\WooCommerce\Infrastructure\SecretSanitizer;
use SooCool\WooCommerce\Rest\ConnectionController;
use SooCool\WooCommerce\Rest\LogsController;
use SooCool\WooCommerce\Rest\ManualTestController;
use SooCool\WooCommerce\Rest\OrderSyncController;
use SooCool\WooCommerce\Rest\SettingsController;
use SooCool\WooCommerce\Rest\SettingsSchema;
use SooCool\WooCommerce\Rest\SettingsValidator;
use SooCool\WooCommerce\Rest\WebhookAuthenticator;
use SooCool\WooCommerce\Rest\WebhookPayloadExtractor;
use SooCool\WooCommerce\Rest\WebhookController;
use SooCool\WooCommerce\Rest\WebhookSecretController;
use SooCool\WooCommerce\Rest\MaintenanceController;
use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use SooCool\WooCommerce\WooCommerce\RemoteStatusMapper;
use SooCool\WooCommerce\WooCommerce\OrderStatusHooks;
use SooCool\WooCommerce\WooCommerce\ShippingLabelActions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ServiceProvider {

	/** @var array<class-string, object> */
	private array $services = array();

	/** @template T of object @param class-string<T> $id @return T */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $this->make( $id );
		}

		return $this->services[ $id ];
	}

	private function make( string $id ): object {
		return match ( $id ) {
			Requirements::class => new Requirements(),
			SecretSanitizer::class => new SecretSanitizer(),
			OptionRepository::class => new OptionRepository(),
			Logger::class => new Logger( $this->get( SecretSanitizer::class ), $this->get( OptionRepository::class ) ),
			ApiErrorMapper::class => new ApiErrorMapper(),
			ApiClient::class => new ApiClient( $this->get( OptionRepository::class ), $this->get( Logger::class ), $this->get( ApiErrorMapper::class ) ),
			AddressParser::class => new AddressParser(),
			DeliverySchedule::class => new DeliverySchedule( $this->get( OptionRepository::class ) ),
			TaskFactory::class => new TaskFactory( $this->get( OptionRepository::class ), $this->get( AddressParser::class ), $this->get( DeliverySchedule::class ) ),
			OrderPayloadValidator::class => new OrderPayloadValidator(),
			OrderPayloadBuilder::class => new OrderPayloadBuilder( $this->get( TaskFactory::class ), $this->get( OptionRepository::class ), $this->get( OrderPayloadValidator::class ) ),
			OrderMeta::class => new OrderMeta(),
			OrderSyncService::class => new OrderSyncService( $this->get( ApiClient::class ), $this->get( OrderMeta::class ) ),
			OrderStatusPresenter::class => new OrderStatusPresenter(),
			ShippingLabelService::class => new ShippingLabelService( $this->get( ApiClient::class ), $this->get( OrderMeta::class ) ),
			DummyOrderFactory::class => new DummyOrderFactory(),
			DebugRedactor::class => new DebugRedactor( $this->get( OptionRepository::class ) ),
			AdminMenu::class => new AdminMenu(),
			Assets::class => new Assets(),
			AdminNoticeSuppressor::class => new AdminNoticeSuppressor(),
			Notices::class => new Notices( $this->get( Requirements::class ), $this->get( OptionRepository::class ) ),
			PrivacyPolicy::class => new PrivacyPolicy(),
			SettingsValidator::class => new SettingsValidator( $this->get( OptionRepository::class ) ),
			SettingsSchema::class => new SettingsSchema( $this->get( SettingsValidator::class ) ),
			SettingsController::class => new SettingsController( $this->get( OptionRepository::class ), $this->get( SettingsSchema::class ), $this->get( SettingsValidator::class ) ),
			DeliveryOptions::class => new DeliveryOptions( $this->get( OptionRepository::class ), $this->get( DeliverySchedule::class ) ),
			ConnectionController::class => new ConnectionController( $this->get( ApiClient::class ) ),
			LogsController::class => new LogsController( $this->get( Logger::class ) ),
			ManualTestController::class => new ManualTestController( $this->get( ApiClient::class ), $this->get( OrderPayloadBuilder::class ), $this->get( DummyOrderFactory::class ), $this->get( DebugRedactor::class ), $this->get( Logger::class ), $this->get( OptionRepository::class ) ),
			OrderSyncController::class => new OrderSyncController( $this->get( ApiClient::class ), $this->get( OrderPayloadBuilder::class ), $this->get( OrderMeta::class ), $this->get( OptionRepository::class ), $this->get( OrderSyncService::class ), $this->get( SecretSanitizer::class ) ),
			WebhookAuthenticator::class => new WebhookAuthenticator( $this->get( OptionRepository::class ) ),
			WebhookPayloadExtractor::class => new WebhookPayloadExtractor(),
			WebhookController::class => new WebhookController( $this->get( OrderMeta::class ), $this->get( Logger::class ), $this->get( WebhookAuthenticator::class ), $this->get( WebhookPayloadExtractor::class ) ),
			WebhookSecretController::class => new WebhookSecretController( $this->get( OptionRepository::class ) ),
			RemoteStatusMapper::class => new RemoteStatusMapper(),
			OrderMetaBox::class => new OrderMetaBox( $this->get( OrderMeta::class ), $this->get( OrderStatusPresenter::class ), $this->get( DeliverySchedule::class ) ),
			OrderActionConfirmScript::class => new OrderActionConfirmScript(),
			OrderActions::class => new OrderActions( $this->get( ApiClient::class ), $this->get( OrderPayloadBuilder::class ), $this->get( OrderMeta::class ), $this->get( OptionRepository::class ), $this->get( RemoteStatusMapper::class ), $this->get( OrderMetaBox::class ), $this->get( OrderActionConfirmScript::class ), $this->get( OrderSyncService::class ), $this->get( SecretSanitizer::class ) ),
			OrderListColumn::class => new OrderListColumn( $this->get( OrderMeta::class ), $this->get( OrderStatusPresenter::class ) ),
			BulkSyncActions::class => new BulkSyncActions( $this->get( OrderActions::class ) ),
			MaintenanceController::class => new MaintenanceController( $this->get( OrderActions::class ) ),
			OrderStatusHooks::class => new OrderStatusHooks( $this->get( OptionRepository::class ), $this->get( OrderActions::class ), $this->get( OrderMeta::class ) ),
			ShippingLabelActions::class => new ShippingLabelActions( $this->get( ShippingLabelService::class ), $this->get( OptionRepository::class ), $this->get( OrderMeta::class ) ),
			default => throw new \InvalidArgumentException( esc_html( 'Unknown service: ' . (string) $id ) ),
		};
	}
}
