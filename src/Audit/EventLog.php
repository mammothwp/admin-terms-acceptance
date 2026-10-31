<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Audit;

use MammothWP\AcceptanceLogs\Support\Logger;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that happens *about* an acceptance: the PDF was generated, the
 * receipt was emailed (or wasn't, and why), the record was anonymised, a
 * version was activated, a snapshot was downloaded.
 *
 * This exists as a separate table for one specific reason. Delivery status
 * is the obvious candidate for a couple of extra columns on the acceptance
 * row — but writing it there means UPDATE-ing an evidence row minutes after
 * it was signed, and a log that gets updated is not an append-only log. Put
 * the mutable narrative here instead, and the acceptance row can be written
 * exactly once and never touched again.
 *
 * Events are signed too, so the narrative is as tamper-evident as the record
 * it describes.
 */
final class EventLog {

	private RecordSigner $signer;

	public function __construct( ?RecordSigner $signer = null ) {
		$this->signer = $signer ?? new RecordSigner();
	}

	/**
	 * @param array<string,mixed> $context Free-form detail: error messages,
	 *                                     provider message ids, the acting user.
	 * @return int|null Inserted event ID, or null if the write failed.
	 */
	public function record(
		string $event_type,
		string $outcome,
		array $context = array(),
		?int $acceptance_id = null,
		?int $version_id = null,
		?int $user_id = null
	): ?int {
		global $wpdb;

		$row = array(
			'acceptance_id'  => $acceptance_id,
			'version_id'     => $version_id,
			'user_id'        => $user_id,
			'event_type'     => substr( $event_type, 0, 40 ),
			'outcome'        => substr( $outcome, 0, 16 ),
			'context'        => (string) wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at_utc' => gmdate( 'Y-m-d H:i:s' ),
		);

		$row['record_hash'] = $this->signer->sign_event( $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert() is the safe, parameterized API for this plugin's own custom table; there is no WP core API to write to it.
		$inserted = $wpdb->insert(
			$wpdb->prefix . OptionKeys::EVENTS_TABLE,
			$row,
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		// An event that cannot be written must not take down the acceptance
		// it describes — the record is the evidence, the event is commentary.
		if ( false === $inserted ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::error( 'failed to write audit event ' . $event_type . ': ' . $wpdb->last_error );
			}

			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Events for one acceptance, oldest first — the delivery story for a
	 * single record, as shown in the log viewer.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function for_acceptance( int $acceptance_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::EVENTS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audit read of this plugin's own custom table; must reflect the live log, not a cached snapshot.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE acceptance_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$acceptance_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Most recent events of a given type for an acceptance, used to answer
	 * "was the receipt delivered?" without loading the whole history.
	 *
	 * @param string[] $event_types
	 * @return array<string, array<string,mixed>>
	 */
	public function latest_of_types( int $acceptance_id, array $event_types ): array {
		$matching = array();

		foreach ( $this->for_acceptance( $acceptance_id ) as $event ) {
			if ( in_array( (string) $event['event_type'], $event_types, true ) ) {
				$matching[ (string) $event['event_type'] ] = $event;
			}
		}

		return $matching;
	}
}
