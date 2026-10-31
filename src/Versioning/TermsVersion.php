<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Versioning;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One published version of the terms, as read from the archive. Immutable by
 * construction — nothing hands back a setter, because a version that can be
 * edited after publication is not evidence of anything.
 */
final class TermsVersion {

	public function __construct(
		public readonly int $id,
		public readonly string $hash,
		public readonly string $number,
		public readonly ?string $effective_date,
		public readonly string $change_summary,
		public readonly string $content,
		public readonly string $created_at_utc,
		public readonly ?string $active_from_utc,
		public readonly ?string $active_to_utc,
		public readonly string $source
	) {}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['version_hash'],
			(string) $row['version_number'],
			isset( $row['effective_date'] ) ? (string) $row['effective_date'] : null,
			(string) ( $row['change_summary'] ?? '' ),
			(string) $row['content'],
			(string) $row['created_at_utc'],
			isset( $row['active_from_utc'] ) ? (string) $row['active_from_utc'] : null,
			isset( $row['active_to_utc'] ) ? (string) $row['active_to_utc'] : null,
			(string) ( $row['source'] ?? 'settings' )
		);
	}

	public function is_active(): bool {
		return null !== $this->active_from_utc && null === $this->active_to_utc;
	}

	/**
	 * A version number if the admin gave one, otherwise a short hash so the
	 * UI always has something to name it by.
	 */
	public function label(): string {
		return '' !== $this->number ? $this->number : substr( $this->hash, 0, 12 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'version_hash'    => $this->hash,
			'version_number'  => $this->number,
			'effective_date'  => $this->effective_date,
			'change_summary'  => $this->change_summary,
			'created_at_utc'  => $this->created_at_utc,
			'active_from_utc' => $this->active_from_utc,
			'active_to_utc'   => $this->active_to_utc,
			'source'          => $this->source,
		);
	}
}
