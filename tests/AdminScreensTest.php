<?php
/**
 * Renders every admin screen the plugin registers.
 *
 * Views are plain PHP files that static analysis does not execute, so a
 * reference that only resolves in the wrong namespace fatals at page load and
 * nothing else catches it.
 *
 * @package MammothWP\AcceptanceLogs
 */

namespace MammothWP\AcceptanceLogs\Tests;

use MammothWP\AcceptanceLogs\Admin\ScreenProbe;
use MammothWP\AcceptanceLogs\Database\Migrator;
use WP_UnitTestCase;

/**
 * Admin screen render tests.
 */
final class AdminScreensTest extends WP_UnitTestCase {

	/**
	 * Installs the schema and signs in as the site's first administrator.
	 */
	public function set_up(): void {
		parent::set_up();
		Migrator::activate();
		wp_set_current_user( 1 );
	}

	/**
	 * Every registered screen renders without a Throwable and with its markup.
	 */
	public function test_every_admin_screen_renders(): void {
		$results = ( new ScreenProbe() )->probe_all( 1 );

		$this->assertSame( array_keys( ScreenProbe::screens() ), array_keys( $results ) );

		foreach ( $results as $key => $result ) {
			$this->assertTrue( $result['ok'], $key . ': ' . $result['error'] );
		}
	}

	/**
	 * The settings screen shows the uninstall choice with its confirmation phrase.
	 */
	public function test_settings_screen_renders_uninstall_markup(): void {
		$html = ( new ScreenProbe() )->probe_all( 1 )['settings']['html'];

		$this->assertStringContainsString( 'name="mmwp_terms_uninstall_data" value="keep"', $html );
		$this->assertStringContainsString( 'name="mmwp_terms_uninstall_data" value="delete"', $html );
		$this->assertStringContainsString( 'mmwp_terms_uninstall_typed', $html );
		$this->assertStringContainsString( '<code>MammothWP Acceptance Logs</code>', $html );
	}

	/**
	 * The log screen offers verification and export but no edit or delete.
	 */
	public function test_log_screen_renders_read_only_controls(): void {
		$html = ( new ScreenProbe() )->probe_all( 1 )['acceptance-log']['html'];

		$this->assertStringContainsString( 'mmwp_terms_verify=1', $html );
		$this->assertStringContainsString( 'mmwp_terms_export_log', $html );
		$this->assertStringNotContainsString( 'bulk-action', $html );
	}

	/**
	 * A user who is not a manager gets no screen rather than a fatal.
	 */
	public function test_non_manager_has_no_screen_registered(): void {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$results = ( new ScreenProbe() )->probe_all( $editor );

		foreach ( $results as $result ) {
			$this->assertFalse( $result['ok'] );
		}
	}
}
