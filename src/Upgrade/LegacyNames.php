<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Upgrade;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every storage name used by pre-1.0 internal builds of this plugin.
 *
 * This is the one place the old identifiers are allowed to exist: the
 * Upgrader needs them to find and carry over existing data, and nothing else
 * reads them. Once no install is left on an old build this class and the
 * Upgrader can be deleted together.
 */
final class LegacyNames {

	/** Old option name => current option name. */
	public const OPTIONS = array(
		'mtv_terms_content'            => 'mmwp_terms_content',
		'mtv_terms_required_roles'     => 'mmwp_terms_required_roles',
		'mtv_terms_current_hash'       => 'mmwp_terms_current_hash',
		'mtv_terms_last_hash_check'    => 'mmwp_terms_last_hash_check',
		'mtv_terms_version_number'     => 'mmwp_terms_version_number',
		'mtv_terms_effective_date'     => 'mmwp_terms_effective_date',
		'mtv_terms_change_summary'     => 'mmwp_terms_change_summary',
		'mtv_terms_current_version_id' => 'mmwp_terms_current_version_id',
		'mtv_terms_managers'           => 'mmwp_terms_managers',
		'mtv_terms_integrity_key'      => 'mmwp_terms_integrity_key',
		'mtv_terms_retention'          => 'mmwp_terms_retention',
		'mtv_terms_db_version'         => 'mmwp_terms_db_version',
	);

	/** Old user meta key => current key. */
	public const USER_META = array(
		'_mtv_terms_accepted_version'    => '_mmwp_terms_accepted_version',
		'_mtv_terms_accepted_at'         => '_mmwp_terms_accepted_at',
		'_mtv_terms_accepted_version_id' => '_mmwp_terms_accepted_version_id',
	);

	/** Old table name (without $wpdb->prefix) => current table name. */
	public const TABLES = array(
		'mtv_terms_acceptances'  => 'mmwp_terms_acceptances',
		'mtv_terms_versions'     => 'mmwp_terms_versions',
		'mtv_terms_audit_events' => 'mmwp_terms_audit_events',
	);

	/** Cron hooks that are cleared; the current ones are scheduled afresh. */
	public const CRON_HOOKS = array( 'mtv_terms_daily_hash_check', 'mtv_terms_retention_sweep' );

	/** Prefix of the "skip for now" transients, which are simply discarded. */
	public const SKIP_TRANSIENT_PREFIX = 'mtv_terms_skip_';

	/** Block name as stored in post content, and its replacement. */
	public const BLOCK_NAME     = 'mtv/terms-display';
	public const BLOCK_NAME_NEW = 'mmwp/terms-display';

	/** wp-config.php constants an existing install may already define. */
	public const CONSTANT_INTEGRITY_KEY    = 'MTV_TERMS_INTEGRITY_KEY';
	public const CONSTANT_DISABLE_ADMIN_UI = 'MTV_TERMS_DISABLE_ADMIN_UI';

	private function __construct() {}
}
