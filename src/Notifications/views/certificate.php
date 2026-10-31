<?php
/**
 * Dompdf HTML source for the acceptance certificate. Keep this simple —
 * dompdf's CSS support is a subset of a real browser's.
 *
 * @var string               $site_name
 * @var string               $user_display_name
 * @var string               $user_email
 * @var string               $terms_content
 * @var string               $version_hash
 * @var array<string,mixed>  $details
 *
 * @package MammothWP\AcceptanceLogs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmwp_accepted_utc   = (string) ( $details['accepted_utc'] ?? '' );
$mmwp_accepted_local = (string) ( $details['accepted_local'] ?? '' );
$mmwp_timezone       = (string) ( $details['timezone'] ?? '' );
$mmwp_offset         = (string) ( $details['utc_offset'] ?? '' );
$mmwp_version_number = (string) ( $details['version_number'] ?? '' );
$mmwp_effective      = $details['effective_date'] ?? null;
$mmwp_acceptance_id  = $details['acceptance_id'] ?? null;
$mmwp_ip             = (string) ( $details['ip_address'] ?? '' );
$mmwp_site_id        = (string) ( $details['site_identifier'] ?? '' );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<?php /* Inline CSS is intentional: this document is only ever handed to dompdf (PdfGenerator), never output as a WordPress page, so wp_enqueue_* never runs for it and dompdf needs the CSS in the HTML. */ ?>
<style>
	body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1d2327; }
	h1 { font-size: 18px; margin-bottom: 4px; }
	.meta { margin-bottom: 20px; color: #50575e; }
	.meta td { padding: 2px 8px 2px 0; vertical-align: top; }
	.terms { border: 1px solid #dcdcde; padding: 12px; }
	.footnote { margin-top: 16px; font-size: 10px; color: #787c82; }
</style>
</head>
<body>
	<h1><?php echo esc_html( sprintf( /* translators: %s: site name */ __( '%s — Terms & Conditions Acceptance', 'mammoth-acceptance-logs' ), $site_name ) ); ?></h1>

	<table class="meta">
		<tr><td><strong><?php esc_html_e( 'Accepted by', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $user_display_name . ' <' . $user_email . '>' ); ?></td></tr>

		<?php if ( '' !== $mmwp_accepted_utc ) : ?>
			<tr><td><strong><?php esc_html_e( 'Accepted at (UTC)', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $mmwp_accepted_utc ); ?></td></tr>
		<?php endif; ?>

		<?php if ( '' !== $mmwp_accepted_local ) : ?>
			<tr>
				<td><strong><?php esc_html_e( 'Accepted at (local)', 'mammoth-acceptance-logs' ); ?></strong></td>
				<td>
					<?php echo esc_html( $mmwp_accepted_local ); ?>
					<?php if ( '' !== $mmwp_timezone ) : ?>
						<?php echo esc_html( ' ' . $mmwp_timezone . ( '' !== $mmwp_offset ? ' (UTC' . $mmwp_offset . ')' : '' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>

		<?php if ( '' !== $mmwp_version_number ) : ?>
			<tr><td><strong><?php esc_html_e( 'Version number', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $mmwp_version_number ); ?></td></tr>
		<?php endif; ?>

		<?php if ( null !== $mmwp_effective ) : ?>
			<tr><td><strong><?php esc_html_e( 'Effective date', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( (string) $mmwp_effective ); ?></td></tr>
		<?php endif; ?>

		<tr><td><strong><?php esc_html_e( 'Version identifier', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $version_hash ); ?></td></tr>

		<?php if ( null !== $mmwp_acceptance_id ) : ?>
			<tr><td><strong><?php esc_html_e( 'Record reference', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( '#' . (string) $mmwp_acceptance_id ); ?></td></tr>
		<?php endif; ?>

		<?php if ( '' !== $mmwp_ip ) : ?>
			<tr><td><strong><?php esc_html_e( 'IP address', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $mmwp_ip ); ?></td></tr>
		<?php endif; ?>

		<?php if ( '' !== $mmwp_site_id ) : ?>
			<tr><td><strong><?php esc_html_e( 'Recorded on', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $mmwp_site_id ); ?></td></tr>
		<?php endif; ?>
	</table>

	<div class="terms">
		<?php echo wp_kses_post( $terms_content ); ?>
	</div>

	<p class="footnote">
		<?php esc_html_e( 'This copy is provided for your records. The authoritative record of this acceptance is held on the site named above.', 'mammoth-acceptance-logs' ); ?>
	</p>
</body>
</html>
