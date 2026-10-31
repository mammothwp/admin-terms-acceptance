<?php
/**
 * Acceptance log view. Read-only by design — see Admin\AcceptanceLogTable.
 *
 * @var \MammothWP\AcceptanceLogs\Admin\AcceptanceLogTable   $table
 * @var array<string,mixed>|null                        $verification
 * @var \MammothWP\AcceptanceLogs\Versioning\TermsVersion[]  $versions
 * @var array<string,mixed>                             $filters
 * @var string                                          $export_url
 * @var string                                          $page_slug
 *
 * @package MammothWP\AcceptanceLogs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmwp_verify_url = add_query_arg(
	array(
		'page'              => $page_slug,
		'mmwp_terms_verify' => 1,
	),
	admin_url( 'users.php' )
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Terms acceptance log', 'mammoth-acceptance-logs' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Every acceptance ever recorded. This log is append-only: records cannot be edited or deleted from anywhere in the admin, which is what allows it to be relied on as evidence.', 'mammoth-acceptance-logs' ); ?>
	</p>

	<p>
		<a href="<?php echo esc_url( $mmwp_verify_url ); ?>" class="button"><?php esc_html_e( 'Verify integrity', 'mammoth-acceptance-logs' ); ?></a>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'mammoth-acceptance-logs' ); ?></a>
	</p>

	<?php if ( null !== $verification ) : ?>
		<?php
		$mmwp_clean = 0 === $verification['invalid_core']
			&& 0 === $verification['invalid_context']
			&& 0 === $verification['chain_breaks'];
		?>
		<div class="notice notice-<?php echo $mmwp_clean ? 'success' : 'error'; ?>">
			<p>
				<?php
				printf(
					/* translators: 1: number checked, 2: number valid */
					esc_html__( 'Checked %1$d records; %2$d verified.', 'mammoth-acceptance-logs' ),
					(int) $verification['checked'],
					(int) $verification['valid']
				);
				?>
			</p>

			<?php if ( ! $mmwp_clean ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: altered records, 2: altered contexts, 3: chain breaks */
						esc_html__( '%1$d records with an altered signature, %2$d with altered context, %3$d chain breaks. A failure here means the stored data no longer matches what was signed when it was written — investigate before relying on the affected records.', 'mammoth-acceptance-logs' ),
						(int) $verification['invalid_core'],
						(int) $verification['invalid_context'],
						(int) $verification['chain_breaks']
					);
					?>
				</p>
				<ul class="mmwp-list">
					<?php foreach ( array_slice( $verification['failures'], 0, 20 ) as $mmwp_failure ) : ?>
						<li>
							<?php echo esc_html( sprintf( '#%d — %s', (int) $mmwp_failure['id'], (string) $mmwp_failure['problem'] ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="get" action="">
		<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />

		<div class="tablenav top">
			<div class="alignleft actions">
				<label class="screen-reader-text" for="filter_user"><?php esc_html_e( 'Filter by user ID', 'mammoth-acceptance-logs' ); ?></label>
				<input type="number" min="1" id="filter_user" name="filter_user" placeholder="<?php esc_attr_e( 'User ID', 'mammoth-acceptance-logs' ); ?>" value="<?php echo esc_attr( (string) ( $filters['user_id'] ?? '' ) ); ?>" />

				<label class="screen-reader-text" for="filter_version"><?php esc_html_e( 'Filter by version', 'mammoth-acceptance-logs' ); ?></label>
				<select id="filter_version" name="filter_version">
					<option value=""><?php esc_html_e( 'All versions', 'mammoth-acceptance-logs' ); ?></option>
					<?php foreach ( $versions as $mmwp_version ) : ?>
						<option value="<?php echo esc_attr( (string) $mmwp_version->id ); ?>" <?php selected( (int) ( $filters['version_id'] ?? 0 ), $mmwp_version->id ); ?>>
							<?php echo esc_html( $mmwp_version->label() ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="screen-reader-text" for="filter_from"><?php esc_html_e( 'From date', 'mammoth-acceptance-logs' ); ?></label>
				<input type="date" id="filter_from" name="filter_from" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>" />

				<label class="screen-reader-text" for="filter_to"><?php esc_html_e( 'To date', 'mammoth-acceptance-logs' ); ?></label>
				<input type="date" id="filter_to" name="filter_to" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>" />

				<?php submit_button( __( 'Filter', 'mammoth-acceptance-logs' ), 'secondary', 'submit', false ); ?>
			</div>
		</div>

		<?php $table->display(); ?>
	</form>
</div>
