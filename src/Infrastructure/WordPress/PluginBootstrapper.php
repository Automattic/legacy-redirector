<?php
/**
 * Plugin bootstrapper.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Infrastructure\DI\Container;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\AdminBootstrapper;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\TrashRedirectEnhancer;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\CreateCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DisableCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\EnableCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\FindDomainsCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\GetCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromMetaCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ListCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\MigrateCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\RedirectorCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\UpdateCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand;

/**
 * Bootstraps the plugin by registering all hooks and initializing components.
 *
 * This class replaces the legacy WPCOM_Legacy_Redirector::start() method,
 * providing a cleaner separation of concerns with dedicated handler classes.
 */
final class PluginBootstrapper {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Initialize the plugin.
	 *
	 * Registers all hooks and initializes components.
	 *
	 * @return void
	 */
	public function init(): void {
		// Register post type on init.
		add_action( 'init', array( $this, 'register_post_type' ) );

		// Register capability on admin_init.
		add_action( 'admin_init', array( $this, 'register_capability' ) );

		// Migrate 1.x redirect data. Runs on init rather than admin_init
		// because the data it repairs is what serves front-end redirects, and
		// a site may go a long time between admin visits. Priority 20 so the
		// post type is registered (init, priority 10) before we query it.
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );

		// Register redirect handler on template_redirect (early, before canonical).
		add_filter( 'template_redirect', array( $this, 'maybe_do_redirect' ), 0 );

		// Initialize admin components. All of them hook admin-only surfaces
		// (admin screens, admin-post.php, admin-ajax.php — all define WP_ADMIN),
		// so skip registration entirely on the front end. This keeps the
		// global query hooks (pre_get_posts, posts_where, wp_redirect) out of
		// front-end requests.
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Register WP-CLI commands.
		$this->register_cli_commands();

		// Register Abilities API abilities (WordPress 6.9+).
		$this->register_abilities();
	}

	/**
	 * Register the custom post type for redirects.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$post_type = new PostType();
		$post_type->register();
	}

	/**
	 * Register capabilities for redirect management.
	 *
	 * @return void
	 */
	public function register_capability(): void {
		$capability = new Capability();
		$capability->register();
	}

	/**
	 * Migrate redirect data created by version 1.x, a batch at a time.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$this->container->upgrader()->maybe_upgrade();
	}

	/**
	 * Check for redirects and perform if needed.
	 *
	 * @return void
	 */
	public function maybe_do_redirect(): void {
		( new RedirectRequestHandler( $this->container->resolver() ) )->maybe_redirect();
	}

	/**
	 * Initialize admin components.
	 *
	 * @return void
	 */
	private function init_admin(): void {
		// Initialize the main admin bootstrapper (AJAX handlers, list table, form pages).
		$admin = new AdminBootstrapper(
			$this->container->repository(),
			$this->container->manager(),
			$this->container->validator(),
			$this->container->query_repository()
		);
		$admin->init();

		// Register bulk actions handler.
		$bulk_actions = new BulkActionsHandler( $this->container->manager() );
		$bulk_actions->register();

		// Register status actions handler (single enable/disable).
		$status_actions = new StatusActionsHandler( $this->container->manager() );
		$status_actions->register();

		// Register status change notices.
		$status_notices = new StatusChangeNotices();
		$status_notices->register();

		// Register trash redirect enhancer.
		$trash_enhancer = new TrashRedirectEnhancer();
		$trash_enhancer->register();
	}

	/**
	 * Register the plugin's abilities with the Abilities API.
	 *
	 * @return void
	 */
	private function register_abilities(): void {
		$abilities = new AbilitiesRegistrar(
			$this->container->manager(),
			$this->container->fetcher(),
			$this->container->query_repository(),
			$this->container->auditor()
		);
		$abilities->register();
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @return void
	 */
	private function register_cli_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$manager = $this->container->manager();
		$fetcher = $this->container->fetcher();

		// Register parent command for help text.
		\WP_CLI::add_command(
			'wpcom-legacy-redirector',
			RedirectorCommand::class
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector create',
			new CreateCommand( $manager )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector migrate',
			new MigrateCommand( $this->container->upgrader() )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector get',
			new GetCommand( $fetcher )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector list',
			new ListCommand( $this->container->query_repository() )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector update',
			new UpdateCommand( $manager, $fetcher )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector delete',
			new DeleteCommand( $manager, $fetcher )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector enable',
			new EnableCommand( $manager, $fetcher )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector disable',
			new DisableCommand( $manager, $fetcher )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector validate',
			new ValidateCommand(
				$fetcher,
				$this->container->query_repository(),
				$this->container->auditor(),
				$manager
			)
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector import',
			new ImportCommand( $manager )
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector import-from-meta',
			new ImportFromMetaCommand(
				$manager,
				$this->container->repository()
			)
		);

		\WP_CLI::add_command(
			'wpcom-legacy-redirector find-domains',
			new FindDomainsCommand( $this->container->query_repository() )
		);
	}
}
