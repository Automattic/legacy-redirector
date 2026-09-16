<?php
/**
 * Get redirect ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use WP_Error;

/**
 * Gets a single redirect by ID or source path.
 */
final class GetRedirectAbility implements AbilityInterface {

	/**
	 * The redirect fetcher.
	 *
	 * @var RedirectFetcher
	 */
	private RedirectFetcher $fetcher;

	/**
	 * Constructor.
	 *
	 * @param RedirectFetcher $fetcher The redirect fetcher.
	 */
	public function __construct( RedirectFetcher $fetcher ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name.
	 */
	#[\Override]
	public function name(): string {
		return 'wpcom-legacy-redirector/get-redirect';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Get Redirect', 'wpcom-legacy-redirector' ),
			'description'         => __( 'Returns a single redirect, looked up by its ID or by the path it redirects from. Use this to check whether a path already has a redirect before creating one, or to see where an existing redirect points.', 'wpcom-legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'redirect' ),
				'properties'           => array(
					'redirect' => array(
						'type'        => array( 'string', 'integer' ),
						'description' => __( 'A redirect ID, or the path it redirects from, e.g. /old-page.', 'wpcom-legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => RedirectSchema::object_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( Capability::class, 'current_user_can_manage' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Get the redirect.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array<string, mixed>|WP_Error The redirect, or an error if it does not exist.
	 */
	public function execute( $input = array() ) {
		$input      = is_array( $input ) ? $input : array();
		$identifier = (string) ( $input['redirect'] ?? '' );

		try {
			$redirect = $this->fetcher->fetch( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error(
				'wpcom_legacy_redirector_invalid_identifier',
				sprintf(
					/* translators: 1: identifier, 2: error message. */
					__( 'Not a valid redirect ID or source path: %1$s (%2$s)', 'wpcom-legacy-redirector' ),
					$identifier,
					$e->getMessage()
				)
			);
		}

		if ( null === $redirect ) {
			return new WP_Error(
				'wpcom_legacy_redirector_not_found',
				sprintf(
					/* translators: %s: identifier. */
					__( 'No redirect found for %s.', 'wpcom-legacy-redirector' ),
					$identifier
				)
			);
		}

		return RedirectSchema::to_array( $redirect );
	}
}
