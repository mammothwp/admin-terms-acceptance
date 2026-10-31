<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Api;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Content\ContentSource;
use MammothWP\AcceptanceLogs\Support\Hasher;
use MammothWP\AcceptanceLogs\Versioning\TermsVersion;
use MammothWP\AcceptanceLogs\Versioning\VersionArchivist;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The supported way to drive the plugin from code.
 *
 * REST, WP-CLI and the `mmwp_terms_*()` helper functions are all thin wrappers
 * around this class, so every programmatic route validates identically and
 * lands in the same archive with the same audit events. Reaching past it into
 * the repositories is not supported and will skip those guarantees.
 *
 * Deliberately limited to the terms themselves — publishing versions, reading
 * the archive, triggering a re-check. It exposes no acceptance records: those
 * are personal data about named people, and they stay behind the manager-gated
 * admin screens and WP's own privacy exporter rather than gaining a second
 * access path with its own permission model to get wrong.
 */
final class TermsApi {

	private AcceptanceService $acceptance;
	private VersionArchivist $archivist;

	public function __construct( AcceptanceService $acceptance, ?VersionArchivist $archivist = null ) {
		$this->acceptance = $acceptance;
		$this->archivist  = $archivist ?? new VersionArchivist();
	}

	/**
	 * Publishes content as a new active version.
	 *
	 * Everyone in scope is asked to accept it, and the previous version is
	 * preserved. Supply `version_number` where you have a meaningful one; if
	 * you do not, one is derived rather than inherited from the version being
	 * replaced.
	 *
	 * @param array{version_number?:string, effective_date?:string, change_summary?:string, source?:string} $meta
	 * @return int|WP_Error Version ID, or an error.
	 */
	public function publish( string $content, array $meta = array() ): int|WP_Error {
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error(
				'mmwp_terms_empty_content',
				__( 'Refusing to publish empty terms: nobody can meaningfully accept a blank document.', 'mammoth-acceptance-logs' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $meta['effective_date'] ) && '' !== (string) $meta['effective_date'] && ! $this->is_valid_date( (string) $meta['effective_date'] ) ) {
			return new WP_Error(
				'mmwp_terms_bad_date',
				__( 'effective_date must be a valid date in Y-m-d format.', 'mammoth-acceptance-logs' ),
				array( 'status' => 400 )
			);
		}

		$number = trim( (string) ( $meta['version_number'] ?? '' ) );

		// Same guardrail the settings screen enforces: two different documents
		// must not both claim to be version 2.1.
		if ( '' !== $number && $this->acceptance->versions()->number_exists( $number ) ) {
			return new WP_Error(
				'mmwp_terms_duplicate_version',
				sprintf(
					/* translators: %s: version number */
					__( 'Version number "%s" has already been published. Give this publication its own number.', 'mammoth-acceptance-logs' ),
					$number
				),
				array( 'status' => 409 )
			);
		}

		$version_id = $this->archivist->publish( $content, $meta + array( 'source' => 'api' ) );

		if ( null === $version_id ) {
			return new WP_Error(
				'mmwp_terms_publish_failed',
				__( 'The version could not be archived. If a mmwp_terms_version_meta filter supplied a version number that is already in use, the publication is refused rather than creating a duplicate. Check the audit event log.', 'mammoth-acceptance-logs' ),
				array( 'status' => 500 )
			);
		}

		return $version_id;
	}

	/**
	 * Publishes only if the content differs from the active version.
	 *
	 * The right call for a sync job that runs on a schedule and cannot easily
	 * tell whether the upstream document moved. Returns the active version's
	 * ID unchanged when there is nothing to do, so a no-op does not re-prompt
	 * every user.
	 *
	 * @param array<string,mixed> $meta
	 * @return int|WP_Error
	 */
	public function publish_if_changed( string $content, array $meta = array() ): int|WP_Error {
		if ( ! $this->would_change( $content ) ) {
			$active = $this->acceptance->active_version();

			if ( null !== $active ) {
				return $active->id;
			}
		}

		return $this->publish( $content, $meta );
	}

	/**
	 * Whether publishing this content would actually produce a new version.
	 *
	 * Compares the NORMALISED form, because that is what gets hashed and
	 * stored. Comparing the raw input instead makes any document that
	 * wp_kses_post() alters look different on every single call — so a nightly
	 * sync using publish_if_changed() would republish every night and nag
	 * every user until they stopped reading the dialog.
	 */
	public function would_change( string $content ): bool {
		$active = $this->acceptance->active_version();

		if ( null === $active ) {
			return true;
		}

		return Hasher::hash( ContentSource::normalise( $content ) ) !== $active->hash;
	}

	public function active(): ?TermsVersion {
		return $this->acceptance->active_version();
	}

	public function find( int $version_id ): ?TermsVersion {
		return $this->acceptance->versions()->find( $version_id );
	}

	/**
	 * @return TermsVersion[] Newest first.
	 */
	public function versions( int $limit = 20, int $offset = 0 ): array {
		return $this->acceptance->versions()->all( $limit, $offset );
	}

	/**
	 * Re-reads the content source now instead of waiting for the daily cron.
	 *
	 * For push-style integrations: when the upstream system says the document
	 * changed, call this and the new text is archived and everyone re-prompted
	 * immediately, rather than up to 24 hours later.
	 *
	 * @return int|null The new version ID, or null if nothing had changed.
	 */
	public function refresh(): ?int {
		$before = $this->acceptance->active_version();

		$this->acceptance->refresh_current_hash();

		// refresh_current_hash() fires mmwp_terms_version_changed, which the
		// archivist turns into a publication. On a site whose archive is still
		// empty nothing is listening yet, so seed it here.
		$this->archivist->ensure_seeded();

		$after = $this->acceptance->active_version();

		if ( null === $after ) {
			return null;
		}

		return ( null === $before || $before->id !== $after->id ) ? $after->id : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array( TermsVersion $version ): array {
		return $version->to_array() + array(
			'content'   => $version->content,
			'is_active' => $version->is_active(),
			'label'     => $version->label(),
		);
	}

	/**
	 * The redacted shape handed to anonymous callers and to the public block.
	 *
	 * Deliberately narrower than to_array(): `version_hash`, `source`,
	 * `created_at_utc` and the activation window are internal provenance —
	 * they matter to an auditor reading the archive, not to a visitor reading
	 * the terms, and publishing them only helps someone probing how the
	 * install is driven.
	 *
	 * Both text fields go back through ContentSource::normalise() so a client
	 * can inject them as HTML without deciding for itself what is safe.
	 *
	 * @return array<string,mixed>
	 */
	public function to_public_array( TermsVersion $version, bool $with_content = false ): array {
		$public = array(
			'id'             => $version->id,
			'version_number' => $version->number,
			'label'          => $version->label(),
			'effective_date' => $version->effective_date,
			'change_summary' => ContentSource::normalise( $version->change_summary ),
			'is_active'      => $version->is_active(),
		);

		if ( $with_content ) {
			$public['content'] = ContentSource::normalise( $version->content );
		}

		return $public;
	}

	private function is_valid_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
