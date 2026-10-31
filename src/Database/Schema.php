<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Database;

use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table definitions, and nothing else. Pure statements with no side effects
 * so activation and upgrade share one description of the schema instead of
 * drifting apart.
 *
 * dbDelta is picky about formatting: two spaces before PRIMARY KEY, each
 * field on its own line, KEY names matching the column list exactly.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		return array(
			self::versions( $wpdb->prefix . OptionKeys::VERSIONS_TABLE, $charset_collate ),
			self::acceptances( $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE, $charset_collate ),
			self::events( $wpdb->prefix . OptionKeys::EVENTS_TABLE, $charset_collate ),
		);
	}

	/**
	 * The permanent archive of every version of the terms ever published.
	 *
	 * `content` is the whole point: a hash proves a version existed, but only
	 * the preserved text proves what the user was actually shown. It is kept
	 * here in the database rather than solely as a PDF so the evidence does
	 * not depend on dompdf being installed or the uploads directory being
	 * writable.
	 *
	 * version_hash is indexed but NOT unique, deliberately. Re-publishing text
	 * identical to an earlier version is a new publication event and gets its
	 * own row with its own number and effective date. That is also what makes
	 * "revert the text and everyone silently counts as accepted" impossible.
	 */
	private static function versions( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			version_hash CHAR(64) NOT NULL,
			version_number VARCHAR(32) NOT NULL DEFAULT '',
			effective_date DATE NULL,
			change_summary LONGTEXT NULL,
			content LONGTEXT NOT NULL,
			content_format VARCHAR(20) NOT NULL DEFAULT 'html',
			created_at_utc DATETIME NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			source VARCHAR(32) NOT NULL DEFAULT 'settings',
			active_from_utc DATETIME NULL,
			active_to_utc DATETIME NULL,
			PRIMARY KEY  (id),
			KEY version_hash (version_hash),
			KEY active_from_utc (active_from_utc)
		) {$charset_collate};";
	}

	/**
	 * The evidence itself. Append-only: nothing in this plugin ever UPDATEs
	 * or DELETEs a row here, with the single audited exception of retention
	 * anonymisation clearing the context columns.
	 *
	 * accepted_at_utc is authoritative; accepted_at_local plus timezone and
	 * utc_offset record what the user was actually shown at the time, so the
	 * record stays interpretable even if the site's timezone is changed later.
	 */
	private static function acceptances( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			version_id BIGINT UNSIGNED NULL,
			version_hash CHAR(64) NOT NULL,
			accepted_at_utc DATETIME NOT NULL,
			accepted_at_local DATETIME NOT NULL,
			timezone VARCHAR(64) NOT NULL DEFAULT '',
			utc_offset VARCHAR(9) NOT NULL DEFAULT '',
			action VARCHAR(32) NOT NULL DEFAULT 'accept',
			ip_address VARCHAR(45) NULL,
			ip_source VARCHAR(24) NOT NULL DEFAULT '',
			user_agent VARCHAR(512) NULL,
			site_identifier VARCHAR(191) NOT NULL DEFAULT '',
			user_login_snapshot VARCHAR(60) NOT NULL DEFAULT '',
			user_email_snapshot VARCHAR(100) NOT NULL DEFAULT '',
			record_hash CHAR(64) NOT NULL DEFAULT '',
			prev_record_hash CHAR(64) NOT NULL DEFAULT '',
			context_hash CHAR(64) NOT NULL DEFAULT '',
			anonymised_at_utc DATETIME NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY version_id (version_id),
			KEY version_hash (version_hash),
			KEY accepted_at_utc (accepted_at_utc)
		) {$charset_collate};";
	}

	/**
	 * The mutable narrative around the immutable records — see Audit\EventLog
	 * for why this is a separate table rather than a few more columns above.
	 */
	private static function events( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			acceptance_id BIGINT UNSIGNED NULL,
			version_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(40) NOT NULL,
			outcome VARCHAR(16) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			created_at_utc DATETIME NOT NULL,
			record_hash CHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY acceptance_id (acceptance_id),
			KEY event_type (event_type)
		) {$charset_collate};";
	}

	private function __construct() {}
}
