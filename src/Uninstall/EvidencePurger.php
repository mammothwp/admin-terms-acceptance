<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Uninstall;

use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts and permanently removes the acceptance evidence.
 *
 * Used by `wp mmwp terms purge`. It is the only code path in the plugin that
 * deletes evidence at runtime; uninstall.php does the same job without the
 * plugin loaded.
 */
final class EvidencePurger {

	/**
	 * How much evidence exists.
	 *
	 * @return array{acceptances:int,versions:int,events:int}
	 */
	public function counts(): array {
		global $wpdb;

		$counts = array();

		foreach ( array(
			'acceptances' => OptionKeys::ACCEPTANCES_TABLE,
			'versions'    => OptionKeys::VERSIONS_TABLE,
			'events'      => OptionKeys::EVENTS_TABLE,
		) as $key => $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- count over this plugin's own table; the name is $wpdb->prefix plus a hardcoded literal.
			$counts[ $key ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $name ) . '`' );
		}

		return $counts;
	}

	/**
	 * Drops the three evidence tables and the per-user markers, then resets the
	 * schema revision so the next load recreates empty tables.
	 *
	 * @return void
	 */
	public function purge(): void {
		global $wpdb;

		foreach ( array( OptionKeys::ACCEPTANCES_TABLE, OptionKeys::VERSIONS_TABLE, OptionKeys::EVENTS_TABLE ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- explicit, confirmed purge of this plugin's own tables; the name is $wpdb->prefix plus a hardcoded literal.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . $table ) . '`' );
		}

		foreach ( array( OptionKeys::USER_META_HASH, OptionKeys::USER_META_ACCEPTED_AT, OptionKeys::USER_META_VERSION_ID ) as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}

		foreach ( array( OptionKeys::CURRENT_VERSION_ID, OptionKeys::CURRENT_HASH, OptionKeys::DB_VERSION ) as $option ) {
			delete_option( $option );
		}
	}
}
