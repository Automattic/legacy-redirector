<?php
/**
 * Admin surfaces for duplicate sources the 2.0 upgrade disabled.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\DuplicateSourcesNotice;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for how the admin shows, and explains, disabled duplicate sources.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\DuplicateSourcesNotice
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::duplicate_count
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 */
final class DuplicateSourcesAdminTest extends TestCase {

	/**
	 * The live redirect.
	 *
	 * @var int
	 */
	private int $live_id;

	/**
	 * The duplicate the upgrade disabled.
	 *
	 * @var int
	 */
	private int $duplicate_id;

	/**
	 * Set up a live redirect and a disabled duplicate of it, as the upgrade leaves them.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Capability() )->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array();

		$this->live_id      = $this->create_redirect( '/clash', 'https://example.com/one' );
		$this->duplicate_id = $this->insert_redirect_post(
			array(
				'post_title'   => '/clash/',
				'post_name'    => md5( '/clash/' ),
				'post_excerpt' => 'https://example.com/two',
				'post_status'  => 'draft',
			)
		);
		update_post_meta( $this->duplicate_id, Upgrader::DUPLICATE_META_KEY, $this->live_id );
	}

	/**
	 * Clean up the request and the current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET = array();
		wp_set_current_user( 0 );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Test the Duplicate sources view appears, counted, while any remain.
	 */
	public function test_duplicate_sources_view_is_counted(): void {
		$views = ( new ViewFilters( $this->query_repository(), new AuditFlags() ) )->customize_views( array() );

		$this->assertArrayHasKey( 'duplicate_sources', $views );
		$this->assertStringContainsString( 'Duplicate sources <span class="count">(1)</span>', $views['duplicate_sources'] );

		delete_post_meta( $this->duplicate_id, Upgrader::DUPLICATE_META_KEY );
		$views = ( new ViewFilters( $this->query_repository(), new AuditFlags() ) )->customize_views( array() );

		$this->assertArrayNotHasKey( 'duplicate_sources', $views );
	}

	/**
	 * Test the Status column explains the duplicate and links to the live redirect.
	 */
	public function test_status_column_explains_how_to_settle_it(): void {
		$output = $this->status_column( $this->duplicate_id );

		$this->assertStringContainsString( 'Duplicate source:', $output );
		$this->assertStringContainsString( 'redirect_id=' . $this->live_id, $output );
		$this->assertStringContainsString( 'To keep that destination, trash this one.', $output );

		update_post_meta( $this->duplicate_id, Upgrader::NEVER_FIRED_META_KEY, 1 );

		$this->assertStringContainsString( 'This one never fired under 1.x', $this->status_column( $this->duplicate_id ) );
		$this->assertStringNotContainsString( 'Duplicate source:', $this->status_column( $this->live_id ) );
	}

	/**
	 * Test a disabled duplicate offers no Enable action, which would be refused.
	 */
	public function test_duplicate_has_no_enable_action(): void {
		$actions = new RowActionsManager( $this->repository() );

		$this->assertArrayNotHasKey( 'enable', $actions->modify_row_actions( array(), get_post( $this->duplicate_id ) ) );

		delete_post_meta( $this->duplicate_id, Upgrader::DUPLICATE_META_KEY );

		$this->assertArrayHasKey( 'enable', $actions->modify_row_actions( array(), get_post( $this->duplicate_id ) ) );
	}

	/**
	 * Test the notice links to the view, and on the view gives the choices.
	 */
	public function test_notice_links_to_the_view_then_explains_the_choices(): void {
		set_current_screen( 'edit-' . PostType::POST_TYPE );
		$notice = new DuplicateSourcesNotice();

		$output = $this->capture( array( $notice, 'display' ) );
		$this->assertStringContainsString( 'The 2.0 upgrade disabled 1 redirect because it has the same source as another redirect', $output );
		$this->assertStringContainsString( 'duplicate_sources=1', $output );

		$_GET['duplicate_sources'] = '1';
		$output                    = $this->capture( array( $notice, 'display' ) );
		$this->assertStringContainsString( "To keep the live redirect's, trash the disabled one.", $output );
	}

	/**
	 * Render the Status column for a redirect.
	 *
	 * @param int $post_id The redirect.
	 * @return string The column's HTML.
	 */
	private function status_column( int $post_id ): string {
		$columns = new ColumnsManager( $this->repository(), $this->auditor() );

		return $this->capture( static fn() => $columns->render_column( 'status', $post_id ) );
	}

	/**
	 * Capture what a callback prints.
	 *
	 * @param callable $callback The callback.
	 * @return string The output.
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}
}
