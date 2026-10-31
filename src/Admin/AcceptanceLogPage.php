<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Audit\EventLog;
use MammothWP\AcceptanceLogs\Audit\IntegrityVerifier;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only viewer for the acceptance log, plus an integrity check and a CSV
 * export for handing evidence to a lawyer or a DPO.
 *
 * Managers only, and only when the management UI is enabled at all — the
 * screen is not registered otherwise, so there is nothing to find by URL.
 */
final class AcceptanceLogPage {

	public const PAGE_SLUG     = 'mmwp-terms-log';
	public const EXPORT_ACTION = 'mmwp_terms_export_log';

	private AcceptanceService $acceptance;
	private ManagerAccess $managers;
	private EventLog $events;

	public function __construct( AcceptanceService $acceptance, ManagerAccess $managers, ?EventLog $events = null ) {
		$this->acceptance = $acceptance;
		$this->managers   = $managers;
		$this->events     = $events ?? new EventLog();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export_csv' ) );
	}

	public function add_menu(): void {
		if ( ! $this->managers->can_manage() ) {
			return;
		}

		add_submenu_page(
			'users.php',
			__( 'Terms acceptance log', 'mammoth-acceptance-logs' ),
			__( 'Terms acceptance log', 'mammoth-acceptance-logs' ),
			'read',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		$this->managers->require_manage();

		$filters = $this->current_filters();
		$table   = new AcceptanceLogTable( $this->acceptance, $filters );
		$table->prepare_items();

		$verification = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only verification trigger; it changes nothing and is capability-gated above.
		if ( isset( $_GET['mmwp_terms_verify'] ) ) {
			$verification = ( new IntegrityVerifier() )->verify( 1000 );
		}

		$versions   = $this->acceptance->versions()->all( 50 );
		$export_url = wp_nonce_url(
			add_query_arg( array( 'action' => self::EXPORT_ACTION ) + $filters, admin_url( 'admin-post.php' ) ),
			self::EXPORT_ACTION,
			'_wpnonce'
		);

		$page_slug = self::PAGE_SLUG;

		require MMWP_ACCEPTANCE_LOGS_PATH . 'src/Admin/views/acceptance-log.php';
	}

	public function export_csv(): void {
		$this->managers->require_manage();

		check_admin_referer( self::EXPORT_ACTION );

		$filters = $this->current_filters();
		$rows    = $this->acceptance->repository()->paginate( $filters + array( 'limit' => 10000 ) );

		$this->events->record(
			'log_exported',
			'success',
			array(
				'actor'   => get_current_user_id(),
				'rows'    => count( $rows ),
				'filters' => $filters,
			),
			null,
			null,
			get_current_user_id()
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="terms-acceptances-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$verifier = new IntegrityVerifier();
		$out      = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'record_id',
				'user_id',
				'user_login',
				'user_email',
				'version_id',
				'version_number',
				'effective_date',
				'version_hash',
				'action',
				'accepted_at_utc',
				'accepted_at_local',
				'timezone',
				'utc_offset',
				'ip_address',
				'ip_source',
				'user_agent',
				'site_identifier',
				'anonymised_at_utc',
				'record_hash',
				'prev_record_hash',
				'integrity_core',
				'integrity_context',
			)
		);

		foreach ( $rows as $row ) {
			$verdict = $verifier->verify_row( $row );

			fputcsv(
				$out,
				array(
					$row['id'],
					$row['user_id'],
					$row['user_login_snapshot'],
					$row['user_email_snapshot'],
					$row['version_id'],
					$row['version_number'] ?? '',
					$row['effective_date'] ?? '',
					$row['version_hash'],
					$row['action'],
					$row['accepted_at_utc'],
					$row['accepted_at_local'],
					$row['timezone'],
					$row['utc_offset'],
					$row['ip_address'],
					$row['ip_source'],
					$row['user_agent'],
					$row['site_identifier'],
					$row['anonymised_at_utc'],
					$row['record_hash'],
					$row['prev_record_hash'],
					$verdict['core'] ? 'verified' : 'FAILED',
					$verdict['context'] ? 'verified' : 'FAILED',
				)
			);
		}

		// The stream is php://output, not a file on disk — WP_Filesystem has no
		// API for streaming a download, and there is nothing here to write.
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function current_filters(): array {
		$filters = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filtering; no state change, and the page is capability-gated.
		if ( ! empty( $_GET['filter_user'] ) ) {
			$filters['user_id'] = absint( wp_unslash( $_GET['filter_user'] ) );
		}

		if ( ! empty( $_GET['filter_version'] ) ) {
			$filters['version_id'] = absint( wp_unslash( $_GET['filter_version'] ) );
		}

		if ( ! empty( $_GET['filter_from'] ) ) {
			$filters['date_from'] = sanitize_text_field( wp_unslash( $_GET['filter_from'] ) );
		}

		if ( ! empty( $_GET['filter_to'] ) ) {
			$filters['date_to'] = sanitize_text_field( wp_unslash( $_GET['filter_to'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $filters;
	}
}
