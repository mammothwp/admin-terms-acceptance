<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Notifications;

use Dompdf\Dompdf;
use Dompdf\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders acceptance certificates and version snapshots as PDFs. Returns
 * null (rather than throwing) when dompdf isn't installed, so a site that
 * hasn't run `composer install` still gets the acceptance flow and the full
 * audit trail — just without the PDF copies. The authoritative record lives
 * in the database either way.
 */
final class PdfGenerator {

	/**
	 * @param array<string,mixed> $details Acceptance id, timestamps, timezone,
	 *                                     IP, version number, effective date.
	 */
	public function generate(
		string $site_name,
		string $user_display_name,
		string $user_email,
		string $terms_content,
		string $version_hash,
		array $details = array()
	): ?string {
		return $this->render( $this->certificate_html( $site_name, $user_display_name, $user_email, $terms_content, $version_hash, $details ) );
	}

	/**
	 * Renders the terms content itself (no user) as a PDF — a dated snapshot
	 * of exactly what a given version said. See Notifications\TermsPdfArchive.
	 */
	public function generate_terms_snapshot(
		string $site_name,
		string $terms_content,
		string $version_hash,
		string $version_number,
		?string $effective_date,
		string $created_at_utc
	): ?string {
		return $this->render( $this->snapshot_html( $site_name, $terms_content, $version_hash, $version_number, $effective_date, $created_at_utc ) );
	}

	/**
	 * Whether PDF rendering is possible at all. dompdf is an optional
	 * dependency; the acceptance flow and the full audit trail work without
	 * it, so callers ask rather than assume.
	 */
	public function is_available(): bool {
		return class_exists( Dompdf::class );
	}

	private function render( string $html ): ?string {
		if ( ! $this->is_available() ) {
			return null;
		}

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		return $dompdf->output();
	}

	/**
	 * @param array<string,mixed> $details
	 */
	private function certificate_html( string $site_name, string $user_display_name, string $user_email, string $terms_content, string $version_hash, array $details ): string {
		ob_start();
		require MMWP_ACCEPTANCE_LOGS_PATH . 'src/Notifications/views/certificate.php';
		return (string) ob_get_clean();
	}

	private function snapshot_html( string $site_name, string $terms_content, string $version_hash, string $version_number, ?string $effective_date, string $created_at_utc ): string {
		ob_start();
		require MMWP_ACCEPTANCE_LOGS_PATH . 'src/Notifications/views/terms-snapshot.php';
		return (string) ob_get_clean();
	}
}
