<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs and verifies audit records.
 *
 * Two signatures per acceptance, and the split is load-bearing:
 *
 *  - sign_core()    covers the immutable evidentiary core. Once written it
 *                   is never touched again for the life of the row.
 *  - sign_context() covers the erasable context (IP, its source, the
 *                   User-Agent), which retention legitimately clears later.
 *
 * With a single signature, lawfully anonymising an expired record would be
 * indistinguishable from an attacker editing it — the plugin's own GDPR
 * compliance would look like tampering. Splitting them means anonymisation
 * invalidates only the context signature (and stamps anonymised_at_utc to
 * say why), while the core signature keeps verifying forever.
 *
 * IMPORTANT: the field orders below are frozen. They are part of the
 * on-disk format — reordering, adding or renaming a field invalidates every
 * signature ever written. If the record shape must change, add a new
 * signing version and keep this one for old rows.
 */
final class RecordSigner {

	/** Frozen. See class docblock before touching. */
	private const CORE_FIELDS = array(
		'user_id',
		'user_login_snapshot',
		'version_id',
		'version_hash',
		'accepted_at_utc',
		'action',
		'site_identifier',
		'prev_record_hash',
	);

	/** Frozen. See class docblock before touching. */
	private const CONTEXT_FIELDS = array(
		'ip_address',
		'ip_source',
		'user_agent',
	);

	/** Frozen. See class docblock before touching. */
	private const EVENT_FIELDS = array(
		'acceptance_id',
		'version_id',
		'user_id',
		'event_type',
		'outcome',
		'context',
		'created_at_utc',
	);

	/**
	 * @param array<string,mixed> $fields
	 */
	public function sign_core( array $fields ): string {
		return $this->sign( $fields, self::CORE_FIELDS );
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function sign_context( array $fields ): string {
		return $this->sign( $fields, self::CONTEXT_FIELDS );
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function sign_event( array $fields ): string {
		return $this->sign( $fields, self::EVENT_FIELDS );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{core: bool, context: bool}
	 */
	public function verify_row( array $row ): array {
		$context_expected = (string) ( $row['context_hash'] ?? '' );

		return array(
			'core'    => hash_equals( (string) ( $row['record_hash'] ?? '' ), $this->sign_core( $row ) ),
			// An anonymised record clears context_hash on purpose; that is a
			// documented state, not a failure.
			'context' => '' === $context_expected
				? true
				: hash_equals( $context_expected, $this->sign_context( $row ) ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function verify_event( array $row ): bool {
		return hash_equals( (string) ( $row['record_hash'] ?? '' ), $this->sign_event( $row ) );
	}

	/**
	 * Canonicalisation: named fields, in the frozen order, JSON-encoded
	 * without escaping so the byte sequence is reproducible across PHP and
	 * database round-trips. Every value is cast to string first, because a
	 * column read back from MySQL returns "5" where the insert passed int 5
	 * and an unsigned comparison would then fail verification on unchanged data.
	 *
	 * @param array<string,mixed> $fields
	 * @param string[]            $order
	 */
	private function sign( array $fields, array $order ): string {
		$canonical = array();

		foreach ( $order as $key ) {
			$canonical[ $key ] = (string) ( $fields[ $key ] ?? '' );
		}

		$payload = (string) wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return hash_hmac( 'sha256', $payload, IntegrityKey::get() );
	}
}
