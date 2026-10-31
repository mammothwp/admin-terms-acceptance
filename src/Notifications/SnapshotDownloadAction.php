<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared names for the snapshot download endpoint, so the link builder
 * (Notifications\TermsPdfArchive) and the handler
 * (Storage\SnapshotDownloadController) cannot drift apart.
 */
final class SnapshotDownloadAction {

	public const NAME  = 'mmwp_terms_download_snapshot';
	public const NONCE = 'mmwp_terms_download_snapshot';

	private function __construct() {}
}
