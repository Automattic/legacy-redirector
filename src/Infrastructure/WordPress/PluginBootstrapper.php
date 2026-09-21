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
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice;
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
	 * WP-CLI command namespace.
	 *
	 * @var string
	 */
	public const string CLI_NAMESPACE = 'legacy-redirector';

	/**
	 * WP-CLI command namespace used before 2.0.0, still registered as an alias.
	 *
	 * @var string
	 */
	public const string CLI_NAMESPACE_DEPRECATED = 'wpcom-legacy-redirector';

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

		// Keep the recorded audit summary fresh with a daily scheduled run.
		// Registered in every context: cron events fire wherever WP loads.
		$scheduler = new AuditScheduler(
			$this->container->query_repository(),
			$this->container->auditor(),
			$this->container->audit_results()
		);
		$scheduler->register();

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
			$this->container->query_repository(),
			$this->container->auditor(),
			$this->container->audit_results()
		);
		$admin->init();

		// The problem-count bubble on the Redirects menu.
		$menu_badge = new MenuBadge( $this->container->audit_results() );
		$menu_badge->register();

		// Register bulk actions handler.
		$bulk_actions = new BulkActionsHandler( $this->container->manager() );
		$bulk_actions->register();

		// Register status actions handler (single enable/disable).
		$status_actions = new StatusActionsHandler( $this->container->manager(), $this->container->repository() );
		$status_actions->register();

		// Register status change notices.
		$status_notices = new StatusChangeNotices();
		$status_notices->register();

		// Register the migration-in-progress notice.
		$upgrade_notice = new UpgradeNotice( $this->container->upgrader() );
		$upgrade_notice->register();

		// Register trash redirect enhancer.
		$trash_enhancer = new TrashRedirectEnhancer( $this->container->repository() );
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
			$this->container->batch(),
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
		$batch   = $this->container->batch();

		// The empty key is the parent command, registered for help text only.
		$commands = array(
			''                 => RedirectorCommand::class,
			'create'           => new CreateCommand( $manager, $this->container->auditor() ),
			'migrate'          => new MigrateCommand( $this->container->upgrader() ),
			'get'              => new GetCommand( $fetcher ),
			'list'             => new ListCommand( $this->container->query_repository() ),
			'update'           => new UpdateCommand( $manager, $batch ),
			'delete'           => new DeleteCommand( $manager, $batch ),
			'enable'           => new EnableCommand( $manager, $batch ),
			'disable'          => new DisableCommand( $manager, $batch ),
			'validate'         => new ValidateCommand(
				$batch,
				$this->container->query_repository(),
				$this->container->auditor(),
				$manager,
				$this->container->audit_results()
			),
			'import'           => new ImportCommand( $manager, $this->container->auditor() ),
			'import-from-meta' => new ImportFromMetaCommand(
				$manager,
				$this->container->repository()
			),
			'find-domains'     => new FindDomainsCommand( $this->container->query_repository() ),
		);

		foreach ( $commands as $subcommand => $command ) {
			\WP_CLI::add_command( rtrim( self::CLI_NAMESPACE . ' ' . $subcommand ), $command );

			// Only commands that existed before 2.0 are aliased, so the old
			// namespace never gains commands nobody could have scripted.
			if ( ! in_array( $subcommand, array( '', 'find-domains', 'import-from-meta' ), true ) ) {
				continue;
			}

			// The 1.x command namespace still works, so existing runbooks and
			// deploy scripts do not break on upgrade. WP_CLI::warning() writes
			// to STDERR, leaving piped --porcelain and --format output intact.
			//
			// The parent command gets no before_invoke: WP-CLI runs a parent's
			// hook on the way down to a subcommand, so attaching it there warns
			// twice per invocation.
			$args = array();

			if ( '' !== $subcommand ) {
				$args['before_invoke'] = static function () use ( $subcommand ): void {
					\WP_CLI::warning(
						sprintf(
							'`wp %1$s %3$s` is deprecated since 2.0.0. Use `wp %2$s %3$s` instead.',
							self::CLI_NAMESPACE_DEPRECATED,
							self::CLI_NAMESPACE,
							$subcommand
						)
					);
				};
			}

			\WP_CLI::add_command(
				rtrim( self::CLI_NAMESPACE_DEPRECATED . ' ' . $subcommand ),
				$command,
				$args
			);
		}

		// Commands removed in the 2.0 redesign stay registered under the old
		// namespace only to fail with the replacement, rather than WP-CLI's
		// generic "not a registered subcommand" error.
		$removed = array(
			'insert-redirect' => 'create <from> <to>',
			'import-from-csv' => 'import <file>',
			'export-to-csv'   => 'list --format=csv',
		);

		foreach ( $removed as $subcommand => $replacement ) {
			\WP_CLI::add_command(
				self::CLI_NAMESPACE_DEPRECATED . ' ' . $subcommand,
				static function () use ( $subcommand, $replacement ): void {
					\WP_CLI::error(
						sprintf(
							'`wp %1$s %2$s` was removed in 2.0.0. Use `wp %3$s %4$s` instead. See UPGRADING.md for the flags that changed.',
							self::CLI_NAMESPACE_DEPRECATED,
							$subcommand,
							self::CLI_NAMESPACE,
							$replacement
						)
					);
				},
				array( 'shortdesc' => sprintf( 'Removed in 2.0.0. Use `%s` instead.', strtok( $replacement, ' ' ) ) )
			);
		}
	}
}
