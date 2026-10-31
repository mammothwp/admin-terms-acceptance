<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Acceptance;

use MammothWP\AcceptanceLogs\Content\ContentSource;
use MammothWP\AcceptanceLogs\Scope\RoleScope;
use MammothWP\AcceptanceLogs\Support\Hasher;
use MammothWP\AcceptanceLogs\Support\OptionKeys;
use MammothWP\AcceptanceLogs\Support\RequestContext;
use MammothWP\AcceptanceLogs\Versioning\TermsVersion;
use MammothWP\AcceptanceLogs\Versioning\VersionRepository;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single place that answers "does this user need to accept the terms
 * right now" and "record that they just did (or skipped)". The popup, the
 * admin notice, the settings page and the cron watcher all go through this
 * class rather than touching options or user meta directly.
 *
 * ON THE LEGAL FRAMING, because it drives the design: this is NOT consent
 * management under Art. 6(1)(a) GDPR and must not be described as such.
 * Accepting the terms is a precondition of access — a contractual and
 * access-control mechanism resting on Art. 6(1)(b) (necessary to perform the
 * employment or service contract) and Art. 6(1)(f) (security, accountability,
 * auditability). Consent would be the wrong basis here anyway: for employees
 * and contractors the imbalance of power makes freely-given consent doubtful,
 * and the HDPA (Decision 26/2019) expects a controller to name the right
 * basis from the outset rather than dressing another one up as consent.
 *
 * Practical consequence: acceptance is not withdrawable in the Art. 7(3)
 * sense, and the records are not deleted on request — see Privacy\PrivacyEraser
 * and docs/retention-policy.md for how that is handled and justified.
 */
final class AcceptanceService {

	public const STATUS_NOT_REQUIRED = 'not_required';
	public const STATUS_PENDING      = 'pending';
	public const STATUS_ACCEPTED     = 'accepted';

	private ContentSource $content_source;
	private RoleScope $role_scope;
	private AcceptanceRepository $repository;
	private VersionRepository $versions;
	private RequestContext $request;

	public function __construct(
		?ContentSource $content_source = null,
		?RoleScope $role_scope = null,
		?AcceptanceRepository $repository = null,
		?VersionRepository $versions = null,
		?RequestContext $request = null
	) {
		$this->content_source = $content_source ?? new ContentSource();
		$this->role_scope     = $role_scope ?? new RoleScope();
		$this->repository     = $repository ?? new AcceptanceRepository();
		$this->versions       = $versions ?? new VersionRepository();
		$this->request        = $request ?? new RequestContext();
	}

	public function current_hash(): string {
		$cached = get_option( OptionKeys::CURRENT_HASH, '' );

		return is_string( $cached ) && '' !== $cached
			? $cached
			: $this->refresh_current_hash();
	}

	/**
	 * Recomputes the hash from the live content source, updates the cache,
	 * and fires `mmwp_terms_version_changed` if it moved — which is what
	 * Versioning\VersionArchivist listens for to archive the new text.
	 */
	public function refresh_current_hash(): string {
		// Normalised first, matching Versioning\VersionArchivist — otherwise
		// this reports a change every run for any document wp_kses_post alters.
		$new_hash = Hasher::hash( ContentSource::normalise( $this->content_source->get_content() ) );
		$old_hash = get_option( OptionKeys::CURRENT_HASH, '' );

		update_option( OptionKeys::CURRENT_HASH, $new_hash, false );
		update_option( OptionKeys::LAST_HASH_CHECK, time(), false );

		if ( is_string( $old_hash ) && '' !== $old_hash && $old_hash !== $new_hash ) {
			/**
			 * Fires when the terms content hash changes.
			 *
			 * @param string $old_hash
			 * @param string $new_hash
			 */
			do_action( 'mmwp_terms_version_changed', $old_hash, $new_hash );
		}

		return $new_hash;
	}

	public function active_version(): ?TermsVersion {
		return $this->versions->active();
	}

	public function user_needs_prompt( WP_User $user ): bool {
		return self::STATUS_PENDING === $this->acceptance_status_for( $user );
	}

	/**
	 * Where a user stands with the current terms version.
	 *
	 * Compares the version ID they accepted against the active one, NOT the
	 * content hash. Hash equality was the old approach and it had a hole: an
	 * admin who reverted the text to an earlier wording restored that
	 * wording's hash, and everyone who had accepted it silently counted as
	 * up to date again — no prompt, no record of the re-publication. Because
	 * every publication gets its own archive row, identical text published
	 * twice is two versions, and the second one is prompted for properly.
	 *
	 * @return self::STATUS_*
	 */
	public function acceptance_status_for( WP_User $user ): string {
		if ( '' === trim( wp_strip_all_tags( $this->content_for_display() ) ) ) {
			return self::STATUS_NOT_REQUIRED;
		}

		if ( ! $this->role_scope->user_requires_acceptance( $user ) ) {
			return self::STATUS_NOT_REQUIRED;
		}

		$active = $this->versions->active();

		if ( null === $active ) {
			// Terms exist but nothing has been archived yet (first run before
			// the first publish). Nobody can accept a version that has no
			// preserved text, so nobody is prompted for one.
			return self::STATUS_NOT_REQUIRED;
		}

		return $this->repository->accepted_version_id_for( $user->ID ) === $active->id
			? self::STATUS_ACCEPTED
			: self::STATUS_PENDING;
	}

	/**
	 * Records an acceptance and returns its ID, or null if it could not be
	 * recorded — in which case the user has NOT accepted anything and the
	 * caller must say so rather than letting them through.
	 *
	 * $expected_version_id is what the user's browser was actually showing.
	 * If the terms were republished while the popup sat open, accepting would
	 * otherwise attribute agreement to text they never saw.
	 */
	public function record_acceptance( WP_User $user, ?int $expected_version_id = null ): ?int {
		// Re-checked here rather than trusted from the caller: the AJAX
		// endpoint is reachable by any logged-in user with a valid nonce, and
		// an out-of-scope user's acceptance is a record of something we never
		// asked them to do.
		if ( ! $this->role_scope->user_requires_acceptance( $user ) ) {
			return null;
		}

		$active = $this->versions->active();

		if ( null === $active ) {
			return null;
		}

		if ( null !== $expected_version_id && $expected_version_id !== $active->id ) {
			return null;
		}

		$context = AcceptanceContext::build( $user, $active->id, $active->hash, $this->request );

		$acceptance_id = $this->repository->record( $context );

		if ( null === $acceptance_id ) {
			return null;
		}

		$this->clear_skip( $user->ID );

		/**
		 * Fires right after an acceptance is persisted.
		 *
		 * @param int    $user_id
		 * @param string $version_hash
		 * @param int    $acceptance_id
		 * @param int    $version_id
		 */
		do_action( 'mmwp_terms_accepted', $user->ID, $active->hash, $acceptance_id, $active->id );

		return $acceptance_id;
	}

	public function record_skip( WP_User $user ): void {
		$ttl = (int) apply_filters( 'mmwp_terms_skip_duration', 12 * HOUR_IN_SECONDS );

		set_transient( OptionKeys::SKIP_TRANSIENT_PREFIX . $user->ID, $this->current_hash(), $ttl );

		/**
		 * @param int $user_id
		 */
		do_action( 'mmwp_terms_skipped', $user->ID );
	}

	/**
	 * Whether the popup overlay itself should be suppressed for this request
	 * (they clicked "skip for now" recently). The admin notice is NOT gated
	 * by this — it stays up regardless, by design.
	 */
	public function popup_is_skipped( WP_User $user ): bool {
		$skipped_hash = get_transient( OptionKeys::SKIP_TRANSIENT_PREFIX . $user->ID );

		return is_string( $skipped_hash ) && $skipped_hash === $this->current_hash();
	}

	private function clear_skip( int $user_id ): void {
		delete_transient( OptionKeys::SKIP_TRANSIENT_PREFIX . $user_id );
	}

	/**
	 * The text users are shown and asked to accept.
	 *
	 * Served from the archive, not from the live content source, so what is
	 * displayed is byte-for-byte the text the acceptance record will point
	 * at. Falls back to the live source only before the first publication,
	 * when there is nothing archived yet.
	 */
	public function content_for_display(): string {
		$active = $this->versions->active();

		return null !== $active ? $active->content : $this->content_source->get_content();
	}

	public function repository(): AcceptanceRepository {
		return $this->repository;
	}

	public function versions(): VersionRepository {
		return $this->versions;
	}
}
