<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Uninstall;

use MammothWP\AcceptanceLogs\Support\OptionKeys;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What deleting the plugin does with the acceptance evidence.
 *
 * The default is to keep it: the log and the version archive are evidence of
 * what was agreed, and a claim can outlive the plugin by years. Choosing to
 * delete it is deliberate and needs the plugin name typed back, here and on
 * the server, so neither a stray click nor a crafted request can arm it.
 * Settings and other non-evidence options are removed either way.
 */
final class UninstallPolicy {

	public const KEEP   = 'keep';
	public const DELETE = 'delete';

	/** The phrase a user must type to arm deletion. Not translated, so it is unambiguous. */
	public const CONFIRM_PHRASE = 'MammothWP Acceptance Logs';

	/** The plugin's name before the MammothWP naming standard; still accepted so a user mid-flow is not rejected. */
	public const PREVIOUS_CONFIRM_PHRASE = 'Mammoth Acceptance Logs';

	/**
	 * The stored choice, defaulting to keep.
	 *
	 * @return string One of self::KEEP or self::DELETE.
	 */
	public static function get(): string {
		return self::DELETE === get_option( OptionKeys::UNINSTALL_DATA, self::KEEP ) ? self::DELETE : self::KEEP;
	}

	/**
	 * Validates and stores a choice.
	 *
	 * @param string $choice Requested choice.
	 * @param string $typed  What the user typed to confirm (required for delete).
	 * @return string|WP_Error The stored choice, or an error.
	 */
	public static function set( string $choice, string $typed = '' ) {
		if ( ! in_array( $choice, array( self::KEEP, self::DELETE ), true ) ) {
			return new WP_Error( 'mmwp_terms_bad_choice', __( 'Unknown uninstall choice.', 'mammoth-acceptance-logs' ), array( 'status' => 400 ) );
		}

		if ( self::DELETE === $choice && ! self::is_confirmation( $typed ) ) {
			return new WP_Error( 'mmwp_terms_confirm_mismatch', __( 'Type the plugin name exactly to confirm deleting the evidence.', 'mammoth-acceptance-logs' ), array( 'status' => 400 ) );
		}

		update_option( OptionKeys::UNINSTALL_DATA, $choice, false );

		return $choice;
	}

	/**
	 * Whether the typed text confirms deletion.
	 *
	 * @param string $typed What the user typed.
	 * @return bool True for the current or the previous plugin name.
	 */
	public static function is_confirmation( string $typed ): bool {
		return in_array( trim( $typed ), array( self::CONFIRM_PHRASE, self::PREVIOUS_CONFIRM_PHRASE ), true );
	}
}
