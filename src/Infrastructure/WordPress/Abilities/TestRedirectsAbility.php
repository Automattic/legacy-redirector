<?php
/**
 * Test redirects ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Checks named redirects live, by requesting them. Mirrors `wp legacy-redirector test`.
 */
final class TestRedirectsAbility implements AbilityInterface {

	/**
	 * Most redirects a single call will request.
	 *
	 * Each one is up to two HTTP requests, so the batch stays small enough
	 * to finish inside one request's time limit.
	 *
	 * @var int
	 */
	private const int MAX_REDIRECTS = 20;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * Constructor.
	 *
	 * @param RedirectAuditor $auditor The redirect auditor.
	 * @param RedirectBatch   $batch   The batch resolver.
	 */
	public function __construct( RedirectAuditor $auditor, RedirectBatch $batch ) {
		$this->auditor = $auditor;
		$this->batch   = $batch;
	}

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name.
	 */
	#[\Override]
	public function name(): string {
		return 'legacy-redirector/test-redirects';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Test Redirects', 'legacy-redirector' ),
			'description'         => __( 'Checks named redirects live. Runs every stored-details check validate-redirects runs, requests the destination where only a request can judge it (a URL, or a path that resolves to no post), and requests the source to see whether visiting it redirects to the destination. A disabled redirect\'s source is not requested. Each finding says what is wrong and what to do about it; a timeout or an error from a server is reported as inconclusive rather than broken. Nothing is recorded. Slow, because each redirect is up to two HTTP requests.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'redirects' ),
				'properties'           => array(
					'redirects' => array(
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => self::MAX_REDIRECTS,
						'items'       => array(
							'type' => array( 'string', 'integer' ),
						),
						'description' => __( 'The redirects to test, each given as a redirect ID or the path it redirects from.', 'legacy-redirector' ),
					),
					'only'      => array(
						'type'        => 'string',
						'enum'        => array( 'destination', 'source' ),
						'description' => __( 'Run just one of the two checks. If omitted, both run.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'tested', 'results', 'failed' ),
				'properties'           => array(
					'tested'  => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects were tested.', 'legacy-redirector' ),
					),
					'results' => array(
						'type'        => 'array',
						'description' => __( 'One entry per redirect tested.', 'legacy-redirector' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array_merge( array_keys( RedirectSchema::properties() ), array( 'source', 'issues' ) ),
							'properties'           => array_merge(
								RedirectSchema::properties(),
								array(
									'source' => array(
										'type'        => array( 'string', 'null' ),
										'enum'        => array( 'confirmed', 'diverted', 'dormant', 'not-firing', 'unreachable', null ),
										'description' => __( 'What requesting the source got: confirmed (it redirected to the destination), diverted (it redirected elsewhere), dormant (the site served content), not-firing (a 404), unreachable (the check could not tell), or null if the source was not requested.', 'legacy-redirector' ),
									),
									'issues' => array(
										'type'        => 'array',
										'description' => __( 'What was found; empty when the redirect works.', 'legacy-redirector' ),
										'items'       => ValidateRedirectsAbility::finding_schema(),
									),
								)
							),
							'additionalProperties' => false,
						),
					),
					'failed'  => RedirectSchema::failures_schema(),
				),
				'additionalProperties' => false,
			),
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
	 * Test the redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{tested: int, results: array<int, array<string, mixed>>, failed: array<int, array{redirect: string, reason: string}>} The outcome.
	 */
	public function execute( $input = array() ): array {
		$input     = is_array( $input ) ? $input : array();
		$only      = $input['only'] ?? null;
		$items     = $this->batch->resolve( (array) ( $input['redirects'] ?? array() ) );
		$redirects = RedirectBatch::redirects( $items );
		$results   = array();

		foreach ( $redirects as $redirect ) {
			$result = $this->auditor->test( $redirect, 'source' !== $only, 'destination' !== $only );

			$results[] = array_merge(
				RedirectSchema::to_array( $redirect ),
				array(
					'source' => $result['source']['status'] ?? null,
					'issues' => array_map( array( ValidateRedirectsAbility::class, 'finding_to_array' ), $result['findings'] ),
				)
			);
		}

		return array(
			'tested'  => count( $redirects ),
			'results' => $results,
			'failed'  => BatchFailures::format( $items ),
		);
	}
}
