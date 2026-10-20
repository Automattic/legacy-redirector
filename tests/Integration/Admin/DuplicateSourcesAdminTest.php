<?php
/**
 * Admin surfaces for duplicate sources the 2.0 upgrade disabled.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
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
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\DuplicateSourcesNotice
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher
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
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::duplicate_count
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::hashed_text
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Application\ErrorText
 * @uses \Automattic\LegacyRedirector\Application\AmbiguousSourceException
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
		$this->assertStringContainsString( 'Duplicate source <span class="count">(1)</span>', $views['duplicate_sources'] );

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
		$this->assertStringContainsString( 'The 2.0 migration disabled 1 redirect because it has the same source as another redirect', $output );
		$this->assertStringContainsString( 'duplicate_sources=1', $output );

		$_GET['duplicate_sources'] = '1';
		$output                    = $this->capture( array( $notice, 'display' ) );
		$this->assertStringContainsString( "To keep the enabled redirect's, trash the disabled one.", $output );
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

	/**
	 * Run a bulk action and return the query arguments it sends back.
	 *
	 * @param string $action   The bulk action.
	 * @param int[]  $post_ids The selected redirects.
	 * @return array<string, string> The sendback query arguments.
	 */
	private function bulk( string $action, array $post_ids ): array {
		$handler  = new BulkActionsHandler( $this->manager(), new RedirectBatch( new RedirectFetcher( $this->repository() ) ) );
		$sendback = $handler->handle_bulk_actions( admin_url( 'edit.php?post_type=' . PostType::POST_TYPE ), $action, $post_ids );

		$query = array();
		parse_str( (string) wp_parse_url( $sendback, PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * Test a bulk enable that skips the duplicate says so, and why.
	 *
	 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException
	 */
	public function test_bulk_enable_reports_refused_duplicate(): void {
		$ordinary = $this->create_redirect( '/ordinary', 'https://example.com/three' );
		wp_update_post(
			array(
				'ID'          => $ordinary,
				'post_status' => 'draft',
			)
		);

		$query = $this->bulk( 'enable_redirects', array( $this->duplicate_id, $ordinary ) );

		$this->assertSame( '1', $query['bulk_redirects_enabled'] );
		$this->assertSame( '1', $query['bulk_redirects_failed'] );
		$this->assertSame( 'duplicate', $query['bulk_redirects_reason'] );
		$this->assertSame( 'draft', get_post_status( $this->duplicate_id ) );
		$this->assertSame( 'publish', get_post_status( $ordinary ) );
	}

	/**
	 * Test a bulk enable where every row succeeds carries no failure.
	 */
	public function test_bulk_enable_without_refusals_reports_none(): void {
		$query = $this->bulk( 'enable_redirects', array( $this->live_id ) );

		$this->assertSame( '1', $query['bulk_redirects_enabled'] );
		$this->assertArrayNotHasKey( 'bulk_redirects_failed', $query );
		$this->assertArrayNotHasKey( 'bulk_redirects_reason', $query );
	}
}
