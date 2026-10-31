<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Notifications;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures the real reason a wp_mail() call failed.
 *
 * wp_mail() returns a bare false, which tells you a receipt did not go out
 * but not whether the SMTP host refused, the address bounced, or a plugin
 * short-circuited the send. The actual error only ever appears in the
 * `wp_mail_failed` action, so we stash it there and let CertificateMailer
 * read it back for the audit event. Without this the log would record
 * "email_failed" with nothing actionable attached.
 */
final class MailFailureListener {

	private static ?WP_Error $last_error = null;

	public function register(): void {
		add_action( 'wp_mail_failed', array( self::class, 'capture' ) );
	}

	public static function capture( WP_Error $error ): void {
		self::$last_error = $error;
	}

	/**
	 * Clears the slot before a send so a stale error from an unrelated
	 * earlier mail on the same request cannot be misattributed.
	 */
	public static function reset(): void {
		self::$last_error = null;
	}

	public static function last_message(): string {
		return self::$last_error instanceof WP_Error
			? self::$last_error->get_error_message()
			: '';
	}
}
