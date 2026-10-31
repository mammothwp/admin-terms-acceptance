<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Uninstall;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Saves the uninstall choice made in the Plugins-screen modal.
 *
 * admin-ajax rather than REST: it is called from wp-admin only, carries a
 * nonce, and is restricted to users who can delete plugins — the same people
 * who can trigger the deletion it prepares.
 */
final class UninstallChoiceController {

	public const ACTION = 'mmwp_terms_uninstall_choice';

	/**
	 * Hooks the AJAX action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Verifies the request and stores the choice.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( self::ACTION, 'nonce' );

		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'mammoth-acceptance-logs' ) ), 403 );
		}

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
		$typed  = isset( $_POST['typed'] ) ? sanitize_text_field( wp_unslash( $_POST['typed'] ) ) : '';
		$result = UninstallPolicy::set( $choice, $typed );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'choice' => $result ) );
	}
}
