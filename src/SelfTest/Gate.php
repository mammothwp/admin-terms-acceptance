<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\SelfTest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets wp-self-test boot for one explicit command on a production-typed site.
 *
 * wp-self-test stays dormant when the environment type is `production`, which
 * is what WordPress reports when nothing configures it — as on a CI runner. The
 * smoke suite of this plugin creates no data, so it is safe to allow exactly
 * when someone runs `wp mmwp self-test` themselves. Every other request keeps
 * the package's default, so a normal production site never boots it.
 */
final class Gate {

	/**
	 * Filter callback for `mmwp_self_test_enabled`.
	 *
	 * @param bool $enabled Package default (false on production).
	 * @return bool
	 */
	public static function filter( $enabled ): bool {
		return (bool) $enabled || self::is_self_test_command( self::cli_args() );
	}

	/**
	 * The raw WP-CLI arguments of this process, empty outside WP-CLI.
	 *
	 * @return string[]
	 */
	private static function cli_args(): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || empty( $GLOBALS['argv'] ) || ! is_array( $GLOBALS['argv'] ) ) {
			return array();
		}

		return array_map( 'strval', $GLOBALS['argv'] );
	}

	/**
	 * Whether the arguments invoke `mmwp self-test`.
	 *
	 * @param string[] $args Process arguments.
	 * @return bool
	 */
	public static function is_self_test_command( array $args ): bool {
		$positional = array_values(
			array_filter(
				$args,
				static fn( string $arg ): bool => 0 !== strpos( $arg, '-' )
			)
		);

		$at = array_search( 'mmwp', $positional, true );

		return false !== $at && 'self-test' === ( $positional[ $at + 1 ] ?? '' );
	}
}
