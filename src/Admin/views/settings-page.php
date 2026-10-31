<?php
/**
 * Settings page view.
 *
 * @var string                                            $content
 * @var string[]                                             $required_roles
 * @var array<string,array<string,mixed>>                                             $all_roles
 * @var string                                            $version_number
 * @var string                                            $next_number
 * @var string                                            $effective_date
 * @var int                                               $effective_days
 * @var int                                               $max_days
 * @var string                                            $change_summary
 * @var \MammothWP\AcceptanceLogs\Versioning\TermsVersion|null  $active_version
 * @var \MammothWP\AcceptanceLogs\Versioning\TermsVersion[]     $version_history
 * @var \MammothWP\AcceptanceLogs\Notifications\TermsPdfArchive $pdf_archive
 * @var int[]                                             $manager_ids
 * @var \WP_User[]                                        $eligible_users
 * @var array<string,mixed>                                             $retention
 * @var string                                            $retention_text
 * @var bool                                              $key_is_external
 * @var string                                            $log_url
 * @var bool                                              $effectively_all
 * @var string                                            $uninstall_data
 * @var string                                            $uninstall_keep
 * @var string                                            $uninstall_drop
 * @var string                                            $uninstall_phrase
 *
 * @package MammothWP\AcceptanceLogs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mmwp_notice = isset( $_GET['mmwp_terms_notice'] ) ? sanitize_key( wp_unslash( $_GET['mmwp_terms_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice key, no state change.

$mmwp_notices = array(
	'published'           => array( 'success', __( 'New version published. Everyone in scope will be asked to accept it, and the previous version has been preserved in the archive.', 'mammoth-acceptance-logs' ) ),
	'saved'               => array( 'success', __( 'Settings saved. The terms text is unchanged, so nobody has been asked to accept again.', 'mammoth-acceptance-logs' ) ),
	'managers'            => array( 'success', __( 'Manager list updated.', 'mammoth-acceptance-logs' ) ),
	'need-summary'        => array( 'error', __( 'Say what changed before saving. The summary is shown to people above the terms, and a version nobody described is much weaker evidence that they knew what they were accepting.', 'mammoth-acceptance-logs' ) ),
	'bad-date'            => array( 'error', __( 'The effective date is too far away. Enter a number of days from today, up to 3650.', 'mammoth-acceptance-logs' ) ),
	'bad-manager'         => array( 'error', __( 'That user could not be added as a manager. Only administrators of this site can be appointed.', 'mammoth-acceptance-logs' ) ),
	'no-manager'          => array( 'error', __( 'Select a user before clicking Add manager.', 'mammoth-acceptance-logs' ) ),
	'uninstall-saved'     => array( 'success', __( 'Uninstall choice saved.', 'mammoth-acceptance-logs' ) ),
	'uninstall-confirm'   => array( 'error', __( 'Type the plugin name exactly to confirm deleting the evidence. The choice was not changed.', 'mammoth-acceptance-logs' ) ),
	'uninstall-forbidden' => array( 'error', __( 'Only users who can delete plugins can change this.', 'mammoth-acceptance-logs' ) ),
	'last-manager'        => array( 'error', __( 'The last manager cannot be removed — that would leave nobody able to reach these screens.', 'mammoth-acceptance-logs' ) ),
);
?>
<div class="wrap mmwp-terms-settings">
	<h1><?php esc_html_e( 'Terms & Conditions', 'mammoth-acceptance-logs' ); ?></h1>

	<?php if ( isset( $mmwp_notices[ $mmwp_notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $mmwp_notices[ $mmwp_notice ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $mmwp_notices[ $mmwp_notice ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'This is what logged-in users are asked to accept. Publishing a new version preserves the previous one permanently and asks everyone in scope to accept again.', 'mammoth-acceptance-logs' ); ?>
		<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'View the acceptance log', 'mammoth-acceptance-logs' ); ?></a>
	</p>

	<?php if ( null !== $active_version ) : ?>
		<h2><?php esc_html_e( 'Current version', 'mammoth-acceptance-logs' ); ?></h2>
		<table class="widefat striped mmwp-table-wide">
			<tbody>
				<tr>
					<th class="mmwp-col-label"><?php esc_html_e( 'Version', 'mammoth-acceptance-logs' ); ?></th>
					<td><?php echo esc_html( $active_version->label() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Effective date', 'mammoth-acceptance-logs' ); ?></th>
					<td><?php echo esc_html( $active_version->effective_date ?? __( 'Not set', 'mammoth-acceptance-logs' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Published (UTC)', 'mammoth-acceptance-logs' ); ?></th>
					<td><?php echo esc_html( $active_version->created_at_utc ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Version identifier', 'mammoth-acceptance-logs' ); ?></th>
					<td><code><?php echo esc_html( $active_version->hash ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'PDF snapshot', 'mammoth-acceptance-logs' ); ?></th>
					<td>
						<?php $mmwp_pdf_url = $pdf_archive->url_for( $active_version ); ?>
						<?php if ( null !== $mmwp_pdf_url ) : ?>
							<a href="<?php echo esc_url( $mmwp_pdf_url ); ?>"><?php esc_html_e( 'Download', 'mammoth-acceptance-logs' ); ?></a>
						<?php else : ?>
							<em><?php esc_html_e( 'Not available — install dompdf (composer install) to generate PDF copies. The full text of every version is stored in the database regardless, so no evidence depends on this.', 'mammoth-acceptance-logs' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'mmwp_terms_save_settings', 'mmwp_terms_settings_nonce' ); ?>

		<h2><?php esc_html_e( 'Version details', 'mammoth-acceptance-logs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Version number', 'mammoth-acceptance-logs' ); ?></th>
				<td>
					<p>
						<strong><?php echo esc_html( $next_number ); ?></strong>
						<?php if ( '' !== $version_number ) : ?>
							<span class="description">
								<?php
								printf(
									/* translators: %s: current version number */
									esc_html__( '(current version: %s)', 'mammoth-acceptance-logs' ),
									esc_html( $version_number )
								);
								?>
							</span>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'Assigned automatically. Every change to the text below is published as the next number in the sequence — 1, then 2, then 3 — and this is what a person sees named in their receipt and in any dispute. Saving without changing the text does not use up a number.', 'mammoth-acceptance-logs' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mmwp_terms_effective_days"><?php esc_html_e( 'Takes effect in (days)', 'mammoth-acceptance-logs' ); ?></label></th>
				<td>
					<input type="number" min="0" max="<?php echo esc_attr( (string) $max_days ); ?>" step="1" id="mmwp_terms_effective_days" name="mmwp_terms_effective_days" value="<?php echo esc_attr( (string) $effective_days ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Counted from today. Defaults to 7 days\' notice; 0 means the version takes effect the day it is published.', 'mammoth-acceptance-logs' ); ?>
						<?php if ( '' !== $effective_date ) : ?>
							<br />
							<?php
							printf(
								/* translators: %s: currently recorded effective date */
								esc_html__( 'Currently recorded: %s', 'mammoth-acceptance-logs' ),
								esc_html( $effective_date )
							);
							?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mmwp_terms_change_summary"><?php esc_html_e( 'Summary of changes', 'mammoth-acceptance-logs' ); ?> <span class="description">*</span></label></th>
				<td>
					<textarea id="mmwp_terms_change_summary" name="mmwp_terms_change_summary" rows="4" class="large-text" required><?php echo esc_textarea( $change_summary ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Required. Shown to returning users above the terms. Saying plainly what changed is much stronger evidence that someone knowingly accepted a new version than silently showing them the text again.', 'mammoth-acceptance-logs' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Content', 'mammoth-acceptance-logs' ); ?></h2>
		<?php
		wp_editor(
			$content,
			'mmwp_terms_content',
			array(
				'textarea_name' => 'mmwp_terms_content',
				'textarea_rows' => 18,
				'media_buttons' => false,
			)
		);
		?>

		<h2><?php esc_html_e( 'Who has to accept', 'mammoth-acceptance-logs' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Leave everything unchecked to require all roles (the default). Guests and logged-out visitors are never included.', 'mammoth-acceptance-logs' ); ?>
		</p>
		<fieldset>
			<?php foreach ( $all_roles as $mmwp_role_slug => $mmwp_role_data ) : ?>
				<label class="mmwp-block-label">
					<input
						type="checkbox"
						name="mmwp_terms_required_roles[]"
						value="<?php echo esc_attr( $mmwp_role_slug ); ?>"
						<?php checked( $effectively_all || in_array( $mmwp_role_slug, $required_roles, true ) ); ?>
					/>
					<?php echo esc_html( translate_user_role( $mmwp_role_data['name'] ) ); ?>
					<code><?php echo esc_html( $mmwp_role_slug ); ?></code>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<p class="description">
			<?php
			printf(
				/* translators: %s: filter names */
				esc_html__( 'Need to exclude specific roles or individual users instead? Use the %s filters — see the plugin README.', 'mammoth-acceptance-logs' ),
				'<code>mmwp_terms_excluded_roles</code> / <code>mmwp_terms_excluded_users</code>'
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Retention', 'mammoth-acceptance-logs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="mmwp_terms_dispute_years"><?php esc_html_e( 'Dispute period (years)', 'mammoth-acceptance-logs' ); ?></label></th>
				<td>
					<input type="number" min="0" max="30" id="mmwp_terms_dispute_years" name="mmwp_terms_dispute_years" value="<?php echo esc_attr( (string) $retention['dispute_period_years'] ); ?>" />
					<p class="description"><?php echo esc_html( $retention_text ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Anonymisation', 'mammoth-acceptance-logs' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="mmwp_terms_anonymise" <?php checked( $retention['anonymise_enabled'] ); ?> />
						<?php esc_html_e( 'Erase IP address and browser identification once the period above has elapsed', 'mammoth-acceptance-logs' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Records themselves are never deleted, and published versions of the terms are never deleted.', 'mammoth-acceptance-logs' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Terms & Conditions', 'mammoth-acceptance-logs' ) ); ?>
	</form>

	<h2><?php esc_html_e( 'Version history', 'mammoth-acceptance-logs' ); ?></h2>
	<?php if ( array() === $version_history ) : ?>
		<p><?php esc_html_e( 'No versions published yet.', 'mammoth-acceptance-logs' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Version', 'mammoth-acceptance-logs' ); ?></th>
					<th><?php esc_html_e( 'Effective', 'mammoth-acceptance-logs' ); ?></th>
					<th><?php esc_html_e( 'Published (UTC)', 'mammoth-acceptance-logs' ); ?></th>
					<th><?php esc_html_e( 'Withdrawn (UTC)', 'mammoth-acceptance-logs' ); ?></th>
					<th><?php esc_html_e( 'Source', 'mammoth-acceptance-logs' ); ?></th>
					<th><?php esc_html_e( 'Identifier', 'mammoth-acceptance-logs' ); ?></th>
					<th><?php esc_html_e( 'Snapshot', 'mammoth-acceptance-logs' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $version_history as $mmwp_version ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $mmwp_version->label() ); ?></strong>
							<?php if ( $mmwp_version->is_active() ) : ?>
								<span class="mmwp-status mmwp-status--ok">&nbsp;&#9679;&nbsp;<?php esc_html_e( 'current', 'mammoth-acceptance-logs' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $mmwp_version->effective_date ?? '—' ); ?></td>
						<td><?php echo esc_html( $mmwp_version->created_at_utc ); ?></td>
						<td><?php echo esc_html( $mmwp_version->active_to_utc ?? '—' ); ?></td>
						<td><?php echo esc_html( $mmwp_version->source ); ?></td>
						<td><code><?php echo esc_html( substr( $mmwp_version->hash, 0, 12 ) ); ?>&hellip;</code></td>
						<td>
							<?php $mmwp_row_pdf = $pdf_archive->url_for( $mmwp_version ); ?>
							<?php if ( null !== $mmwp_row_pdf ) : ?>
								<a href="<?php echo esc_url( $mmwp_row_pdf ); ?>"><?php esc_html_e( 'PDF', 'mammoth-acceptance-logs' ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Republishing text identical to an earlier version creates a new entry here. That is deliberate: a re-publication is a new event, with its own date and its own acceptances.', 'mammoth-acceptance-logs' ); ?>
		</p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Who can manage this', 'mammoth-acceptance-logs' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Only the users listed here can see this screen, the acceptance log, or the Terms column on the Users list. Being an administrator is not enough on its own — the acceptance log is evidence about other people, so access to it is granted explicitly.', 'mammoth-acceptance-logs' ); ?>
	</p>

	<table class="widefat striped mmwp-table-narrow">
		<tbody>
			<?php foreach ( $manager_ids as $mmwp_manager_id ) : ?>
				<?php $mmwp_manager = get_userdata( $mmwp_manager_id ); ?>
				<tr>
					<td>
						<?php if ( $mmwp_manager ) : ?>
							<?php echo esc_html( $mmwp_manager->display_name . ' (' . $mmwp_manager->user_login . ')' ); ?>
						<?php else : ?>
							<em><?php echo esc_html( sprintf( /* translators: %d: user ID */ __( 'Deleted user #%d', 'mammoth-acceptance-logs' ), $mmwp_manager_id ) ); ?></em>
						<?php endif; ?>
					</td>
					<td class="mmwp-align-end">
						<?php if ( count( $manager_ids ) > 1 ) : ?>
							<form method="post" action="" class="mmwp-inline-form">
								<?php wp_nonce_field( 'mmwp_terms_save_settings', 'mmwp_terms_settings_nonce' ); ?>
								<input type="hidden" name="mmwp_terms_manager_form" value="1" />
								<input type="hidden" name="mmwp_terms_remove_manager" value="<?php echo esc_attr( (string) $mmwp_manager_id ); ?>" />
								<?php submit_button( __( 'Remove', 'mammoth-acceptance-logs' ), 'small', 'submit', false ); ?>
							</form>
						<?php else : ?>
							<em><?php esc_html_e( 'Last manager — cannot be removed', 'mammoth-acceptance-logs' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	// Only administrators are offered: a manager who cannot reach wp-admin
	// could never open the screens being granted to them.
	$mmwp_addable = array_filter(
		$eligible_users,
		static function ( $mmwp_candidate ) use ( $manager_ids ) {
			return ! in_array( (int) $mmwp_candidate->ID, $manager_ids, true );
		}
	);
	?>

	<form method="post" action="" class="mmwp-spaced-form">
		<?php wp_nonce_field( 'mmwp_terms_save_settings', 'mmwp_terms_settings_nonce' ); ?>
		<input type="hidden" name="mmwp_terms_manager_form" value="1" />

		<?php if ( array() === $mmwp_addable ) : ?>
			<p class="description">
				<?php esc_html_e( 'Every administrator on this site is already a manager.', 'mammoth-acceptance-logs' ); ?>
			</p>
		<?php else : ?>
			<label for="mmwp_terms_add_manager"><?php esc_html_e( 'Add a manager:', 'mammoth-acceptance-logs' ); ?></label>
			<select id="mmwp_terms_add_manager" name="mmwp_terms_add_manager">
				<option value=""><?php esc_html_e( '— Select a user —', 'mammoth-acceptance-logs' ); ?></option>
				<?php foreach ( $mmwp_addable as $mmwp_candidate ) : ?>
					<option value="<?php echo esc_attr( (string) $mmwp_candidate->ID ); ?>">
						<?php echo esc_html( $mmwp_candidate->display_name . ' (' . $mmwp_candidate->user_login . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Add manager', 'mammoth-acceptance-logs' ), 'secondary', 'submit', false ); ?>
		<?php endif; ?>
	</form>

	<h2><?php esc_html_e( 'Record integrity', 'mammoth-acceptance-logs' ); ?></h2>
	<?php if ( $key_is_external ) : ?>
		<p>
			<span class="mmwp-status mmwp-status--ok">&#10003;</span>
			<?php esc_html_e( 'Records are signed with a key defined in wp-config.php. Someone with database access alone can alter a record but cannot produce a matching signature, so the alteration is detectable.', 'mammoth-acceptance-logs' ); ?>
		</p>
	<?php else : ?>
		<p>
			<span class="mmwp-status mmwp-status--warn">&#9888;</span>
			<?php esc_html_e( 'Records are signed with a key stored in the database. This detects accidental corruption and careless direct edits, but someone who has the database also has the key and could re-sign an altered record.', 'mammoth-acceptance-logs' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'To strengthen this, add a line like the following to wp-config.php and keep it secret. Existing records keep verifying against the old key only until you switch, so set this up before you rely on the log:', 'mammoth-acceptance-logs' ); ?>
			<br />
			<code>define( 'MMWP_TERMS_INTEGRITY_KEY', '&lt;a long random string&gt;' );</code>
		</p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'When this plugin is deleted', 'mammoth-acceptance-logs' ); ?></h2>
	<form method="post" action="">
		<?php wp_nonce_field( 'mmwp_terms_save_settings', 'mmwp_terms_settings_nonce' ); ?>
		<input type="hidden" name="mmwp_terms_uninstall_form" value="1" />
		<fieldset <?php disabled( ! current_user_can( 'delete_plugins' ) ); ?>>
			<legend class="screen-reader-text"><?php esc_html_e( 'What happens to the acceptance evidence when the plugin is deleted', 'mammoth-acceptance-logs' ); ?></legend>
			<label class="mmwp-block-label">
				<input type="radio" name="mmwp_terms_uninstall_data" value="<?php echo esc_attr( $uninstall_keep ); ?>" <?php checked( $uninstall_keep, $uninstall_data ); ?> />
				<?php esc_html_e( 'Keep acceptance evidence (recommended)', 'mammoth-acceptance-logs' ); ?>
			</label>
			<label class="mmwp-block-label">
				<input type="radio" name="mmwp_terms_uninstall_data" value="<?php echo esc_attr( $uninstall_drop ); ?>" <?php checked( $uninstall_drop, $uninstall_data ); ?> />
				<?php esc_html_e( 'Delete all acceptance evidence permanently', 'mammoth-acceptance-logs' ); ?>
			</label>
			<p class="description">
				<?php
				/* translators: %s: plugin name to type */
				printf( esc_html__( 'To choose deletion, type the plugin name (%s) here:', 'mammoth-acceptance-logs' ), '<code>' . esc_html( $uninstall_phrase ) . '</code>' );
				?>
			</p>
			<input type="text" name="mmwp_terms_uninstall_typed" class="regular-text" autocomplete="off" />
		</fieldset>
		<p class="description"><?php esc_html_e( 'Settings are always removed on deletion. You can also purge the evidence now with: wp mmwp terms purge --force', 'mammoth-acceptance-logs' ); ?></p>
		<?php submit_button( __( 'Save uninstall choice', 'mammoth-acceptance-logs' ), 'secondary', 'submit', false ); ?>
	</form>
</div>
