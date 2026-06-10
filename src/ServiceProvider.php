<?php

declare(strict_types=1);

namespace SooCool\WooCommerce;

use SooCool\WooCommerce\Admin\AdminMenu;
use SooCool\WooCommerce\Admin\Assets;
use SooCool\WooCommerce\Admin\Notices;
use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Domain\AddressParser;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Domain\TaskFactory;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\Requirements;
use SooCool\WooCommerce\Infrastructure\SecretSanitizer;
use SooCool\WooCommerce\Rest\ConnectionController;
use SooCool\WooCommerce\Rest\LogsController;
use SooCool\WooCommerce\Rest\OrderSyncController;
use SooCool\WooCommerce\Rest\SettingsController;
use SooCool\WooCommerce\WooCommerce\OrderActions;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
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
			ApiClient::class => new ApiClient( $this->get( OptionRepository::class ), $this->get( Logger::class ) ),
			AddressParser::class => new AddressParser(),
			TaskFactory::class => new TaskFactory( $this->get( OptionRepository::class ), $this->get( AddressParser::class ) ),
			OrderPayloadBuilder::class => new OrderPayloadBuilder( $this->get( TaskFactory::class ), $this->get( OptionRepository::class ) ),
			OrderMeta::class => new OrderMeta(),
			ShippingLabelService::class => new ShippingLabelService( $this->get( ApiClient::class ), $this->get( OrderMeta::class ) ),
			AdminMenu::class => new AdminMenu(),
			Assets::class => new Assets(),
			Notices::class => new Notices( $this->get( Requirements::class ) ),
			SettingsController::class => new SettingsController( $this->get( OptionRepository::class ) ),
			ConnectionController::class => new ConnectionController( $this->get( ApiClient::class ) ),
			LogsController::class => new LogsController( $this->get( Logger::class ) ),
			OrderSyncController::class => new OrderSyncController( $this->get( ApiClient::class ), $this->get( OrderPayloadBuilder::class ), $this->get( OrderMeta::class ), $this->get( OptionRepository::class ) ),
			OrderActions::class => new OrderActions( $this->get( ApiClient::class ), $this->get( OrderPayloadBuilder::class ), $this->get( OrderMeta::class ), $this->get( OptionRepository::class ) ),
			OrderStatusHooks::class => new OrderStatusHooks( $this->get( OptionRepository::class ), $this->get( OrderActions::class ), $this->get( OrderMeta::class ) ),
			ShippingLabelActions::class => new ShippingLabelActions( $this->get( ShippingLabelService::class ), $this->get( OptionRepository::class ) ),
			default => throw new \InvalidArgumentException( esc_html( 'Unknown service: ' . (string) $id ) ),
		};
	}
}
