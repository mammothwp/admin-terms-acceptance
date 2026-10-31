<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Database;

use MammothWP\AcceptanceLogs\Content\ContentSource;
use MammothWP\AcceptanceLogs\Support\Hasher;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation-time setup and, from here on, schema upgrades.
 *
 * maybe_upgrade() runs on every load and is a no-op once the stored schema
 * revision matches the code's. It exists so a future release can change the
 * tables without needing someone to deactivate and reactivate the plugin —
 * the failure mode the previous version had, where new columns simply never
 * appeared on an existing install.
 */
final class Migrator {

	public static function activate(): void {
		self::install();
		ManagerAccess::seed();
		self::seed_current_hash();
		self::schedule_cron();
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( OptionKeys::DB_VERSION, 0 ) === OptionKeys::DB_VERSION_CURRENT ) {
			return;
		}

		self::install();
		self::schedule_cron();
	}

	private static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}

		self::drop_legacy_columns();

		update_option( OptionKeys::DB_VERSION, OptionKeys::DB_VERSION_CURRENT, false );
	}

	/**
	 * Removes columns from the pre-release schema that nothing writes any more.
	 *
	 * dbDelta adds and alters columns but never drops them, so a machine that
	 * ran an earlier build keeps `accepted_at` — declared NOT NULL with no
	 * default. Left in place, every insert would fail outright on a server in
	 * strict mode (the MySQL 5.7+ default), and silently store a zero date on
	 * one that isn't. Only ever affects development installs; a fresh install
	 * has no such column and this is a no-op.
	 */
	private static function drop_legacy_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema inspection of this plugin's own custom table; $table is $wpdb->prefix plus a hardcoded literal.
		$has_legacy = $wpdb->get_var( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . "` LIKE 'accepted_at'" );

		if ( null === $has_legacy ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dropping a superseded column from this plugin's own custom table; the name is a hardcoded literal.
		$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` DROP COLUMN `accepted_at`' );
	}

	private static function schedule_cron(): void {
		if ( ! wp_next_scheduled( OptionKeys::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', OptionKeys::CRON_HOOK );
		}

		if ( ! wp_next_scheduled( OptionKeys::CRON_RETENTION_HOOK ) ) {
			wp_schedule_event( time(), 'daily', OptionKeys::CRON_RETENTION_HOOK );
		}
	}

	private static function seed_current_hash(): void {
		if ( false !== get_option( OptionKeys::CURRENT_HASH, false ) ) {
			return;
		}

		$content = ( new ContentSource() )->get_content();
		update_option( OptionKeys::CURRENT_HASH, Hasher::hash( $content ), false );
		update_option( OptionKeys::LAST_HASH_CHECK, time(), false );
	}
}
