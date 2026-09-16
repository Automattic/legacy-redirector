<?php
/**
 * Find redirect domains ability.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Lists the outbound domains redirects point at.
 */
final class FindRedirectDomainsAbility implements AbilityInterface {

	/**
	 * Number of destination URLs fetched per query.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

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
		return 'wpcom-legacy-redirector/find-redirect-domains';
	}

	/**
	 * Get the arguments to register the ability with.
	 *
	 * @return array<string, mixed> Arguments accepted by wp_register_ability().
	 */
	#[\Override]
	public function args(): array {
		return array(
			'label'               => __( 'Find Redirect Domains', 'wpcom-legacy-redirector' ),
			'description'         => __( 'Returns the unique external domains that redirects on this site point at. Useful when deciding which hosts to allow as redirect destinations, since WordPress only redirects to hosts on its allow list.', 'wpcom-legacy-redirector' ),
			'category'            => AbilitiesRegistrar::CATEGORY,
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'count', 'domains' ),
				'properties'           => array(
					'count'   => array(
						'type'        => 'integer',
						'description' => __( 'How many unique domains were found.', 'wpcom-legacy-redirector' ),
					),
					'domains' => array(
						'type'        => 'array',
						'description' => __( 'The domains, in alphabetical order.', 'wpcom-legacy-redirector' ),
						'items'       => array(
							'type' => 'string',
						),
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
	 * Collect the outbound domains.
	 *
	 * @return array{count: int, domains: array<int, string>} The domains found.
	 */
	public function execute(): array {
		$domains = array();
		$offset  = 0;

		do {
			$destination_urls = $this->query_repository->get_external_destination_urls( self::PAGE_SIZE, $offset );

			foreach ( $destination_urls as $destination_url ) {
				$host = $destination_url ? wp_parse_url( $destination_url, PHP_URL_HOST ) : null;

				if ( $host ) {
					$domains[ $host ] = true;
				}
			}

			$offset       += self::PAGE_SIZE;
			$fetched_count = count( $destination_urls );
		} while ( self::PAGE_SIZE === $fetched_count );

		$domains = array_keys( $domains );
		sort( $domains );

		return array(
			'count'   => count( $domains ),
			'domains' => $domains,
		);
	}
}
