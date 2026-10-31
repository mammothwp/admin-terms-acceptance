<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Scope;

use MammothWP\AcceptanceLogs\Support\OptionKeys;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a given user is in scope for the acceptance gate.
 *
 * Scope is: (required roles, from the settings page — defaults to "all
 * roles") minus (excluded roles / excluded users, both filterable). Guests
 * (not logged in) are never in scope; this class is only ever asked about
 * an authenticated WP_User.
 */
final class RoleScope {

	public function user_requires_acceptance( WP_User $user ): bool {
		if ( in_array( $user->ID, $this->excluded_user_ids(), true ) ) {
			return false;
		}

		$required = $this->required_roles();
		$excluded = $this->excluded_roles();
		$roles    = $user->roles;

		foreach ( $roles as $role ) {
			if ( in_array( $role, $excluded, true ) ) {
				continue;
			}
			if ( in_array( $role, $required, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Roles that must accept the terms. Empty/missing option means "all
	 * registered roles" (the documented default).
	 *
	 * @return string[]
	 */
	private function required_roles(): array {
		$saved = get_option( OptionKeys::REQUIRED_ROLES, array() );

		if ( ! is_array( $saved ) || array() === $saved ) {
			$saved = array_keys( wp_roles()->roles );
		}

		return array_map( 'strval', $saved );
	}

	/**
	 * Roles exempted from the gate regardless of the setting above, e.g. a
	 * "customer" role on a WooCommerce site that logs in but should be
	 * treated like a guest for this purpose.
	 *
	 * @return string[]
	 */
	private function excluded_roles(): array {
		/**
		 * @param string[] $roles Role slugs to exclude from the gate.
		 */
		$roles = apply_filters( 'mmwp_terms_excluded_roles', array() );

		return is_array( $roles ) ? array_map( 'strval', $roles ) : array();
	}

	/**
	 * Individual users exempted from the gate regardless of role, e.g. a
	 * service/API account.
	 *
	 * @return int[]
	 */
	private function excluded_user_ids(): array {
		/**
		 * @param int[] $user_ids User IDs to exclude from the gate.
		 */
		$ids = apply_filters( 'mmwp_terms_excluded_users', array() );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
