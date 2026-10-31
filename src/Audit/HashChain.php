<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Audit;

use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links each acceptance to the one before it, so removing or reordering
 * rows is detectable and not just altering them.
 *
 * Deliberate limitation, stated plainly because it affects how much weight
 * the chain can carry: under simultaneous acceptances two requests can read
 * the same predecessor and interleave. The per-row HMAC is unconditionally
 * valid regardless; the chain link is best-effort ordering evidence on top
 * of it. A MySQL advisory lock narrows the window, and when the lock cannot
 * be taken we record the gap explicitly rather than pretending the chain is
 * intact — a known, logged gap is honest evidence, a silently wrong link is
 * not.
 *
 * A user is never blocked from accepting because of lock contention. Failing
 * an acceptance to protect a bookkeeping nicety would be exactly backwards.
 */
final class HashChain {

	private const LOCK_NAME    = 'mmwp_terms_chain';
	private const LOCK_TIMEOUT = 5;

	public function acquire(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; there is no WP core API for this.
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, self::LOCK_TIMEOUT )
		);
	}

	public function release(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; there is no WP core API for this.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
	}

	/**
	 * The record_hash of the most recent acceptance, or '' for the first row
	 * ever written (the genesis record).
	 */
	public function previous_hash(): string {
		global $wpdb;

		$table = $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE;

		// Chain read of this plugin's own custom table; a cached value would
		// break the link, and the only interpolation is our own table name.
		$hash = $wpdb->get_var( "SELECT record_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_string( $hash ) ? $hash : '';
	}
}
