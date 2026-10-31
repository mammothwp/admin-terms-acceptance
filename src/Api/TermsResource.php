<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Api;

use MammothWP\WpKit\Context;
use MammothWP\WpKit\Field;
use MammothWP\WpKit\Operation;
use MammothWP\WpKit\Resource;
use MammothWP\AcceptanceLogs\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The management surface, declared once for wp-kit.
 *
 * wp-kit projects each operation onto a REST route under `mmwp/v1`, a
 * `wp mmwp terms` subcommand and a `mmwp-terms/*` ability, and runs the one
 * validation/authorisation path for all three.
 *
 * Permissions follow the plugin's two existing boundaries: terms
 * administration needs `manage_options` (and ignores the admin-UI
 * kill-switch, so locked-down installs stay scriptable), while reading the
 * acceptance log goes through ManagerAccess like the admin screen does.
 */
final class TermsResource extends Resource {

	private TermsService $service;

	/**
	 * @param TermsService $service The adapter over TermsApi.
	 */
	public function __construct( TermsService $service ) {
		$this->service = $service;
	}

	/** @return string */
	public function name() {
		return 'terms';
	}

	/** @return string */
	public function label() {
		return __( 'Acceptance Logs', 'mammoth-acceptance-logs' );
	}

	/** @return object */
	public function service() {
		return $this->service;
	}

	/** @return string */
	public function rest_base() {
		return '/terms';
	}

	/** @return string */
	public function rest_namespace() {
		return 'mmwp/v1';
	}

	/** @return string */
	public function cli_base() {
		return 'mmwp terms';
	}

	/** @return string */
	public function ability_category() {
		return 'mmwp-acceptance-logs';
	}

	/** @return array{label:string,description:string} */
	public function ability_category_args() {
		return array(
			'label'       => __( 'Acceptance Logs', 'mammoth-acceptance-logs' ),
			'description' => __( 'Terms versions and the log of who accepted them.', 'mammoth-acceptance-logs' ),
		);
	}

	/** @return array<string,Operation> */
	public function operations() {
		return array(
			'active'      => Operation::read( 'active_version' )
				->label( __( 'Show the active terms version', 'mammoth-acceptance-logs' ) )
				->description( __( 'Returns the terms version users must currently have accepted.', 'mammoth-acceptance-logs' ) )
				->capability( 'edit_posts' )
				->rest( 'GET', '/version' )
				->cli( 'get' )
				->ability( 'get-active-version' ),
			'versions'    => Operation::read( 'list_versions' )
				->label( __( 'List archived terms versions', 'mammoth-acceptance-logs' ) )
				->description( __( 'Lists every archived terms version, newest first.', 'mammoth-acceptance-logs' ) )
				->capability( 'edit_posts' )
				->input(
					array(
						Field::int( 'limit' )->default_value( 20 )->min( 1 )->max( 100 )->describe( __( 'Rows to return.', 'mammoth-acceptance-logs' ) ),
						Field::int( 'offset' )->default_value( 0 )->min( 0 )->describe( __( 'Rows to skip.', 'mammoth-acceptance-logs' ) ),
					)
				)
				->rest( 'GET', '/versions' )
				->cli( 'list', array( 'columns' => array( 'id', 'version_number', 'effective_date', 'is_active', 'source' ) ) )
				->ability( 'list-versions' ),
			'version'     => Operation::read( 'get_version' )
				->label( __( 'Get one archived terms version', 'mammoth-acceptance-logs' ) )
				->description( __( 'Returns one archived terms version, including its full text.', 'mammoth-acceptance-logs' ) )
				->capability( 'edit_posts' )
				->input( array( Field::int( 'id' )->required()->min( 1 )->positional()->describe( __( 'Version ID.', 'mammoth-acceptance-logs' ) ) ) )
				->rest( 'GET', '/versions/(?P<id>\d+)' )
				->cli( 'show' )
				->ability( 'get-version' ),
			'publish'     => Operation::write( 'publish_version' )
				->label( __( 'Publish a new terms version', 'mammoth-acceptance-logs' ) )
				->description( __( 'Publishes the text as the active version. Everyone in scope is asked to accept it again.', 'mammoth-acceptance-logs' ) )
				->input(
					array(
						Field::string( 'content' )->required()->describe( __( 'The terms text. HTML is filtered through wp_kses_post().', 'mammoth-acceptance-logs' ) ),
						Field::string( 'version_number' )->max_length( 32 )->describe( __( 'Human version number, e.g. 2.1. Derived if omitted.', 'mammoth-acceptance-logs' ) ),
						Field::string( 'effective_date' )->max_length( 10 )->describe( __( 'Effective date, Y-m-d.', 'mammoth-acceptance-logs' ) ),
						Field::string( 'change_summary' )->describe( __( 'Shown to returning users above the terms.', 'mammoth-acceptance-logs' ) ),
						Field::bool( 'only_if_changed' )->default_value( false )->describe( __( 'Do nothing if the text matches the active version.', 'mammoth-acceptance-logs' ) ),
					)
				)
				->rest( 'POST', '/version' )
				->cli( 'publish' )
				->ability( 'publish-version' ),
			'refresh'     => Operation::write( 'refresh_content' )
				->label( __( 'Re-read the terms content source', 'mammoth-acceptance-logs' ) )
				->description( __( 'Re-reads the terms content source now instead of waiting for the daily check, and publishes a new version if it changed.', 'mammoth-acceptance-logs' ) )
				->rest( 'POST', '/refresh' )
				->cli( 'refresh' )
				->ability( 'refresh-content' ),
			'acceptances' => Operation::read( 'list_acceptances' )
				->label( __( 'List acceptance log entries', 'mammoth-acceptance-logs' ) )
				->description( __( 'Read-only, redacted export of the acceptance log. Restricted to the plugin managers.', 'mammoth-acceptance-logs' ) )
				->capability( array( $this, 'can_read_log' ) )
				->input(
					array(
						Field::int( 'user_id' )->default_value( 0 )->min( 0 )->describe( __( 'Only this user. 0 for everyone.', 'mammoth-acceptance-logs' ) ),
						Field::int( 'version_id' )->default_value( 0 )->min( 0 )->describe( __( 'Only this terms version. 0 for all.', 'mammoth-acceptance-logs' ) ),
						Field::string( 'date_from' )->max_length( 10 )->validate_with( array( $this, 'validate_date' ) )->describe( __( 'First day, Y-m-d (UTC).', 'mammoth-acceptance-logs' ) ),
						Field::string( 'date_to' )->max_length( 10 )->validate_with( array( $this, 'validate_date' ) )->describe( __( 'Last day, Y-m-d (UTC).', 'mammoth-acceptance-logs' ) ),
						Field::int( 'limit' )->default_value( 50 )->min( 1 )->max( 500 )->describe( __( 'Rows to return.', 'mammoth-acceptance-logs' ) ),
						Field::int( 'offset' )->default_value( 0 )->min( 0 )->describe( __( 'Rows to skip.', 'mammoth-acceptance-logs' ) ),
					)
				)
				->rest( 'GET', '/acceptances' )
				->cli( 'acceptances', array( 'columns' => array( 'id', 'user_login', 'version_number', 'accepted_at_utc', 'record_hash' ) ) )
				->ability( 'list-acceptances' ),
			'purge'       => Operation::destructive( 'purge_evidence' )
				->label( __( 'Report or permanently delete all acceptance evidence', 'mammoth-acceptance-logs' ) )
				->description( __( 'Without --force this only reports what exists. With --force it drops the log, the version archive and the audit events.', 'mammoth-acceptance-logs' ) )
				->capability( 'delete_plugins' )
				->input(
					array(
						Field::bool( 'dry_run' )->default_value( false )->describe( __( 'Report only, even with --force.', 'mammoth-acceptance-logs' ) ),
						Field::bool( 'force' )->default_value( false )->describe( __( 'Actually delete. Irreversible.', 'mammoth-acceptance-logs' ) ),
					)
				)
				->surfaces( array( Context::CLI ), __( 'Deleting evidence is deliberately not offered over REST or abilities.', 'mammoth-acceptance-logs' ) )
				->cli( 'purge' ),
		);
	}

	/**
	 * Capability resolver for the log: the plugin's manager list, not a role.
	 *
	 * An unattended WP-CLI run (no --user) has no user to look up, and wp-kit
	 * trusts it as an administrator only when handed a capability string, so
	 * that case answers with one.
	 *
	 * @param mixed   $operation Operation being authorised (unused).
	 * @param mixed   $input     Normalised input (unused).
	 * @param Context $context   Invocation context.
	 * @return bool|string
	 */
	public function can_read_log( $operation = null, $input = null, $context = null ) {
		if ( $context instanceof Context && $context->is_unattended_cli() ) {
			return 'manage_options';
		}

		return Plugin::instance()->managers()->can_manage();
	}

	/**
	 * Accepts an empty value or a real Y-m-d date.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public function validate_date( $value ) {
		if ( '' === $value || null === $value ) {
			return true;
		}

		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $value );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === (string) $value;
	}
}
