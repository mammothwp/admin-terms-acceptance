<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Acceptance;

use MammothWP\AcceptanceLogs\Support\Logger;
use MammothWP\AcceptanceLogs\Audit\EventLog;
use MammothWP\AcceptanceLogs\Audit\HashChain;
use MammothWP\AcceptanceLogs\Audit\RecordSigner;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The append-only acceptance log, plus the fast-path "what did this user
 * last accept" cache in user meta so the hot path doesn't query the log.
 *
 * APPEND-ONLY IS A DESIGN CONSTRAINT, NOT AN ACCIDENT. There is deliberately
 * no update() and no delete() on this class, and there is no admin UI that
 * could call one. The single mutating method is anonymise(), which clears
 * only the retention-expiring context columns, stamps why, and logs an event.
 * If you are about to add a way to edit or remove a record, the answer is
 * almost certainly a new row or an audit event instead — a log that can be
 * quietly rewritten proves nothing in the dispute it exists for.
 */
final class AcceptanceRepository {

	private RecordSigner $signer;
	private HashChain $chain;
	private EventLog $events;

	public function __construct(
		?RecordSigner $signer = null,
		?HashChain $chain = null,
		?EventLog $events = null
	) {
		$this->signer = $signer ?? new RecordSigner();
		$this->chain  = $chain ?? new HashChain();
		$this->events = $events ?? new EventLog();
	}

	/**
	 * Writes one acceptance and returns its ID, or null if the write failed.
	 *
	 * The null return matters. The previous implementation ignored the insert
	 * result and updated user meta regardless, so a failed write left the user
	 * marked as having accepted with no record behind it — gated through, and
	 * unprovable. Callers must treat null as "the acceptance did not happen".
	 */
	public function record( AcceptanceContext $context ): ?int {
		global $wpdb;

		$locked = $this->chain->acquire();

		try {
			$row = $context->to_row();

			// Without the lock another request may be mid-insert, so the
			// predecessor we would read could be wrong. Record no link at all
			// and flag the gap rather than writing a link that looks valid
			// and isn't.
			$row['prev_record_hash'] = $locked ? $this->chain->previous_hash() : '';
			$row['record_hash']      = $this->signer->sign_core( $row );
			$row['context_hash']     = $this->signer->sign_context( $row );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- $wpdb->insert() is the safe, parameterized API for this plugin's own custom table; there is no WP core API to write to it.
			$inserted = $wpdb->insert(
				$wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE,
				$row,
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				$error = (string) $wpdb->last_error;

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					Logger::error( 'acceptance insert failed for user ' . $context->user_id . ': ' . $error );
				}

				/**
				 * Fires when an acceptance could not be persisted.
				 *
				 * The user has NOT been recorded as accepting and their meta
				 * is untouched. Hook this to alert someone: silently losing
				 * acceptance evidence is the worst failure this plugin has.
				 *
				 * @param int    $user_id
				 * @param int    $version_id
				 * @param string $db_error
				 */
				do_action( 'mmwp_terms_record_failed', $context->user_id, $context->version_id, $error );

				return null;
			}

			$acceptance_id = (int) $wpdb->insert_id;

			if ( ! $locked ) {
				$this->events->record(
					'chain_gap',
					'failure',
					array( 'reason' => 'could not acquire chain lock; record signature is unaffected' ),
					$acceptance_id,
					$context->version_id,
					$context->user_id
				);
			}

			// Only now — a user is marked as accepted strictly after the
			// evidence for it exists.
			update_user_meta( $context->user_id, OptionKeys::USER_META_HASH, $context->version_hash );
			update_user_meta( $context->user_id, OptionKeys::USER_META_ACCEPTED_AT, $context->accepted_at_local );
			update_user_meta( $context->user_id, OptionKeys::USER_META_VERSION_ID, $context->version_id );

			return $acceptance_id;
		} finally {
			if ( $locked ) {
				$this->chain->release();
			}
		}
	}

	public function accepted_hash_for( int $user_id ): string {
		return (string) get_user_meta( $user_id, OptionKeys::USER_META_HASH, true );
	}

	public function accepted_at_for( int $user_id ): string {
		return (string) get_user_meta( $user_id, OptionKeys::USER_META_ACCEPTED_AT, true );
	}

	public function accepted_version_id_for( int $user_id ): int {
		return (int) get_user_meta( $user_id, OptionKeys::USER_META_VERSION_ID, true );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audit read of this plugin's own custom table; must reflect the live log rather than a cached snapshot.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function latest_for( int $user_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- audit read of this plugin's own custom table; see find().
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Full acceptance history for a user, most recent first, joined to the
	 * version archive so each entry can name what was actually accepted.
	 * Feeds the privacy exporter and the admin log viewer.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function history_for( int $user_id ): array {
		global $wpdb;

		$acceptances = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;
		$versions    = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- compliance export of this plugin's own custom tables; must reflect the current audit log rather than a cached snapshot, and the only interpolations are our own table names.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, v.version_number, v.effective_date
				FROM {$acceptances} a
				LEFT JOIN {$versions} v ON v.id = a.version_id
				WHERE a.user_id = %d
				ORDER BY a.accepted_at_utc DESC",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Paged, filtered read for the admin log viewer.
	 *
	 * @param array{user_id?:int, version_id?:int, date_from?:string, date_to?:string, limit?:int, offset?:int} $args
	 * @return array<int, array<string,mixed>>
	 */
	public function paginate( array $args = array() ): array {
		global $wpdb;

		$acceptances = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;
		$versions    = $wpdb->prefix . OptionKeys::VERSIONS_TABLE;

		list( $where, $params ) = $this->build_where( $args );

		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- audit read of this plugin's own custom tables; see find(). The interpolations are our own table names and the placeholder-only clause built by build_where(), whose values travel in $params.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, v.version_number, v.effective_date
				FROM {$acceptances} a
				LEFT JOIN {$versions} v ON v.id = a.version_id
				{$where}
				ORDER BY a.id DESC
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array{user_id?:int, version_id?:int, date_from?:string, date_to?:string} $args
	 */
	public function count_all( array $args = array() ): int {
		global $wpdb;

		$acceptances = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		list( $where, $params ) = $this->build_where( $args );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- count over this plugin's own custom table for pagination; see paginate() for why $where is safe to interpolate.
		$sql = "SELECT COUNT(*) FROM {$acceptances} a {$where}";

		return (int) ( array() === $params
			? $wpdb->get_var( $sql )
			: $wpdb->get_var( $wpdb->prepare( $sql, $params ) )
		);
		// phpcs:enable
	}

	/**
	 * Clears the retention-expiring context fields (IP, its source, the
	 * User-Agent) and stamps when. The ONLY mutating operation on a record.
	 *
	 * context_hash is cleared alongside them by design: with the values gone
	 * the context signature can no longer verify, and leaving a stale one
	 * behind would read as tampering. record_hash is untouched and keeps
	 * verifying, which is exactly why the two signatures are separate — see
	 * Audit\RecordSigner.
	 */
	public function anonymise( int $id, string $reason ): bool {
		global $wpdb;

		$row = $this->find( $id );

		if ( null === $row || null !== $row['anonymised_at_utc'] ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention anonymisation of this plugin's own custom table; $wpdb->update() is the parameterized API for it.
		$updated = $wpdb->update(
			$wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE,
			array(
				'ip_address'        => null,
				'ip_source'         => '',
				'user_agent'        => null,
				'context_hash'      => '',
				'anonymised_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$this->events->record(
			'anonymised',
			'success',
			array( 'reason' => $reason ),
			$id,
			(int) $row['version_id'],
			(int) $row['user_id']
		);

		return true;
	}

	/**
	 * Records whose context fields are still present and whose retention
	 * window has closed. Batched, because a sweep must not try to load an
	 * entire history into memory.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function expired_context( string $cutoff_utc, int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- retention sweep over this plugin's own custom table; the only interpolation is our own table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE anonymised_at_utc IS NULL
				AND accepted_at_utc < %s
				ORDER BY id ASC
				LIMIT %d",
				$cutoff_utc,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array{user_id?:int, version_id?:int, date_from?:string, date_to?:string} $args
	 * @return array{0:string, 1:array<int,mixed>}
	 */
	private function build_where( array $args ): array {
		$clauses = array();
		$params  = array();

		if ( ! empty( $args['user_id'] ) ) {
			$clauses[] = 'a.user_id = %d';
			$params[]  = (int) $args['user_id'];
		}

		if ( ! empty( $args['version_id'] ) ) {
			$clauses[] = 'a.version_id = %d';
			$params[]  = (int) $args['version_id'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = 'a.accepted_at_utc >= %s';
			$params[]  = (string) $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = 'a.accepted_at_utc <= %s';
			$params[]  = (string) $args['date_to'] . ' 23:59:59';
		}

		return array(
			array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			$params,
		);
	}
}
