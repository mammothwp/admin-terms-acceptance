<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Acceptance;

use MammothWP\AcceptanceLogs\Support\RequestContext;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything that goes into one acceptance record, resolved once and frozen.
 *
 * Exists so AcceptanceRepository::record() takes a single well-named
 * parameter instead of a dozen positional ones, and so the values that get
 * signed are captured at one moment rather than re-read at various points
 * during the write.
 */
final class AcceptanceContext {

	public function __construct(
		public readonly int $user_id,
		public readonly string $user_login,
		public readonly string $user_email,
		public readonly int $version_id,
		public readonly string $version_hash,
		public readonly string $accepted_at_utc,
		public readonly string $accepted_at_local,
		public readonly string $timezone,
		public readonly string $utc_offset,
		public readonly string $action,
		public readonly string $ip_address,
		public readonly string $ip_source,
		public readonly string $user_agent,
		public readonly string $site_identifier
	) {}

	public static function build(
		WP_User $user,
		int $version_id,
		string $version_hash,
		RequestContext $request,
		string $action = 'accept'
	): self {
		return new self(
			$user->ID,
			substr( (string) $user->user_login, 0, 60 ),
			substr( (string) $user->user_email, 0, 100 ),
			$version_id,
			$version_hash,
			$request->now_utc(),
			$request->now_local(),
			$request->timezone(),
			$request->utc_offset(),
			$action,
			$request->ip(),
			$request->ip_source(),
			$request->user_agent(),
			$request->site_identifier()
		);
	}

	/**
	 * The row as it will be written and signed. Key names match the column
	 * names exactly, because Audit\RecordSigner reads its frozen field list
	 * straight off this shape.
	 *
	 * @return array<string,mixed>
	 */
	public function to_row(): array {
		return array(
			'user_id'             => $this->user_id,
			'version_id'          => $this->version_id,
			'version_hash'        => $this->version_hash,
			'accepted_at_utc'     => $this->accepted_at_utc,
			'accepted_at_local'   => $this->accepted_at_local,
			'timezone'            => $this->timezone,
			'utc_offset'          => $this->utc_offset,
			'action'              => $this->action,
			'ip_address'          => $this->ip_address,
			'ip_source'           => $this->ip_source,
			'user_agent'          => $this->user_agent,
			'site_identifier'     => $this->site_identifier,
			'user_login_snapshot' => $this->user_login,
			'user_email_snapshot' => $this->user_email,
		);
	}
}
