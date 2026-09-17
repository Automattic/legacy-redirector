<?php
/**
 * Admin UI tests
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Admin UI tests class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 */
final class UITest extends TestCase {

	/**
	 * Instance of ViewFilters.
	 *
	 * @var ViewFilters
	 */
	private ViewFilters $view_filters;

	/**
	 * Instance of ValidationNotices.
	 *
	 * @var ValidationNotices
	 */
	private ValidationNotices $notices;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->view_filters = new ViewFilters( $this->query_repository() );

		$this->notices = new ValidationNotices(
			$this->repository(),
			$this->validator()
		);

		// Register capabilities for tests.
		$capability = new Capability();
		$capability->register();

		// Validation notices are only rendered for users who manage redirects.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		// Clean up superglobals.
		$_POST = array();
		$_GET  = array();

		wp_set_current_user( 0 );

		// Unregister capabilities.
		( new Capability() )->unregister();

		parent::tear_down();
	}

	/**
	 * Test add_removable_args adds expected query args.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::add_removable_args
	 */
	public function test_add_removable_arg_adds_expected_args(): void {
		$initial_args = array( 'existing_arg' );
		$result       = $this->notices->add_removable_args( $initial_args );

		$this->assertContains( 'existing_arg', $result );
		$this->assertContains( 'validate', $result );
		$this->assertContains( 'ids', $result );
	}

	/**
	 * Test customize_views renames statuses.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::customize_views
	 */
	public function test_vip_redirects_custom_post_status_filters_renames_statuses(): void {
		$views = array(
			'all'     => '<a href="#">All</a>',
			'publish' => '<a href="#">Published (5)</a>',
			'draft'   => '<a href="#">Draft (1)</a>',
			'trash'   => '<a href="#">Trash</a>',
		);

		$result = $this->view_filters->customize_views( $views );

		$this->assertArrayHasKey( 'all', $result );
		$this->assertArrayHasKey( 'publish', $result );
		$this->assertArrayHasKey( 'draft', $result );
		$this->assertArrayHasKey( 'trash', $result );

		// Published should be renamed to Enabled.
		$this->assertStringContainsString( 'Enabled', $result['publish'] );
		$this->assertStringNotContainsString( 'Published', $result['publish'] );

		// Draft should be renamed to Disabled.
		$this->assertStringContainsString( 'Disabled', $result['draft'] );
		$this->assertStringNotContainsString( 'Draft', $result['draft'] );
	}

	/**
	 * Test display_validation_notices outputs correct notice for invalid validation.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_shows_invalid_notice(): void {
		$_GET['validate'] = 'invalid';

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'not valid', $output );
		$this->assertStringContainsString( 'site-relative path', $output );
	}

	/**
	 * Test display_validation_notices names the filter when the host is not allowed.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_shows_host_not_allowed_notice(): void {
		$_GET['validate'] = 'host-not-allowed';

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'not valid', $output );
		$this->assertStringContainsString( 'allowed_redirect_hosts', $output );
	}

	/**
	 * Test display_validation_notices outputs correct notice for 404.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_shows_404_notice(): void {
		$_GET['validate'] = '404';

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( '404', $output );
	}

	/**
	 * Test display_validation_notices outputs correct notice for valid redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_shows_valid_notice(): void {
		$_GET['validate'] = 'valid';

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'valid', $output );
	}

	/**
	 * Test display_validation_notices outputs correct notice for private.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_shows_private_notice(): void {
		$_GET['validate'] = 'private';

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'not publicly accessible', $output );
	}

	/**
	 * Test display_validation_notices outputs correct notice for null post.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_shows_null_notice(): void {
		$_GET['validate'] = 'null';

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'does not exist', $output );
	}

	/**
	 * Test display_validation_notices outputs nothing when no validate param.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\ValidationNotices::display_validation_notices
	 */
	public function test_validate_redirects_notices_outputs_nothing_without_param(): void {
		unset( $_GET['validate'] );

		ob_start();
		$this->notices->display_validation_notices();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test customize_views adds destination type filters with counts.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::customize_views
	 */
	public function test_vip_redirects_custom_post_status_filters_adds_destination_type_filters(): void {
		// Clean up any existing redirects from other tests.
		$this->delete_all_redirects();

		// Create a destination post for post_id redirects.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Destination Post',
			)
		);

		// Create redirect to post ID (post_parent > 0, empty post_excerpt).
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-post',
				'post_parent'  => $destination_post_id,
				'post_excerpt' => '', // Explicitly empty to indicate post ID redirect.
			)
		);

		// Create redirect to internal path (post_excerpt contains relative path starting with /).
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-path',
				'post_excerpt' => '/some-internal-path',
			)
		);

		// Create redirect to external URL.
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-external',
				'post_excerpt' => 'https://external-site.com/page',
			)
		);

		$views = array(
			'all'     => '<a href="#">All</a>',
			'publish' => '<a href="#">Published (3)</a>',
		);

		$result = $this->view_filters->customize_views( $views );

		// Should have "To ID" filter.
		$this->assertArrayHasKey( 'to_id', $result );
		$this->assertStringContainsString( 'To ID', $result['to_id'] );
		$this->assertStringContainsString( 'destination_type=post_id', $result['to_id'] );
		$this->assertStringContainsString( '(1)', $result['to_id'] );

		// Should have "To Path" filter.
		$this->assertArrayHasKey( 'to_path', $result );
		$this->assertStringContainsString( 'To Path', $result['to_path'] );
		$this->assertStringContainsString( 'destination_type=path', $result['to_path'] );
		// Count should be 1 (only internal path starting with /).
		$this->assertStringContainsString( '(1)', $result['to_path'] );

		// Should have "To External" filter.
		$this->assertArrayHasKey( 'to_external', $result );
		$this->assertStringContainsString( 'To External', $result['to_external'] );
		$this->assertStringContainsString( 'destination_type=external', $result['to_external'] );
		$this->assertStringContainsString( '(1)', $result['to_external'] );
	}

	/**
	 * Helper method to delete all redirect posts.
	 *
	 * @return void
	 */
	private function delete_all_redirects(): void {
		$redirects = get_posts(
			array(
				'post_type'      => PostType::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);

		foreach ( $redirects as $redirect_id ) {
			wp_delete_post( $redirect_id, true );
		}
	}

	/**
	 * Test destination type filters are not shown when counts are zero.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::customize_views
	 */
	public function test_vip_redirects_custom_post_status_filters_hides_empty_destination_types(): void {
		// Clean up any existing redirects from other tests.
		$this->delete_all_redirects();

		// Don't create any redirects - all counts should be zero.
		$views = array(
			'all' => '<a href="#">All</a>',
		);

		$result = $this->view_filters->customize_views( $views );

		// None of the destination type filters should be present.
		$this->assertArrayNotHasKey( 'to_id', $result );
		$this->assertArrayNotHasKey( 'to_path', $result );
		$this->assertArrayNotHasKey( 'to_external', $result );
	}

	/**
	 * Test destination type filter shows "current" class when active.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::customize_views
	 */
	public function test_vip_redirects_custom_post_status_filters_marks_current_destination_type(): void {
		// Create a redirect to external URL.
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-external',
				'post_excerpt' => 'https://external-site.com/page',
			)
		);

		// Set the current destination type filter.
		$_GET['destination_type'] = 'external';

		$views  = array( 'all' => '<a href="#">All</a>' );
		$result = $this->view_filters->customize_views( $views );

		// External filter should have "current" class.
		$this->assertArrayHasKey( 'to_external', $result );
		$this->assertStringContainsString( 'class="current"', $result['to_external'] );
	}

	/**
	 * Test the post_id destination filter returns only post-ID redirects.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::add_destination_type_where_clause
	 */
	public function test_filter_by_destination_type_sets_query_for_post_id(): void {
		// Set up test data.
		$this->delete_all_redirects();

		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Destination Post',
			)
		);

		// Create redirect to post ID.
		$post_id_redirect = self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-post',
				'post_parent'  => $destination_post_id,
				'post_excerpt' => '',
			)
		);

		// Create redirect to URL (should not be returned).
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-url',
				'post_excerpt' => 'https://example.com/page',
			)
		);

		// Add the post_id filter via posts_where.
		$this->view_filters->register();

		// Add a filter to set the query var at the right time (before posts_where).
		$set_filter_var = function ( \WP_Query $query ) {
			if ( PostType::POST_TYPE === $query->get( 'post_type' ) ) {
				$query->query_vars['legacy_redirector_destination_type'] = 'post_id';
			}
		};
		add_action( 'pre_get_posts', $set_filter_var, 1 );

		$query = new \WP_Query(
			array(
				'post_type' => PostType::POST_TYPE,
			)
		);

		remove_action( 'pre_get_posts', $set_filter_var, 1 );

		// Should only return the post ID redirect.
		$this->assertCount( 1, $query->posts );
		$this->assertEquals( $post_id_redirect, $query->posts[0]->ID );
	}

	/**
	 * Test add_destination_type_where_clause filters internal path redirects correctly.
	 *
	 * Tests the path filtering by directly querying with the filter applied.
	 * Path filter includes: relative paths starting with / OR URLs containing home host.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::add_destination_type_where_clause
	 */
	public function test_path_filter_returns_only_internal_redirects(): void {
		$this->delete_all_redirects();

		// Create destination post.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Destination Post',
			)
		);

		// Create redirect to post ID (no post_excerpt, should not be returned).
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-post',
				'post_parent'  => $destination_post_id,
				'post_excerpt' => '',
			)
		);

		// Create redirect to internal relative path (should be returned).
		$path_redirect = self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-path',
				'post_excerpt' => '/some-internal-page',
			)
		);

		// Create redirect to external URL (should not be returned).
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-external',
				'post_excerpt' => 'https://external-domain.com/page',
			)
		);

		// Add the path filter via posts_where.
		$this->view_filters->register();

		// Add a filter to set the query var at the right time (before posts_where).
		$set_filter_var = function ( \WP_Query $query ) {
			if ( PostType::POST_TYPE === $query->get( 'post_type' ) ) {
				$query->query_vars['legacy_redirector_destination_type'] = 'path';
			}
		};
		add_action( 'pre_get_posts', $set_filter_var, 1 );

		$query = new \WP_Query(
			array(
				'post_type' => PostType::POST_TYPE,
			)
		);

		remove_action( 'pre_get_posts', $set_filter_var, 1 );

		// Should only return the internal path redirect.
		$this->assertCount( 1, $query->posts );
		$this->assertEquals( $path_redirect, $query->posts[0]->ID );
	}

	/**
	 * Test add_destination_type_where_clause filters external redirects correctly.
	 *
	 * Tests the external filtering by directly querying with the filter applied.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::add_destination_type_where_clause
	 */
	public function test_external_filter_returns_only_external_urls(): void {
		$this->delete_all_redirects();

		// Create redirect to internal URL. Internal destinations are stored
		// relative by construction (absolute forms are normalized on save).
		self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-internal',
				'post_excerpt' => '/internal-page',
			)
		);

		// Create redirect to external URL.
		$external_redirect = self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-external',
				'post_excerpt' => 'https://external-domain.com/page',
			)
		);

		// An external URL that merely mentions the home host must still count
		// as external; the old NOT LIKE predicate misclassified it.
		$home_host          = wp_parse_url( home_url(), PHP_URL_HOST );
		$lookalike_redirect = self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-lookalike',
				'post_excerpt' => 'https://external-domain.com/?ref=' . $home_host,
			)
		);

		// Add the external filter via posts_where.
		$this->view_filters->register();

		// Add a filter to set the query var at the right time (before posts_where).
		$set_filter_var = function ( \WP_Query $query ) {
			if ( PostType::POST_TYPE === $query->get( 'post_type' ) ) {
				$query->query_vars['legacy_redirector_destination_type'] = 'external';
			}
		};
		add_action( 'pre_get_posts', $set_filter_var, 1 );

		$query = new \WP_Query(
			array(
				'post_type' => PostType::POST_TYPE,
			)
		);

		remove_action( 'pre_get_posts', $set_filter_var, 1 );

		// Should return both external redirects and not the internal one.
		$this->assertCount( 2, $query->posts );
		$returned_ids = wp_list_pluck( $query->posts, 'ID' );
		$this->assertContains( $external_redirect, $returned_ids );
		$this->assertContains( $lookalike_redirect, $returned_ids );
	}

	/**
	 * Test filter_by_destination_type does nothing for non-admin queries.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::filter_by_destination_type
	 */
	public function test_filter_by_destination_type_ignores_non_admin_queries(): void {
		// Clean up any existing redirects from other tests.
		$this->delete_all_redirects();

		// Create redirect to URL.
		$url_redirect = self::factory()->post->create(
			array(
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => '/redirect-to-url',
				'post_excerpt' => 'https://example.com/page',
			)
		);

		// Create redirect to post ID.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
			)
		);
		$post_id_redirect    = self::factory()->post->create(
			array(
				'post_type'   => PostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '/redirect-to-post',
				'post_parent' => $destination_post_id,
			)
		);

		// Set the filter (but we're not in admin context).
		$_GET['destination_type'] = 'url';

		// Simulate non-admin by using a non-main query.
		$query = new \WP_Query(
			array(
				'post_type' => PostType::POST_TYPE,
			)
		);

		// Filter should not apply to non-main queries, so both redirects returned.
		// Note: In integration tests, is_admin() returns true due to WP_ADMIN constant.
		// The filter also checks is_main_query() which is false for this query.
		$this->assertCount( 2, $query->posts );
	}

	/**
	 * Test filter_by_destination_type does nothing for other post types.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::filter_by_destination_type
	 */
	public function test_filter_by_destination_type_ignores_other_post_types(): void {
		// Create a regular post with unique title for identification.
		$unique_title = 'Regular Post for filter test ' . uniqid();
		$regular_post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => $unique_title,
			)
		);

		// Set destination type filter.
		$_GET['destination_type'] = 'url';

		// Initialize the UI hooks.
		$this->view_filters->register();

		// Query for the specific regular post by ID.
		$query = new \WP_Query(
			array(
				'post_type' => 'post',
				'p'         => $regular_post,
			)
		);

		// Should return the regular post unaffected by the destination_type filter.
		$this->assertCount( 1, $query->posts );
		$this->assertEquals( $regular_post, $query->posts[0]->ID );
	}

	/**
	 * Test add_destination_type_where_clause adds correct WHERE clause for path filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::add_destination_type_where_clause
	 */
	public function test_filter_external_redirects_where_adds_path_clause(): void {
		// Create query, parse basic args, then use set() to add the filter flag.
		$query = new \WP_Query();
		$query->parse_query( array() );
		$query->set( 'legacy_redirector_destination_type', 'path' );

		$where  = " AND wp_posts.post_type = 'vip-legacy-redirect'";
		$result = $this->view_filters->add_destination_type_where_clause( $where, $query );

		// Verify the flag was set.
		$this->assertSame( 'path', $query->get( 'legacy_redirector_destination_type' ), 'Query var should be set' );

		// Verify the WHERE clause filters for paths starting with /.
		$this->assertStringContainsString( 'post_excerpt LIKE', $result, 'WHERE clause should filter for paths' );
	}

	/**
	 * Test add_destination_type_where_clause adds correct WHERE clause for external filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::add_destination_type_where_clause
	 */
	public function test_filter_external_redirects_where_adds_external_clause(): void {
		// Create and initialize query object with parse_query, then set the filter flag.
		$query = new \WP_Query();
		$query->parse_query( array( 'legacy_redirector_destination_type' => 'external' ) );

		$where  = " AND wp_posts.post_type = 'vip-legacy-redirect'";
		$result = $this->view_filters->add_destination_type_where_clause( $where, $query );

		// Should filter for URLs starting with http (wpdb->prepare escapes % to a hash).
		// Check the clause structure is present.
		$this->assertStringContainsString( 'post_excerpt LIKE', $result );
		$this->assertStringContainsString( 'http', $result );

		// Internal destinations are stored relative by construction, so there
		// is no host exclusion any more.
		$this->assertStringNotContainsString( 'NOT LIKE', $result );
	}

	/**
	 * Test add_destination_type_where_clause does nothing without filter flags.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::add_destination_type_where_clause
	 */
	public function test_filter_external_redirects_where_unchanged_without_flags(): void {
		$query = new \WP_Query();

		$where  = " AND wp_posts.post_type = 'vip-legacy-redirect'";
		$result = $this->view_filters->add_destination_type_where_clause( $where, $query );

		// Should be unchanged.
		$this->assertSame( $where, $result );
	}

	/**
	 * Test customize_views removes mine filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ViewFilters::customize_views
	 */
	public function test_vip_redirects_custom_post_status_filters_removes_mine(): void {
		$views = array(
			'all'  => '<a href="#">All</a>',
			'mine' => '<a href="#">Mine (5)</a>',
		);

		$result = $this->view_filters->customize_views( $views );

		$this->assertArrayNotHasKey( 'mine', $result );
		$this->assertArrayHasKey( 'all', $result );
	}
}
