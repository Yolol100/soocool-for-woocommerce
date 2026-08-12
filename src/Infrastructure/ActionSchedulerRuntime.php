<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class ActionSchedulerRuntime {

	public static function is_ready(): bool {
		if ( class_exists( '\\Action_Scheduler' ) && method_exists( '\\Action_Scheduler', 'is_initialized' ) ) {
			return (bool) \Action_Scheduler::is_initialized();
		}

		return function_exists( 'did_action' ) && 0 < did_action( 'action_scheduler_init' );
	}
}
