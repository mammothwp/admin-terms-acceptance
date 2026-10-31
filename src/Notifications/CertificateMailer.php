<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Notifications;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Audit\EventLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for `mmwp_terms_accepted` and emails the user a receipt with the
 * accepted terms attached as a PDF. Deliberately hooked onto the action
 * rather than called from AcceptanceService directly, so notifications stay
 * swappable/extensible without editing the acceptance logic.
 *
 * The receipt is supplementary evidence, never the record itself — email is
 * lost, filtered and deleted, which is exactly why the server-side archive
 * exists. What this class adds to the audit trail is the dispatch outcome:
 * whether the PDF was produced and whether the mail was handed off, both
 * written to Audit\EventLog. Previously wp_mail()'s return value was
 * discarded, so a silently failing mail server left no way to tell which
 * acceptances had actually been receipted.
 */
final class CertificateMailer {

	private AcceptanceService $acceptance;
	private PdfGenerator $pdf;
	private EventLog $events;

	public function __construct( AcceptanceService $acceptance, PdfGenerator $pdf, ?EventLog $events = null ) {
		$this->acceptance = $acceptance;
		$this->pdf        = $pdf;
		$this->events     = $events ?? new EventLog();
	}

	public function register(): void {
		add_action( 'mmwp_terms_accepted', array( $this, 'send' ), 10, 4 );
		add_action( 'mmwp_terms_delivery_status_recorded', array( $this, 'record_delivery_status' ), 10, 3 );
	}

	/**
	 * Records provider-side delivery status against an acceptance.
	 *
	 * wp_mail() only tells us the message was handed off, which is not the
	 * same as delivered. The plugin cannot poll an ESP, so this is the
	 * documented way in: point a SendGrid/Mailgun/Postmark webhook at it and
	 * the outcome joins the same audit trail as everything else.
	 *
	 *     do_action( 'mmwp_terms_delivery_status_recorded', $acceptance_id, $message_id, 'delivered' );
	 */
	public function record_delivery_status( int $acceptance_id, string $provider_message_id, string $status ): void {
		$record = $this->acceptance->repository()->find( $acceptance_id );

		if ( null === $record ) {
			return;
		}

		$delivered = in_array( strtolower( $status ), array( 'delivered', 'opened', 'sent' ), true );

		$this->events->record(
			$delivered ? 'email_sent' : 'email_failed',
			$delivered ? 'success' : 'failure',
			array(
				'source'              => 'provider_webhook',
				'provider_status'     => $status,
				'provider_message_id' => $provider_message_id,
			),
			$acceptance_id,
			null !== $record['version_id'] ? (int) $record['version_id'] : null,
			(int) $record['user_id']
		);
	}

	public function send( int $user_id, string $version_hash, ?int $acceptance_id = null, ?int $version_id = null ): void {
		/**
		 * Allows disabling the emailed PDF receipt entirely.
		 *
		 * @param bool $enabled
		 * @param int  $user_id
		 */
		if ( ! apply_filters( 'mmwp_terms_send_pdf_email', true, $user_id ) ) {
			$this->events->record( 'email_sent', 'skipped', array( 'reason' => 'disabled by mmwp_terms_send_pdf_email filter' ), $acceptance_id, $version_id, $user_id );

			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$record  = null !== $acceptance_id ? $this->acceptance->repository()->find( $acceptance_id ) : null;
		$version = null !== $version_id ? $this->acceptance->versions()->find( $version_id ) : null;

		// The text for THIS version, read from the archive. Using the live
		// content instead — as this class used to — attaches whatever the
		// terms happen to say now under the hash of what they said then, which
		// is precisely the kind of mismatch a receipt is supposed to rule out.
		$terms_content = null !== $version
			? $version->content
			: $this->acceptance->content_for_display();

		$site_name = get_bloginfo( 'name' );
		$local     = null !== $record ? (string) $record['accepted_at_local'] : $this->acceptance->repository()->accepted_at_for( $user_id );
		$utc       = null !== $record ? (string) $record['accepted_at_utc'] : '';

		$pdf = $this->pdf->generate(
			$site_name,
			$user->display_name,
			$user->user_email,
			$terms_content,
			$version_hash,
			array(
				'acceptance_id'   => $acceptance_id,
				'accepted_local'  => $local,
				'accepted_utc'    => $utc,
				'timezone'        => null !== $record ? (string) $record['timezone'] : '',
				'utc_offset'      => null !== $record ? (string) $record['utc_offset'] : '',
				'ip_address'      => null !== $record ? (string) $record['ip_address'] : '',
				'version_number'  => null !== $version ? $version->number : '',
				'effective_date'  => null !== $version ? $version->effective_date : null,
				'site_identifier' => null !== $record ? (string) $record['site_identifier'] : '',
			)
		);

		$attachments = array();

		if ( null !== $pdf ) {
			$tmp_file = wp_tempnam( 'mammoth-acceptance-logs.pdf' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the receipt attachment to a temporary file for wp_mail(); removed again below.
			file_put_contents( $tmp_file, $pdf );
			$attachments[] = $tmp_file;

			$this->events->record( 'pdf_generated', 'success', array( 'bytes' => strlen( $pdf ) ), $acceptance_id, $version_id, $user_id );
		} else {
			$this->events->record( 'pdf_failed', 'failure', array( 'reason' => 'dompdf is not installed' ), $acceptance_id, $version_id, $user_id );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '%s — your Terms & Conditions acceptance', 'mammoth-acceptance-logs' ),
			$site_name
		);

		$body = sprintf(
			/* translators: 1: user display name, 2: site name, 3: date/time */
			__( "Hi %1\$s,\n\nThis confirms you accepted the Terms & Conditions for %2\$s on %3\$s.\n\nIf a PDF is attached, it contains the exact version you accepted for your records.", 'mammoth-acceptance-logs' ),
			$user->display_name,
			$site_name,
			$local
		);

		MailFailureListener::reset();

		$sent = wp_mail( $user->user_email, $subject, $body, array(), $attachments );

		$this->events->record(
			$sent ? 'email_sent' : 'email_failed',
			$sent ? 'success' : 'failure',
			array(
				'recipient'  => $user->user_email,
				'attachment' => null !== $pdf,
				'error'      => $sent ? '' : MailFailureListener::last_message(),
			),
			$acceptance_id,
			$version_id,
			$user_id
		);

		foreach ( $attachments as $attachment ) {
			if ( file_exists( $attachment ) ) {
				wp_delete_file( $attachment );
			}
		}

		$this->maybe_notify_admin( $user->display_name, $user->user_email, $local, $subject, $acceptance_id, $version_id, $user_id );
	}

	private function maybe_notify_admin(
		string $display_name,
		string $email,
		string $accepted_at,
		string $subject,
		?int $acceptance_id,
		?int $version_id,
		int $user_id
	): void {
		$notify_admin_email = apply_filters( 'mmwp_terms_notify_admin_email', '' );

		if ( ! is_string( $notify_admin_email ) || ! is_email( $notify_admin_email ) ) {
			return;
		}

		MailFailureListener::reset();

		$sent = wp_mail(
			$notify_admin_email,
			$subject,
			sprintf(
				/* translators: 1: user display name, 2: user email, 3: date/time */
				__( '%1$s (%2$s) accepted the current Terms & Conditions on %3$s.', 'mammoth-acceptance-logs' ),
				$display_name,
				$email,
				$accepted_at
			)
		);

		$this->events->record(
			'admin_email_sent',
			$sent ? 'success' : 'failure',
			array(
				'recipient' => $notify_admin_email,
				'error'     => $sent ? '' : MailFailureListener::last_message(),
			),
			$acceptance_id,
			$version_id,
			$user_id
		);
	}
}
