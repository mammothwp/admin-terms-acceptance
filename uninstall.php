<?php
/**
 * Fires on plugin deletion (not deactivation).
 *
 * Always removes the plugin's settings, transients and scheduled hooks.
 *
 * The acceptance evidence — the three data tables and the per-user acceptance
 * markers — is KEPT unless the site owner chose otherwise. The choice is stored
 * in the `mmwp_terms_uninstall_data` option ('keep' by default), set from the
 * confirmation shown when deleting the plugin, from the Settings screen, or
 * purged explicitly with `wp mmwp terms purge --force`. The filter
 * `mmwp_terms_drop_data_on_uninstall` overrides the stored choice.
 *
 * @package MammothWP\AcceptanceLogs
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Read first: the option is removed with the others below.
$mmwp_terms_choice = get_option( 'mmwp_terms_uninstall_data', 'keep' );

// Loaded directly by WP with no plugin bootstrap, so the names cannot come from
// Support\OptionKeys here — keep this list in step with that class.
$mmwp_terms_options = array(
	'mmwp_terms_content',
	'mmwp_terms_required_roles',
	'mmwp_terms_current_hash',
	'mmwp_terms_last_hash_check',
	'mmwp_terms_version_number',
	'mmwp_terms_effective_date',
	'mmwp_terms_change_summary',
	'mmwp_terms_current_version_id',
	'mmwp_terms_managers',
	'mmwp_terms_retention',
	'mmwp_terms_db_version',
	'mmwp_terms_upgrade',
	'mmwp_terms_uninstall_data',
);

foreach ( $mmwp_terms_options as $mmwp_terms_option ) {
	delete_option( $mmwp_terms_option );
}

wp_clear_scheduled_hook( 'mmwp_terms_daily_hash_check' );
wp_clear_scheduled_hook( 'mmwp_terms_retention_sweep' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's own transients on uninstall; there is no core API to delete transients by prefix.
$mmwp_terms_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_mmwp_terms_skip_' ) . '%'
	)
);

foreach ( (array) $mmwp_terms_transients as $mmwp_terms_transient ) {
	delete_transient( substr( (string) $mmwp_terms_transient, strlen( '_transient_' ) ) );
}

/**
 * Whether to delete the acceptance log, version archive, audit events and
 * per-user acceptance markers when the plugin is deleted.
 *
 * Defaults to the stored choice, which is false (keep) unless the site owner
 * explicitly chose to delete. After deletion no acceptance can be reproduced
 * or proved, so only return true deliberately.
 *
 * @since 1.0.0
 *
 * @param bool $drop Whether to delete the evidence.
 */
if ( ! apply_filters( 'mmwp_terms_drop_data_on_uninstall', 'delete' === $mmwp_terms_choice ) ) {
	return;
}

// The signing key is evidence: records that are kept can only be verified with
// it, so it goes only when the records do.
delete_option( 'mmwp_terms_integrity_key' );

$mmwp_terms_tables = array(
	$wpdb->prefix . 'mmwp_terms_acceptances',
	$wpdb->prefix . 'mmwp_terms_versions',
	$wpdb->prefix . 'mmwp_terms_audit_events',
);

foreach ( $mmwp_terms_tables as $mmwp_terms_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- DROP of this plugin's own custom tables on uninstall; each name is $wpdb->prefix plus a hardcoded literal, additionally passed through esc_sql().
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $mmwp_terms_table ) . '`' );
}

foreach ( array( '_mmwp_terms_accepted_version', '_mmwp_terms_accepted_at', '_mmwp_terms_accepted_version_id' ) as $mmwp_terms_meta_key ) {
	delete_metadata( 'user', 0, $mmwp_terms_meta_key, '', true );
}
