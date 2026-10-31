<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the admin stylesheet on the plugin's own screens and the Users list,
 * and nowhere else.
 */
final class AdminAssets {

	/**
	 * Hooks the enqueue callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the stylesheet when the current screen is one of ours.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'users.php' !== $hook_suffix && ! str_contains( $hook_suffix, 'mmwp-terms' ) ) {
			return;
		}

		wp_enqueue_style(
			'mmwp-terms-admin',
			MMWP_ACCEPTANCE_LOGS_URL . 'assets/css/admin.css',
			array(),
			MMWP_ACCEPTANCE_LOGS_VERSION
		);
	}
}
