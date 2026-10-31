<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Audit;

use MammothWP\AcceptanceLogs\Support\OptionKeys;
use MammothWP\AcceptanceLogs\Upgrade\LegacyNames;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The HMAC secret used to sign audit records.
 *
 * Preferred: define MMWP_TERMS_INTEGRITY_KEY in wp-config.php. That puts the
 * key outside the database, so an attacker who gets SQL access alone can
 * alter a row but cannot re-sign it — which is the whole point of signing.
 *
 * Fallback: a generated key stored in an autoload-false option. Convenient,
 * and still detects accidental corruption or a careless direct-SQL edit, but
 * an attacker holding the database holds the key too. That limitation is
 * documented in the README and docs/evidence-handling.md rather than glossed
 * over: this is tamper-evidence, not tamper-proofing, and claiming otherwise
 * would be worse than claiming nothing.
 */
final class IntegrityKey {

	/**
	 * The signing key, from wp-config.php when defined, else the stored option.
	 *
	 * @return string
	 */
	public static function get(): string {
		$external = self::external_key();

		if ( '' !== $external ) {
			return $external;
		}

		$stored = get_option( OptionKeys::INTEGRITY_KEY, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$generated = bin2hex( random_bytes( 32 ) );
		update_option( OptionKeys::INTEGRITY_KEY, $generated, false );

		return $generated;
	}

	/**
	 * Whether the key lives outside the database. Surfaced in the admin so
	 * an operator can see at a glance which of the two guarantees they have.
	 *
	 * @return bool
	 */
	public static function is_external(): bool {
		return '' !== self::external_key();
	}

	/**
	 * The wp-config.php key, under the current constant name or — so rows signed
	 * by an earlier build keep verifying — the name that build used.
	 *
	 * @return string Empty when no constant is defined.
	 */
	private static function external_key(): string {
		foreach ( array( 'MMWP_TERMS_INTEGRITY_KEY', LegacyNames::CONSTANT_INTEGRITY_KEY ) as $name ) {
			$value = defined( $name ) ? constant( $name ) : '';

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function __construct() {}
}
