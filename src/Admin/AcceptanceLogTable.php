<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Admin;

use MammothWP\AcceptanceLogs\Acceptance\AcceptanceService;
use MammothWP\AcceptanceLogs\Audit\EventLog;
use MammothWP\AcceptanceLogs\Audit\IntegrityVerifier;
use WP_List_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The acceptance log, rendered read-only.
 *
 * DO NOT ADD BULK ACTIONS, ROW ACTIONS, OR EDIT/DELETE LINKS HERE. Their
 * absence is not an oversight to be tidied up later — it is the immutability
 * control expressed at the UI layer. The repository has no update() or
 * delete() to call, and this table gives nobody a reason to add one. A log an
 * administrator can edit is worth nothing in the dispute it exists for; if a
 * record is wrong, the answer is a new record and an audit event, not a
 * correction in place.
 */
final class AcceptanceLogTable extends WP_List_Table {

	private AcceptanceService $acceptance;
	private IntegrityVerifier $verifier;
	private EventLog $events;

	/** @var array<string,mixed> */
	private array $filters;

	/**
	 * @param array<string,mixed> $filters
	 */
	public function __construct( AcceptanceService $acceptance, array $filters = array() ) {
		parent::__construct(
			array(
				'singular' => 'acceptance',
				'plural'   => 'acceptances',
				'ajax'     => false,
			)
		);

		$this->acceptance = $acceptance;
		$this->verifier   = new IntegrityVerifier();
		$this->events     = new EventLog();
		$this->filters    = $filters;
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'id'        => __( 'Record', 'mammoth-acceptance-logs' ),
			'user'      => __( 'User', 'mammoth-acceptance-logs' ),
			'version'   => __( 'Version', 'mammoth-acceptance-logs' ),
			'accepted'  => __( 'Accepted', 'mammoth-acceptance-logs' ),
			'origin'    => __( 'Origin', 'mammoth-acceptance-logs' ),
			'delivery'  => __( 'Receipt', 'mammoth-acceptance-logs' ),
			'integrity' => __( 'Integrity', 'mammoth-acceptance-logs' ),
		);
	}

	public function prepare_items(): void {
		$per_page     = 25;
		$current_page = $this->get_pagenum();
		$repository   = $this->acceptance->repository();

		$args = $this->filters + array(
			'limit'  => $per_page,
			'offset' => ( $current_page - 1 ) * $per_page,
		);

		$this->items = $repository->paginate( $args );

		$total = $repository->count_all( $this->filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_id( array $item ): string {
		return '<strong>#' . esc_html( (string) $item['id'] ) . '</strong>';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_user( array $item ): string {
		$user  = get_userdata( (int) $item['user_id'] );
		$login = (string) $item['user_login_snapshot'];

		// The snapshot is what the record says; the live account may be gone.
		// Showing the snapshot either way keeps a deleted user's rows readable.
		if ( ! $user ) {
			return esc_html( $login ) . '<br /><em>' . esc_html__( 'account deleted', 'mammoth-acceptance-logs' ) . '</em>';
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( get_edit_user_link( $user->ID ) ),
			esc_html( $login )
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_version( array $item ): string {
		$number = (string) ( $item['version_number'] ?? '' );
		$label  = '' !== $number ? $number : substr( (string) $item['version_hash'], 0, 12 );

		$out = '<strong>' . esc_html( $label ) . '</strong>';

		if ( ! empty( $item['effective_date'] ) ) {
			$out .= '<br /><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: date */
					__( 'effective %s', 'mammoth-acceptance-logs' ),
					(string) $item['effective_date']
				)
			) . '</span>';
		}

		$out .= '<br /><code title="' . esc_attr( (string) $item['version_hash'] ) . '">' . esc_html( substr( (string) $item['version_hash'], 0, 12 ) ) . '&hellip;</code>';

		return $out;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_accepted( array $item ): string {
		$utc = (string) $item['accepted_at_utc'];

		// UTC is the authoritative value; the local rendering is a courtesy,
		// which is why the timezone it was recorded in is shown next to it
		// rather than silently reinterpreted in the reader's.
		$local = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) strtotime( $utc . ' UTC' )
		);

		return sprintf(
			'%s<br /><span class="description">%s</span><br /><span class="description">%s</span>',
			esc_html( (string) $local ),
			esc_html( $utc . ' UTC' ),
			esc_html( trim( (string) $item['timezone'] . ' ' . ( '' !== (string) $item['utc_offset'] ? '(UTC' . (string) $item['utc_offset'] . ')' : '' ) ) )
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_origin( array $item ): string {
		if ( null !== $item['anonymised_at_utc'] ) {
			return '<em>' . esc_html__( 'erased under retention policy', 'mammoth-acceptance-logs' ) . '</em><br /><span class="description">' . esc_html( (string) $item['anonymised_at_utc'] ) . '</span>';
		}

		$agent = (string) $item['user_agent'];

		return sprintf(
			'<code>%s</code><br /><span class="description">%s</span><br /><span class="description" title="%s">%s</span>',
			esc_html( (string) $item['ip_address'] ),
			esc_html( (string) $item['ip_source'] ),
			esc_attr( $agent ),
			esc_html( '' !== $agent ? mb_strimwidth( $agent, 0, 40, '…' ) : __( 'no browser identification recorded', 'mammoth-acceptance-logs' ) )
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_delivery( array $item ): string {
		$events = $this->events->latest_of_types(
			(int) $item['id'],
			array( 'email_sent', 'email_failed', 'pdf_failed' )
		);

		if ( isset( $events['email_sent'] ) && 'success' === (string) $events['email_sent']['outcome'] ) {
			return '<span class="mmwp-status mmwp-status--ok">&#10003; ' . esc_html__( 'sent', 'mammoth-acceptance-logs' ) . '</span>';
		}

		if ( isset( $events['email_sent'] ) && 'skipped' === (string) $events['email_sent']['outcome'] ) {
			return '<span class="mmwp-status mmwp-status--muted">&#8212; ' . esc_html__( 'disabled', 'mammoth-acceptance-logs' ) . '</span>';
		}

		if ( isset( $events['email_failed'] ) ) {
			$context = json_decode( (string) $events['email_failed']['context'], true );
			$error   = is_array( $context ) ? (string) ( $context['error'] ?? '' ) : '';

			return '<span class="mmwp-status mmwp-status--error" title="' . esc_attr( $error ) . '">&#9679; ' . esc_html__( 'failed', 'mammoth-acceptance-logs' ) . '</span>';
		}

		return '<span class="mmwp-status mmwp-status--muted">' . esc_html__( 'not recorded', 'mammoth-acceptance-logs' ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_integrity( array $item ): string {
		$verdict = $this->verifier->verify_row( $item );

		if ( ! $verdict['core'] ) {
			return '<span class="mmwp-status mmwp-status--error">&#10007; ' . esc_html__( 'altered', 'mammoth-acceptance-logs' ) . '</span>';
		}

		if ( ! $verdict['context'] ) {
			return '<span class="mmwp-status mmwp-status--warn">&#9888; ' . esc_html__( 'context altered', 'mammoth-acceptance-logs' ) . '</span>';
		}

		return '<span class="mmwp-status mmwp-status--ok">&#10003; ' . esc_html__( 'verified', 'mammoth-acceptance-logs' ) . '</span>';
	}

	public function no_items(): void {
		esc_html_e( 'No acceptances recorded yet.', 'mammoth-acceptance-logs' );
	}
}
