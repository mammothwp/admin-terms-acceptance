<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Users;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Audit\EventLog;
use MammothWP\AcceptanceLogs\Retention\RetentionPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the audit trail coherent when a WP user is deleted.
 *
 * WordPress removes the user row and all of their user meta, including the
 * fast-path acceptance cache. The acceptance records are ours and survive —
 * they have to, since a dispute about what someone accepted is at least as
 * likely after they have left as before.
 *
 * Because each record carries the username and email as they stood at the
 * time, a deleted user's rows stay meaningful rather than pointing at a
 * user_id that resolves to nothing. All this handler adds is:
 *
 *  - an audit event marking the deletion, so the gap between "user exists"
 *    and "user does not" is itself part of the record;
 *  - retention applied at that point, since account closure starts the
 *    dispute-period clock;
 *  - removal from the plugin's manager list, so a deleted account cannot
 *    linger there.
 */
final class DeletedUserHandler {

	private AcceptanceService $acceptance;
	private RetentionPolicy $policy;
	private EventLog $events;

	public function __construct(
		AcceptanceService $acceptance,
		?RetentionPolicy $policy = null,
		?EventLog $events = null
	) {
		$this->acceptance = $acceptance;
		$this->policy     = $policy ?? new RetentionPolicy();
		$this->events     = $events ?? new EventLog();
	}

	public function register(): void {
		add_action( 'deleted_user', array( $this, 'on_deleted' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( $this, 'on_deleted' ), 10, 1 );
	}

	public function on_deleted( int $user_id ): void {
		$repository = $this->acceptance->repository();
		$history    = $repository->history_for( $user_id );

		if ( array() === $history ) {
			return;
		}

		$latest = $history[0];

		$this->events->record(
			'user_deleted',
			'success',
			array(
				'user_login'   => (string) $latest['user_login_snapshot'],
				'user_email'   => (string) $latest['user_email_snapshot'],
				'records_held' => count( $history ),
			),
			null,
			null,
			$user_id
		);

		// Account closure starts the dispute-period clock. Anything already
		// past it is anonymised now rather than waiting for the next sweep.
		if ( ! $this->policy->anonymise_enabled() ) {
			return;
		}

		foreach ( $history as $row ) {
			if ( $this->policy->is_expired( $row ) ) {
				$repository->anonymise( (int) $row['id'], 'user account deleted; retention period already elapsed' );
			}
		}
	}
}
