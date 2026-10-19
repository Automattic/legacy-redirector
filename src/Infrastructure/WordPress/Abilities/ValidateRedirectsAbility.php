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
			'description'         => __( 'Checks redirects\' stored details for problems, without requesting any URL: destination posts that have been deleted, trashed, or unpublished, external hosts missing from the allowed_redirect_hosts filter, and rows whose data cannot be read. Also warns about sources on paths WordPress itself serves, such as /wp-admin, sources a published post or page already answers, and possible loops through other redirects. Each finding says what is wrong and what to do about it. Records the result, so the admin "Has issues" view and menu badge stay in step. Given no redirects, it re-checks the ones the background check flagged, a page at a time: pass next_offset back as offset to continue. To check whether a redirect works live, use test-redirects.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'redirects' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => array( 'string', 'integer' ),
						),
						'maxItems'    => RedirectSchema::MAX_IDENTIFIERS,
						'description' => __( 'Specific redirects to check, each given as a redirect ID or the path it redirects from. If omitted, the redirects the background check flagged are re-checked.', 'legacy-redirector' ),
					),
					'limit'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => 100,
						'description' => __( 'How many flagged redirects to re-check when none are given.', 'legacy-redirector' ),
					),
					'offset'    => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'How many flagged redirects to skip when none are given. Use the next_offset from the previous call.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'checked', 'issues', 'flagged', 'next_offset', 'last_full_check', 'failed' ),
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
					'flagged'         => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects are flagged now, after this check.', 'legacy-redirector' ),
					),
					'next_offset'     => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The offset for the next page of flagged redirects, or null when there are no more (or redirects were named).', 'legacy-redirector' ),
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
	 * Given no redirects, it pages through the flagged ones. A redirect
	 * found fine is unflagged by checking it, which shifts every later one
	 * forward, so the next page starts after only those still flagged; a
	 * plain offset would skip one redirect for every one fixed.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{checked: int, issues: array<int, array<string, mixed>>, flagged: int, next_offset: int|null, last_full_check: string|null, failed: array<int, array{redirect: string, reason: string}>} The outcome.
	 */
	public function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$failures = array();
		$named    = ! empty( $input['redirects'] );
		$offset   = (int) ( $input['offset'] ?? 0 );
		$limit    = (int) ( $input['limit'] ?? 100 );

		if ( $named ) {
			$items     = $this->batch->resolve( $input['redirects'] );
			$failures  = BatchFailures::format( $items );
			$redirects = RedirectBatch::redirects( $items );
		} else {
			$redirects = $this->query_repository->find_matching(
				new RedirectCriteria( null, null, null, 'ID', 'ASC', $limit, $offset, 'any' )
			);
		}

		$findings      = $this->sweep->validate_redirects( $redirects );
		$still_flagged = count( array_unique( array_map( static fn( AuditFinding $finding ) => $finding->redirect_id(), $findings ) ) );
		$flagged       = $this->query_repository->count_matching( new RedirectCriteria( null, null, null, 'ID', 'ASC', 1, 0, 'any' ) );
		$next_offset   = $offset + $still_flagged;
		$checked_at    = $this->flags->checked_at();

		return array(
			'checked'         => count( $redirects ),
			'issues'          => array_map( array( self::class, 'finding_to_array' ), $findings ),
			'flagged'         => $flagged,
			'next_offset'     => ! $named && count( $redirects ) === $limit && $next_offset < $flagged ? $next_offset : null,
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
		return array_merge(
			RedirectSchema::to_array( $finding->redirect() ),
			array(
				'severity'    => $finding->severity(),
				'issue'       => $finding->label(),
				'description' => $finding->description(),
				'remedy'      => $finding->remedy(),
			)
		);
	}
}
