<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the client IP for an acceptance record.
 *
 * The address is stored in full and never truncated. Truncation is the usual
 * analytics-grade minimisation move, but here the whole evidentiary point is
 * being able to say which host accepted the terms in a dispute; a zeroed last
 * octet proves materially less. Minimisation is handled instead by retention
 * — Retention\RetentionPolicy anonymises the field once the documented
 * dispute period has elapsed.
 *
 * A forwarded header is honoured ONLY when the connecting address is a
 * configured trusted proxy. Trusting X-Forwarded-For unconditionally would
 * let any client write its own IP into the audit log, which is worse than
 * logging the proxy address honestly. Sites behind Cloudflare/a load
 * balancer must therefore opt in:
 *
 *     add_filter( 'mmwp_terms_trusted_proxies', fn() => array( '10.0.0.0/8' ) );
 */
final class ClientIp {

	/**
	 * @return array{ip: string, source: string} source is remote_addr|forwarded|unknown
	 */
	public function resolve(): array {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $remote || ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return array(
				'ip'     => '',
				'source' => 'unknown',
			);
		}

		$forwarded = $this->forwarded_for( $remote );

		if ( null !== $forwarded ) {
			return array(
				'ip'     => $forwarded,
				'source' => 'forwarded',
			);
		}

		return array(
			'ip'     => substr( $remote, 0, 45 ),
			'source' => 'remote_addr',
		);
	}

	/**
	 * The right-most address in the forwarded chain that is not itself a
	 * trusted proxy — i.e. the last hop we cannot vouch for, which is the
	 * closest thing to the real client that is not attacker-controlled.
	 */
	private function forwarded_for( string $remote ): ?string {
		$trusted = $this->trusted_proxies();

		if ( array() === $trusted || ! $this->matches_any( $remote, $trusted ) ) {
			return null;
		}

		/**
		 * Filters which request header carries the forwarded client IP.
		 *
		 * @param string $header A $_SERVER key, e.g. HTTP_CF_CONNECTING_IP.
		 */
		$header = (string) apply_filters( 'mmwp_terms_ip_header', 'HTTP_X_FORWARDED_FOR' );

		if ( ! isset( $_SERVER[ $header ] ) ) {
			return null;
		}

		$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
		$chain = array_map( 'trim', explode( ',', $raw ) );

		foreach ( array_reverse( $chain ) as $candidate ) {
			if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( $this->matches_any( $candidate, $trusted ) ) {
				continue;
			}

			return substr( $candidate, 0, 45 );
		}

		return null;
	}

	/**
	 * @return string[] IPs or CIDR ranges.
	 */
	private function trusted_proxies(): array {
		/**
		 * Filters the proxies whose forwarded-IP header may be trusted.
		 *
		 * Empty by default: without this configured the plugin records the
		 * connecting address, which behind a proxy is the proxy itself.
		 * That is deliberate — an honest wrong-ish value beats a
		 * client-spoofable one in an audit log.
		 *
		 * @param string[] $proxies IP addresses or CIDR ranges.
		 */
		$proxies = apply_filters( 'mmwp_terms_trusted_proxies', array() );

		return is_array( $proxies ) ? array_map( 'strval', $proxies ) : array();
	}

	/**
	 * @param string[] $ranges
	 */
	private function matches_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( $this->in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	private function in_range( string $ip, string $range ): bool {
		if ( ! str_contains( $range, '/' ) ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		$bits       = (int) $bits;

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false; // Mixed IPv4/IPv6, or unparseable.
		}

		$whole_bytes   = intdiv( $bits, 8 );
		$leftover_bits = $bits % 8;

		if ( $whole_bytes > 0 && strncmp( $ip_bin, $subnet_bin, $whole_bytes ) !== 0 ) {
			return false;
		}

		if ( 0 === $leftover_bits ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $leftover_bits ) ) - 1 ) & 0xFF;

		return ( ord( $ip_bin[ $whole_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $whole_bytes ] ) & $mask );
	}
}
