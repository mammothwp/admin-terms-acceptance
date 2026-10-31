<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

use MammothWP\AcceptanceLogs\Audit\EventLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who is allowed to see and use the plugin's management UI.
 *
 * This is deliberately NOT a WP capability. `manage_options` is handed out
 * liberally by role-management plugins and by agencies onboarding staff, and
 * being an administrator should not by itself grant read access to other
 * people's acceptance evidence — an audit log whose whole purpose is to be
 * trustworthy in a dispute. The list is seeded with whoever activated the
 * plugin and is explicit from then on.
 *
 * This class is the single choke point every admin surface consults, so it
 * also answers false whenever the UI is switched off entirely
 * (Support\AdminUi) — that way no screen can forget to honour the
 * kill-switch, and a screen added later inherits both controls for free.
 */
final class ManagerAccess {

	public function register(): void {
		// WP deletes the user row and their meta; the manager list is ours
		// to keep tidy, and must never be left empty.
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );
	}

	/**
	 * Seeds the manager list at activation with whoever clicked "Activate".
	 * Falls back to user ID 1 when there is no user context at all (WP-CLI,
	 * a provisioning script), because a list that starts empty would lock
	 * the backend before anyone could open it.
	 */
	public static function seed(): void {
		$existing = get_option( OptionKeys::MANAGERS, array() );

		if ( is_array( $existing ) && array() !== $existing ) {
			return;
		}

		$user_id = get_current_user_id();

		update_option( OptionKeys::MANAGERS, array( $user_id > 0 ? $user_id : 1 ) );
	}

	/**
	 * Recovers from an empty manager list.
	 *
	 * seed() only runs on activation, so an install whose activation hook
	 * never fired — or whose option was cleared by hand — would have no
	 * managers at all, and with no manager there is no way to appoint one:
	 * the backend is unreachable short of WP-CLI. remove() refuses to empty
	 * the list, so an empty list can only mean "never seeded", which makes
	 * healing it safe.
	 *
	 * It deliberately does NOT fall back to the current user. Doing that
	 * would hand the plugin to whoever happened to load a page first, which
	 * on a site with many logged-in users is a privilege escalation. User ID
	 * 1 — the site's original account — is the one defensible default.
	 */
	/**
	 * @return int[]
	 */
	private static function recover(): array {
		update_option( OptionKeys::MANAGERS, array( 1 ) );

		return array( 1 );
	}

	public function can_manage( ?int $user_id = null ): bool {
		if ( ! AdminUi::is_enabled() ) {
			return false;
		}

		$user_id = $user_id ?? get_current_user_id();
		$allowed = $user_id > 0 && in_array( $user_id, $this->managers(), true );

		/**
		 * Filters whether a user may manage the terms plugin.
		 *
		 * The escape hatch for sites that genuinely want to map this onto a
		 * capability or an external permission system. Note it cannot
		 * re-enable anything while the UI kill-switch is on — that check
		 * runs first, above.
		 *
		 * @param bool $allowed
		 * @param int  $user_id
		 */
		return (bool) apply_filters( 'mmwp_terms_can_manage', $allowed, $user_id );
	}

	/**
	 * @return int[]
	 */
	public function managers(): array {
		$saved = get_option( OptionKeys::MANAGERS, array() );

		if ( ! is_array( $saved ) ) {
			return self::recover();
		}

		$managers = array_values( array_unique( array_filter( array_map( 'intval', $saved ) ) ) );

		return array() === $managers ? self::recover() : $managers;
	}

	/**
	 * Users who may be appointed as managers: those who can already
	 * administer the site.
	 *
	 * Being an administrator does not make someone a manager — that is the
	 * whole point of this class — but it is the right pool to choose from.
	 * Appointing someone who cannot reach wp-admin would hand them access to
	 * a screen they can never open.
	 *
	 * @return \WP_User[]
	 */
	public function eligible_users(): array {
		$users = get_users(
			array(
				'capability' => 'manage_options',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'number'     => 200,
			)
		);

		/**
		 * Filters the users offered when appointing a manager.
		 *
		 * @param \WP_User[] $users
		 */
		return (array) apply_filters( 'mmwp_terms_eligible_managers', $users );
	}

	public function add( int $user_id ): bool {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}

		// Only from the eligible pool, so a crafted POST cannot appoint an
		// arbitrary subscriber to a role that reads other people's records.
		if ( ! in_array( $user_id, wp_list_pluck( $this->eligible_users(), 'ID' ), true ) ) {
			return false;
		}

		$managers = $this->managers();

		if ( in_array( $user_id, $managers, true ) ) {
			return true;
		}

		$managers[] = $user_id;
		update_option( OptionKeys::MANAGERS, $managers );

		( new EventLog() )->record(
			'manager_added',
			'success',
			array( 'actor' => get_current_user_id() ),
			null,
			null,
			$user_id
		);

		return true;
	}

	/**
	 * Removes a manager, refusing to empty the list. Losing the last manager
	 * would lock the backend for everyone with no in-admin way back.
	 */
	public function remove( int $user_id ): bool {
		$managers = $this->managers();

		if ( ! in_array( $user_id, $managers, true ) || count( $managers ) <= 1 ) {
			return false;
		}

		update_option( OptionKeys::MANAGERS, array_values( array_diff( $managers, array( $user_id ) ) ) );

		( new EventLog() )->record(
			'manager_removed',
			'success',
			array( 'actor' => get_current_user_id() ),
			null,
			null,
			$user_id
		);

		return true;
	}

	/**
	 * Prunes a deleted user. If they were the last manager the list would
	 * otherwise be empty, so it re-seeds to user ID 1 — same reasoning as
	 * seed(): never leave the backend unreachable.
	 */
	public function on_user_deleted( int $user_id ): void {
		$managers = array_values( array_diff( $this->managers(), array( $user_id ) ) );

		if ( array() === $managers ) {
			$managers = array( 1 );
		}

		update_option( OptionKeys::MANAGERS, $managers );
	}

	/**
	 * Hard stop for direct hits on a management URL. Screens are never
	 * registered for non-managers, so reaching this means someone typed or
	 * guessed the URL.
	 */
	public function require_manage(): void {
		if ( $this->can_manage() ) {
			return;
		}

		wp_die(
			esc_html__( 'You are not allowed to manage Terms & Conditions on this site.', 'mammoth-acceptance-logs' ),
			esc_html__( 'Forbidden', 'mammoth-acceptance-logs' ),
			array( 'response' => 403 )
		);
	}
}
