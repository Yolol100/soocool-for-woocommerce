<?php

declare(strict_types=1);

function soocool_release_fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

function soocool_release_read( string $path ): string {
	$content = file_get_contents( $path );
	if ( false === $content ) {
		soocool_release_fail( 'Unable to read release metadata file: ' . $path );
	}

	return $content;
}

function soocool_release_match( string $content, string $pattern, string $label ): string {
	if ( 1 !== preg_match( $pattern, $content, $matches ) || ! isset( $matches[1] ) ) {
		soocool_release_fail( 'Unable to read ' . $label . '.' );
	}

	return trim( (string) $matches[1] );
}

$root       = dirname( __DIR__ );
$plugin     = soocool_release_read( $root . '/soocool-for-woocommerce.php' );
$readme     = soocool_release_read( $root . '/readme.txt' );
$asset      = soocool_release_read( $root . '/assets/build/admin.asset.php' );
$options    = soocool_release_read( $root . '/src/Infrastructure/OptionRepository.php' );
$pot        = soocool_release_read( $root . '/languages/soocool-for-woocommerce.pot' );
$po         = soocool_release_read( $root . '/languages/soocool-for-woocommerce-nl_NL.po' );
$version    = soocool_release_match( $plugin, '/^\s*\*\s*Version:\s*([^\s]+)\s*$/m', 'plugin header version' );
$constant   = soocool_release_match( $plugin, "/define\(\s*'SOOCOOL_VERSION',\s*'([^']+)'\s*\)/", 'SOOCOOL_VERSION' );
$stable     = soocool_release_match( $readme, '/^Stable tag:\s*([^\s]+)\s*$/m', 'readme Stable tag' );
$asset_ver  = soocool_release_match( $asset, "/'version'\s*=>\s*'([^']+)'/", 'admin asset version' );
$migration  = soocool_release_match( $options, "/MIGRATION_VERSION_FALLBACK\s*=\s*'([^']+)'/", 'migration fallback version' );
$pot_ver    = soocool_release_match( $pot, '/Project-Id-Version: SooCool for WooCommerce ([0-9.]+)/', 'POT project version' );
$po_ver     = soocool_release_match( $po, '/Project-Id-Version: SooCool for WooCommerce ([0-9.]+)/', 'PO project version' );
$tested_wc  = soocool_release_match( $plugin, '/^\s*\*\s*WC tested up to:\s*([^\s]+)\s*$/m', 'WooCommerce tested-up-to metadata' );
$tested_wp  = soocool_release_match( $readme, '/^Tested up to:\s*([^\s]+)\s*$/m', 'WordPress tested-up-to metadata' );

$versions = array(
	'plugin header'      => $version,
	'runtime constant'   => $constant,
	'readme Stable tag'  => $stable,
	'admin asset'        => $asset_ver,
	'migration fallback' => $migration,
	'POT catalog'        => $pot_ver,
	'PO catalog'         => $po_ver,
);

foreach ( $versions as $label => $candidate ) {
	if ( $version !== $candidate ) {
		soocool_release_fail( sprintf( 'Release version drift: %s is %s, expected %s.', $label, $candidate, $version ) );
	}
}

if ( '11.1' !== $tested_wc ) {
	soocool_release_fail( 'WooCommerce tested-up-to metadata must match proven 11.1 runtime coverage.' );
}
if ( '7.1' !== $tested_wp ) {
	soocool_release_fail( 'WordPress tested-up-to metadata must match proven 7.1 runtime coverage.' );
}

echo sprintf(
	"SooCool release metadata regression passed for version %s (WordPress %s, WooCommerce %s).\n",
	$version,
	$tested_wp,
	$tested_wc
);
