<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Blocks;

use MammothWP\AcceptanceLogs\Api\TermsApi;
use MammothWP\AcceptanceLogs\Versioning\TermsVersion;
use WP_Block;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `mmwp/terms-display` block: a public, read-only window onto the version
 * archive for the site's own Terms & Conditions page.
 *
 * Two things worth knowing before changing it.
 *
 * **It is server-rendered on purpose.** The active version's identity —
 * number, effective date, what changed — is the part a visitor (or a search
 * engine, or a regulator looking at an archived copy of the page) needs to
 * see, so it is in the HTML, not fetched. Only the full text of a version is
 * lazy-loaded, and only when someone asks for it.
 *
 * **The assets are declared in block.json, not enqueued here.** That is what
 * keeps the CSS and JS off every other page on the site. Do not add a
 * wp_enqueue_scripts hook for them.
 */
final class TermsDisplayBlock {

	private const EDITOR_HANDLE = 'mmwp-terms-block-editor';

	private TermsApi $api;

	public function __construct( TermsApi $api ) {
		$this->api = $api;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Registered by hand rather than as a `file:` path in block.json:
		// without a build step there is no generated .asset.php, so the
		// dependencies have to be stated somewhere, and this is the only
		// place they can be.
		wp_register_script(
			self::EDITOR_HANDLE,
			MMWP_ACCEPTANCE_LOGS_URL . 'blocks/terms-display/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			MMWP_ACCEPTANCE_LOGS_VERSION,
			true
		);

		wp_set_script_translations( self::EDITOR_HANDLE, 'mammoth-acceptance-logs', MMWP_ACCEPTANCE_LOGS_PATH . 'languages' );

		// The render callback rather than block.json's `render` key, because
		// rendering needs the injected TermsApi — the archive is only ever
		// read through the supported surface.
		register_block_type(
			MMWP_ACCEPTANCE_LOGS_PATH . 'blocks/terms-display',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function render( array $attributes, string $content = '', ?WP_Block $block = null ): string {
		$version = $this->api->active();

		if ( null === $version ) {
			return '';
		}

		$heading      = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
		$button_label = isset( $attributes['buttonLabel'] ) && '' !== $attributes['buttonLabel']
			? (string) $attributes['buttonLabel']
			: __( 'Read the full terms', 'mammoth-acceptance-logs' );

		$history = ! empty( $attributes['showHistory'] )
			? $this->history( $version, isset( $attributes['historyLimit'] ) ? (int) $attributes['historyLimit'] : 10 )
			: array();

		ob_start();
		require MMWP_ACCEPTANCE_LOGS_PATH . 'src/Blocks/views/terms-display.php';

		return (string) ob_get_clean();
	}

	/**
	 * Superseded versions, newest first.
	 *
	 * Asks for one more than requested so the active version can be dropped
	 * without the list coming up a row short.
	 *
	 * @return TermsVersion[]
	 */
	private function history( TermsVersion $active, int $limit ): array {
		$limit = max( 1, min( 50, $limit ) );

		$older = array_filter(
			$this->api->versions( $limit + 1 ),
			static fn( TermsVersion $version ): bool => $version->id !== $active->id
		);

		return array_slice( array_values( $older ), 0, $limit );
	}
}
