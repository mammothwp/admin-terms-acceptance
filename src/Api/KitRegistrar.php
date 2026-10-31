<?php
declare( strict_types=1 );

namespace MammothWP\AcceptanceLogs\Api;

use MammothWP\WpKit\Adapters\Ability_Adapter;
use MammothWP\WpKit\Adapters\Cli_Adapter;
use MammothWP\WpKit\Adapters\Rest_Adapter;
use MammothWP\WpKit\Kit;
use MammothWP\WpKit\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands the TermsResource to wp-kit.
 *
 * wp-kit is version-gated: when several plugins bundle it, the newest copy
 * boots once on `plugins_loaded` (priority -100) and owns the autoloader for
 * its classes, so this plugin never loads a second, older copy and cannot
 * collide with another plugin's version. Everything kit-related therefore has
 * to happen inside Kit::on_ready().
 */
final class KitRegistrar {

	private TermsResource $resource;

	/**
	 * @param TermsResource $terms_resource The resource to expose.
	 */
	public function __construct( TermsResource $terms_resource ) {
		$this->resource = $terms_resource;
	}

	/**
	 * Queues the adapters for when wp-kit has booted. A no-op when the
	 * dependency is missing (a source checkout without `composer install`).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( Kit::class, false ) ) {
			return;
		}

		Kit::on_ready( array( $this, 'expose' ) );
	}

	/**
	 * Registers the REST, WP-CLI and Abilities adapters.
	 *
	 * @return void
	 */
	public function expose(): void {
		$registry = ( new Registry() )->add( $this->resource )->ready();

		foreach ( $registry->all() as $declared ) {
			( new Rest_Adapter( $declared ) )->register();
			( new Cli_Adapter( $declared ) )->register();
			( new Ability_Adapter( $declared ) )->register();
		}
	}
}
