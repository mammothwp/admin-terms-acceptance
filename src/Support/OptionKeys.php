<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for option/meta/table names so nothing gets typo'd
 * across classes.
 */
final class OptionKeys {

	public const CONTENT         = 'mmwp_terms_content';
	public const REQUIRED_ROLES  = 'mmwp_terms_required_roles';
	public const CURRENT_HASH    = 'mmwp_terms_current_hash';
	public const LAST_HASH_CHECK = 'mmwp_terms_last_hash_check';

	/** Human-authored version metadata for the version about to be / last published. */
	public const VERSION_NUMBER = 'mmwp_terms_version_number';
	public const EFFECTIVE_DATE = 'mmwp_terms_effective_date';
	public const CHANGE_SUMMARY = 'mmwp_terms_change_summary';

	/** ID (in the versions table) of the version users must currently have accepted. */
	public const CURRENT_VERSION_ID = 'mmwp_terms_current_version_id';

	/** User IDs allowed to see and use the plugin's management UI. See Support\ManagerAccess. */
	public const MANAGERS = 'mmwp_terms_managers';

	/** HMAC secret for record signing, used only when MMWP_TERMS_INTEGRITY_KEY is undefined. */
	public const INTEGRITY_KEY = 'mmwp_terms_integrity_key';

	/** array{dispute_period_years:int, anonymise_enabled:bool} — see Retention\RetentionPolicy. */
	public const RETENTION_SETTINGS = 'mmwp_terms_retention';

	public const DB_VERSION = 'mmwp_terms_db_version';

	/** 'keep' (default) or 'delete': what uninstall does with the acceptance evidence. */
	public const UNINSTALL_DATA = 'mmwp_terms_uninstall_data';

	/**
	 * Schema revision this code expects. Bump when Database\Schema changes;
	 * Database\Migrator::maybe_upgrade() re-runs dbDelta when it moves.
	 */
	public const DB_VERSION_CURRENT = 1;

	public const USER_META_HASH        = '_mmwp_terms_accepted_version';
	public const USER_META_ACCEPTED_AT = '_mmwp_terms_accepted_at';
	public const USER_META_VERSION_ID  = '_mmwp_terms_accepted_version_id';

	public const SKIP_TRANSIENT_PREFIX = 'mmwp_terms_skip_';

	public const ACCEPTANCES_TABLE = 'mmwp_terms_acceptances';
	public const VERSIONS_TABLE    = 'mmwp_terms_versions';
	public const EVENTS_TABLE      = 'mmwp_terms_audit_events';

	public const CRON_HOOK           = 'mmwp_terms_daily_hash_check';
	public const CRON_RETENTION_HOOK = 'mmwp_terms_retention_sweep';

	private function __construct() {}
}
