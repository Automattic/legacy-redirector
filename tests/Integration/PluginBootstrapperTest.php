<?php
/**
 * Plugin wiring integration tests.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\DI\Container;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ListScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ValidateAllButton;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\DuplicateSourcesNotice;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\FormScreenSetup;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\TrashRedirectEnhancer;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper;

/**
 * Runs the plugin's own wiring, as a request of each kind would.
 *
 * The plugin loads before TestCase defines WP_ADMIN, so the admin
 * components are never registered for the other integration tests, which
 * build each component by hand. These tests run PluginBootstrapper::init()
 * again in each context and check what it hooks.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PluginBootstrapper
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\AdminBootstrapper
 * @uses \Automattic\LegacyRedirector\Infrastructure\DI\Container
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\AbilitiesRegistrar
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ListScreenSetup
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ValidateAllButton
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\MenuBadge
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\DuplicateSourcesNotice
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\UpgradeNotice
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\FormScreenSetup
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages\RedirectFormPage
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\StatusActionsHandler
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\TrashRedirectEnhancer
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditSweep
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\AbstractStatusCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\CreateCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\DeleteCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\FindDomainsCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\GetCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ImportFromMetaCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ListCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\MigrateCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\TestCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\UpdateCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ValidateCommand
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CoreEditGuard
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ChecksController
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Rest\ValidateAllController
 */
final class PluginBootstrapperTest extends TestCase {

	/**
	 * Run the plugin's wiring for the current request.
	 *
	 * @return PluginBootstrapper The bootstrapper that registered the hooks.
	 */
	private function boot(): PluginBootstrapper {
		$bootstrapper = new PluginBootstrapper( Container::instance() );
		$bootstrapper->init();

		return $bootstrapper;
	}

	/**
	 * The classes of the objects hooked to a hook, or to any hook.
	 *
	 * @param string|null $hook The hook, or null for every hook.
	 * @return string[] Class names.
	 */
	private function hooked_classes( ?string $hook = null ): array {
		$classes = array();

		foreach ( $GLOBALS['wp_filter'] as $name => $wp_hook ) {
			if ( null !== $hook && $hook !== $name ) {
				continue;
			}

			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) ) {
						$classes[] = get_class( $callback['function'][0] );
					}
				}
			}
		}

		return array_unique( $classes );
	}

	/**
	 * Test an admin request hooks each admin component where core calls it.
	 *
	 * A component left out of the wiring, or hooked under a misspelled name,
	 * would otherwise go unnoticed: its own tests call it directly.
	 *
	 * @dataProvider data_admin_components
	 *
	 * @param string $component The component's class.
	 * @param string $hook      A hook core fires that the component must be on.
	 */
	public function test_an_admin_request_hooks_each_admin_component( string $component, string $hook ): void {
		$this->assertTrue( is_admin() );

		$this->boot();

		$this->assertContains( $component, $this->hooked_classes( $hook ) );
	}

	/**
	 * Test a front-end request hooks no admin component, but does hook the redirect and the migration.
	 *
	 * Several admin components filter every query (pre_get_posts,
	 * posts_where) and every redirect (wp_redirect), so they must stay out of
	 * front-end requests.
	 */
	public function test_a_front_end_request_hooks_only_the_redirect_and_the_migration(): void {
		set_current_screen( 'front' );
		$this->assertFalse( is_admin() );

		$bootstrapper = $this->boot();

		$this->assertSame( array(), array_intersect( array_column( self::data_admin_components(), 0 ), $this->hooked_classes() ) );
		$this->assertSame( 0, has_action( 'template_redirect', array( $bootstrapper, 'maybe_do_redirect' ) ) );
		$this->assertSame( 20, has_action( 'init', array( $bootstrapper, 'maybe_upgrade' ) ) );
	}

	/**
	 * Data provider: each admin component, and a hook core fires that it must be on.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function data_admin_components(): array {
		return array(
			'list columns'            => array( ColumnsManager::class, 'manage_vip-legacy-redirect_posts_columns' ),
			'row actions'             => array( RowActionsManager::class, 'post_row_actions' ),
			'list views'              => array( ViewFilters::class, 'views_edit-vip-legacy-redirect' ),
			'list screen'             => array( ListScreenSetup::class, 'current_screen' ),
			'validate all button'     => array( ValidateAllButton::class, 'manage_posts_extra_tablenav' ),
			'form save'               => array( RedirectFormPage::class, 'admin_post_save_redirect' ),
			'form screen'             => array( FormScreenSetup::class, 'current_screen' ),
			'no-JS test action'       => array( ValidationNotices::class, 'admin_init' ),
			'menu badge'              => array( MenuBadge::class, 'admin_menu' ),
			'bulk actions'            => array( BulkActionsHandler::class, 'handle_bulk_actions-edit-vip-legacy-redirect' ),
			'enable link'             => array( StatusActionsHandler::class, 'admin_post_enable_redirect' ),
			'disable link'            => array( StatusActionsHandler::class, 'admin_post_disable_redirect' ),
			'status notices'          => array( StatusChangeNotices::class, 'admin_notices' ),
			'migration notice'        => array( UpgradeNotice::class, 'admin_notices' ),
			'duplicate source notice' => array( DuplicateSourcesNotice::class, 'admin_notices' ),
			'trash notice source'     => array( TrashRedirectEnhancer::class, 'wp_redirect' ),
		);
	}
}
