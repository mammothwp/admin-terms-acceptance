<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the plugin's admin screens the way WordPress does, and reports
 * whether they survived.
 *
 * Static analysis does not execute view templates, so a bad reference inside
 * one only shows up when a real admin opens the page. This runs the very same
 * `do_action( '<page hook>' )` that wp-admin runs, under output buffering, so
 * PHPUnit and `wp mmwp self-test` can catch that class of fatal on real sites.
 */
final class ScreenProbe {

	/**
	 * Every admin screen the plugin registers.
	 *
	 * Each entry names the menu parent, the page slug and a fragment of markup
	 * that proves the real view rendered rather than an empty shell.
	 *
	 * @return array<string,array{parent:string,slug:string,marker:string}>
	 */
	public static function screens(): array {
		return array(
			'settings'       => array(
				'parent' => 'options-general.php',
				'slug'   => SettingsPage::PAGE_SLUG,
				'marker' => 'mmwp_terms_uninstall_data',
			),
			'acceptance-log' => array(
				'parent' => 'users.php',
				'slug'   => AcceptanceLogPage::PAGE_SLUG,
				'marker' => 'mmwp_terms_verify',
			),
		);
	}

	/**
	 * Renders every screen as the given user.
	 *
	 * The current user is restored afterwards.
	 *
	 * @param int $user_id A user who may manage the plugin.
	 * @return array<string,array{ok:bool,html:string,error:string}> Result per screen key.
	 */
	public function probe_all( int $user_id ): array {
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		self::load_admin_includes();

		// WP_List_Table builds URLs from the request host, which WP-CLI lacks.
		$had_host = isset( $_SERVER['HTTP_HOST'] );
		if ( ! $had_host ) {
			$_SERVER['HTTP_HOST'] = (string) wp_parse_url( home_url(), PHP_URL_HOST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- assigning, not reading.
		}

		$results = array();

		foreach ( self::screens() as $key => $screen ) {
			$results[ $key ] = $this->probe( $screen['parent'], $screen['slug'], $screen['marker'] );
		}

		if ( ! $had_host ) {
			unset( $_SERVER['HTTP_HOST'] );
		}

		wp_set_current_user( $previous );

		return $results;
	}

	/**
	 * Renders one screen through its registered page hook.
	 *
	 * @param string $menu_parent Menu parent file.
	 * @param string $slug   Page slug.
	 * @param string $marker Markup that must appear in the output.
	 * @return array{ok:bool,html:string,error:string}
	 */
	public function probe( string $menu_parent, string $slug, string $marker ): array {
		$hook = $this->page_hook( $menu_parent, $slug );

		if ( '' === $hook ) {
			return self::result( false, '', 'screen is not registered for this user' );
		}

		$level = ob_get_level();
		ob_start();

		try {
			$GLOBALS['hook_suffix'] = $hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp-admin sets this before any page callback runs.
			set_current_screen( $hook );
			do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the page hook WordPress itself fires.
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			return self::result( false, '', get_class( $e ) . ': ' . $e->getMessage() );
		}

		if ( false === strpos( $html, $marker ) ) {
			return self::result( false, $html, 'expected markup "' . $marker . '" not found' );
		}

		return self::result( true, $html, '' );
	}

	/**
	 * Resolves the page hook, firing admin_menu once if nothing registered it yet.
	 *
	 * @param string $menu_parent Menu parent file.
	 * @param string $slug   Page slug.
	 * @return string Hook name, or an empty string when not registered.
	 */
	private function page_hook( string $menu_parent, string $slug ): string {
		$hook = (string) get_plugin_page_hook( $slug, $menu_parent );

		if ( '' !== $hook ) {
			return $hook;
		}

		// Front-end and CLI requests never build the admin menu.
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

		return (string) get_plugin_page_hook( $slug, $menu_parent );
	}

	/**
	 * Loads the wp-admin functions and classes the screens rely on.
	 *
	 * @return void
	 */
	private static function load_admin_includes(): void {
		if ( function_exists( 'set_current_screen' ) && function_exists( 'add_options_page' ) && class_exists( 'WP_List_Table' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	/**
	 * Builds a result row.
	 *
	 * @param bool   $ok    Whether the screen rendered as expected.
	 * @param string $html  Captured output.
	 * @param string $error Failure description.
	 * @return array{ok:bool,html:string,error:string}
	 */
	private static function result( bool $ok, string $html, string $error ): array {
		return array(
			'ok'    => $ok,
			'html'  => $html,
			'error' => $error,
		);
	}
}
