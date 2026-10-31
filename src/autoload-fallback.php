<?php
/**
 * Minimal PSR-4 autoloader used only when `composer install` has not been run
 * yet (no vendor/autoload.php). It loads our own classes so the plugin can
 * still activate without `composer install`; no admin notice is shown;
 * it does NOT provide dompdf, so PDF attachments are skipped until then.
 *
 * @package MammothWP\AcceptanceLogs
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'MammothWP\\AcceptanceLogs\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = MMWP_ACCEPTANCE_LOGS_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);
