<?php
/**
 * Set redirect status ability.
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
 * Enables or disables redirects, without touching their destinations.
 *
 * `update-redirect` can also change status, as a side effect of an edit.
 * This ability exists because turning a redirect on or off is a task in its
 * own right — it is what the admin row and bulk actions do — and a client
 * should be able to ask for it without describing an update.
 */
final class SetRedirectStatusAbility implements AbilityInterface {

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
		return 'wpcom-legacy-redirector/set-redirect-status';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Enable or Disable Redirects', 'wpcom-legacy-redirector' ),
			'description'         => __( 'Turns redirects on or off without deleting them or changing where they point. A disabled redirect stays in the list and keeps its destination, but is no longer served to visitors, who get whatever the site would otherwise serve for that path. Accepts one redirect or several.', 'wpcom-legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'redirects', 'status' ),
				'properties'           => array(
					'redirects' => RedirectSchema::identifiers_schema(
						__( 'The redirects to enable or disable, each given as a redirect ID or the path it redirects from.', 'wpcom-legacy-redirector' )
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'enabled', 'disabled' ),
						'description' => __( 'Whether the redirects should be served to visitors.', 'wpcom-legacy-redirector' ),
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
						'description' => __( 'How many redirects had their status set.', 'wpcom-legacy-redirector' ),
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
	 * Set the status of the redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{updated: int, failed: array<int, array{redirect: string, reason: string}>} The outcome.
	 */
	public function execute( $input = array() ): array {
		$input       = is_array( $input ) ? $input : array();
		$post_status = 'disabled' === ( $input['status'] ?? '' ) ? 'draft' : 'publish';

		$items = $this->batch->apply(
			$input['redirects'] ?? array(),
			fn( Redirect $redirect ) => $this->manager->change_status( (int) $redirect->id(), $post_status )
		);

		return array(
			'updated' => RedirectBatch::count_succeeded( $items ),
			'failed'  => BatchFailures::format( $items, __( 'The redirect status could not be changed.', 'wpcom-legacy-redirector' ) ),
		);
	}
}
