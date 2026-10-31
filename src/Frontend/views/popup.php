<?php
/**
 * Popup overlay markup. Visibility is controlled by JS (mmwpTermsPopup.showPopup),
 * so a user who already clicked "skip for now" this session doesn't see the
 * overlay flash on every page load — the admin notice covers reminding them.
 *
 * @var array<string,string>                          $strings
 * @var string                                        $content
 * @var bool                                          $is_update
 * @var \MammothWP\AcceptanceLogs\Versioning\TermsVersion  $version
 *
 * @package MammothWP\AcceptanceLogs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="mmwp-terms-overlay" class="mmwp-terms-overlay" role="dialog" aria-modal="true" aria-labelledby="mmwp-terms-title">
	<div class="mmwp-terms-modal">
		<h2 id="mmwp-terms-title">
			<?php
			echo esc_html(
				$is_update
					? __( 'Our Terms & Conditions have been updated', 'mammoth-acceptance-logs' )
					: $strings['title']
			);
			?>
		</h2>

		<?php
		// Identify the version being accepted rather than silently swapping
		// the text. A re-prompt that doesn't say what changed is much weaker
		// evidence that the user knew they were agreeing to something new.
		?>
		<div class="mmwp-terms-version">
			<p class="mmwp-terms-version-meta">
				<?php if ( '' !== $version->number ) : ?>
					<strong><?php esc_html_e( 'Version', 'mammoth-acceptance-logs' ); ?></strong>
					<?php echo esc_html( $version->number ); ?>
				<?php endif; ?>

				<?php if ( null !== $version->effective_date ) : ?>
					<span class="mmwp-terms-effective">
						<strong><?php esc_html_e( 'Effective', 'mammoth-acceptance-logs' ); ?></strong>
						<?php echo esc_html( wp_date( (string) get_option( 'date_format' ), strtotime( $version->effective_date . ' 00:00:00 UTC' ) ) ); ?>
					</span>
				<?php endif; ?>
			</p>

			<?php if ( $is_update && '' !== $version->change_summary ) : ?>
				<div class="mmwp-terms-changes">
					<h3><?php esc_html_e( 'What changed in this version', 'mammoth-acceptance-logs' ); ?></h3>
					<?php echo wp_kses_post( wpautop( $version->change_summary ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="mmwp-terms-content"><?php echo wp_kses_post( $content ); ?></div>

		<?php
		// Transparency at the point of collection (Art. 13). Costs one line
		// and is exactly what a user is entitled to know before an act that
		// gets logged against their account.
		?>
		<p class="mmwp-terms-privacy">
			<?php esc_html_e( 'Accepting these terms is required to continue using your account. Your acceptance is recorded with the date and time, your IP address and your browser identification, as proof that these terms were accepted.', 'mammoth-acceptance-logs' ); ?>
		</p>

		<p class="mmwp-terms-error" id="mmwp-terms-error" role="alert"></p>

		<p class="mmwp-terms-actions">
			<button type="button" class="button button-primary" id="mmwp-terms-accept">
				<?php echo esc_html( $strings['accept'] ); ?>
			</button>
			<button type="button" class="button button-secondary" id="mmwp-terms-skip">
				<?php echo esc_html( $strings['skip'] ); ?>
			</button>
		</p>
	</div>
</div>
