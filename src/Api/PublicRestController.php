<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Api;

use MammothWP\AcceptanceLogs\Versioning\TermsVersion;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unauthenticated read-only routes under `mmwp/v1/public`.
 *
 * A site that publishes its terms on a public page needs the text to be
 * readable without a login, which the authenticated routes in RestController
 * deliberately are not. Rather than loosening those, this is a separate,
 * narrower surface:
 *
 * 1. **Read only.** No publishing, no refresh, no writes of any kind.
 * 2. **Versions only.** Same boundary as RestController — acceptance records
 *    are personal data about named people and get no second access path,
 *    least of all an anonymous one.
 * 3. **Redacted.** Responses come from TermsApi::to_public_array(), which
 *    drops hashes, source and the activation window. The redaction lives
 *    there, once, so it cannot drift between routes.
 *
 * The archive is already public information by intention — these are the terms
 * the site asks people to agree to — but the whole surface can still be
 * removed with the `mmwp_terms_public_api_enabled` filter for installs whose
 * terms are internal.
 */
final class PublicRestController {

	public const NAMESPACE = 'mmwp/v1';

	/**
	 * Seconds anonymous responses may be cached. The archive changes on
	 * publication only, and a stale-by-minutes copy of a legal document is
	 * preferable to every visitor hitting the database.
	 */
	private const CACHE_SECONDS = 300;

	private TermsApi $api;

	public function __construct( TermsApi $api ) {
		$this->api = $api;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		/**
		 * Filters whether the public, unauthenticated read routes exist at all.
		 *
		 * Returning false unregisters them entirely — they 404 rather than
		 * 401, because a site whose terms are internal should not advertise
		 * that the endpoint is there.
		 *
		 * @param bool $enabled Defaults to true.
		 */
		if ( ! apply_filters( 'mmwp_terms_public_api_enabled', true ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/public/version',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_active' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/versions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_versions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'offset'   => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/versions/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_version' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function get_active(): WP_REST_Response|WP_Error {
		$version = $this->api->active();

		if ( null === $version ) {
			return new WP_Error(
				'mmwp_terms_no_version',
				__( 'No terms version has been published yet.', 'mammoth-acceptance-logs' ),
				array( 'status' => 404 )
			);
		}

		return $this->cacheable( $this->api->to_public_array( $version, true ) );
	}

	public function get_version( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$version = $this->api->find( (int) $request['id'] );

		if ( null === $version ) {
			return new WP_Error(
				'mmwp_terms_not_found',
				__( 'That terms version does not exist.', 'mammoth-acceptance-logs' ),
				array( 'status' => 404 )
			);
		}

		return $this->cacheable( $this->api->to_public_array( $version, true ) );
	}

	/**
	 * The archive, newest first, without the full text — a list of a dozen
	 * legal documents is a payload nobody asked for. Clients fetch the text
	 * of the one version they are about to show.
	 */
	public function list_versions( WP_REST_Request $request ): WP_REST_Response {
		$versions = $this->api->versions( (int) $request['per_page'], (int) $request['offset'] );

		return $this->cacheable(
			array_map(
				fn( TermsVersion $version ): array => $this->api->to_public_array( $version ),
				$versions
			)
		);
	}

	/**
	 * @param array<mixed> $data
	 */
	private function cacheable( array $data ): WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_SECONDS );

		return $response;
	}
}
