<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Storage;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Audit\EventLog;
use MammothWP\AcceptanceLogs\Notifications\SnapshotDownloadAction;
use MammothWP\AcceptanceLogs\Notifications\TermsPdfArchive;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a version's PDF and sends it to an authorised manager.
 *
 * The only route to a snapshot: capability first, then nonce, then the
 * document is generated from the archived text and streamed. Because nothing
 * is stored under the web root there is no second, unauthenticated path to
 * the same bytes — the failure mode that an .htaccess-protected uploads
 * directory has on nginx.
 *
 * Downloads are logged: who took a copy of the terms archive and when is part
 * of the same accountability story as the acceptances themselves.
 */
final class SnapshotDownloadController {

	private AcceptanceService $acceptance;
	private ManagerAccess $managers;
	private TermsPdfArchive $archive;
	private EventLog $events;

	public function __construct(
		AcceptanceService $acceptance,
		ManagerAccess $managers,
		TermsPdfArchive $archive,
		?EventLog $events = null
	) {
		$this->acceptance = $acceptance;
		$this->managers   = $managers;
		$this->archive    = $archive;
		$this->events     = $events ?? new EventLog();
	}

	public function register(): void {
		add_action( 'admin_post_' . SnapshotDownloadAction::NAME, array( $this, 'download' ) );
	}

	public function download(): void {
		$this->managers->require_manage();

		check_admin_referer( SnapshotDownloadAction::NONCE );

		$version_id = isset( $_GET['version_id'] ) ? absint( wp_unslash( $_GET['version_id'] ) ) : 0;
		$version    = $version_id > 0 ? $this->acceptance->versions()->find( $version_id ) : null;

		if ( null === $version ) {
			wp_die(
				esc_html__( 'That terms version does not exist.', 'mammoth-acceptance-logs' ),
				esc_html__( 'Not found', 'mammoth-acceptance-logs' ),
				array( 'response' => 404 )
			);
		}

		$pdf = $this->archive->render( $version );

		if ( null === $pdf ) {
			$this->events->record( 'pdf_failed', 'failure', array( 'reason' => 'dompdf is not installed' ), null, $version->id, get_current_user_id() );

			wp_die(
				esc_html__( 'PDF copies are unavailable because dompdf is not installed. The full text of this version is still shown on the Terms & Conditions screen.', 'mammoth-acceptance-logs' ),
				esc_html__( 'PDF unavailable', 'mammoth-acceptance-logs' ),
				array( 'response' => 501 )
			);
		}

		$this->events->record(
			'snapshot_downloaded',
			'success',
			array( 'actor' => get_current_user_id() ),
			null,
			$version->id,
			get_current_user_id()
		);

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . (string) strlen( $pdf ) );
		header( 'Content-Disposition: attachment; filename="' . $this->archive->filename( $version ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF body; escaping would corrupt it.
		exit;
	}
}
