<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Privacy;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feeds acceptance records into WordPress's personal-data export
 * (Tools -> Export Personal Data), answering an Art. 15 access request.
 *
 * Exports everything held about the person's acceptances, including the
 * version they accepted and the circumstances recorded — a subject access
 * request is entitled to the context, not just the fact.
 */
final class PrivacyExporter {

	private const GROUP_ID = 'mmwp-terms-acceptances';

	private AcceptanceService $acceptance;

	public function __construct( AcceptanceService $acceptance ) {
		$this->acceptance = $acceptance;
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['mammoth-acceptance-logs'] = array(
			'exporter_friendly_name' => __( 'Terms & Conditions acceptance records', 'mammoth-acceptance-logs' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @return array{data:array<int,array<string,mixed>>, done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$export = array();

		foreach ( $this->acceptance->repository()->history_for( $user->ID ) as $row ) {
			$export[] = array(
				'group_id'    => self::GROUP_ID,
				'group_label' => __( 'Terms & Conditions acceptances', 'mammoth-acceptance-logs' ),
				'item_id'     => 'mammoth-acceptance-logs-' . (int) $row['id'],
				'data'        => $this->fields( $row ),
			);
		}

		return array(
			'data' => $export,
			// history_for() returns the whole history in one pass; there is
			// no second page to fetch.
			'done' => true,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,array{name:string, value:string}>
	 */
	private function fields( array $row ): array {
		$fields = array(
			array(
				'name'  => __( 'Record reference', 'mammoth-acceptance-logs' ),
				'value' => '#' . (string) $row['id'],
			),
			array(
				'name'  => __( 'Action', 'mammoth-acceptance-logs' ),
				'value' => (string) $row['action'],
			),
			array(
				'name'  => __( 'Accepted at (UTC)', 'mammoth-acceptance-logs' ),
				'value' => (string) $row['accepted_at_utc'],
			),
			array(
				'name'  => __( 'Accepted at (local time shown to you)', 'mammoth-acceptance-logs' ),
				'value' => trim( (string) $row['accepted_at_local'] . ' ' . (string) $row['timezone'] . ( '' !== (string) $row['utc_offset'] ? ' (UTC' . (string) $row['utc_offset'] . ')' : '' ) ),
			),
			array(
				'name'  => __( 'Version number', 'mammoth-acceptance-logs' ),
				'value' => (string) ( $row['version_number'] ?? '' ),
			),
			array(
				'name'  => __( 'Effective date', 'mammoth-acceptance-logs' ),
				'value' => (string) ( $row['effective_date'] ?? '' ),
			),
			array(
				'name'  => __( 'Version identifier', 'mammoth-acceptance-logs' ),
				'value' => (string) $row['version_hash'],
			),
			array(
				'name'  => __( 'Site', 'mammoth-acceptance-logs' ),
				'value' => (string) $row['site_identifier'],
			),
		);

		// Present only while the retention window is open; say so plainly
		// rather than showing an empty row that looks like missing data.
		if ( null !== $row['anonymised_at_utc'] ) {
			$fields[] = array(
				'name'  => __( 'IP address and browser identification', 'mammoth-acceptance-logs' ),
				'value' => sprintf(
					/* translators: %s: date/time */
					__( 'Erased on %s, once the retention period for this record had elapsed.', 'mammoth-acceptance-logs' ),
					(string) $row['anonymised_at_utc']
				),
			);

			return $fields;
		}

		$fields[] = array(
			'name'  => __( 'IP address', 'mammoth-acceptance-logs' ),
			'value' => (string) $row['ip_address'],
		);
		$fields[] = array(
			'name'  => __( 'Browser identification (User-Agent)', 'mammoth-acceptance-logs' ),
			'value' => (string) $row['user_agent'],
		);

		return $fields;
	}
}
