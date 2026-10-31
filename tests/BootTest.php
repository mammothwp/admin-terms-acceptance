<?php
/**
 * Boot, schema and legacy-upgrade tests.
 *
 * @package MammothWP\AcceptanceLogs
 */

namespace MammothWP\AcceptanceLogs\Tests;

use MammothWP\AcceptanceLogs\Api\TermsService;
use MammothWP\AcceptanceLogs\Database\Migrator;
use MammothWP\AcceptanceLogs\Plugin;
use MammothWP\AcceptanceLogs\SelfTest\Gate;
use MammothWP\AcceptanceLogs\Support\OptionKeys;
use MammothWP\AcceptanceLogs\Upgrade\LegacyNames;
use MammothWP\AcceptanceLogs\Uninstall\UninstallChoiceController;
use MammothWP\AcceptanceLogs\Uninstall\UninstallPolicy;
use MammothWP\AcceptanceLogs\Upgrade\Upgrader;
use WP_UnitTestCase;

/**
 * Covers the plugin's boot path and data upgrade.
 */
final class BootTest extends WP_UnitTestCase {

	/**
	 * DROP statements seen since the last reset.
	 *
	 * @var string[]
	 */
	private array $drops = array();

	/**
	 * Records DROP statements. The test suite rewrites DROP TABLE into DROP
	 * TEMPORARY TABLE, so the statement is what can be asserted here.
	 *
	 * @param string $sql Query.
	 * @return string
	 */
	public function capture_drop( $sql ) {
		if ( 0 === stripos( ltrim( $sql ), 'DROP' ) ) {
			$this->drops[] = $sql;
		}

		return $sql;
	}

	/**
	 * Whether the acceptances table was dropped.
	 */
	private function acceptances_dropped(): bool {
		global $wpdb;

		foreach ( $this->drops as $sql ) {
			if ( false !== strpos( $sql, $wpdb->prefix . OptionKeys::ACCEPTANCES_TABLE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The slug constant, the text domain and the main file agree.
	 */
	public function test_identity_is_consistent(): void {
		$this->assertSame( 'mammoth-acceptance-logs', MMWP_ACCEPTANCE_LOGS_SLUG );
		$this->assertSame( 'mammoth-acceptance-logs.php', basename( MMWP_ACCEPTANCE_LOGS_FILE ) );
	}

	/**
	 * Activation creates the three tables.
	 */
	public function test_tables_exist_after_install(): void {
		global $wpdb;

		Migrator::activate();

		foreach ( array( OptionKeys::ACCEPTANCES_TABLE, OptionKeys::VERSIONS_TABLE, OptionKeys::EVENTS_TABLE ) as $table ) {
			$name = $wpdb->prefix . $table;
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) );
		}
	}

	/**
	 * A published version can be recorded as accepted and read back.
	 */
	public function test_acceptance_is_recorded_and_read_back(): void {
		Migrator::activate();

		$api = Plugin::instance()->api();
		$id  = $api->publish( '<p>Terms for the test.</p>', array( 'source' => 'test' ) );
		$this->assertIsInt( $id );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = get_user_by( 'id', $user_id );
		$record  = Plugin::instance()->acceptance()->record_acceptance( $user, $id );

		$this->assertIsInt( $record );
		$row = Plugin::instance()->acceptance()->repository()->find( $record );
		$this->assertSame( $user_id, (int) $row['user_id'] );
		$this->assertSame( $id, (int) $row['version_id'] );
	}

	/**
	 * Legacy options are carried over and the upgrade is idempotent.
	 */
	public function test_legacy_options_are_migrated_once(): void {
		delete_option( Upgrader::OPTION );
		update_option( 'mtv_terms_version_number', '9.9' );
		delete_option( 'mmwp_terms_version_number' );

		Upgrader::maybe_upgrade();
		$this->assertSame( '9.9', get_option( 'mmwp_terms_version_number' ) );
		$this->assertFalse( get_option( 'mtv_terms_version_number' ) );

		update_option( 'mmwp_terms_version_number', '10.0' );
		Upgrader::maybe_upgrade();
		$this->assertSame( '10.0', get_option( 'mmwp_terms_version_number' ) );
		$this->assertSame( Upgrader::CURRENT, (int) get_option( Upgrader::OPTION ) );
		$this->assertArrayHasKey( 'mtv_terms_version_number', LegacyNames::OPTIONS );
	}

	/**
	 * The management routes are registered under mmwp/v1 and are not public.
	 */
	public function test_rest_routes_require_permission(): void {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mmwp/v1/terms/acceptances', $routes );

		wp_set_current_user( 0 );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/mmwp/v1/terms/acceptances' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Runs uninstall.php as WordPress would.
	 */
	private function run_uninstall(): void {
		$this->drops = array();
		add_filter( 'query', array( $this, 'capture_drop' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', MMWP_ACCEPTANCE_LOGS_FILE );
		}

		require dirname( MMWP_ACCEPTANCE_LOGS_FILE ) . '/uninstall.php';
	}

	/**
	 * Whether an evidence table exists.
	 *
	 * @param string $table Table name without prefix.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$name = $wpdb->prefix . $table;

		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	/**
	 * By default uninstall removes settings but keeps the evidence.
	 */
	public function test_uninstall_keeps_evidence_by_default(): void {
		Migrator::activate();
		update_option( OptionKeys::CONTENT, 'x' );
		update_user_meta( 1, OptionKeys::USER_META_HASH, 'x' );
		delete_option( OptionKeys::UNINSTALL_DATA );

		$this->run_uninstall();

		$this->assertFalse( get_option( OptionKeys::CONTENT ) );
		$this->assertSame( 'x', get_user_meta( 1, OptionKeys::USER_META_HASH, true ) );
		$this->assertFalse( $this->acceptances_dropped() );
	}

	/**
	 * Choosing delete removes everything the plugin created.
	 */
	public function test_uninstall_deletes_evidence_when_chosen(): void {
		Migrator::activate();
		update_option( OptionKeys::CONTENT, 'x' );
		update_user_meta( 1, OptionKeys::USER_META_HASH, 'x' );
		update_option( OptionKeys::UNINSTALL_DATA, UninstallPolicy::DELETE );

		$this->run_uninstall();

		$this->assertFalse( get_option( OptionKeys::CONTENT ) );
		$this->assertFalse( get_option( OptionKeys::UNINSTALL_DATA ) );
		$this->assertSame( '', (string) get_user_meta( 1, OptionKeys::USER_META_HASH, true ) );
		$this->assertTrue( $this->acceptances_dropped() );

		Migrator::activate();
	}

	/**
	 * The filter overrides the stored choice.
	 */
	public function test_uninstall_filter_overrides_choice(): void {
		Migrator::activate();
		update_option( OptionKeys::UNINSTALL_DATA, UninstallPolicy::DELETE );
		add_filter( 'mmwp_terms_drop_data_on_uninstall', '__return_false' );

		$this->run_uninstall();

		$this->assertFalse( $this->acceptances_dropped() );
	}

	/**
	 * Deleting needs the plugin name typed back; keep never does.
	 */
	public function test_policy_requires_typed_confirmation_to_delete(): void {
		$this->assertTrue( is_wp_error( UninstallPolicy::set( UninstallPolicy::DELETE, 'wrong' ) ) );
		$this->assertTrue( is_wp_error( UninstallPolicy::set( 'bogus' ) ) );
		$this->assertSame( UninstallPolicy::KEEP, UninstallPolicy::get() );

		$this->assertSame( UninstallPolicy::DELETE, UninstallPolicy::set( UninstallPolicy::DELETE, UninstallPolicy::CONFIRM_PHRASE ) );
		$this->assertSame( UninstallPolicy::DELETE, UninstallPolicy::get() );
		$this->assertSame( UninstallPolicy::KEEP, UninstallPolicy::set( UninstallPolicy::KEEP ) );
	}

	/**
	 * The previous plugin name still confirms deletion, so a user mid-flow is not broken.
	 */
	public function test_policy_accepts_previous_confirmation_phrase(): void {
		$this->assertSame( 'MammothWP Acceptance Logs', UninstallPolicy::CONFIRM_PHRASE );
		$this->assertSame( 'Mammoth Acceptance Logs', UninstallPolicy::PREVIOUS_CONFIRM_PHRASE );
		$this->assertTrue( UninstallPolicy::is_confirmation( ' Mammoth Acceptance Logs ' ) );
		$this->assertTrue( UninstallPolicy::is_confirmation( 'MammothWP Acceptance Logs' ) );
		$this->assertFalse( UninstallPolicy::is_confirmation( 'mammothwp acceptance logs' ) );
		$this->assertSame( UninstallPolicy::DELETE, UninstallPolicy::set( UninstallPolicy::DELETE, 'Mammoth Acceptance Logs' ) );
		UninstallPolicy::set( UninstallPolicy::KEEP );
	}

	/**
	 * The AJAX handler refuses users who cannot delete plugins.
	 */
	public function test_ajax_choice_is_capability_checked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- building a request for the handler under test.
		$_POST['nonce']  = wp_create_nonce( UninstallChoiceController::ACTION );
		$_POST['choice'] = 'keep';
		$_REQUEST        = $_POST;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		add_filter(
			'wp_die_ajax_handler',
			static fn() => static function () {
			throw new \RuntimeException( 'died' );
			}
		);
		add_filter( 'wp_doing_ajax', '__return_true' );

		ob_start();
		try {
			( new UninstallChoiceController() )->handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( '"success":false', $out );
	}

	/**
	 * The purger reports without deleting unless told to.
	 */
	public function test_purge_reports_then_deletes(): void {
		Migrator::activate();
		$service     = new TermsService( Plugin::instance()->api(), Plugin::instance()->acceptance() );
		$this->drops = array();
		add_filter( 'query', array( $this, 'capture_drop' ) );

		$report = $service->purge_evidence( false, false );
		$this->assertFalse( $report['deleted'] );
		$this->assertFalse( $this->acceptances_dropped() );

		$report = $service->purge_evidence( true, true );
		$this->assertFalse( $report['deleted'], 'dry run never deletes' );
		$this->assertFalse( $this->acceptances_dropped() );

		$report = $service->purge_evidence( false, true );
		$this->assertTrue( $report['deleted'] );
		$this->assertTrue( $this->acceptances_dropped() );

		Migrator::activate();
	}

	/**
	 * The self-test gate opens only for `wp mmwp self-test`.
	 */
	public function test_self_test_gate_matches_only_the_command(): void {
		$this->assertTrue( Gate::is_self_test_command( array( 'wp', 'mmwp', 'self-test', 'mammoth-acceptance-logs' ) ) );
		$this->assertTrue( Gate::is_self_test_command( array( 'wp', '--path=/x', 'mmwp', 'self-test' ) ) );
		$this->assertFalse( Gate::is_self_test_command( array( 'wp', 'mmwp', 'terms', 'list' ) ) );
		$this->assertFalse( Gate::is_self_test_command( array( 'wp', 'self-test' ) ) );
		$this->assertFalse( Gate::is_self_test_command( array() ) );
	}
}
