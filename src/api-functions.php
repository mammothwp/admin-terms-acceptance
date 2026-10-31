<?php
/**
 * Public helper functions.
 *
 * Thin wrappers over Api\TermsApi for code that would rather not reach into
 * the plugin's namespace. Everything here is safe to call from a theme, an
 * mu-plugin, a cron job or another plugin, and works with the admin UI
 * switched off.
 *
 * All of them boot the plugin's own services, so they must be called on or
 * after `plugins_loaded`.
 *
 * @package MammothWP\AcceptanceLogs
 */

declare( strict_types=1 );

use MammothWP\AcceptanceLogs\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'mmwp_terms_publish_version' ) ) {
	/**
	 * Publishes new terms and asks everyone in scope to accept them.
	 *
	 *     $result = mmwp_terms_publish_version(
	 *         $html,
	 *         array(
	 *             'version_number' => '2.1',
	 *             'effective_date' => '2026-10-01',
	 *             'change_summary' => 'Clarified section 4.',
	 *         )
	 *     );
	 *
	 * @param array{version_number?:string, effective_date?:string, change_summary?:string} $meta
	 * @return int|WP_Error Version ID, or an error.
	 */
	function mmwp_terms_publish_version( string $content, array $meta = array() ) {
		return Plugin::instance()->api()->publish( $content, $meta + array( 'source' => 'code' ) );
	}
}

if ( ! function_exists( 'mmwp_terms_publish_version_if_changed' ) ) {
	/**
	 * Publishes only when the text differs from the active version.
	 *
	 * The right call inside a sync job: a no-op returns the active version's
	 * ID rather than minting a version and re-prompting everyone for nothing.
	 *
	 * @param array<string,mixed> $meta
	 * @return int|WP_Error
	 */
	function mmwp_terms_publish_version_if_changed( string $content, array $meta = array() ) {
		return Plugin::instance()->api()->publish_if_changed( $content, $meta + array( 'source' => 'code' ) );
	}
}

if ( ! function_exists( 'mmwp_terms_active_version' ) ) {
	/**
	 * The version users are currently being asked to accept.
	 *
	 * @return array<string,mixed>|null
	 */
	function mmwp_terms_active_version(): ?array {
		$api     = Plugin::instance()->api();
		$version = $api->active();

		return null !== $version ? $api->to_array( $version ) : null;
	}
}

if ( ! function_exists( 'mmwp_terms_refresh' ) ) {
	/**
	 * Re-reads the content source now rather than waiting for the daily cron.
	 *
	 * For push-style integrations: call it from your upstream system's webhook
	 * and the change is archived and re-prompted immediately.
	 *
	 * @return int|null New version ID, or null if nothing had changed.
	 */
	function mmwp_terms_refresh(): ?int {
		return Plugin::instance()->api()->refresh();
	}
}

if ( ! function_exists( 'mmwp_terms_user_status' ) ) {
	/**
	 * Whether a user still needs to accept: 'accepted', 'pending' or
	 * 'not_required'.
	 *
	 * Read-only, and says nothing about anyone's IP or history — safe to call
	 * from anywhere that just needs to know whether to let someone through.
	 */
	function mmwp_terms_user_status( ?int $user_id = null ): string {
		$user = get_userdata( $user_id ?? get_current_user_id() );

		if ( ! $user ) {
			return 'not_required';
		}

		return Plugin::instance()->acceptance()->acceptance_status_for( $user );
	}
}
