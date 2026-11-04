<?php
/**
 * BulkActionsHandler integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for the actions offered in the bulk actions menu.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector::__construct
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor::__construct
 * @uses \Automattic\LegacyRedirector\Application\RedirectBatch::__construct
 * @uses \Automattic\LegacyRedirector\Application\RedirectFetcher::__construct
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager::__construct
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator::__construct
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository::__construct
 */
final class BulkActionsHandlerTest extends TestCase {

	/**
	 * Test the Trash view does not offer Test, which needs a row's Test link.
	 *
	 * Trashed rows have no such link, so the script posted to
	 * /redirects/undefined/test and reported the route's 404 as the result.
	 */
	public function test_trash_view_does_not_offer_test(): void {
		$_GET['post_status'] = 'trash';

		$actions = $this->bulk_actions();

		$this->assertArrayNotHasKey( 'test_redirects', $actions );
		$this->assertArrayHasKey( 'untrash', $actions );
	}

	/**
	 * Test every other view offers Test.
	 *
	 * @dataProvider data_views_with_test_links
	 *
	 * @param array<string, string> $query The view's query arguments.
	 */
	public function test_other_views_offer_test( array $query ): void {
		$_GET = $query;

		$this->assertArrayHasKey( 'test_redirects', $this->bulk_actions() );
	}

	/**
	 * Data provider for views whose rows have a Test link.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function data_views_with_test_links(): array {
		return array(
			'all'      => array( array() ),
			'enabled'  => array( array( 'post_status' => 'publish' ) ),
			'disabled' => array( array( 'post_status' => 'draft' ) ),
		);
	}

	/**
	 * Get the bulk actions for the current request.
	 *
	 * @return array<string, string> The bulk actions.
	 */
	private function bulk_actions(): array {
		$handler = new BulkActionsHandler( $this->manager(), new RedirectBatch( new RedirectFetcher( $this->repository() ) ) );

		return $handler->modify_bulk_actions(
			array(
				'untrash' => 'Restore',
				'delete'  => 'Delete permanently',
			)
		);
	}
}
