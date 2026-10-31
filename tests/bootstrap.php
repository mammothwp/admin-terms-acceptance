<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library and this plugin.
 *
 * Run bin/install-wp-tests.sh first (or let CI do it); WP_TESTS_DIR points at
 * the library it installs.
 *
 * @package MammothWP\AcceptanceLogs
 */

$mmwp_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $mmwp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// PHPUnit loads Composer's autoloader before WordPress exists, so the
		// libraries' own bootstrap files returned early; run them again now.
		foreach ( glob( dirname( __DIR__ ) . '/vendor/mammothwp/*/bootstrap.php' ) as $mmwp_library ) {
			require $mmwp_library;
		}

		require dirname( __DIR__ ) . '/mammoth-acceptance-logs.php';
	}
);

require $mmwp_tests_dir . '/includes/bootstrap.php';
