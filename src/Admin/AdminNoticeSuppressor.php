<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Behouden voor oudere koppelingen die deze klasse nog direct aanmaken.
 */
final class AdminNoticeSuppressor {

	public function register(): void {
		// Geen hookregistratie: WordPress- en pluginmeldingen blijven zichtbaar.
	}
}
