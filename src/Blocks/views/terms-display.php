<?php
/**
 * Public terms display block.
 *
 * The card and the history list are rendered here rather than fetched, so the
 * page states which version is in force even with JavaScript off. Only the
 * body text is loaded on demand, from the public REST routes.
 *
 * @var \MammothWP\AcceptanceLogs\Versioning\TermsVersion   $version      Active version.
 * @var \MammothWP\AcceptanceLogs\Versioning\TermsVersion[] $history      Superseded versions, newest first.
 * @var string                                         $heading      Optional block heading.
 * @var string                                         $button_label Label for the open-in-modal buttons.
 *
 * @package MammothWP\AcceptanceLogs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders one version's meta line and its "read full text" button.
 *
 * Declared once and reused for the active card and every history row so the
 * two cannot drift apart.
 */
$mmwp_terms_render_entry = static function ( $entry, string $button_label ): void {
	$date_format = (string) get_option( 'date_format' );
	?>
	<p class="mmwp-terms-block__meta">
		<span class="mmwp-terms-block__version">
			<?php
			printf(
				/* translators: %s: version number or short hash. */
				esc_html__( 'Version %s', 'mammoth-acceptance-logs' ),
				esc_html( $entry->label() )
			);
			?>
		</span>

		<?php if ( null !== $entry->effective_date ) : ?>
			<span class="mmwp-terms-block__effective">
				<?php
				printf(
					/* translators: %s: date the version took effect. */
					esc_html__( 'Effective %s', 'mammoth-acceptance-logs' ),
					esc_html( wp_date( $date_format, strtotime( $entry->effective_date . ' 00:00:00 UTC' ) ) )
				);
				?>
			</span>
		<?php endif; ?>
	</p>

	<?php if ( '' !== $entry->change_summary ) : ?>
		<div class="mmwp-terms-block__summary">
			<?php echo wp_kses_post( wpautop( $entry->change_summary ) ); ?>
		</div>
	<?php endif; ?>

	<button type="button" class="mmwp-terms-block__open" data-version-id="<?php echo esc_attr( (string) $entry->id ); ?>">
		<?php echo esc_html( $button_label ); ?>
	</button>
	<?php
};
?>
<div
	<?php
	// get_block_wrapper_attributes() escapes each attribute itself; running it
	// through an escaper again would mangle the quoting.
	echo get_block_wrapper_attributes( array( 'class' => 'mmwp-terms-block' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
	data-rest="<?php echo esc_url( rest_url( 'mmwp/v1/public/versions/' ) ); ?>"
>
	<?php if ( '' !== $heading ) : ?>
		<h2 class="mmwp-terms-block__heading"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>

	<div class="mmwp-terms-block__current">
		<?php $mmwp_terms_render_entry( $version, $button_label ); ?>
	</div>

	<?php if ( array() !== $history ) : ?>
		<details class="mmwp-terms-block__history">
			<summary><?php esc_html_e( 'Previous versions', 'mammoth-acceptance-logs' ); ?></summary>

			<ul class="mmwp-terms-block__history-list">
				<?php foreach ( $history as $mmwp_terms_past ) : ?>
					<li class="mmwp-terms-block__history-item">
						<?php $mmwp_terms_render_entry( $mmwp_terms_past, $button_label ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>

	<?php
	// One shell per block, filled in by the view script. The strings live here
	// so the front-end script needs no translation payload of its own.
	?>
	<div
		class="mmwp-terms-block__modal"
		role="dialog"
		aria-modal="true"
		aria-labelledby="mmwp-terms-block-title-<?php echo esc_attr( (string) $version->id ); ?>"
		data-error="<?php esc_attr_e( 'We could not load these terms. Please try again.', 'mammoth-acceptance-logs' ); ?>"
		data-loading="<?php esc_attr_e( 'Loading…', 'mammoth-acceptance-logs' ); ?>"
		hidden
	>
		<div class="mmwp-terms-block__dialog" tabindex="-1">
			<h2 class="mmwp-terms-block__title" id="mmwp-terms-block-title-<?php echo esc_attr( (string) $version->id ); ?>"></h2>
			<div class="mmwp-terms-block__body"></div>
			<button type="button" class="mmwp-terms-block__close">
				<?php esc_html_e( 'Close', 'mammoth-acceptance-logs' ); ?>
			</button>
		</div>
	</div>
</div>
