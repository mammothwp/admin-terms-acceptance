<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Versioning;

use MammothWP\AcceptanceLogs\Audit\EventLog;
use MammothWP\AcceptanceLogs\Content\ContentSource;
use MammothWP\AcceptanceLogs\Support\Hasher;
use MammothWP\AcceptanceLogs\Support\OptionKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns "the terms changed" into a permanent archive entry.
 *
 * Every path a change can arrive by ends here:
 *
 *  - mmwp_terms_content_saved   — an admin saved the settings page.
 *  - mmwp_terms_version_changed — Cron\HashWatcher noticed the filtered
 *    content source moved (a JSON file on disk, an external legal CMS).
 *  - Api\TermsApi::publish()   — a deliberate programmatic publication via
 *    REST, WP-CLI, or `mmwp_terms_publish_version()`.
 *
 * Version metadata for non-interactive publications comes from the
 * `mmwp_terms_version_meta` filter, so an integration can supply the number,
 * effective date and change summary alongside the text it feeds in. Where
 * none is supplied the number is DERIVED as the next whole number in the
 * archive's own sequence — never inherited from the version being replaced.
 * Reusing the previous number would leave two different documents claiming
 * to be the same version, which is exactly the ambiguity the archive exists
 * to remove. The settings screen never supplies one: numbering there is
 * automatic, so an admin cannot mis-number a publication.
 */
final class VersionArchivist {

	private VersionRepository $versions;
	private ContentSource $content_source;
	private EventLog $events;

	public function __construct(
		?VersionRepository $versions = null,
		?ContentSource $content_source = null,
		?EventLog $events = null
	) {
		$this->versions       = $versions ?? new VersionRepository();
		$this->content_source = $content_source ?? new ContentSource();
		$this->events         = $events ?? new EventLog();
	}

	public function register(): void {
		add_action( 'mmwp_terms_content_saved', array( $this, 'on_content_saved' ), 10, 2 );
		add_action( 'mmwp_terms_version_changed', array( $this, 'on_version_changed' ), 10, 2 );
	}

	/**
	 * @param array<string,mixed> $meta Version number, effective date, change summary.
	 */
	public function on_content_saved( string $version_hash, array $meta = array() ): void {
		$this->publish( $this->content_source->get_content(), $meta + array( 'source' => 'settings' ) );
	}

	/**
	 * The content source moved without anyone touching the settings screen.
	 *
	 * Whatever the integration supplies through `mmwp_terms_version_meta` is
	 * used; anything it leaves out is derived. The change summary says plainly
	 * that this was an automatic pickup rather than inventing an account of
	 * what changed that nobody wrote.
	 */
	public function on_version_changed( string $old_hash, string $new_hash ): void {
		$this->publish(
			$this->content_source->get_content(),
			array(
				'source'         => 'filter',
				'change_summary' => __( 'Recorded automatically: the terms source changed outside the settings screen.', 'mammoth-acceptance-logs' ),
			)
		);
	}

	/**
	 * Archives the given content as a new active version and returns its ID.
	 *
	 * No-ops when neither the text nor the human-authored metadata has moved,
	 * so re-saving the settings page without changing anything does not mint
	 * a version and re-prompt every user for nothing.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function publish( string $content, array $meta = array() ): ?int {
		// Normalised before hashing, so the hash always describes the bytes
		// that get stored and displayed. Content used to be sanitised on some
		// paths and not others, which meant the same document hashed two ways
		// and the cron saw a change that had not happened.
		$content = ContentSource::normalise( $content );
		$hash    = Hasher::hash( $content );
		$current = $this->versions->active();

		/**
		 * Filters the version metadata attached to a publication.
		 *
		 * This is how a programmatic content source names its own versions.
		 * Set `version_number`, `effective_date` (Y-m-d) and `change_summary`
		 * to have them recorded; anything left empty is derived, except the
		 * effective date, which is simply not recorded.
		 *
		 * PRECEDENCE: this filter runs last and wins, per WordPress
		 * convention — including over a number typed on the settings screen
		 * or passed to the API. That is easy to get wrong in a way that
		 * mislabels a legal document, so scope the filter to the sources it
		 * is meant for and leave values the caller already supplied alone:
		 *
		 *     add_filter( 'mmwp_terms_version_meta', function ( array $meta, $content ) {
		 *         // Someone published deliberately — don't second-guess them.
		 *         if ( 'settings' === ( $meta['source'] ?? '' ) || ! empty( $meta['version_number'] ) ) {
		 *             return $meta;
		 *         }
		 *
		 *         $doc = my_legal_api_fetch();
		 *
		 *         $meta['version_number'] = $doc['version'];
		 *         $meta['effective_date'] = $doc['effective_from'];
		 *         $meta['change_summary'] = $doc['changelog'];
		 *
		 *         return $meta;
		 *     }, 10, 2 );
		 *
		 * `$meta['source']` is one of settings|filter|api|rest|cli|code.
		 *
		 * @param array<string,mixed> $meta    Version metadata so far.
		 * @param string              $content The content being published.
		 * @param string              $hash    Its version hash.
		 */
		$meta = (array) apply_filters( 'mmwp_terms_version_meta', $meta, $content, $hash );

		$number         = trim( (string) ( $meta['version_number'] ?? '' ) );
		$effective_date = $this->normalise_date( $meta['effective_date'] ?? null );
		$summary        = (string) ( $meta['change_summary'] ?? '' );
		$source         = (string) ( $meta['source'] ?? 'settings' );

		// The effective date is never inherited from the version being
		// replaced. It is a statement about a specific document — "these
		// terms take effect on X" — so carrying v1.0's date onto v1.3 would
		// put a false claim in the archive and in every receipt that cites
		// it. A version published without one simply has none recorded.

		$content_changed = null === $current || $current->hash !== $hash;

		// Nothing moved and nothing was renamed: publishing again would mint
		// a version and re-prompt every user for no reason.
		if ( ! $content_changed && ( '' === $number || $current->number === $number ) ) {
			return null !== $current ? $current->id : null;
		}

		// Derived, never inherited. See the class docblock.
		if ( '' === $number ) {
			$number = $this->next_version_number();
		} elseif ( $this->versions->number_exists( $number ) ) {
			// Backstop, and it has to live here rather than only in the API
			// layer: mmwp_terms_version_meta runs after any caller-side check,
			// so a misconfigured filter could otherwise put two different
			// documents into the archive both claiming to be version 2.1 —
			// destroying the one guarantee a version number provides.
			$this->events->record(
				'version_activated',
				'failure',
				array(
					'version_hash'   => $hash,
					'version_number' => $number,
					'source'         => $source,
					'reason'         => 'version number already used; refusing to create a duplicate',
				)
			);

			return null;
		}

		$now_utc = gmdate( 'Y-m-d H:i:s' );

		$version_id = $this->versions->insert(
			array(
				'version_hash'   => $hash,
				'version_number' => $number,
				'effective_date' => $effective_date,
				'change_summary' => $summary,
				'content'        => $content,
				'created_by'     => ( get_current_user_id() > 0 ? get_current_user_id() : null ),
				'source'         => $source,
			)
		);

		if ( null === $version_id ) {
			$this->events->record( 'version_activated', 'failure', array( 'version_hash' => $hash ) );

			return null;
		}

		$this->versions->close_active( $now_utc );
		$this->versions->activate( $version_id, $now_utc );

		// Keep the stored content, the settings screen and the cached hash in
		// step. Writing the content back matters most: ContentSource reads
		// that option, so a programmatic publication that skipped it left the
		// option holding older text — and the next cron run dutifully
		// "restored" it, silently reverting the published version within 24
		// hours and re-prompting everyone with superseded terms.
		update_option( OptionKeys::CONTENT, $content );
		update_option( OptionKeys::VERSION_NUMBER, $number );
		update_option( OptionKeys::CURRENT_HASH, $hash, false );

		if ( null !== $effective_date ) {
			update_option( OptionKeys::EFFECTIVE_DATE, $effective_date );
		}

		$this->events->record(
			'version_activated',
			'success',
			array(
				'version_hash'   => $hash,
				'version_number' => $number,
				'source'         => $source,
				'replaces'       => null !== $current ? $current->id : null,
			),
			null,
			$version_id
		);

		/**
		 * Fires once a new terms version has been archived and made active.
		 *
		 * @param int    $version_id
		 * @param string $version_hash
		 */
		do_action( 'mmwp_terms_version_published', $version_id, $hash );

		return $version_id;
	}

	/**
	 * The next version number when the caller did not supply one.
	 *
	 * A plain sequence — 1, 2, 3 — counted from the archive itself, so the
	 * first publication is "1" and every change after it is the next whole
	 * number. It still steps until the result is unused: two rows sharing a
	 * version number would defeat the point of recording one.
	 */
	private function next_version_number(): string {
		$next = $this->versions->next_sequence();

		while ( $this->versions->number_exists( (string) $next ) ) {
			++$next;
		}

		return (string) $next;
	}

	/**
	 * @param mixed $date
	 */
	private function normalise_date( $date ): ?string {
		if ( ! is_string( $date ) || '' === trim( $date ) ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', trim( $date ) );

		return ( false !== $parsed && $parsed->format( 'Y-m-d' ) === trim( $date ) ) ? trim( $date ) : null;
	}

	/**
	 * Ensures the archive has an entry for the current content. Used on the
	 * very first publish, when a site has terms configured but the archive is
	 * still empty.
	 */
	public function ensure_seeded(): ?int {
		if ( null !== $this->versions->active() ) {
			return null;
		}

		$content = $this->content_source->get_content();

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return null;
		}

		return $this->publish( $content, array( 'source' => 'settings' ) );
	}
}
