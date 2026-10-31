<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Uninstall;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the delete-confirmation modal on the Plugins screen only.
 */
final class PluginsScreen {

	private const HANDLE = 'mmwp-terms-plugins-screen';

	/**
	 * Hooks the enqueue callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the modal script and style for users who can delete plugins.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'plugins.php' !== $hook_suffix || ! current_user_can( 'delete_plugins' ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, MMWP_ACCEPTANCE_LOGS_URL . 'assets/css/plugins-screen.css', array(), MMWP_ACCEPTANCE_LOGS_VERSION );
		wp_enqueue_script( self::HANDLE, MMWP_ACCEPTANCE_LOGS_URL . 'assets/js/plugins-screen.js', array( 'wp-i18n' ), MMWP_ACCEPTANCE_LOGS_VERSION, true );
		wp_set_script_translations( self::HANDLE, 'mammoth-acceptance-logs', MMWP_ACCEPTANCE_LOGS_PATH . 'languages' );

		wp_localize_script(
			self::HANDLE,
			'mmwpTermsUninstall',
			array(
				'plugin'  => plugin_basename( MMWP_ACCEPTANCE_LOGS_FILE ),
				'name'    => UninstallPolicy::CONFIRM_PHRASE,
				'current' => UninstallPolicy::get(),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => UninstallChoiceController::ACTION,
				'nonce'   => wp_create_nonce( UninstallChoiceController::ACTION ),
			)
		);
	}
}
