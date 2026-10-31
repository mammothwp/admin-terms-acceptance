<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Content;

use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the current terms & conditions content.
 *
 * By default the content is whatever was saved on the settings page
 * (a plain option). A site can swap that out entirely — pull from a JSON
 * file, a CPT, a legal team's external CMS, whatever — by hooking the
 * `mmwp_terms_content_source` filter. Whatever string comes back out of the
 * filter is what gets hashed into the version identifier, so pointing the
 * filter at a different source is enough to make the plugin track it.
 *
 * Example: read the terms from a JSON file instead of the option.
 *
 *     add_filter( 'mmwp_terms_content_source', function ( string $content ) {
 *         $path = WP_CONTENT_DIR . '/mmwp-terms/terms.json';
 *         if ( ! is_readable( $path ) ) {
 *             return $content;
 *         }
 *         $data = json_decode( (string) file_get_contents( $path ), true );
 *         return (string) ( $data['content'] ?? $content );
 *     } );
 */
final class ContentSource {

	/**
	 * The canonical form of a terms document.
	 *
	 * Applied at every point content enters the archive or gets hashed, so
	 * the hash always describes the bytes that are actually stored and
	 * displayed. Skipping it anywhere means the same document hashes two
	 * different ways depending on which path it arrived by, and the plugin
	 * then sees phantom changes and republishes on every cron run.
	 *
	 * wp_kses_post() is idempotent, so applying it more than once is safe.
	 */
	public static function normalise( string $content ): string {
		return wp_kses_post( $content );
	}

	public function get_content(): string {
		$default = (string) get_option( OptionKeys::CONTENT, '' );

		/**
		 * Filters the raw terms & conditions content used both for display
		 * and for computing the version hash.
		 *
		 * @param string $default The content saved on the settings page.
		 */
		return (string) apply_filters( 'mmwp_terms_content_source', $default );
	}
}
