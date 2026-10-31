<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Retention;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the retention policy on a daily schedule.
 *
 * Anonymises; never deletes. Batched, because a sweep that tried to process
 * an entire history in one cron tick would time out on exactly the large
 * installs that most need it to run.
 */
final class RetentionCron {

	private const BATCH_SIZE = 100;

	private AcceptanceService $acceptance;
	private RetentionPolicy $policy;

	public function __construct( AcceptanceService $acceptance, ?RetentionPolicy $policy = null ) {
		$this->acceptance = $acceptance;
		$this->policy     = $policy ?? new RetentionPolicy();
	}

	public function register(): void {
		add_action( OptionKeys::CRON_RETENTION_HOOK, array( $this, 'sweep' ) );
	}

	public function sweep(): void {
		if ( ! $this->policy->anonymise_enabled() ) {
			return;
		}

		$repository = $this->acceptance->repository();
		$cutoff     = $this->policy->cutoff_utc();

		foreach ( $repository->expired_context( $cutoff, self::BATCH_SIZE ) as $row ) {
			$repository->anonymise(
				(int) $row['id'],
				sprintf(
					/* translators: %d: number of years */
					__( 'retention period of %d years elapsed', 'mammoth-acceptance-logs' ),
					$this->policy->dispute_period_years()
				)
			);
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( OptionKeys::CRON_RETENTION_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, OptionKeys::CRON_RETENTION_HOOK );
		}
	}

	public function policy(): RetentionPolicy {
		return $this->policy;
	}
}
