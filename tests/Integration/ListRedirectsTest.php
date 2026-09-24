<?php
/**
 * ListTable tests
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * ListTable tests class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class ListRedirectsTest extends TestCase {

	/**
	 * Instance of ColumnsManager.
	 *
	 * @var ColumnsManager
	 */
	private ColumnsManager $columns_manager;

	/**
	 * Instance of RowActionsManager.
	 *
	 * @var RowActionsManager
	 */
	private RowActionsManager $row_actions_manager;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->columns_manager     = new ColumnsManager( $this->repository(), $this->auditor() );
		$this->row_actions_manager = new RowActionsManager( $this->repository() );
	}

	/**
	 * Test set_columns returns expected column keys.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::set_columns
	 */
	public function test_set_columns_returns_expected_columns(): void {
		$columns = $this->columns_manager->set_columns();

		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'cb', $columns );
		$this->assertArrayHasKey( 'from', $columns );
		$this->assertArrayHasKey( 'to', $columns );
		$this->assertArrayHasKey( 'health', $columns );
		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'date', $columns );
		$this->assertCount( 6, $columns );
	}

	/**
	 * Test set_columns returns translated labels.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::set_columns
	 */
	public function test_set_columns_returns_translated_labels(): void {
		$columns = $this->columns_manager->set_columns();

		$this->assertSame( 'Redirect From', $columns['from'] );
		$this->assertSame( 'Redirect To', $columns['to'] );
		$this->assertSame( 'Health', $columns['health'] );
		$this->assertSame( 'Date', $columns['date'] );
	}

	/**
	 * Test render_column displays from URL as link for 'from' column.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_displays_from_url(): void {
		$from_url = '/test-from-url';
		$to_url   = 'http://example.com/destination';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'from', $post_id );
		$output = ob_get_clean();

		// Output should be a bold link with the row-title class.
		$this->assertStringContainsString( '<strong>', $output );
		$this->assertStringContainsString( 'class="row-title"', $output );
		$this->assertStringContainsString( $from_url, $output );
		$this->assertStringContainsString( 'page=edit-redirect', $output );
		$this->assertStringContainsString( 'redirect_id=' . $post_id, $output );
	}

	/**
	 * Test render_column displays external URL for 'to' column.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_displays_external_to_url(): void {
		$from_url = '/test-from-external';
		$to_url   = 'http://example.com/external';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'to', $post_id );
		$output = ob_get_clean();

		// External URLs are bolded for consistency with the prefixed relative
		// paths they sit alongside, so they follow the same predicate.
		$expected = '' !== HomePath::current() ? '<strong>' . $to_url . '</strong>' : $to_url;
		$this->assertSame( $expected, $output );
	}

	/**
	 * Test render_column HTML-encodes ampersands in external URLs.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_encodes_ampersands_in_external_to_url(): void {
		$from_url = '/test-from-query-args';
		$to_url   = 'http://example.com/external?foo=1&bar=2';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'to', $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'foo=1&#038;bar=2', $output );
	}

	/**
	 * Test render_column displays relative path for 'to' column.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_displays_relative_to_url(): void {
		$from_url = '/test-from-relative';
		$to_url   = '/destination-path';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'to', $post_id );
		$output = ob_get_clean();

		$this->assertSame( $this->expected_relative_path_output( $to_url ), $output );
	}

	/**
	 * Test render_column displays home URL redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_displays_home_redirect(): void {
		$from_url = '/redirect-to-home';
		$to_url   = '/';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'to', $post_id );
		$output = ob_get_clean();

		$this->assertSame( $this->expected_relative_path_output( '/' ), $output );
	}

	/**
	 * Expected 'to' column output for a relative path.
	 *
	 * Where home is not the domain root the column prefixes the path with the
	 * site's home URL in gray; where home is the root the path is rendered
	 * as-is. Note this is not the same as "multisite": the network's root site
	 * has home at the domain root and so takes no prefix, while a single site
	 * installed at example.com/blog does.
	 *
	 * @param string $path The relative path.
	 * @return string Expected rendered output.
	 */
	private function expected_relative_path_output( string $path ): string {
		if ( '' === HomePath::current() ) {
			return $path;
		}

		return sprintf(
			'<span style="color: #888;">%s</span><strong>%s</strong>',
			untrailingslashit( home_url() ),
			$path
		);
	}

	/**
	 * Test render_column displays post parent redirect (internal).
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_displays_internal_post_redirect(): void {
		// Create a destination post.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'destination-post',
				'post_title'  => 'Destination Post',
			)
		);

		$from_url = '/redirect-to-internal-post';

		$post_id = $this->create_redirect( $from_url, $destination_post_id );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'to', $post_id );
		$output = ob_get_clean();

		// Should display a link to the destination post (plain or pretty permalink).
		// With plain permalinks it's /?p=X, with pretty permalinks it's /destination-post/.
		$this->assertTrue(
			str_contains( $output, 'destination-post' ) || str_contains( $output, '?p=' . $destination_post_id ),
			'Expected output to contain either "destination-post" or "?p=' . $destination_post_id . '", got: ' . $output
		);
	}

	/**
	 * Test render_column shows warning for private internal redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_shows_warning_for_private_internal_redirect(): void {
		// Create a draft destination post.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_name'   => 'private-destination',
				'post_title'  => 'Private Destination',
			)
		);

		$from_url = '/redirect-to-private';

		$post_id = $this->create_redirect( $from_url, $destination_post_id );
		$this->assertIsInt( $post_id );

		ob_start();
		$this->columns_manager->render_column( 'to', $post_id );
		$to_output = ob_get_clean();

		ob_start();
		$this->columns_manager->render_column( 'health', $post_id );
		$health_output = ob_get_clean();

		// The To column stays a stable read of where the redirect points; the
		// finding lives in the Health column, as a problem: an unpublished
		// destination is what the write gate refuses.
		$this->assertStringNotContainsString( 'Post not published', $to_output );
		$this->assertStringContainsString( 'Problem', $health_output );
		$this->assertStringContainsString( 'Post not published', $health_output );
	}

	/**
	 * Test render_column shows no warning for an attachment destination.
	 *
	 * Attachments carry post_status 'inherit', never 'publish', so reading the
	 * raw property marks every media destination as private.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_shows_no_warning_for_attachment_destination(): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'brochure.pdf',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Brochure',
			)
		);

		$post_id = $this->create_redirect( '/redirect-to-attachment', $attachment_id );

		ob_start();
		$this->columns_manager->render_column( 'health', $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No issues found', $output );
	}

	/**
	 * Test render_column shows error for nonexistent post parent.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_posts_custom_column_shows_error_for_nonexistent_post_parent(): void {
		// Create redirect post directly to simulate orphaned redirect.
		// Must set post_excerpt to empty string explicitly to simulate internal redirect.
		$redirect_post_id = self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_name'    => SourceUrl::from_string( '/orphaned-redirect' )->hash(),
				'post_title'   => '/orphaned-redirect',
				'post_parent'  => 999999999, // Nonexistent post ID.
				'post_excerpt' => '', // Empty excerpt = internal redirect via post_parent.
				'post_content' => '', // Empty content to prevent auto-excerpt.
			)
		);

		ob_start();
		$this->columns_manager->render_column( 'to', $redirect_post_id );
		$to_output = ob_get_clean();

		ob_start();
		$this->columns_manager->render_column( 'health', $redirect_post_id );
		$health_output = ob_get_clean();

		// The To column names the post that was pointed at; the Health column
		// carries the finding.
		$this->assertStringContainsString( 'Post 999999999', $to_output );
		$this->assertStringContainsString( 'Problem', $health_output );
		$this->assertStringContainsString( 'Post deleted', $health_output );
	}

	/**
	 * Test a destination only HTTP can judge is not shown as a clean pass.
	 *
	 * A relative path with no post behind it is indeterminate without a
	 * request, so the Health column must say "not fully checked" rather than
	 * showing the same tick as a conclusively healthy row.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_health_column_marks_http_only_destinations_as_not_fully_checked(): void {
		$post_id = $this->create_redirect( '/needs-http-check', '/definitely-not-here' );

		ob_start();
		$this->columns_manager->render_column( 'health', $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Not fully checked', $output );
		$this->assertStringNotContainsString( 'No issues found', $output );
	}

	/**
	 * Test a destination reachable through a redirect chain is conclusive.
	 *
	 * The destination has no post behind it, but it is another redirect's
	 * source, and that chain ends at published content - as good as pointing
	 * at the content directly, so no grey "not fully checked".
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_health_column_follows_chains_to_published_content(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'chain-landing',
			)
		);
		$start = $this->create_redirect( '/chain-start', '/chain-middle' );
		$this->create_redirect( '/chain-middle', '/chain-landing' );

		ob_start();
		$this->columns_manager->render_column( 'health', $start );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No issues found', $output );
		$this->assertStringNotContainsString( 'Not fully checked', $output );
	}

	/**
	 * Test a redirect to the home path is conclusive.
	 *
	 * The front controller never 404s '/', so landing there needs no HTTP
	 * request to judge.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_health_column_treats_home_destination_as_conclusive(): void {
		$post_id = $this->create_redirect( '/to-home', '/' );

		ob_start();
		$this->columns_manager->render_column( 'health', $post_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No issues found', $output );
	}

	/**
	 * Test pagination is deterministic when many rows share one timestamp.
	 *
	 * An import creates hundreds of rows in the same second; without an ID
	 * tiebreaker the database orders the ties arbitrarily per query, so
	 * paginating could show one row twice and another never.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::handle_sorting
	 */
	public function test_pagination_covers_every_row_despite_identical_dates(): void {
		$ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$redirect_id = $this->create_redirect( "/same-second-$i", '/target' );
			wp_update_post(
				array(
					'ID'            => $redirect_id,
					'post_date'     => '2026-01-01 00:00:00',
					'post_date_gmt' => '2026-01-01 00:00:00',
					'edit_date'     => true,
				)
			);
			$ids[] = $redirect_id;
		}

		$this->columns_manager->register();

		$seen = array();
		for ( $page = 1; $page <= 5; $page++ ) {
			$query = new \WP_Query();
			// Mark it as the main query so handle_sorting applies; the shared
			// TestCase already defines WP_ADMIN for is_admin().
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored by the test framework; main-query semantics need the real global.
			$GLOBALS['wp_the_query'] = $query;

			$query->query(
				array(
					'post_type'      => PostType::POST_TYPE,
					'posts_per_page' => 1,
					'paged'          => $page,
					'fields'         => 'ids',
				)
			);

			$seen = array_merge( $seen, $query->posts );
		}

		$this->assertSame( array(), array_diff( $ids, $seen ), 'Every same-second row must appear on exactly one page.' );
		$this->assertSame( $seen, array_unique( $seen ), 'No row may appear on two pages.' );
	}

	/**
	 * Test modify_row_actions adds validate and follow links.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager::modify_row_actions
	 */
	public function test_modify_list_row_actions_adds_custom_actions(): void {
		// Register capabilities first.
		( new Capability() )->register();

		// Create an admin user with the capability.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Force refresh the user's capabilities.
		$user = wp_get_current_user();
		$user->get_role_caps();

		// Verify the user has the capability.
		$this->assertTrue(
			current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ),
			'User should have manage_redirects capability'
		);

		$from_url = '/test-row-actions';
		$to_url   = '/internal-destination'; // Use internal URL to avoid validation errors.

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id, 'Should create redirect successfully' );

		$post = get_post( $post_id );

		$default_actions = array(
			'edit'  => '<a href="#">Edit</a>',
			'trash' => '<a href="#">Trash</a>',
		);

		$actions = $this->row_actions_manager->modify_row_actions( $default_actions, $post );

		$this->assertArrayHasKey( 'validate', $actions, 'Actions should have validate key. Got: ' . wp_json_encode( array_keys( $actions ) ) );
		$this->assertArrayHasKey( 'follow', $actions );
		$this->assertArrayHasKey( 'trash', $actions );
		$this->assertArrayHasKey( 'edit', $actions ); // Edit action for editing redirects.

		// Validate link should have PHP fallback URL and AJAX data attributes.
		$this->assertStringContainsString( 'action=validate', $actions['validate'] );
		$this->assertStringContainsString( '_validate_redirect', $actions['validate'] );
		$this->assertStringContainsString( 'class="validate-redirect"', $actions['validate'] );
		$this->assertStringContainsString( 'data-redirect-id="', $actions['validate'] );
		$this->assertStringContainsString( 'data-source="', $actions['validate'] );

		// Follow link should contain the from URL.
		$this->assertStringContainsString( $from_url, $actions['follow'] );
		$this->assertStringContainsString( 'target="_blank"', $actions['follow'] );
	}

	/**
	 * Test modify_row_actions returns unchanged actions for non-redirect post types.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager::modify_row_actions
	 */
	public function test_modify_list_row_actions_unchanged_for_other_post_types(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$post    = get_post( $post_id );

		$default_actions = array(
			'edit'  => '<a href="#">Edit</a>',
			'trash' => '<a href="#">Trash</a>',
		);

		$actions = $this->row_actions_manager->modify_row_actions( $default_actions, $post );

		$this->assertSame( $default_actions, $actions );
	}

	/**
	 * Test modify_row_actions preserves actions when in trash view.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\RowActionsManager::modify_row_actions
	 */
	public function test_modify_list_row_actions_preserves_trash_view_actions(): void {
		$_GET['post_status'] = 'trash';

		$from_url = '/trashed-redirect-row';
		$to_url   = 'http://example.com/';

		$post_id = $this->create_redirect( $from_url, $to_url );
		wp_trash_post( $post_id );
		$post = get_post( $post_id );

		$default_actions = array(
			'untrash' => '<a href="#">Restore</a>',
			'delete'  => '<a href="#">Delete Permanently</a>',
		);

		$actions = $this->row_actions_manager->modify_row_actions( $default_actions, $post );

		$this->assertSame( $default_actions, $actions );

		unset( $_GET['post_status'] );
	}

	/**
	 * Test register method registers hooks.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::register
	 */
	public function test_init_registers_hooks(): void {
		// Remove any existing hooks first.
		remove_all_filters( 'manage_vip-legacy-redirect_posts_columns' );
		remove_all_actions( 'manage_vip-legacy-redirect_posts_custom_column' );
		remove_all_filters( 'post_row_actions' );

		$columns_manager     = new ColumnsManager( $this->repository(), $this->auditor() );
		$row_actions_manager = new RowActionsManager( $this->repository() );
		$columns_manager->register();
		$row_actions_manager->register();

		$this->assertNotFalse( has_filter( 'manage_vip-legacy-redirect_posts_columns', array( $columns_manager, 'set_columns' ) ) );
		$this->assertNotFalse( has_action( 'manage_vip-legacy-redirect_posts_custom_column', array( $columns_manager, 'render_column' ) ) );
		$this->assertNotFalse( has_filter( 'post_row_actions', array( $row_actions_manager, 'modify_row_actions' ) ) );
	}
}
