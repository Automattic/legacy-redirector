<?php
/**
 * Create redirect ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use WP_Error;

/**
 * Creates a redirect.
 */
final class CreateRedirectAbility implements AbilityInterface {

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager The redirect manager.
	 */
	public function __construct( RedirectManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name.
	 */
	public function name(): string {
		return 'wpcom-legacy-redirector/create-redirect';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Redirect', 'wpcom-legacy-redirector' ),
			'description'         => __( 'Creates a redirect from a path on this site to a new destination, so visitors and search engines following the old URL are sent to the right place. The destination may be a path, an absolute URL, or the ID of a post on this site. Fails if a redirect already exists for the path, or if the destination is invalid.', 'wpcom-legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'from', 'to' ),
				'properties'           => array(
					'from'   => array(
						'type'        => 'string',
						'description' => __( 'The path to redirect from, e.g. /old-page or /old-page/?utm_source=news.', 'wpcom-legacy-redirector' ),
					),
					'to'     => array(
						'type'        => array( 'string', 'integer' ),
						'description' => __( 'The destination: a path, an absolute URL, or a post ID.', 'wpcom-legacy-redirector' ),
					),
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'enabled', 'disabled' ),
						'default'     => 'enabled',
						'description' => __( 'Whether the redirect starts out being served to visitors. Defaults to enabled.', 'wpcom-legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => RedirectSchema::object_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( Capability::class, 'current_user_can_manage' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Create the redirect.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array<string, mixed>|WP_Error The created redirect, or an error.
	 */
	public function execute( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$from   = (string) ( $input['from'] ?? '' );
		$to     = $input['to'] ?? '';
		$status = 'disabled' === ( $input['status'] ?? 'enabled' ) ? 'draft' : 'publish';

		try {
			$source      = SourceUrl::from_string( $from );
			$destination = Destination::from_mixed( is_string( $to ) && ctype_digit( $to ) ? (int) $to : $to );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error(
				'wpcom_legacy_redirector_invalid_redirect',
				sprintf(
					/* translators: 1: source path, 2: destination, 3: error message. */
					__( 'Could not create a redirect from %1$s to %2$s: %3$s', 'wpcom-legacy-redirector' ),
					$from,
					(string) $to,
					$e->getMessage()
				)
			);
		}

		$result = $this->create( $source, $destination, $status );

		if ( $result->is_error() ) {
			return new WP_Error(
				'wpcom_legacy_redirector_' . str_replace( '-', '_', (string) $result->error_code() ),
				(string) $result->error_message()
			);
		}

		return array(
			'id'     => (int) $result->redirect_id(),
			'from'   => $source->path(),
			'to'     => $destination->raw_value(),
			'type'   => $destination->is_post_id() ? 'post' : 'url',
			'status' => 'draft' === $status ? 'disabled' : 'enabled',
		);
	}

	/**
	 * Create the redirect, allowing insertion for the duration of the call.
	 *
	 * RedirectManager only allows creation from WP-CLI and the admin, so that a
	 * stray front-end code path cannot write redirects. An ability runs in
	 * neither context — typically it arrives over the REST API — but the
	 * permission callback has already established that the current user may
	 * manage redirects, which is the same bar the admin screens apply.
	 *
	 * @param SourceUrl   $source      The source URL.
	 * @param Destination $destination The destination.
	 * @param string      $status      The post status to create with.
	 * @return \Automattic\LegacyRedirector\Application\RedirectCreationResult The creation result.
	 */
	private function create( SourceUrl $source, Destination $destination, string $status ) {
		$allow_insert = static function (): bool {
			return true;
		};

		add_filter( 'wpcom_legacy_redirector_allow_insert', $allow_insert );

		$result = $this->manager->create_redirect( $source, $destination, true, $status );

		remove_filter( 'wpcom_legacy_redirector_allow_insert', $allow_insert );

		return $result;
	}
}
