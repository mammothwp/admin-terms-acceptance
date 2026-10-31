<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Upgrade;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries data written by pre-1.0 internal builds over to the current names.
 *
 * Idempotent and versioned: the revision is stored in `mmwp_terms_upgrade`,
 * each step is safe to repeat, and a site that never ran an old build finds
 * nothing to do and pays one option read. Existing evidence is never rewritten
 * — tables are renamed in place, so rows and their signatures are untouched.
 */
final class Upgrader {

	public const OPTION  = 'mmwp_terms_upgrade';
	public const CURRENT = 1;

	/**
	 * Runs any pending upgrade steps. Hooked early on plugins_loaded, ahead of
	 * the schema migrator, so the renamed tables exist before dbDelta looks.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::CURRENT ) {
			return;
		}

		self::migrate_tables();
		self::migrate_options();
		self::migrate_user_meta();
		self::migrate_block_name();
		self::discard_transients();
		self::clear_cron();

		update_option( self::OPTION, self::CURRENT, false );
	}

	/**
	 * Renames each legacy table that exists when the new name does not.
	 *
	 * @return void
	 */
	private static function migrate_tables(): void {
		global $wpdb;

		foreach ( LegacyNames::TABLES as $old => $new ) {
			$old_table = $wpdb->prefix . $old;
			$new_table = $wpdb->prefix . $new;

			if ( ! self::table_exists( $old_table ) || self::table_exists( $new_table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off rename of this plugin's own tables; both names are $wpdb->prefix plus a hardcoded literal.
			$wpdb->query( 'RENAME TABLE `' . esc_sql( $old_table ) . '` TO `' . esc_sql( $new_table ) . '`' );
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe on this plugin's own tables.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Moves each legacy option to its new name unless the new one is already set.
	 *
	 * @return void
	 */
	private static function migrate_options(): void {
		$missing = new \stdClass();

		foreach ( LegacyNames::OPTIONS as $old => $new ) {
			$value = get_option( $old, $missing );

			if ( $missing === $value ) {
				continue;
			}

			if ( get_option( $new, $missing ) === $missing ) {
				add_option( $new, $value, '', false );
			}

			delete_option( $old );
		}
	}

	/**
	 * Re-keys the per-user acceptance markers.
	 *
	 * @return void
	 */
	private static function migrate_user_meta(): void {
		global $wpdb;

		foreach ( LegacyNames::USER_META as $old => $new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off re-key of this plugin's own meta rows; no core API renames a meta key in bulk.
			$wpdb->update( $wpdb->usermeta, array( 'meta_key' => $new ), array( 'meta_key' => $old ) );
		}

		wp_cache_flush();
	}

	/**
	 * Points saved posts at the renamed block.
	 *
	 * @return void
	 */
	private static function migrate_block_name(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off rewrite of the block comment delimiter in saved post content.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s",
				'wp:' . LegacyNames::BLOCK_NAME,
				'wp:' . LegacyNames::BLOCK_NAME_NEW,
				'%' . $wpdb->esc_like( 'wp:' . LegacyNames::BLOCK_NAME ) . '%'
			)
		);
	}

	/**
	 * Drops the old "skip for now" transients; they are minutes-long state.
	 *
	 * @return void
	 */
	private static function discard_transients(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's own transients; there is no core API to delete by prefix.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . LegacyNames::SKIP_TRANSIENT_PREFIX ) . '%'
			)
		);

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}

	/**
	 * Unschedules the old cron hooks; the migrator schedules the current ones.
	 *
	 * @return void
	 */
	private static function clear_cron(): void {
		foreach ( LegacyNames::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
