<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Api;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Uninstall\EvidencePurger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The service object behind the wp-kit resource.
 *
 * wp-kit matches declared fields to method parameters by name, so each public
 * method here takes plain scalars and returns plain arrays (or a WP_Error).
 * All behaviour is delegated to TermsApi and the repositories; this class only
 * adapts shapes, which keeps REST, WP-CLI and the Abilities API identical.
 */
final class TermsService {

	private TermsApi $api;
	private AcceptanceService $acceptance;

	/**
	 * @param TermsApi          $api        The supported programmatic surface.
	 * @param AcceptanceService $acceptance Source of the acceptance log.
	 */
	public function __construct( TermsApi $api, AcceptanceService $acceptance ) {
		$this->api        = $api;
		$this->acceptance = $acceptance;
	}

	/**
	 * The version users must currently have accepted.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function active_version(): array|WP_Error {
		$version = $this->api->active();

		if ( null === $version ) {
			return $this->not_found( 'mmwp_terms_no_version', __( 'No terms version has been published yet.', 'mammoth-acceptance-logs' ) );
		}

		return $this->api->to_array( $version );
	}

	/**
	 * Archived versions, newest first.
	 *
	 * @param int $limit  Rows to return.
	 * @param int $offset Rows to skip.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_versions( int $limit = 20, int $offset = 0 ): array {
		return array_map( array( $this->api, 'to_array' ), $this->api->versions( $limit, $offset ) );
	}

	/**
	 * One archived version.
	 *
	 * @param int $id Version ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_version( int $id ): array|WP_Error {
		$version = $this->api->find( $id );

		if ( null === $version ) {
			return $this->not_found( 'mmwp_terms_not_found', __( 'That terms version does not exist.', 'mammoth-acceptance-logs' ) );
		}

		return $this->api->to_array( $version );
	}

	/**
	 * Publishes terms text as a new active version.
	 *
	 * @param string $content         The terms text; HTML is filtered through wp_kses_post().
	 * @param string $version_number  Optional human version number; derived if empty.
	 * @param string $effective_date  Optional Y-m-d date.
	 * @param string $change_summary  Optional summary shown to returning users.
	 * @param bool   $only_if_changed Do nothing when the text matches the active version.
	 * @return array<string,mixed>|WP_Error
	 */
	public function publish_version( string $content, string $version_number = '', string $effective_date = '', string $change_summary = '', bool $only_if_changed = false ): array|WP_Error {
		$meta = array( 'source' => 'api' );

		foreach ( compact( 'version_number', 'effective_date', 'change_summary' ) as $key => $value ) {
			if ( '' !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		// Captured first so the response can tell "published a new version"
		// from "did nothing": the active version is active either way.
		$before = $this->api->active();
		$result = $only_if_changed
			? $this->api->publish_if_changed( $content, $meta )
			: $this->api->publish( $content, $meta );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$version = $this->api->find( $result );

		return array(
			'published' => null === $before || $before->id !== $result,
			'version'   => null !== $version ? $this->api->to_array( $version ) : null,
		);
	}

	/**
	 * Re-reads the content source now instead of waiting for the daily cron.
	 *
	 * @return array{changed:bool,version_id:int|null}
	 */
	public function refresh_content(): array {
		$version_id = $this->api->refresh();

		return array(
			'changed'    => null !== $version_id,
			'version_id' => $version_id,
		);
	}

	/**
	 * Read-only page of the acceptance log, in the redacted export shape.
	 *
	 * IP address, User-Agent and e-mail are deliberately left out: they are the
	 * erasable context, and a listing for dashboards or agents does not need
	 * them. The admin screen remains the place to see the full record.
	 *
	 * @param int    $user_id    Only this user's acceptances (0 = everyone).
	 * @param int    $version_id Only acceptances of this version (0 = all).
	 * @param string $date_from  Y-m-d, inclusive (UTC).
	 * @param string $date_to    Y-m-d, inclusive (UTC).
	 * @param int    $limit      Rows to return.
	 * @param int    $offset     Rows to skip.
	 * @return array{total:int,items:array<int,array<string,mixed>>}
	 */
	public function list_acceptances( int $user_id = 0, int $version_id = 0, string $date_from = '', string $date_to = '', int $limit = 50, int $offset = 0 ): array {
		$repository = $this->acceptance->repository();
		$filters    = array(
			'user_id'    => $user_id,
			'version_id' => $version_id,
			'date_from'  => $date_from,
			'date_to'    => $date_to,
		);
		$rows       = $repository->paginate(
			$filters + array(
				'limit'  => $limit,
				'offset' => $offset,
			)
		);

		return array(
			'total' => $repository->count_all( $filters ),
			'items' => array_map( array( $this, 'to_export_row' ), $rows ),
		);
	}

	/**
	 * Reports, and with force removes, all acceptance evidence.
	 *
	 * Without `force` (or with `dry_run`) nothing is deleted: the result only
	 * says what would be.
	 *
	 * @param bool $dry_run Report only, even with force.
	 * @param bool $force   Actually delete.
	 * @return array{deleted:bool,dry_run:bool,counts:array<string,int>}
	 */
	public function purge_evidence( bool $dry_run = false, bool $force = false ): array {
		$purger = new EvidencePurger();
		$counts = $purger->counts();
		$delete = $force && ! $dry_run;

		if ( $delete ) {
			$purger->purge();
		}

		return array(
			'deleted' => $delete,
			'dry_run' => ! $delete,
			'counts'  => $counts,
		);
	}

	/**
	 * @param array<string,mixed> $row Raw acceptance row joined with its version.
	 * @return array<string,mixed>
	 */
	private function to_export_row( array $row ): array {
		return array(
			'id'                 => (int) $row['id'],
			'user_id'            => (int) $row['user_id'],
			'user_login'         => (string) $row['user_login_snapshot'],
			'version_id'         => null === $row['version_id'] ? null : (int) $row['version_id'],
			'version_number'     => (string) ( $row['version_number'] ?? '' ),
			'version_hash'       => (string) $row['version_hash'],
			'action'             => (string) $row['action'],
			'accepted_at_utc'    => (string) $row['accepted_at_utc'],
			'accepted_at_local'  => (string) $row['accepted_at_local'],
			'timezone'           => (string) $row['timezone'],
			'record_hash'        => (string) $row['record_hash'],
			'prev_record_hash'   => (string) $row['prev_record_hash'],
			'context_anonymised' => null !== $row['anonymised_at_utc'],
		);
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Translated message.
	 */
	private function not_found( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 404 ) );
	}
}
