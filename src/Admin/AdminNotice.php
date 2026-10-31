<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The persistent wp-admin nag for anyone who has skipped (or not yet seen)
 * the popup. Deliberately has no dismiss button — it only goes away once the
 * user actually accepts.
 *
 * This is the one admin-side surface that is NOT manager-gated and NOT hidden
 * by the admin-UI kill-switch, because it is not part of the management UI at
 * all: it is the acceptance prompt itself, addressed to the person being
 * asked to accept. Hiding it from ordinary users would break the plugin's
 * entire function. Only the management-facing notice below — the one telling
 * an operator their configuration is incomplete — follows the manager rule.
 */
final class AdminNotice {

	private AcceptanceService $acceptance;
	private ManagerAccess $managers;

	public function __construct( AcceptanceService $acceptance, ManagerAccess $managers ) {
		$this->acceptance = $acceptance;
		$this->managers   = $managers;
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_notices', array( $this, 'render_setup_warning' ) );
	}

	public function render(): void {
		$user = wp_get_current_user();

		if ( 0 === $user->ID || ! $this->acceptance->user_needs_prompt( $user ) ) {
			return;
		}

		$version = $this->acceptance->active_version();
		$label   = null !== $version ? $version->label() : '';

		$message = '' !== $label
			? sprintf(
				/* translators: 1: version label, 2: "Review now" link */
				__( 'Please review and accept version %1$s of our Terms &amp; Conditions to continue using this site. %2$s', 'mammoth-acceptance-logs' ),
				esc_html( $label ),
				'<a href="#mmwp-terms-overlay" class="mmwp-terms-notice-link">' . esc_html__( 'Review now', 'mammoth-acceptance-logs' ) . '</a>'
			)
			: sprintf(
				/* translators: %s: "Review now" link */
				__( 'Please review and accept our Terms &amp; Conditions to continue using this site. %s', 'mammoth-acceptance-logs' ),
				'<a href="#mmwp-terms-overlay" class="mmwp-terms-notice-link">' . esc_html__( 'Review now', 'mammoth-acceptance-logs' ) . '</a>'
			);

		printf(
			'<div class="notice notice-warning mmwp-terms-notice"><p>%s</p></div>',
			wp_kses(
				$message,
				array(
					'a' => array(
						'href'  => array(),
						'class' => array(),
					),
				)
			)
		);
	}

	/**
	 * Whether the current admin screen is this plugin's own settings page.
	 * The setup warning is confined to it so the plugin never adds notices to
	 * unrelated admin screens (wordpress.org guideline 11).
	 */
	private function is_settings_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null !== $screen && false !== strpos( $screen->id, SettingsPage::PAGE_SLUG );
	}

	/**
	 * Tells a manager when terms exist but no version has been published, so
	 * the gate is silently inactive. Manager-only, and shown only on the
	 * plugin's own settings screen: an operator's configuration problem is not
	 * every user's business.
	 */
	public function render_setup_warning(): void {
		if ( ! $this->managers->can_manage() || ! $this->is_settings_screen() ) {
			return;
		}

		if ( null !== $this->acceptance->active_version() ) {
			return;
		}

		if ( '' === trim( wp_strip_all_tags( $this->acceptance->content_for_display() ) ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Terms & Conditions text has been entered but no version has been published yet, so nobody is being asked to accept anything.', 'mammoth-acceptance-logs' ),
			esc_url( admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG ) ),
			esc_html__( 'Publish a version', 'mammoth-acceptance-logs' )
		);
	}
}
