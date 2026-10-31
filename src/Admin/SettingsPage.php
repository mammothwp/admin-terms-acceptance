<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Audit\IntegrityKey;
use MammothWP\AcceptanceLogs\Notifications\TermsPdfArchive;
use MammothWP\AcceptanceLogs\Retention\RetentionPolicy;
use MammothWP\AcceptanceLogs\Support\Hasher;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;
use MammothWP\AcceptanceLogs\Support\OptionKeys;
use MammothWP\AcceptanceLogs\Uninstall\UninstallPolicy;
use MammothWP\AcceptanceLogs\Versioning\VersionArchivist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "publish a version of the terms, decide who has to accept it" screen.
 *
 * Access is via Support\ManagerAccess, not a capability — see that class for
 * why. This whole screen is only ever constructed when the management UI is
 * enabled (see Plugin::services()), so with the kill-switch on the menu is
 * never registered and the page cannot be reached at all.
 */
final class SettingsPage {

	public const PAGE_SLUG = 'mmwp-terms-settings';

	/** Upper bound on the "takes effect in N days" field — roughly ten years. */
	private const MAX_EFFECTIVE_DAYS = 3650;

	/**
	 * Where the "takes effect in N days" field starts when nothing has been
	 * recorded yet. A week's notice before new terms bite is a defensible
	 * default; 0 is available for a version that has to take effect at once.
	 */
	private const DEFAULT_EFFECTIVE_DAYS = 7;

	private AcceptanceService $acceptance;
	private TermsPdfArchive $pdf_archive;
	private ManagerAccess $managers;
	private VersionArchivist $archivist;
	private RetentionPolicy $retention;

	public function __construct(
		AcceptanceService $acceptance,
		TermsPdfArchive $pdf_archive,
		ManagerAccess $managers,
		?VersionArchivist $archivist = null,
		?RetentionPolicy $retention = null
	) {
		$this->acceptance  = $acceptance;
		$this->pdf_archive = $pdf_archive;
		$this->managers    = $managers;
		$this->archivist   = $archivist ?? new VersionArchivist();
		$this->retention   = $retention ?? new RetentionPolicy();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	public function add_menu(): void {
		if ( ! $this->managers->can_manage() ) {
			return;
		}

		add_options_page(
			__( 'Terms & Conditions', 'mammoth-acceptance-logs' ),
			__( 'Terms & Conditions', 'mammoth-acceptance-logs' ),
			'read',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['mmwp_terms_settings_nonce'] ) ) {
			return;
		}

		// Scoped to this screen. The previous version ran on every admin_init
		// where the nonce field happened to be present.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! $this->managers->can_manage() ) {
			return;
		}

		check_admin_referer( 'mmwp_terms_save_settings', 'mmwp_terms_settings_nonce' );

		// The manager forms sit on the same screen and share the nonce, so
		// they carry their own marker rather than being inferred from which
		// fields happen to be present.
		if ( isset( $_POST['mmwp_terms_manager_form'] ) ) {
			$this->handle_manager_change();
			return;
		}

		if ( isset( $_POST['mmwp_terms_uninstall_form'] ) ) {
			$this->handle_uninstall_choice();
			return;
		}

		$content = isset( $_POST['mmwp_terms_content'] )
			? wp_kses_post( wp_unslash( $_POST['mmwp_terms_content'] ) )
			: '';

		$roles = isset( $_POST['mmwp_terms_required_roles'] ) && is_array( $_POST['mmwp_terms_required_roles'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['mmwp_terms_required_roles'] ) )
			: array();

		// The number is never posted: publication numbering is a plain
		// sequence derived by VersionArchivist from the archive itself, so
		// two documents can never end up claiming the same version and an
		// admin cannot forget to increment.
		$effective_days = isset( $_POST['mmwp_terms_effective_days'] ) && '' !== $_POST['mmwp_terms_effective_days']
			? absint( wp_unslash( $_POST['mmwp_terms_effective_days'] ) )
			: self::DEFAULT_EFFECTIVE_DAYS;

		if ( $effective_days > self::MAX_EFFECTIVE_DAYS ) {
			$this->redirect_with_notice( 'bad-date' );
		}

		$effective_date = $this->date_in_days( $effective_days );

		$change_summary = isset( $_POST['mmwp_terms_change_summary'] )
			? wp_kses_post( wp_unslash( $_POST['mmwp_terms_change_summary'] ) )
			: '';

		// Required, not optional. A version whose changes nobody described is
		// far weaker evidence that the person accepting it knew what had
		// changed, and the summary is what they are shown above the text.
		if ( '' === trim( wp_strip_all_tags( $change_summary ) ) ) {
			$this->redirect_with_notice( 'need-summary' );
		}

		$active          = $this->acceptance->active_version();
		$content_changed = null === $active || Hasher::hash( $content ) !== $active->hash;

		update_option( OptionKeys::CONTENT, $content );
		update_option( OptionKeys::REQUIRED_ROLES, $roles );
		update_option( OptionKeys::EFFECTIVE_DATE, $effective_date );
		update_option( OptionKeys::CHANGE_SUMMARY, $change_summary );
		update_option(
			OptionKeys::RETENTION_SETTINGS,
			array(
				'dispute_period_years' => isset( $_POST['mmwp_terms_dispute_years'] ) ? max( 0, absint( wp_unslash( $_POST['mmwp_terms_dispute_years'] ) ) ) : 5,
				'anonymise_enabled'    => isset( $_POST['mmwp_terms_anonymise'] ),
			)
		);

		$current_hash = $this->acceptance->refresh_current_hash();

		/**
		 * Fires when the settings page is saved.
		 *
		 * @param string              $version_hash
		 * @param array<string,mixed> $meta Version number, effective date, change summary.
		 */
		do_action(
			'mmwp_terms_content_saved',
			$current_hash,
			array(
				'effective_date' => $effective_date,
				'change_summary' => $change_summary,
				'source'         => 'settings',
			)
		);

		$this->redirect_with_notice( $content_changed ? 'published' : 'saved' );
	}

	private function handle_manager_change(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reached only from handle_save(), which runs check_admin_referer() and the manager gate first.
		if ( ! empty( $_POST['mmwp_terms_remove_manager'] ) ) {
			$removed = $this->managers->remove( absint( wp_unslash( $_POST['mmwp_terms_remove_manager'] ) ) );

			$this->redirect_with_notice( $removed ? 'managers' : 'last-manager' );
		}

		$new_manager = isset( $_POST['mmwp_terms_add_manager'] ) ? absint( wp_unslash( $_POST['mmwp_terms_add_manager'] ) ) : 0;

		if ( 0 === $new_manager ) {
			$this->redirect_with_notice( 'no-manager' );
		}

		$this->redirect_with_notice( $this->managers->add( $new_manager ) ? 'managers' : 'bad-manager' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Stores the "when this plugin is deleted" choice. Needs the right to delete
	 * plugins as well as manager access, since it arms an irreversible action.
	 *
	 * @return void
	 */
	private function handle_uninstall_choice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- reached only from handle_save(), which runs check_admin_referer() and the manager gate first.
		if ( ! current_user_can( 'delete_plugins' ) ) {
			$this->redirect_with_notice( 'uninstall-forbidden' );
		}

		$choice = isset( $_POST['mmwp_terms_uninstall_data'] ) ? sanitize_key( wp_unslash( $_POST['mmwp_terms_uninstall_data'] ) ) : '';
		$typed  = isset( $_POST['mmwp_terms_uninstall_typed'] ) ? sanitize_text_field( wp_unslash( $_POST['mmwp_terms_uninstall_typed'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = UninstallPolicy::set( $choice, $typed );

		$this->redirect_with_notice( is_wp_error( $result ) ? 'uninstall-confirm' : 'uninstall-saved' );
	}

	public function render(): void {
		$this->managers->require_manage();

		// A site that had terms configured before ever publishing has content
		// but no archived version; seed one so the gate can actually engage.
		$this->archivist->ensure_seeded();

		$content          = (string) get_option( OptionKeys::CONTENT, '' );
		$required_roles   = (array) get_option( OptionKeys::REQUIRED_ROLES, array() );
		$all_roles        = wp_roles()->roles;
		$version_number   = (string) get_option( OptionKeys::VERSION_NUMBER, '' );
		$effective_date   = (string) get_option( OptionKeys::EFFECTIVE_DATE, '' );
		$next_number      = (string) $this->acceptance->versions()->next_sequence();
		$effective_days   = $this->days_until( $effective_date );
		$max_days         = self::MAX_EFFECTIVE_DAYS;
		$change_summary   = (string) get_option( OptionKeys::CHANGE_SUMMARY, '' );
		$active_version   = $this->acceptance->active_version();
		$version_history  = $this->acceptance->versions()->all( 20 );
		$pdf_archive      = $this->pdf_archive;
		$manager_ids      = $this->managers->managers();
		$eligible_users   = $this->managers->eligible_users();
		$retention        = $this->retention->settings();
		$retention_text   = $this->retention->justification();
		$key_is_external  = IntegrityKey::is_external();
		$uninstall_data   = UninstallPolicy::get();
		$uninstall_keep   = UninstallPolicy::KEEP;
		$uninstall_drop   = UninstallPolicy::DELETE;
		$uninstall_phrase = UninstallPolicy::CONFIRM_PHRASE;
		$log_url          = admin_url( 'users.php?page=' . AcceptanceLogPage::PAGE_SLUG );

		// Empty saved selection means "all roles" per RoleScope's default —
		// reflect that in the UI so it isn't confusingly all-unchecked.
		$effectively_all = array() === $required_roles;

		require MMWP_ACCEPTANCE_LOGS_PATH . 'src/Admin/views/settings-page.php';
	}

	/**
	 * The date a given number of days from today, in the site's timezone.
	 *
	 * Zero means "today", which is the ordinary case: terms usually take
	 * effect the moment they are published.
	 */
	private function date_in_days( int $days ): string {
		return $this->today()->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Turns a stored effective date back into the number of days the form
	 * asks for. Dates already reached come back as 0 rather than a negative,
	 * so re-saving never quietly moves the date backwards; with nothing
	 * recorded yet the field starts at the default notice period.
	 */
	private function days_until( string $date ): int {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date, wp_timezone() );

		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return self::DEFAULT_EFFECTIVE_DAYS;
		}

		$days = (int) $this->today()->diff( $parsed->setTime( 0, 0 ) )->format( '%r%a' );

		return max( 0, min( self::MAX_EFFECTIVE_DAYS, $days ) );
	}

	private function today(): \DateTimeImmutable {
		return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
	}

	/**
	 * Redirect after save so a refresh cannot re-post, and the notice
	 * survives as a query arg rather than an add_action that dies with the
	 * request.
	 */
	private function redirect_with_notice( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					'mmwp_terms_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
