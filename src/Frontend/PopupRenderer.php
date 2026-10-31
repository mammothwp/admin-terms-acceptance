<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Frontend;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders (and enqueues assets for) the non-dismissable-except-"skip"
 * overlay. Hooked into both wp_footer (frontend) and admin_footer
 * (wp-admin), so it shows wherever the user happens to be logged in.
 */
final class PopupRenderer {

	private AcceptanceService $acceptance;

	public function __construct( AcceptanceService $acceptance ) {
		$this->acceptance = $acceptance;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render' ) );
		add_action( 'admin_footer', array( $this, 'maybe_render' ) );
	}

	public function maybe_enqueue(): void {
		$user = wp_get_current_user();

		if ( 0 === $user->ID || ! $this->acceptance->user_needs_prompt( $user ) ) {
			return;
		}

		$version = $this->acceptance->active_version();

		wp_enqueue_style(
			'mmwp-terms-popup',
			MMWP_ACCEPTANCE_LOGS_URL . 'assets/css/popup.css',
			array(),
			MMWP_ACCEPTANCE_LOGS_VERSION
		);

		wp_enqueue_script(
			'mmwp-terms-popup',
			MMWP_ACCEPTANCE_LOGS_URL . 'assets/js/popup.js',
			array(),
			MMWP_ACCEPTANCE_LOGS_VERSION,
			true
		);

		wp_localize_script(
			'mmwp-terms-popup',
			'mmwpTermsPopup',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'acceptAction' => 'mmwp_terms_accept',
				'skipAction'   => 'mmwp_terms_skip',
				'nonce'        => wp_create_nonce( 'mmwp_terms_ajax' ),
				'showPopup'    => ! $this->acceptance->popup_is_skipped( $user ),
				// Posted back on accept so the server can reject agreement to
				// a version that was superseded while the popup was open.
				'versionId'    => null !== $version ? $version->id : 0,
				'errorMessage' => __( 'We could not record your acceptance. Please reload the page and try again.', 'mammoth-acceptance-logs' ),
			)
		);
	}

	public function maybe_render(): void {
		$user = wp_get_current_user();

		if ( 0 === $user->ID || ! $this->acceptance->user_needs_prompt( $user ) ) {
			return;
		}

		$version = $this->acceptance->active_version();

		if ( null === $version ) {
			return;
		}

		/**
		 * Filters the button/label strings shown in the popup.
		 *
		 * @param array<string,string> $strings
		 */
		$strings = apply_filters(
			'mmwp_terms_popup_strings',
			array(
				'title'  => __( 'Please review our Terms & Conditions', 'mammoth-acceptance-logs' ),
				'accept' => __( 'I accept', 'mammoth-acceptance-logs' ),
				'skip'   => __( 'Skip for now', 'mammoth-acceptance-logs' ),
			)
		);

		// Whether this is a first acceptance or a re-acceptance changes what
		// the user needs told: "here are the terms" versus "here is what
		// changed and why you are seeing this again".
		$is_update = '' !== $this->acceptance->repository()->accepted_hash_for( $user->ID );

		$content = wp_kses_post( $version->content );

		require MMWP_ACCEPTANCE_LOGS_PATH . 'src/Frontend/views/popup.php';
	}
}
