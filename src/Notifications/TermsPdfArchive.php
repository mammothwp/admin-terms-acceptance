<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Notifications;

use MammothWP\AcceptanceLogs\Versioning\TermsVersion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces a PDF of any archived version, on demand.
 *
 * Nothing is written to disk, and that is a security decision rather than a
 * simplification. Snapshots used to be stored under wp-content/uploads and
 * "protected" with an .htaccess deny rule — which nginx ignores entirely.
 * Measured against a real nginx install, the file was served with a 200; the
 * only thing standing between the public and the archive was an unguessable
 * directory name. Since the authoritative text of every version now lives in
 * the versions table, there is no reason to keep a second copy lying in a
 * web-served directory: rendering it per request removes the exposure
 * outright instead of obscuring it, and also removes stale files, failed
 * writes and unwritable-directory handling.
 *
 * Regeneration is deterministic — same archived text, same template, remote
 * assets disabled — so the document a manager downloads is the document that
 * version consisted of.
 */
final class TermsPdfArchive {

	private PdfGenerator $pdf;

	public function __construct( PdfGenerator $pdf ) {
		$this->pdf = $pdf;
	}

	/**
	 * Nothing to hook: this service is called when a snapshot is asked for,
	 * not on a schedule. Kept so the class matches the register() pattern the
	 * composition root uses.
	 */
	public function register(): void {}

	public function render( TermsVersion $version ): ?string {
		return $this->pdf->generate_terms_snapshot(
			get_bloginfo( 'name' ),
			$version->content,
			$version->hash,
			$version->number,
			$version->effective_date,
			$version->created_at_utc
		);
	}

	public function filename( TermsVersion $version ): string {
		return sanitize_file_name( 'terms-' . $version->label() . '-' . substr( $version->hash, 0, 12 ) ) . '.pdf';
	}

	/**
	 * A capability- and nonce-checked download URL, or null when dompdf is
	 * not installed and no PDF can be produced.
	 */
	public function url_for( TermsVersion $version ): ?string {
		if ( ! $this->pdf->is_available() ) {
			return null;
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => SnapshotDownloadAction::NAME,
					'version_id' => $version->id,
				),
				admin_url( 'admin-post.php' )
			),
			SnapshotDownloadAction::NONCE,
			'_wpnonce'
		);
	}
}
