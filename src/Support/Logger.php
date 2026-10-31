<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's single logging seam.
 *
 * wp-kit 0.4 ships no logger of its own (it only calls error_log() for failed
 * operations), so this is local; it is a candidate to move upstream.
 * Output goes to the PHP error log, never to the browser, and is
 * reserved for failures to write evidence — a failed acceptance insert must
 * always leave a trace for the operator.
 */
final class Logger {

	/**
	 * Records a failure that must not be thrown to the user.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function error( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the one deliberate log sink, server-side only, never shown to the user.
		error_log( MMWP_ACCEPTANCE_LOGS_SLUG . ': ' . $message );
	}

	private function __construct() {}
}
