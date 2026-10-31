<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The circumstances of the request being recorded: who connected from
 * where, in which browser, against which site, and in what timezone.
 *
 * Resolved once per acceptance and frozen into the record. Everything here
 * is either directly evidentiary (IP, site, timestamps) or corroborating
 * (User-Agent). Nothing here fingerprints the device — see docs/dpia-screening.md
 * for why that was rejected rather than merely not built.
 */
final class RequestContext {

	private ClientIp $client_ip;

	/** @var array{ip: string, source: string}|null */
	private ?array $resolved_ip = null;

	public function __construct( ?ClientIp $client_ip = null ) {
		$this->client_ip = $client_ip ?? new ClientIp();
	}

	public function ip(): string {
		return $this->ip_parts()['ip'];
	}

	public function ip_source(): string {
		return $this->ip_parts()['source'];
	}

	/**
	 * Corroborating only. A User-Agent is trivially spoofable and must never
	 * be leaned on as primary proof; it earns its place by helping
	 * reconstruct the circumstances of an access during an investigation.
	 */
	public function user_agent(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		return substr( $agent, 0, 512 );
	}

	/**
	 * Which application/site the acceptance happened on. Matters when one
	 * operator provisions many installs and the evidence has to name the
	 * one the user was actually looking at.
	 */
	public function site_identifier(): string {
		$host    = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
		$default = $host . '|blog_' . get_current_blog_id();

		/**
		 * Filters the source application identifier stored on each record.
		 *
		 * @param string $default
		 */
		return substr( (string) apply_filters( 'mmwp_terms_site_identifier', $default ), 0, 191 );
	}

	public function timezone(): string {
		return wp_timezone_string();
	}

	/**
	 * The site's UTC offset at this moment, e.g. "+03:00". Stored alongside
	 * the local time so a record stays interpretable even if the site's
	 * timezone setting is changed later — or if the reader is looking at it
	 * across a DST boundary years afterwards.
	 */
	public function utc_offset(): string {
		return wp_timezone()->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) >= 0
			? '+' . $this->offset_hhmm()
			: '-' . $this->offset_hhmm();
	}

	public function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public function now_local(): string {
		return current_time( 'mysql' );
	}

	private function offset_hhmm(): string {
		$seconds = abs( wp_timezone()->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ) );

		return sprintf( '%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
	}

	/**
	 * @return array{ip: string, source: string}
	 */
	private function ip_parts(): array {
		if ( null === $this->resolved_ip ) {
			$this->resolved_ip = $this->client_ip->resolve();
		}

		return $this->resolved_ip;
	}
}
