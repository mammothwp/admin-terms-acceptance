<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

use MammothWP\AcceptanceLogs\Upgrade\LegacyNames;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Master switch for the plugin's entire management UI.
 *
 * When disabled, the admin screens are never registered at all — not
 * rendered-then-hidden — so there is nothing to enumerate and nothing to
 * reach by direct URL. This is for locked-down/provisioned installs where
 * the terms are managed centrally in code and no site user should ever see
 * the plugin's backend.
 *
 * What it deliberately does NOT touch: the acceptance popup, the AJAX
 * endpoints, acceptance recording, the certificate email, the cron jobs and
 * the WP privacy exporter/eraser. Turning off the admin UI must never turn
 * off the audit trail or the site's GDPR compliance surface.
 *
 * Two ways in, and the constant is the important one: wp-config.php is
 * loaded long before plugins, so a provisioning template can set it without
 * depending on plugin load order (a filter would need an mu-plugin).
 *
 *     define( 'MMWP_TERMS_DISABLE_ADMIN_UI', true );          // wp-config.php
 *     add_filter( 'mmwp_terms_enable_admin_ui', '__return_false' );
 */
final class AdminUi {

	public static function is_enabled(): bool {
		foreach ( array( 'MMWP_TERMS_DISABLE_ADMIN_UI', LegacyNames::CONSTANT_DISABLE_ADMIN_UI ) as $name ) {
			if ( defined( $name ) && constant( $name ) ) {
				return false;
			}
		}

		/**
		 * Filters whether the plugin's management UI is available at all.
		 *
		 * Returning false hides every admin screen from everyone, managers
		 * included. The acceptance flow itself keeps running.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'mmwp_terms_enable_admin_ui', true );
	}

	private function __construct() {}
}
