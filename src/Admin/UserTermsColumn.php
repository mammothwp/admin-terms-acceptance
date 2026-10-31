<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Support\ManagerAccess;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Surfaces each user's Terms & Conditions acceptance status — accepted,
 * pending, or not required — as a read-only column on the Users list table
 * and a field on the user profile/edit screen.
 *
 * Registered for managers only. Whether a named colleague has accepted the
 * terms is information about them, drawn from the same evidence log as
 * everything else, so it follows the same access rule rather than leaking to
 * every administrator through a list column.
 */
final class UserTermsColumn {

	private const COLUMN_ID = 'mmwp_terms_status';

	private AcceptanceService $acceptance;
	private ManagerAccess $managers;

	public function __construct( AcceptanceService $acceptance, ManagerAccess $managers ) {
		$this->acceptance = $acceptance;
		$this->managers   = $managers;
	}

	/**
	 * Hooks are added unconditionally and each callback checks access,
	 * rather than checking once here. register() runs on plugins_loaded,
	 * which is early enough that asking who the current user is forces
	 * WordPress to resolve them ahead of anything hooked onto
	 * determine_current_user — REST authentication, SSO plugins — and the
	 * answer we would cache is not necessarily the right one. The callbacks
	 * below all run late in an admin request, where the question is safe.
	 *
	 * The kill-switch is still absolute: this class is only ever constructed
	 * when the management UI is enabled (see Plugin::services()).
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );

		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
	}

	/**
	 * @param string[] $columns
	 * @return string[]
	 */
	public function add_column( array $columns ): array {
		if ( ! $this->managers->can_manage() ) {
			return $columns;
		}

		$columns[ self::COLUMN_ID ] = __( 'Terms & Conditions', 'mammoth-acceptance-logs' );

		return $columns;
	}

	public function render_column( string $value, string $column_name, int $user_id ): string {
		if ( self::COLUMN_ID !== $column_name || ! $this->managers->can_manage() ) {
			return $value;
		}

		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? $this->status_badge( $user ) : $value;
	}

	public function render_profile_field( WP_User $user ): void {
		if ( ! $this->managers->can_manage() ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Terms & Conditions', 'mammoth-acceptance-logs' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Acceptance status', 'mammoth-acceptance-logs' ); ?></th>
				<td>
					<?php echo wp_kses_post( $this->status_badge( $user ) ); ?>
					<p class="description">
						<a href="<?php echo esc_url( $this->log_url( $user->ID ) ); ?>">
							<?php esc_html_e( 'View this user\'s acceptance records', 'mammoth-acceptance-logs' ); ?>
						</a>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function status_badge( WP_User $user ): string {
		$status = $this->acceptance->acceptance_status_for( $user );

		switch ( $status ) {
			case AcceptanceService::STATUS_ACCEPTED:
				return '<span class="mmwp-status mmwp-status--ok">&#10003; ' . esc_html( $this->accepted_label( $user ) ) . '</span>';

			case AcceptanceService::STATUS_PENDING:
				return '<span class="mmwp-status mmwp-status--error">&#9679; ' . esc_html__( 'Pending', 'mammoth-acceptance-logs' ) . '</span>';

			default:
				return '<span class="mmwp-status mmwp-status--muted">&#8212; ' . esc_html__( 'Not required', 'mammoth-acceptance-logs' ) . '</span>';
		}
	}

	private function accepted_label( WP_User $user ): string {
		$record = $this->acceptance->repository()->latest_for( $user->ID );

		if ( null === $record ) {
			return __( 'Accepted', 'mammoth-acceptance-logs' );
		}

		// Rendered from the authoritative UTC value in the site's own format,
		// rather than echoing a raw MySQL datetime as this used to.
		$when = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) strtotime( (string) $record['accepted_at_utc'] . ' UTC' )
		);

		$version = null !== $record['version_id']
			? $this->acceptance->versions()->find( (int) $record['version_id'] )
			: null;

		if ( null === $version ) {
			/* translators: %s: date/time the user accepted */
			return sprintf( __( 'Accepted on %s', 'mammoth-acceptance-logs' ), (string) $when );
		}

		return sprintf(
			/* translators: 1: version label, 2: date/time the user accepted */
			__( 'Accepted %1$s on %2$s', 'mammoth-acceptance-logs' ),
			$version->label(),
			(string) $when
		);
	}

	private function log_url( int $user_id ): string {
		return add_query_arg(
			array(
				'page'        => AcceptanceLogPage::PAGE_SLUG,
				'filter_user' => $user_id,
			),
			admin_url( 'users.php' )
		);
	}
}
