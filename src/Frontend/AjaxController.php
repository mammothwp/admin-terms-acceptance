<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Frontend;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the two logged-in-only AJAX actions the popup calls.
 */
final class AjaxController {

	private AcceptanceService $acceptance;

	public function __construct( AcceptanceService $acceptance ) {
		$this->acceptance = $acceptance;
	}

	public function register(): void {
		add_action( 'wp_ajax_mmwp_terms_accept', array( $this, 'accept' ) );
		add_action( 'wp_ajax_mmwp_terms_skip', array( $this, 'skip' ) );
	}

	public function accept(): void {
		$user = $this->authorized_user();

		// What the browser was actually displaying. If the terms were
		// republished while this popup sat open, the service rejects rather
		// than recording agreement to text the user never saw.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorized_user() calls check_ajax_referer() before this line is reached.
		$version_id = isset( $_POST['versionId'] ) ? absint( wp_unslash( $_POST['versionId'] ) ) : null;

		$acceptance_id = $this->acceptance->record_acceptance( $user, $version_id > 0 ? $version_id : null );

		// A failed write must surface as a failure. Reporting success here
		// would reload the page into a state where the user believes they
		// have accepted and no evidence of it exists anywhere.
		if ( null === $acceptance_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'We could not record your acceptance. Please reload the page and try again — if this keeps happening, contact the site administrator.', 'mammoth-acceptance-logs' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message'      => __( 'Thank you — your acceptance has been recorded.', 'mammoth-acceptance-logs' ),
				'acceptanceId' => $acceptance_id,
			)
		);
	}

	public function skip(): void {
		$user = $this->authorized_user();

		$this->acceptance->record_skip( $user );

		wp_send_json_success();
	}

	/**
	 * The signed-in user, after the nonce check. Answers 403 and ends the
	 * request for anonymous callers.
	 *
	 * @return WP_User
	 */
	private function authorized_user(): WP_User {
		check_ajax_referer( 'mmwp_terms_ajax', 'nonce' );

		$user = wp_get_current_user();

		if ( 0 === $user->ID ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'mammoth-acceptance-logs' ) ), 403 );
		}

		return $user;
	}
}
