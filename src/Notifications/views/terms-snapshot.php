<?php
/**
 * Dompdf HTML source for the terms-content snapshot generated whenever a
 * version is published. Keep this simple — dompdf's CSS support is a subset
 * of a real browser's.
 *
 * @var string      $site_name
 * @var string      $terms_content
 * @var string      $version_hash
 * @var string      $version_number
 * @var string|null $effective_date
 * @var string      $created_at_utc
 *
 * @package MammothWP\AcceptanceLogs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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
	.meta td { padding: 2px 8px 2px 0; }
	.terms { border: 1px solid #dcdcde; padding: 12px; }
</style>
</head>
<body>
	<h1><?php echo esc_html( sprintf( /* translators: %s: site name */ __( '%s — Terms & Conditions', 'mammoth-acceptance-logs' ), $site_name ) ); ?></h1>

	<table class="meta">
		<?php if ( '' !== $version_number ) : ?>
			<tr><td><strong><?php esc_html_e( 'Version number', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $version_number ); ?></td></tr>
		<?php endif; ?>

		<?php if ( null !== $effective_date ) : ?>
			<tr><td><strong><?php esc_html_e( 'Effective date', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $effective_date ); ?></td></tr>
		<?php endif; ?>

		<tr><td><strong><?php esc_html_e( 'Published at (UTC)', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $created_at_utc ); ?></td></tr>
		<tr><td><strong><?php esc_html_e( 'Version identifier', 'mammoth-acceptance-logs' ); ?></strong></td><td><?php echo esc_html( $version_hash ); ?></td></tr>
	</table>

	<div class="terms">
		<?php echo wp_kses_post( $terms_content ); ?>
	</div>
</body>
</html>
