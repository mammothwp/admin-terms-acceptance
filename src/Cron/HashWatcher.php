<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Cron;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily safety net for content sources that can change without going
 * through the settings page — a filtered-in JSON file edited directly on
 * disk, a legal CMS reached via `mmwp_terms_content_source`, etc. The
 * settings page itself already refreshes the hash synchronously on save;
 * this catches everything else within 24 hours.
 */
final class HashWatcher {

	private AcceptanceService $acceptance;

	public function __construct( AcceptanceService $acceptance ) {
		$this->acceptance = $acceptance;
	}

	public function register(): void {
		add_action( OptionKeys::CRON_HOOK, array( $this, 'check' ) );
	}

	public function check(): void {
		// refresh_current_hash() itself fires mmwp_terms_version_changed
		// when the hash actually moved — that's what puts every
		// previously-accepted user back at "please accept again".
		$this->acceptance->refresh_current_hash();
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( OptionKeys::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, OptionKeys::CRON_HOOK );
		}
	}
}
