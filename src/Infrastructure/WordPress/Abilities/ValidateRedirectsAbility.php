<?php
/**
 * Validate redirects ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Domain\ValidationIssue;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Reports redirects whose destination is broken.
 */
final class ValidateRedirectsAbility implements AbilityInterface {

	/**
	 * Largest number of redirects a single call will check.
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
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 * @param RedirectBatch                    $batch            The batch resolver.
	 */
	public function __construct(
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		RedirectBatch $batch
	) {
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->batch            = $batch;
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
			'description'         => __( 'Checks redirects for broken destinations: posts that have been deleted, trashed, or unpublished, and internal paths that no longer resolve. Also flags sources on paths WordPress itself serves, such as /wp-admin or /wp-login.php, which take over that path if it ever returns a 404. Reports what it finds without changing anything; disable or repoint a broken redirect by updating it. Given no redirects, it checks a batch of the most recent ones matching the status filter.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'redirects'  => array(
						'type'        => 'array',
						'items'       => array(
							'type' => array( 'string', 'integer' ),
						),
						'description' => __( 'Specific redirects to check, each given as a redirect ID or the path it redirects from. If omitted, a batch is selected using the status and limit.', 'legacy-redirector' ),
					),
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'enabled', 'disabled' ),
						'default'     => 'enabled',
						'description' => __( 'Which redirects to select when none are given. Defaults to enabled, the ones visitors can reach.', 'legacy-redirector' ),
					),
					'limit'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => 100,
						'description' => __( 'How many redirects to check when none are given.', 'legacy-redirector' ),
					),
					'check_urls' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Also request absolute URL destinations to see whether they respond. Slow, because it makes an HTTP request per redirect.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'checked', 'issues', 'failed' ),
				'properties'           => array(
					'checked' => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects were checked.', 'legacy-redirector' ),
					),
					'issues'  => array(
						'type'        => 'array',
						'description' => __( 'The redirects found to be broken.', 'legacy-redirector' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'from', 'to', 'type', 'status', 'issue', 'description' ),
							'properties'           => array_merge(
								RedirectSchema::properties(),
								array(
									'issue'       => array(
										'type'        => 'string',
										'description' => __( 'A short label for what is wrong.', 'legacy-redirector' ),
									),
									'description' => array(
										'type'        => 'string',
										'description' => __( 'A fuller explanation of what is wrong.', 'legacy-redirector' ),
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
	 * Validate the redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{checked: int, issues: array<int, array<string, mixed>>, failed: array<int, array{redirect: string, reason: string}>} The outcome.
	 */
	public function execute( $input = array() ): array {
		$input    = is_array( $input ) ? $input : array();
		$failures = array();

		if ( ! empty( $input['redirects'] ) ) {
			$items     = $this->batch->resolve( $input['redirects'] );
			$failures  = BatchFailures::format( $items );
			$redirects = RedirectBatch::redirects( $items );
		} else {
			$status    = (string) ( $input['status'] ?? 'enabled' );
			$redirects = $this->query_repository->find_matching(
				new RedirectCriteria(
					'any' === $status ? null : $status,
					null,
					null,
					'date',
					'DESC',
					(int) ( $input['limit'] ?? 100 ),
					0
				)
			);
		}

		$issues = $this->auditor->validate_batch( $redirects, (bool) ( $input['check_urls'] ?? false ) );

		return array(
			'checked' => count( $redirects ),
			'issues'  => array_map( array( $this, 'issue_to_array' ), $issues ),
			'failed'  => $failures,
		);
	}

	/**
	 * Format a validation issue for ability output.
	 *
	 * @param ValidationIssue $issue The issue.
	 * @return array<string, mixed> The formatted issue.
	 */
	private function issue_to_array( ValidationIssue $issue ): array {
		return array_merge(
			RedirectSchema::to_array( $issue->redirect() ),
			array(
				'issue'       => $issue->label(),
				'description' => $issue->description(),
			)
		);
	}
}
