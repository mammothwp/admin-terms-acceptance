<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Privacy;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Retention\RetentionPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers an Art. 17 erasure request against the acceptance log.
 *
 * It does NOT delete the records, and that is the compliant answer rather
 * than an omission. The processing rests on Art. 6(1)(b) and 6(1)(f), not
 * consent, and Art. 17(3) removes the right to erasure where continued
 * processing is necessary for compliance with a legal obligation and for the
 * establishment, exercise or defence of legal claims — which is precisely
 * what an acceptance record is for. Deleting it on request would destroy the
 * evidence at exactly the moment a dispute makes it matter, and would leave
 * the controller unable to show the terms were ever accepted.
 *
 * What it does do:
 *  - explains the retained data and the reason, in the response the requester
 *    actually reads;
 *  - erases the context fields (IP, User-Agent) for any record whose
 *    retention window has already closed, so nothing is kept past its
 *    documented life just because nobody swept it yet.
 *
 * WordPress's eraser contract supports this: items_retained with a message is
 * a first-class outcome, not an error.
 */
final class PrivacyEraser {

	private AcceptanceService $acceptance;
	private RetentionPolicy $policy;

	public function __construct( AcceptanceService $acceptance, ?RetentionPolicy $policy = null ) {
		$this->acceptance = $acceptance;
		$this->policy     = $policy ?? new RetentionPolicy();
	}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['mammoth-acceptance-logs'] = array(
			'eraser_friendly_name' => __( 'Terms & Conditions acceptance records', 'mammoth-acceptance-logs' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array{items_removed:bool, items_retained:bool, messages:string[], done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );

		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$repository = $this->acceptance->repository();
		$history    = $repository->history_for( $user->ID );

		if ( array() === $history ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = false;

		foreach ( $history as $row ) {
			if ( $this->policy->is_expired( $row ) ) {
				$removed = $repository->anonymise( (int) $row['id'], 'erasure request; retention period already elapsed' ) || $removed;
			}
		}

		$messages = array(
			sprintf(
				/* translators: %d: number of acceptance records */
				_n(
					'%d Terms & Conditions acceptance record has been retained.',
					'%d Terms & Conditions acceptance records have been retained.',
					count( $history ),
					'mammoth-acceptance-logs'
				),
				count( $history )
			),
			__( 'These records are not processed on the basis of consent. They are processed under Article 6(1)(b) GDPR, because accepting the terms is necessary for the contract and for providing access to the platform, and under Article 6(1)(f), for security, accountability and audit purposes. Article 17(3) GDPR limits the right to erasure where processing is necessary for compliance with a legal obligation and for the establishment, exercise or defence of legal claims, which applies to proof that the applicable terms were accepted.', 'mammoth-acceptance-logs' ),
			$this->policy->justification(),
		);

		if ( $removed ) {
			$messages[] = __( 'The IP address and browser identification held against records whose retention period had already elapsed have now been erased.', 'mammoth-acceptance-logs' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
