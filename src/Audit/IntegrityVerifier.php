<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Audit;

use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-checks the signatures and the chain links across the acceptance log.
 *
 * Read-only by construction: it reports what it finds and never "repairs"
 * anything. Re-signing a row that failed verification would destroy the only
 * evidence that something happened to it.
 */
final class IntegrityVerifier {

	private RecordSigner $signer;

	public function __construct( ?RecordSigner $signer = null ) {
		$this->signer = $signer ?? new RecordSigner();
	}

	/**
	 * @return array{checked:int, valid:int, invalid_core:int, invalid_context:int, chain_breaks:int, failures:array<int,array{id:int,problem:string}>}
	 */
	public function verify( int $limit = 500, int $offset = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- integrity verification of this plugin's own custom table; caching would defeat the purpose.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);

		$result = array(
			'checked'         => 0,
			'valid'           => 0,
			'invalid_core'    => 0,
			'invalid_context' => 0,
			'chain_breaks'    => 0,
			'failures'        => array(),
		);

		if ( ! is_array( $rows ) ) {
			return $result;
		}

		$expected_previous = null;

		foreach ( $rows as $row ) {
			++$result['checked'];

			$verdict = $this->signer->verify_row( $row );
			$id      = (int) $row['id'];
			$problem = array();

			if ( ! $verdict['core'] ) {
				++$result['invalid_core'];
				$problem[] = __( 'record signature does not match', 'mammoth-acceptance-logs' );
			}

			if ( ! $verdict['context'] ) {
				++$result['invalid_context'];
				$problem[] = __( 'context signature does not match', 'mammoth-acceptance-logs' );
			}

			// Only meaningful when walking a contiguous run from the start:
			// a paged slice has no predecessor for its first row, and a
			// recorded chain gap (see HashChain) is a known state, not a break.
			if ( null !== $expected_previous
				&& '' !== (string) $row['prev_record_hash']
				&& (string) $row['prev_record_hash'] !== $expected_previous
			) {
				++$result['chain_breaks'];
				$problem[] = __( 'chain link does not match the preceding record', 'mammoth-acceptance-logs' );
			}

			if ( array() === $problem ) {
				++$result['valid'];
			} else {
				$result['failures'][] = array(
					'id'      => $id,
					'problem' => implode( '; ', $problem ),
				);
			}

			$expected_previous = (string) $row['record_hash'];
		}

		return $result;
	}

	/**
	 * Convenience for the log viewer's per-row badge.
	 *
	 * @param array<string,mixed> $row
	 * @return array{core: bool, context: bool}
	 */
	public function verify_row( array $row ): array {
		return $this->signer->verify_row( $row );
	}

	public function total(): int {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// Count of this plugin's own custom table for the verification progress
		// display; the only interpolation is our own table name.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
