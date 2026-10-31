<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Versioning;

use MammothWP\AcceptanceLogs\Support\Logger;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write access to the version archive.
 *
 * Versions are never deleted — not by retention, not by uninstall's default
 * path. An acceptance record that points at a version whose text is gone has
 * lost most of its evidentiary value, so the archive outlives everything else.
 */
final class VersionRepository {

	public function active(): ?TermsVersion {
		$id = (int) get_option( OptionKeys::CURRENT_VERSION_ID, 0 );

		return $id > 0 ? $this->find( $id ) : null;
	}

	public function find( int $id ): ?TermsVersion {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read of this plugin's own custom table; the archive is the evidentiary source and must not be served stale.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? TermsVersion::from_row( $row ) : null;
	}

	/**
	 * The most recent publication of a given hash. Note "most recent": the
	 * same text can legitimately appear more than once (see Schema), so this
	 * is a convenience for display, never for deciding acceptance status.
	 */
	public function find_by_hash( string $hash ): ?TermsVersion {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read of this plugin's own custom table; see find().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE version_hash = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			),
			ARRAY_A
		);

		return is_array( $row ) ? TermsVersion::from_row( $row ) : null;
	}

	/**
	 * @return TermsVersion[] Newest first.
	 */
	public function all( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read of this plugin's own custom table; see find().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( TermsVersion::class, 'from_row' ), $rows ) : array();
	}

	/**
	 * Whether a version number has already been used. Two rows sharing a
	 * number would make "which version did they accept?" ambiguous, which is
	 * the one question the archive exists to answer.
	 */
	public function number_exists( string $number ): bool {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uniqueness check against this plugin's own custom table; a cached answer could mint a duplicate.
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE version_number = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$number
			)
		);
	}

	/**
	 * The next number in the plain sequence 1, 2, 3…
	 *
	 * Read from the archive rather than from a counter option, so it cannot
	 * drift out of step with what was actually published, and rows numbered
	 * some other way (a "2.1" supplied through the meta filter) are simply
	 * ignored rather than breaking the sequence.
	 */
	public function next_sequence(): int {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// Read of this plugin's own custom table; a cached answer could mint a
		// duplicate number, and the only interpolation is our own table name.
		$max = $wpdb->get_var( "SELECT MAX( CAST( version_number AS UNSIGNED ) ) FROM {$table} WHERE version_number REGEXP '^[0-9]+$'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return max( 0, (int) $max ) + 1;
	}

	public function count_all(): int {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// Count of this plugin's own custom table for pagination; the only
		// interpolation is our own table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param array<string,mixed> $data
	 * @return int|null Inserted version ID, or null if the write failed.
	 */
	public function insert( array $data ): ?int {
		global $wpdb;

		$row = array(
			'version_hash'    => (string) $data['version_hash'],
			'version_number'  => substr( (string) ( $data['version_number'] ?? '' ), 0, 32 ),
			'effective_date'  => $data['effective_date'] ?? null,
			'change_summary'  => (string) ( $data['change_summary'] ?? '' ),
			'content'         => (string) $data['content'],
			'content_format'  => (string) ( $data['content_format'] ?? 'html' ),
			'created_at_utc'  => gmdate( 'Y-m-d H:i:s' ),
			'created_by'      => $data['created_by'] ?? null,
			'source'          => substr( (string) ( $data['source'] ?? 'settings' ), 0, 32 ),
			'active_from_utc' => $data['active_from_utc'] ?? null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert() is the safe, parameterized API for this plugin's own custom table.
		$inserted = $wpdb->insert(
			$wpdb->prefix . OptionKeys::VERSIONS_TABLE,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::error( 'failed to archive terms version: ' . $wpdb->last_error );
			}

			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Closes off whichever version is currently active. Only ever writes
	 * active_to_utc — the published text, its number and its dates are never
	 * touched after insertion.
	 */
	public function close_active( string $now_utc ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- closing the active window on this plugin's own custom table; $wpdb->update() is the parameterized API for it.
		$wpdb->update(
			$wpdb->prefix . OptionKeys::VERSIONS_TABLE,
			array( 'active_to_utc' => $now_utc ),
			array( 'active_to_utc' => null ),
			array( '%s' ),
			array( '%s' )
		);
	}

	public function activate( int $version_id, string $now_utc ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- opening the active window on this plugin's own custom table.
		$wpdb->update(
			$wpdb->prefix . OptionKeys::VERSIONS_TABLE,
			array(
				'active_from_utc' => $now_utc,
				'active_to_utc'   => null,
			),
			array( 'id' => $version_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		update_option( OptionKeys::CURRENT_VERSION_ID, $version_id, true );
	}
}
