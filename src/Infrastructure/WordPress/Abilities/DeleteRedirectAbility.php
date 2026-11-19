<?php
/**
 * Delete redirect ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Deletes one or more redirects.
 */
final class DeleteRedirectAbility implements AbilityInterface {

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
		return 'legacy-redirector/delete-redirect';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Delete Redirects', 'legacy-redirector' ),
			'description'         => __( 'Permanently deletes one or more redirects. Visitors following those paths will get whatever the site would otherwise serve, usually a 404. To stop serving a redirect while keeping it, update its status to disabled instead.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'redirects' ),
				'properties'           => array(
					'redirects' => RedirectSchema::identifiers_schema(
						__( 'The redirects to delete, each given as a redirect ID or the path it redirects from.', 'legacy-redirector' )
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'failed' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects were deleted.', 'legacy-redirector' ),
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
					'destructive' => true,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Delete the redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{deleted: int, failed: array<int, array{redirect: string, reason: string}>} The outcome.
	 */
	public function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$items = $this->batch->apply(
			$input['redirects'] ?? array(),
			fn( Redirect $redirect ): bool => $this->manager->delete_by_id( (int) $redirect->id() )
		);

		return array(
			'deleted' => RedirectBatch::count_succeeded( $items ),
			'failed'  => BatchFailures::format( $items, __( 'The redirect could not be deleted.', 'legacy-redirector' ) ),
		);
	}
}
