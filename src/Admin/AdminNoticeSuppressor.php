<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility shim for integrations that instantiate it directly.
 */
final class AdminNoticeSuppressor {

	public function register(): void {
		// Geen hookregistratie: WordPress- en pluginmeldingen blijven zichtbaar.
	}
}
