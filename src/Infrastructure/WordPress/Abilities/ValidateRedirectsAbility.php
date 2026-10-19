<?php
/**
 * Validate redirects ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Checks redirects' stored details for problems, and records what it finds.
 *
 * Mirrors `wp legacy-redirector validate`, with one deliberate difference:
 * given no redirects, it re-checks the ones the background check flagged
 * rather than every redirect, because a full pass over a large site does
 * not fit in one request. The background check and the CLI cover that.
 */
final class ValidateRedirectsAbility implements AbilityInterface {

	/**
	 * Largest number of flagged redirects a single call will re-check.
	 *
	 * @var int
	 */
	private const int MAX_LIMIT = 1000;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * The audit sweep, which validates and records.
	 *
	 * @var AuditSweep
	 */
	private AuditSweep $sweep;

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectBatch                    $batch            The batch resolver.
	 * @param AuditSweep                       $sweep            The audit sweep.
	 * @param AuditFlags                       $flags            The per-row audit flags.
	 */
	public function __construct(
		RedirectQueryRepositoryInterface $query_repository,
		RedirectBatch $batch,
		AuditSweep $sweep,
		AuditFlags $flags
	) {
		$this->query_repository = $query_repository;
		$this->batch            = $batch;
		$this->sweep            = $sweep;
		$this->flags            = $flags;
	}

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name.
	 */
	#[\Override]
	public function name(): string {
		return 'legacy-redirector/validate-redirects';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Validate Redirects', 'legacy-redirector' ),
			'description'         => __( 'Checks redirects\' stored details for problems, without requesting any URL: destination posts that have been deleted, trashed, or unpublished, external hosts missing from the allowed_redirect_hosts filter, and rows whose data cannot be read. Also warns about sources on paths WordPress itself serves, such as /wp-admin, and possible loops through other redirects. Each finding says what is wrong and what to do about it. Records the result, so the admin "Has issues" view and menu badge stay in step. Given no redirects, it re-checks the ones the background check flagged. To check whether a redirect works live, use test-redirects.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'redirects' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => array( 'string', 'integer' ),
						),
						'description' => __( 'Specific redirects to check, each given as a redirect ID or the path it redirects from. If omitted, the redirects the background check flagged are re-checked.', 'legacy-redirector' ),
					),
					'limit'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => 100,
						'description' => __( 'How many flagged redirects to re-check when none are given.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'checked', 'issues', 'last_full_check', 'failed' ),
				'properties'           => array(
					'checked'         => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects were checked.', 'legacy-redirector' ),
					),
					'issues'          => array(
						'type'        => 'array',
						'description' => __( 'What was found, one entry per finding.', 'legacy-redirector' ),
						'items'       => self::finding_schema(),
					),
					'last_full_check' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'When every redirect was last checked, as an ISO 8601 date, or null if never.', 'legacy-redirector' ),
					),
					'failed'          => RedirectSchema::failures_schema(),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( Capability::class, 'current_user_can_manage' ),
			'meta'                => array(
				'annotations'  => array(
					// It writes the per-row flags, though nothing about the redirects themselves.
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Validate the redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{checked: int, issues: array<int, array<string, mixed>>, last_full_check: string|null, failed: array<int, array{redirect: string, reason: string}>} The outcome.
	 */
	public function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$failures = array();

		if ( ! empty( $input['redirects'] ) ) {
			$items     = $this->batch->resolve( $input['redirects'] );
			$failures  = BatchFailures::format( $items );
			$redirects = RedirectBatch::redirects( $items );
		} else {
			$redirects = $this->query_repository->find_matching(
				new RedirectCriteria( null, null, null, 'ID', 'ASC', (int) ( $input['limit'] ?? 100 ), 0, 'any' )
			);
		}

		$checked_at = $this->flags->checked_at();

		return array(
			'checked'         => count( $redirects ),
			'issues'          => array_map( array( self::class, 'finding_to_array' ), $this->sweep->validate_redirects( $redirects ) ),
			'last_full_check' => null === $checked_at ? null : gmdate( 'c', $checked_at ),
			'failed'          => $failures,
		);
	}

	/**
	 * The output schema for one finding, shared with the test ability.
	 *
	 * @return array<string, mixed> The schema.
	 */
	public static function finding_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'from', 'to', 'type', 'status', 'severity', 'issue', 'description', 'remedy' ),
			'properties'           => array_merge(
				RedirectSchema::properties(),
				array(
					'severity'    => array(
						'type'        => 'string',
						'enum'        => array( 'problem', 'warning', 'inconclusive' ),
						'description' => __( 'A problem means the redirect is broken; a warning that it works but deserves a look; inconclusive that the check could not tell.', 'legacy-redirector' ),
					),
					'issue'       => array(
						'type'        => 'string',
						'description' => __( 'A short label for what is wrong.', 'legacy-redirector' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'A fuller explanation of what is wrong.', 'legacy-redirector' ),
					),
					'remedy'      => array(
						'type'        => 'string',
						'description' => __( 'What can be done about it.', 'legacy-redirector' ),
					),
				)
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Format a finding for ability output.
	 *
	 * @param AuditFinding $finding The finding.
	 * @return array<string, mixed> The formatted finding.
	 */
	public static function finding_to_array( AuditFinding $finding ): array {
		$severity = 'problem';
		if ( $finding->is_warning() ) {
			$severity = 'warning';
		} elseif ( ! $finding->is_conclusive() ) {
			$severity = 'inconclusive';
		}

		return array_merge(
			RedirectSchema::to_array( $finding->redirect() ),
			array(
				'severity'    => $severity,
				'issue'       => $finding->label(),
				'description' => $finding->description(),
				'remedy'      => $finding->remedy(),
			)
		);
	}
}
