<?php
/**
 * Plugin Name:       MammothWP Acceptance Logs
 * Plugin URI:        https://mammothwp.com/plugins/mammothwp-acceptance-logs/
 * Description:       Records that your logged-in users accepted your terms. Versioned, role-scoped, tamper-evident acceptance logs with a permanent archive of every version.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Mammoth WP
 * Author URI:        https://mammothwp.com
 * Text Domain:       mammoth-acceptance-logs
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MammothWP\AcceptanceLogs
 */

declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Disallow direct access.
}

/*
 * The slug lives here and nowhere else in PHP. The text domain in the header
 * above and in __() calls must be literals (tooling reads them statically), so
 * a rename is: this constant, the header, the folder, and a search/replace of
 * the text domain.
 */
define( 'MMWP_ACCEPTANCE_LOGS_SLUG', 'mammoth-acceptance-logs' );
define( 'MMWP_ACCEPTANCE_LOGS_VERSION', '1.0.0' );
define( 'MMWP_ACCEPTANCE_LOGS_FILE', __FILE__ );
define( 'MMWP_ACCEPTANCE_LOGS_PATH', plugin_dir_path( __FILE__ ) );
define( 'MMWP_ACCEPTANCE_LOGS_URL', plugin_dir_url( __FILE__ ) );

$mmwp_acceptance_logs_autoload = MMWP_ACCEPTANCE_LOGS_PATH . 'vendor/autoload.php';

if ( file_exists( $mmwp_acceptance_logs_autoload ) ) {
	require_once $mmwp_acceptance_logs_autoload;
} else {
	// Composer dependencies (wp-kit, dompdf) are missing. The acceptance flow
	// still boots; the REST/CLI/Abilities surface and PDF receipts are skipped.
	require_once MMWP_ACCEPTANCE_LOGS_PATH . 'src/autoload-fallback.php';
}

require_once MMWP_ACCEPTANCE_LOGS_PATH . 'src/api-functions.php';

// The shared Mammoth WP → Support screen. The recipient lives in
// mammothwp-support.json; nothing is sent unless a user presses Send there, and
// the site report only if they tick the box after previewing it.
if ( class_exists( \MammothWP\Support\Support::class ) && function_exists( 'mmwp_support_queue_plugin' ) ) {
	\MammothWP\Support\Support::register_plugin( MMWP_ACCEPTANCE_LOGS_FILE );
}

register_activation_hook( MMWP_ACCEPTANCE_LOGS_FILE, array( Database\Migrator::class, 'activate' ) );
register_deactivation_hook( MMWP_ACCEPTANCE_LOGS_FILE, array( Cron\HashWatcher::class, 'deactivate' ) );
register_deactivation_hook( MMWP_ACCEPTANCE_LOGS_FILE, array( Retention\RetentionCron::class, 'deactivate' ) );

/**
 * Boots the plugin once all plugins are loaded so translation-ready strings
 * and third-party integrations (WPML included) see a fully loaded environment.
 *
 * @return void
 */
function mmwp_acceptance_logs_boot(): void {
	Plugin::instance()->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\mmwp_acceptance_logs_boot' );

// Ahead of the services above, so legacy data is carried over and a schema
// change lands without anyone having to deactivate and reactivate the plugin.
add_action( 'plugins_loaded', array( Upgrade\Upgrader::class, 'maybe_upgrade' ), 4 );
add_action( 'plugins_loaded', array( Database\Migrator::class, 'maybe_upgrade' ), 5 );

/**
 * Registers the in-site smoke suite when wp-self-test is installed. The
 * package is a development dependency and is not shipped in the release zip,
 * so on a normal install this action simply never fires.
 *
 * @param object $registry The wp-self-test registry.
 * @return void
 */
function mmwp_acceptance_logs_register_self_test( $registry ): void {
	// The case class extends a wp-self-test class; never register it without the package.
	if ( ! class_exists( \MammothWP\SelfTest\Case_Base::class ) ) {
		return;
	}

	$registry->register(
		MMWP_ACCEPTANCE_LOGS_SLUG,
		MMWP_ACCEPTANCE_LOGS_PATH . 'self-test/manifest.json',
		array( 'label' => 'MammothWP Acceptance Logs' )
	);
}
add_action( 'mmwp_self_test_register', __NAMESPACE__ . '\\mmwp_acceptance_logs_register_self_test' );

// wp-self-test does not boot on production-typed sites; allow it only for an
// explicit `wp mmwp self-test` run. Added at load time, before the package
// reads the filter on plugins_loaded.
add_filter( 'mmwp_self_test_enabled', array( SelfTest\Gate::class, 'filter' ) );
