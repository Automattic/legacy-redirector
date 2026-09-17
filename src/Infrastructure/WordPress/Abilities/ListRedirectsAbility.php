<?php
/**
 * List redirects ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Lists redirects, with filtering and pagination.
 */
final class ListRedirectsAbility implements AbilityInterface {

	/**
	 * Largest page of redirects a single call will return.
	 *
	 * @var int
	 */
	private const int MAX_LIMIT = 200;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 */
	public function __construct( RedirectQueryRepositoryInterface $query_repository ) {
		$this->query_repository = $query_repository;
	}

	/**
	 * Get the namespaced ability name.
	 *
	 * @return string The ability name.
	 */
	#[\Override]
	public function name(): string {
		return 'legacy-redirector/list-redirects';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'List Redirects', 'legacy-redirector' ),
			'description'         => __( 'Returns a page of redirects, optionally filtered by whether they are enabled, by what kind of destination they point at, or by a search term matched against their source paths. The total count of matching redirects is returned alongside the page, so further pages can be requested with an offset.', 'legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'           => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'enabled', 'disabled' ),
						'default'     => 'any',
						'description' => __( 'Only return redirects with this status.', 'legacy-redirector' ),
					),
					'destination_type' => array(
						'type'        => 'string',
						'enum'        => array( 'any', 'post', 'url' ),
						'default'     => 'any',
						'description' => __( 'Only return redirects pointing at a post ID, or at a URL.', 'legacy-redirector' ),
					),
					'search'           => array(
						'type'        => 'string',
						'description' => __( 'Only return redirects whose source path contains this text.', 'legacy-redirector' ),
					),
					'limit'            => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'default'     => 20,
						'description' => __( 'How many redirects to return.', 'legacy-redirector' ),
					),
					'offset'           => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'How many redirects to skip, for paging through the results.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'total', 'redirects' ),
				'properties'           => array(
					'total'     => array(
						'type'        => 'integer',
						'description' => __( 'How many redirects match the filters, ignoring limit and offset.', 'legacy-redirector' ),
					),
					'redirects' => array(
						'type'        => 'array',
						'description' => __( 'The matching redirects.', 'legacy-redirector' ),
						'items'       => RedirectSchema::object_schema(),
					),
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
	 * List the matching redirects.
	 *
	 * @param mixed $input The validated ability input.
	 * @return array{total: int, redirects: array<int, array<string, mixed>>} The matching redirects.
	 */
	public function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$criteria = new RedirectCriteria(
			$this->filter_value( $input, 'status' ),
			$this->filter_value( $input, 'destination_type' ),
			isset( $input['search'] ) ? (string) $input['search'] : null,
			'date',
			'DESC',
			(int) ( $input['limit'] ?? 20 ),
			(int) ( $input['offset'] ?? 0 )
		);

		$redirects = array_map(
			array( RedirectSchema::class, 'to_array' ),
			$this->query_repository->find_matching( $criteria )
		);

		return array(
			'total'     => $this->query_repository->count_matching( $criteria ),
			'redirects' => $redirects,
		);
	}

	/**
	 * Read a filter from the input, treating 'any' as no filter.
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @param string               $key   The filter key.
	 * @return string|null The filter value, or null for no filter.
	 */
	private function filter_value( array $input, string $key ): ?string {
		$value = $input[ $key ] ?? 'any';

		return 'any' === $value ? null : (string) $value;
	}
}
