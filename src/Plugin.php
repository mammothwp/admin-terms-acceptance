<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Admin\AcceptanceLogPage;
use MammothWP\AcceptanceLogs\Api\KitRegistrar;
use MammothWP\AcceptanceLogs\Api\PublicRestController;
use MammothWP\AcceptanceLogs\Api\TermsApi;
use MammothWP\AcceptanceLogs\Api\TermsResource;
use MammothWP\AcceptanceLogs\Api\TermsService;
use MammothWP\AcceptanceLogs\Admin\AdminAssets;
use MammothWP\AcceptanceLogs\Admin\AdminNotice;
use MammothWP\AcceptanceLogs\Admin\SettingsPage;
use MammothWP\AcceptanceLogs\Admin\UserTermsColumn;
use MammothWP\AcceptanceLogs\Blocks\TermsDisplayBlock;
use MammothWP\AcceptanceLogs\Cron\HashWatcher;
use MammothWP\AcceptanceLogs\Frontend\AjaxController;
use MammothWP\AcceptanceLogs\Frontend\PopupRenderer;
use MammothWP\AcceptanceLogs\Notifications\CertificateMailer;
use MammothWP\AcceptanceLogs\Notifications\MailFailureListener;
use MammothWP\AcceptanceLogs\Notifications\PdfGenerator;
use MammothWP\AcceptanceLogs\Notifications\TermsPdfArchive;
use MammothWP\AcceptanceLogs\Privacy\PolicyContent;
use MammothWP\AcceptanceLogs\Privacy\PrivacyEraser;
use MammothWP\AcceptanceLogs\Privacy\PrivacyExporter;
use MammothWP\AcceptanceLogs\Retention\RetentionCron;
use MammothWP\AcceptanceLogs\Storage\SnapshotDownloadController;
use MammothWP\AcceptanceLogs\Support\AdminUi;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;
use MammothWP\AcceptanceLogs\Uninstall\PluginsScreen;
use MammothWP\AcceptanceLogs\Uninstall\UninstallChoiceController;
use MammothWP\AcceptanceLogs\Users\DeletedUserHandler;
use MammothWP\AcceptanceLogs\Versioning\VersionArchivist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composition root. Nothing here does anything by itself beyond wiring
 * services together and calling their register() methods — keeps the
 * bootstrap file thin and every dependency explicit/testable.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private AcceptanceService $acceptance;
	private ManagerAccess $managers;
	private TermsApi $api;

	private function __construct() {
		$this->acceptance = new AcceptanceService();
		$this->managers   = new ManagerAccess();
		$this->api        = new TermsApi( $this->acceptance );
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function init(): void {
		foreach ( $this->services() as $service ) {
			$service->register();
		}
	}

	/**
	 * Every service, in registration order.
	 *
	 * The admin screens are appended only when the management UI is enabled,
	 * so with the kill-switch on they are never constructed and their hooks
	 * are never added — nothing to enumerate and nothing reachable by URL,
	 * rather than a screen that renders and then hides itself.
	 *
	 * Everything above that boundary keeps running either way: the acceptance
	 * popup, recording, receipts, the cron jobs and the privacy exporter and
	 * eraser. Switching off the admin UI must never switch off the audit trail
	 * or the site's GDPR obligations.
	 *
	 * @return array<int, object{register: callable}>
	 */
	private function services(): array {
		$pdf         = new PdfGenerator();
		$pdf_archive = new TermsPdfArchive( $pdf );

		$services = array(
			$this->managers,
			new VersionArchivist(),
			new AdminNotice( $this->acceptance, $this->managers ),
			new PopupRenderer( $this->acceptance ),
			new AjaxController( $this->acceptance ),
			new MailFailureListener(),
			new CertificateMailer( $this->acceptance, $pdf ),
			new HashWatcher( $this->acceptance ),
			new RetentionCron( $this->acceptance ),
			new DeletedUserHandler( $this->acceptance ),
			new PrivacyExporter( $this->acceptance ),
			new PrivacyEraser( $this->acceptance ),
			new PolicyContent(),
			new PluginsScreen(),
			new UninstallChoiceController(),
			// Programmatic management. Deliberately outside the admin-UI
			// block below: a locked-down install is exactly the one that has
			// to be driven by code, so switching off the screens must not
			// also remove the only remaining way to manage the terms.
			new KitRegistrar( new TermsResource( new TermsService( $this->api, $this->acceptance ) ) ),
			// The public face of the archive: unauthenticated read-only
			// routes and the block that consumes them. Public surfaces, so
			// they sit above the admin kill-switch alongside the popup — a
			// site that hides its management screens still has a terms page.
			new PublicRestController( $this->api ),
			new TermsDisplayBlock( $this->api ),
		);

		if ( AdminUi::is_enabled() ) {
			$services[] = new AdminAssets();
			$services[] = new SettingsPage( $this->acceptance, $pdf_archive, $this->managers );
			$services[] = new AcceptanceLogPage( $this->acceptance, $this->managers );
			$services[] = new UserTermsColumn( $this->acceptance, $this->managers );
			$services[] = new SnapshotDownloadController( $this->acceptance, $this->managers, $pdf_archive );
		}

		return $services;
	}

	public function acceptance(): AcceptanceService {
		return $this->acceptance;
	}

	public function managers(): ManagerAccess {
		return $this->managers;
	}

	/**
	 * The supported entry point for driving the plugin from code. REST,
	 * WP-CLI and the mmwp_terms_*() helpers all go through it.
	 */
	public function api(): TermsApi {
		return $this->api;
	}
}
