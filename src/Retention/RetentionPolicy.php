<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Retention;

use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long acceptance evidence is kept, and on what reasoning.
 *
 * Neither the GDPR nor Greek law sets a retention period for acceptance
 * logs of this kind, so Art. 5(1)(e) requires the controller to choose one
 * and be able to justify it. The choice made here:
 *
 *  - Records are retained while the account exists. The purpose — proving
 *    the terms governing an active relationship were accepted — is live for
 *    as long as the relationship is.
 *  - After that, a dispute period. The default of 5 years tracks the general
 *    limitation period for contractual claims under Greek civil law
 *    (Art. 250 AK), i.e. the window in which someone could still bring a
 *    claim the record would answer. It is a settable default, not a
 *    constant, because the right number depends on the operator's own
 *    exposure.
 *  - When that window closes, records are ANONYMISED, never deleted. The IP
 *    and User-Agent — the parts that identify a person beyond the account
 *    itself — are cleared; the fact of acceptance, its version and its
 *    timestamp remain. That satisfies minimisation without destroying the
 *    accountability record.
 *  - Versions are never removed at all. An acceptance whose text cannot be
 *    reproduced proves very little, so the archive outlives everything else.
 *
 * See docs/retention-policy.md, which is the version of this a DPO reads.
 */
final class RetentionPolicy {

	private const DEFAULT_DISPUTE_YEARS = 5;

	/**
	 * @return array{dispute_period_years:int, anonymise_enabled:bool}
	 */
	public function settings(): array {
		$saved = get_option( OptionKeys::RETENTION_SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$settings = array(
			'dispute_period_years' => isset( $saved['dispute_period_years'] )
				? max( 0, (int) $saved['dispute_period_years'] )
				: self::DEFAULT_DISPUTE_YEARS,
			'anonymise_enabled'    => (bool) ( $saved['anonymise_enabled'] ?? true ),
		);

		/**
		 * Filters the retention settings.
		 *
		 * @param array{dispute_period_years:int, anonymise_enabled:bool} $settings
		 */
		return (array) apply_filters( 'mmwp_terms_retention_settings', $settings );
	}

	public function dispute_period_years(): int {
		return (int) $this->settings()['dispute_period_years'];
	}

	public function anonymise_enabled(): bool {
		return (bool) $this->settings()['anonymise_enabled'];
	}

	/**
	 * The UTC datetime before which a record's context fields have outlived
	 * their purpose.
	 */
	public function cutoff_utc(): string {
		$years = $this->dispute_period_years();

		return gmdate( 'Y-m-d H:i:s', ( strtotime( '-' . $years . ' years' ) ? strtotime( '-' . $years . ' years' ) : time() ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function is_expired( array $row ): bool {
		if ( null !== ( $row['anonymised_at_utc'] ?? null ) ) {
			return false;
		}

		return (string) $row['accepted_at_utc'] < $this->cutoff_utc();
	}

	/**
	 * The written justification, shown in the admin and reused verbatim in
	 * the privacy policy text so the two cannot drift apart.
	 */
	public function justification(): string {
		return sprintf(
			/* translators: %d: number of years */
			__( 'Acceptance records are kept for as long as the account exists, and then for a further %d years — the period in which a contractual claim could still be brought that the record would answer. After that the IP address and browser identification are erased, while the fact, version and time of acceptance are retained as an accountability record. Published versions of the terms are never deleted, because an acceptance whose text cannot be reproduced has little evidential value.', 'mammoth-acceptance-logs' ),
			$this->dispute_period_years()
		);
	}
}
