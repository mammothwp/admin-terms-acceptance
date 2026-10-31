<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\SelfTest;

use MammothWP\SelfTest\Case_Base;
use MammothWP\AcceptanceLogs\Admin\ScreenProbe;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-site smoke test: `wp mmwp self-test` runs this against a real site.
 *
 * Deliberately creates no data. Acceptance records are append-only evidence and
 * cannot be removed afterwards, so recording and reading one back is covered by
 * the PHPUnit suite (rolled-back transaction), and uninstall by its own test.
 */
final class SmokeCase extends Case_Base {

	/**
	 * What the case will do, with no side effects.
	 *
	 * @return string[]
	 */
	public function preview() {
		return array(
			'Check that the three tables and the plugin options exist',
			'Read the version list over REST',
			'Check that the acceptance log refuses anonymous REST callers',
			'Run `wp mmwp terms list`',
			'Render each admin screen (settings, acceptance log) as a manager and check none fatals',
		);
	}

	/**
	 * Runs the observations.
	 *
	 * @return array<string,mixed>
	 */
	public function run() {
		global $wpdb;

		$observed = array( 'tables' => array() );

		foreach ( array( OptionKeys::ACCEPTANCES_TABLE, OptionKeys::VERSIONS_TABLE, OptionKeys::EVENTS_TABLE ) as $table ) {
			$name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- existence probe on this plugin's own tables.
			$observed['tables'][ $table ] = $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		}

		$observed['db_version'] = (int) get_option( OptionKeys::DB_VERSION, 0 );
		$observed['versions']   = $this->rest( 'GET', '/mmwp/v1/terms/versions' );

		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		$observed['anonymous'] = $this->rest( 'GET', '/mmwp/v1/terms/acceptances' );
		wp_set_current_user( $previous );

		$observed['cli'] = $this->cli(
			static function (): void {
				call_user_func(
					array( 'WP_CLI', 'runcommand' ),
					'mmwp terms list --format=json',
					array(
						'launch'     => false,
						'exit_error' => false,
					)
				);
			}
		);

		$observed['screens'] = $this->render_screens();

		return array( 'observed' => $observed );
	}

	/**
	 * Turns observations into pass/fail rows.
	 *
	 * @param array<string,mixed> $context Value returned by run().
	 * @return array<int,array<string,string>>
	 */
	public function validate( array $context ) {
		$o = $context['observed'];

		return array(
			$this->check( 'rest', 'all three tables exist', ! in_array( false, $o['tables'], true ) ),
			$this->check( 'rest', 'schema revision recorded', $o['db_version'] > 0 ),
			$this->check( 'rest', 'GET terms/versions answers 200', 200 === $this->status( $o, 'versions' ) ),
			$this->check( 'rest', 'GET terms/acceptances refuses anonymous callers', in_array( $this->status( $o, 'anonymous' ), array( 401, 403 ), true ), $this->detail( $o, 'anonymous' ) ),
			$this->cli_check( $o, 'cli', 'wp mmwp terms list runs' ),
			$this->screen_check( $o, 'settings', 'Settings → Terms & Conditions renders' ),
			$this->screen_check( $o, 'acceptance-log', 'Users → Terms acceptance log renders' ),
			$this->skip( 'ability', 'record and read back an acceptance', 'append-only evidence; covered by PHPUnit' ),
		);
	}

	/**
	 * Renders the admin screens as the first manager who still exists.
	 *
	 * Views are not covered by static analysis, so this is what catches a fatal
	 * that only shows when a real admin opens the page.
	 *
	 * @return array<string,array{ok:bool,error:string}>|null Null when no manager account exists.
	 */
	private function render_screens(): ?array {
		foreach ( ( new ManagerAccess() )->managers() as $user_id ) {
			if ( false === get_userdata( $user_id ) ) {
				continue;
			}

			return array_map(
				static fn( array $r ): array => array(
					'ok'    => $r['ok'],
					'error' => $r['error'],
				),
				( new ScreenProbe() )->probe_all( $user_id )
			);
		}

		return null;
	}

	/**
	 * Turns one screen's observation into a check.
	 *
	 * @param array<string,mixed> $o     Observations.
	 * @param string              $key   Screen key from ScreenProbe::screens().
	 * @param string              $label Check label.
	 * @return array<string,string>
	 */
	private function screen_check( array $o, string $key, string $label ) {
		if ( ! is_array( $o['screens'] ) ) {
			return $this->skip( 'core', $label, 'no manager account exists to render it as' );
		}

		$r = $o['screens'][ $key ] ?? array(
			'ok'    => false,
			'error' => 'not rendered',
		);

		return $this->check( 'core', $label, $r['ok'], $r['error'] );
	}

	/**
	 * Nothing to remove: the case creates no data.
	 *
	 * @param array<string,mixed> $context Value returned by run().
	 * @return string[]
	 */
	public function cleanup( array $context ) {
		return array( 'Nothing to clean up.' );
	}
}
