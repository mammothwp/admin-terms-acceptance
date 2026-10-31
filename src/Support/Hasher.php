<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns terms content into a stable version identifier. sha256 rather than
 * md5 purely because this hash may end up quoted in a legal/compliance
 * export; collision resistance is cheap to have and costs nothing here.
 */
final class Hasher {

	public static function hash( string $content ): string {
		// Normalise whitespace so trivial re-saves (extra newline, editor
		// autop) don't manufacture a spurious "the terms changed" event.
		$normalised = preg_replace( '/\s+/', ' ', trim( $content ) ) ?? '';

		return hash( 'sha256', $normalised );
	}

	private function __construct() {}
}
