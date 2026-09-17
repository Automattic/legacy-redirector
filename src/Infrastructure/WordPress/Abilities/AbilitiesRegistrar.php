<?php
/**
 * Abilities API registration.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;

/**
 * Registers this plugin's abilities with the WordPress Abilities API.
 *
 * The Abilities API arrived in WordPress 6.9, and lets MCP clients and other
 * agents discover and call site functionality. Nothing here runs on older
 * versions: the hooks it uses simply never fire, and WordPress only fires them
 * at all once something asks the registry for abilities, so front-end requests
 * pay nothing for this.
 */
final class AbilitiesRegistrar {

	/**
	 * The ability category these abilities belong to.
	 *
	 * @var string
	 */
	public const string CATEGORY = 'legacy-redirects';

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * The redirect fetcher.
	 *
	 * @var RedirectFetcher
	 */
	private RedirectFetcher $fetcher;

	/**
	 * The batch resolver.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

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
	 * Constructor.
	 *
	 * @param RedirectManager                  $manager          The redirect manager.
	 * @param RedirectFetcher                  $fetcher          The redirect fetcher.
	 * @param RedirectBatch                    $batch            The batch resolver.
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 */
	public function __construct(
		RedirectManager $manager,
		RedirectFetcher $fetcher,
		RedirectBatch $batch,
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor
	) {
		$this->manager          = $manager;
		$this->fetcher          = $fetcher;
		$this->batch            = $batch;
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Legacy Redirects', 'legacy-redirector' ),
				'description' => __( 'Abilities that inspect and manage redirects from legacy URLs.', 'legacy-redirector' ),
			)
		);
	}

	/**
	 * Register the abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		foreach ( $this->abilities() as $ability ) {
			wp_register_ability( $ability->name(), $ability->args() );
		}
	}

	/**
	 * Build the abilities this plugin provides.
	 *
	 * @return AbilityInterface[] The abilities.
	 */
	public function abilities(): array {
		return array(
			new CreateRedirectAbility( $this->manager ),
			new GetRedirectAbility( $this->fetcher ),
			new ListRedirectsAbility( $this->query_repository ),
			new UpdateRedirectAbility( $this->manager, $this->batch ),
			new SetRedirectStatusAbility( $this->manager, $this->batch ),
			new DeleteRedirectAbility( $this->manager, $this->batch ),
			new ValidateRedirectsAbility( $this->query_repository, $this->auditor, $this->batch ),
			new FindRedirectDomainsAbility( $this->query_repository ),
		);
	}
}
