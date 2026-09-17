<?php
/**
 * Update redirect ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use WP_Error;

/**
 * Updates the destination and/or status of one or more redirects.
 */
final class UpdateRedirectAbility implements AbilityInterface {

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager The redirect manager.
	 * @param RedirectBatch   $batch   The batch resolver.
	 */
	public function __construct( RedirectManager $manager, RedirectBatch $batch ) {
		$this->manager = $manager;
		$this->batch   = $batch;
	}

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name.
	 */
	#[\Override]
	public function name(): string {
		return 'legacy-redirector/update-redirect';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Update Redirects', 'legacy-redirector' ),
			'description'         => __( 'Changes where one or more existing redirects point, and optionally their status at the same time. At least one of the destination or the status must be given. To only turn redirects on or off, use set-redirect-status instead.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'redirects' ),
				'properties'           => array(
					'redirects' => RedirectSchema::identifiers_schema(
						__( 'The redirects to update, each given as a redirect ID or the path it redirects from.', 'legacy-redirector' )
					),
					'to'        => array(
						'type'        => array( 'string', 'integer' ),
						'description' => __( 'The new destination: a path, an absolute URL, or a post ID.', 'legacy-redirector' ),
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'enabled', 'disabled' ),
						'description' => __( 'Whether the redirects should be served to visitors.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'updated', 'failed' ),
				'properties'           => array(
					'updated' => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects were updated.', 'legacy-redirector' ),
					),
					'failed'  => RedirectSchema::failures_schema(),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( Capability::class, 'current_user_can_manage' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Update the redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{updated: int, failed: array<int, array{redirect: string, reason: string}>}|WP_Error The outcome, or an error if the input asks for no change.
	 */
	public function execute( $input = array() ) {
		$input       = is_array( $input ) ? $input : array();
		$destination = null;
		$status      = null;

		if ( ! isset( $input['to'] ) && ! isset( $input['status'] ) ) {
			return new WP_Error(
				'legacy_redirector_nothing_to_update',
				__( 'Pass a new destination, a new status, or both.', 'legacy-redirector' )
			);
		}

		if ( isset( $input['to'] ) ) {
			$to = $input['to'];

			try {
				$destination = Destination::from_mixed( is_string( $to ) && ctype_digit( $to ) ? (int) $to : $to );
			} catch ( \InvalidArgumentException $e ) {
				return new WP_Error(
					'legacy_redirector_invalid_destination',
					sprintf(
						/* translators: 1: destination, 2: error message. */
						__( 'Not a valid destination: %1$s (%2$s)', 'legacy-redirector' ),
						(string) $to,
						$e->getMessage()
					)
				);
			}
		}

		if ( isset( $input['status'] ) ) {
			$status = 'disabled' === $input['status'] ? 'draft' : 'publish';
		}

		$items = $this->batch->apply(
			$input['redirects'] ?? array(),
			function ( Redirect $redirect ) use ( $destination, $status ) {
				$redirect_id = (int) $redirect->id();

				return null !== $destination
					? $this->manager->update_destination( $redirect_id, $destination, $status )
					: $this->manager->change_status( $redirect_id, (string) $status );
			}
		);

		return array(
			'updated' => RedirectBatch::count_succeeded( $items ),
			'failed'  => BatchFailures::format( $items, __( 'The redirect could not be saved.', 'legacy-redirector' ) ),
		);
	}
}
