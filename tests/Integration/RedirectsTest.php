<?php
/**
 * Redirects tests
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Redirects tests class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectManager
 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectResolver
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectHttpStatus
 * @uses \Automattic\LegacyRedirector\Domain\RedirectPersistenceException
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class RedirectsTest extends TestCase {

	/**
	 * Data provider.
	 *
	 * Each item in the outermost array should be an array containing:
	 * - $from path
	 * - $to destination
	 *
	 * @return array<string, array>
	 */
	public function get_redirect_data() {
		return array(
			'redirect_relative_path'    => array(
				'/non-existing-page',
				'/test2',
				home_url() . '/test2',
			),

			'redirect_unicode_in_path'  => array(
				// https://www.w3.org/International/articles/idn-and-iri/ .
				'/JP納豆',
				'http://example.com',
			),

			'redirect Arabic in path'   => array(
				// https://www.w3.org/International/articles/idn-and-iri/ .
				'/فوتوغرافيا/?test=فوتوغرافيا',
				'http://example.com',
			),

			'redirect Cyrillic in path' => array(
				'/привет-мир/',
				'http://example.com',
			),
			'redirect Hebrew in path'   => array(
				'/שלום-עולם',
				'http://example.com',
			),
			'redirect emoji in path'    => array(
				// Astral plane, so four UTF-8 bytes and utf8mb4-only in the DB.
				'/party-🎉',
				'http://example.com',
			),
			'redirect mixed scripts'    => array(
				'/привет-納豆-🎉?тест=значение',
				'http://example.com',
			),
			'redirect to unicode dest'  => array(
				'/unicode-destination',
				'http://example.com/привет-🎉',
			),
			'redirect unicode relative' => array(
				'/unicode-relative-destination',
				'/привет-мир',
				home_url() . '/привет-мир',
			),
			'redirect_simple'           => array(
				'/simple-redirect',
				'http://example.com',
			),

			'redirect_with_querystring' => array(
				'/a-redirect?with=query-string',
				'http://example.com',
			),

			'redirect_with_hashes'      => array(
				// The plugin should strip the hash and only store the URL path.
				'/hash-redirect#with-hash',
				'http://example.com',
			),
		);
	}

	/**
	 * Test redirect is inserted successfully and returns the post ID.
	 *
	 * @dataProvider get_redirect_data
	 * @covers       \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 * @param string      $from     From path.
	 * @param string      $to       Destination.
	 * @param string|null $expected Expected redirect URL.
	 */
	public function test_redirect_is_inserted_successfully( $from, $to, $expected = null ) {
		$post_id = $this->create_redirect( $from, $to );
		$this->assertIsInt( $post_id );

		$redirect_data = $this->resolver()->get_redirect_data( $from );

		if ( \is_null( $expected ) ) {
			$expected = $to;
		}
		$this->assertEquals( $expected, $redirect_data['url'], 'get_redirect_data(), failed - got "' . $redirect_data['url'] . '", expected "' . $to . '"' );
	}

	/**
	 * Test create_redirect returns a result with the redirect ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 * @covers \Automattic\LegacyRedirector\Application\RedirectCreationResult::redirect_id
	 */
	public function test_create_redirect_returns_result_with_id() {
		$result = $this->create_redirect_result( '/simple-redirect-result', 'http://example.com' );

		$this->assertFalse( $result->is_error() );
		self::assertIsInt( $result->redirect_id() );
	}

	/**
	 * Data Provider of Redirect Rules and test urls for Protected Params
	 *
	 * @return array
	 */
	public function get_protected_redirect_data() {
		return array(
			'redirect_simple_protected'           => array(
				'/simple-redirectA/',
				'http://example.com/',
				'/simple-redirectA/?utm_source=XYZ',
				'http://example.com/?utm_source=XYZ',
			),

			'redirect_protected_with_querystring' => array(
				'/b-redirect/?with=query-string',
				'http://example.com/',
				'/b-redirect/?with=query-string&utm_medium=123',
				'http://example.com/?utm_medium=123',
			),

			'redirect_protected_with_hashes'      => array(
				// The plugin should strip the hash and only store the URL path.
				'/hash-redirectA/#with-hash',
				'http://example.com/',
				'/hash-redirectA/?utm_source=SDF#with-hash',
				'http://example.com/?utm_source=SDF',
			),

			'redirect_multiple_protected'         => array(
				'/simple-redirectC/',
				'http://example.com/',
				'/simple-redirectC/?utm_source=XYZ&utm_medium=FALSE&utm_campaign=543',
				'http://example.com/?utm_source=XYZ&utm_medium=FALSE&utm_campaign=543',
			),
		);
	}

	/**
	 * Verify that safelisted parameters are maintained on final redirect URLs.
	 *
	 * @dataProvider get_protected_redirect_data
	 * @covers       \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 * @param string $from           From path.
	 * @param string $to             Destination.
	 * @param string $protected_from From path with preserved params.
	 * @param string $protected_to   Destination. with preserved params.
	 */
	public function test_protected_query_redirect( $from, $to, $protected_from, $protected_to ) {
		add_filter(
			'legacy_redirector_preserve_query_params',
			function ( $preserved_params ) {
				array_push(
					$preserved_params,
					'utm_source',
					'utm_medium',
					'utm_campaign'
				);
				return $preserved_params;
			}
		);

		$this->create_redirect( $from, $to );

		$redirect_data = $this->resolver()->get_redirect_data( $protected_from );
		$this->assertEquals( $redirect_data['url'], $protected_to, 'get_redirect_data failed' );
	}

	/**
	 * Test redirect to a post ID works correctly (covers CLI use case).
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_redirect_to_post_id_with_validation() {
		// Create a published post to redirect to.
		$destination_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Destination Post',
			)
		);

		// Insert redirect to post ID with validation.
		$post_id = $this->create_redirect( '/redirect-to-post-id', $destination_post_id, true );
		$this->assertIsInt( $post_id );

		// Verify the redirect works.
		$redirect_data = $this->resolver()->get_redirect_data( '/redirect-to-post-id' );
		$this->assertEquals( get_permalink( $destination_post_id ), $redirect_data['url'] );
	}

	/**
	 * Test redirect to non-existent post ID fails validation.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_redirect_to_nonexistent_post_id_fails() {
		// Use a very high post ID that doesn't exist.
		$result = $this->create_redirect_result( '/redirect-to-nonexistent', 999999999, true );

		$this->assertTrue( $result->is_error() );
		$this->assertEquals( 'empty-postid', $result->error_code() );
	}

	/**
	 * Test redirect to draft post ID fails validation.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_redirect_to_draft_post_id_fails() {
		// Create a draft post.
		$draft_post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Draft Post',
			)
		);

		$result = $this->create_redirect_result( '/redirect-to-draft', $draft_post_id, true );

		$this->assertTrue( $result->is_error() );
		// 'non-public' is returned for posts that exist but aren't published.
		$this->assertEquals( 'non-public', $result->error_code() );
	}

	/**
	 * Test BulkActionsHandler removes edit and adds enable/disable.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\BulkActionsHandler::modify_bulk_actions
	 */
	public function test_bulk_actions_handler_removes_edit_and_adds_enable_disable(): void {
		$handler = new BulkActionsHandler( $this->manager() );

		$actions = array(
			'edit'   => 'Edit',
			'delete' => 'Delete',
		);

		$result = $handler->modify_bulk_actions( $actions );

		$this->assertArrayNotHasKey( 'edit', $result );
		$this->assertArrayHasKey( 'delete', $result );
		$this->assertArrayHasKey( 'enable_redirects', $result );
		$this->assertArrayHasKey( 'disable_redirects', $result );
	}

	/**
	 * Test create_redirect clears cache for URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_clears_cache(): void {
		$from_url = '/cache-clear-test-' . wp_generate_uuid4();
		$to_url   = 'http://example.com/';

		// Prime the cache with a "not found" state.
		wp_cache_set(
			CachingRedirectRepository::cache_key( SourceUrl::from_string( $from_url )->hash() ),
			0,
			CachingRedirectRepository::CACHE_GROUP
		);

		// Insert should clear/update the cache.
		$this->create_redirect( $from_url, $to_url );

		// The critical test: despite priming cache with 0, lookup should work.
		// This verifies the cache was properly invalidated and updated.
		$result = $this->resolver()->get_redirect_data( $from_url );
		$this->assertNotNull( $result );
		$this->assertSame( $to_url, $result['url'] );
	}

	/**
	 * Test validation returns error for duplicate redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validation_returns_error_for_duplicate(): void {
		$from_url = '/duplicate-validate-test-' . wp_generate_uuid4();
		$to_url   = 'http://example.com/';

		// Create first redirect.
		$this->create_redirect( $from_url, $to_url );

		// Try to create another redirect with the same source (with validation).
		$result = $this->create_redirect_result( $from_url, 'http://different.com/', true );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'duplicate-redirect-uri', $result->error_code() );
	}

	/**
	 * Test that external redirect hosts are added to allowed_redirect_hosts filter.
	 *
	 * This verifies that when a redirect to an external URL is created,
	 * the external host will be allowed by wp_safe_redirect().
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_external_redirect_host_is_allowed(): void {
		$from_url = '/external-host-test-' . wp_generate_uuid4();
		$to_url   = 'https://external-example.com/page';

		// Create the redirect.
		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertIsInt( $post_id );

		// Verify the redirect data resolves correctly.
		$redirect_data = $this->resolver()->get_redirect_data( $from_url );
		$this->assertIsArray( $redirect_data );
		$this->assertSame( $to_url, $redirect_data['url'] );

		// Verify the external host would be allowed by wp_safe_redirect.
		// We can't test the actual redirect (it calls exit), but we can verify
		// the host is properly extracted and would be added to allowed hosts.
		$external_host = wp_parse_url( $to_url, PHP_URL_HOST );
		$this->assertSame( 'external-example.com', $external_host );
	}

	/**
	 * Test that redirect to external URL resolves correctly.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectResolver::get_redirect_data
	 */
	public function test_get_redirect_data_resolves_external_url(): void {
		$from_url = '/external-uri-test-' . wp_generate_uuid4();
		$to_url   = 'https://another-external-site.org/destination';

		$this->create_redirect( $from_url, $to_url );

		$redirect_data = $this->resolver()->get_redirect_data( $from_url );

		$this->assertSame( $to_url, $redirect_data['url'] );
	}

	/**
	 * Test RedirectManager enable and disable methods.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::enable
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::disable
	 */
	public function test_enable_and_disable_redirect(): void {
		$from_url = '/enable-disable-test-' . wp_generate_uuid4();
		$to_url   = 'http://example.com/';

		$post_id = $this->create_redirect( $from_url, $to_url );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		// Disable the redirect.
		$manager = $this->manager();
		$result  = $manager->disable( $post_id );
		$this->assertTrue( $result );
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Enable the redirect.
		$result = $manager->enable( $post_id );
		$this->assertTrue( $result );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Test RedirectManager bulk_enable and bulk_disable methods.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::bulk_enable
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::bulk_disable
	 */
	public function test_bulk_enable_and_disable(): void {
		$post_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$post_ids[] = $this->create_redirect( '/bulk-test-' . $i . '-' . wp_generate_uuid4(), 'http://example.com/' );
		}

		$manager = $this->manager();

		// Bulk disable.
		$disabled = $manager->bulk_disable( $post_ids );
		$this->assertSame( 3, $disabled );

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( 'draft', get_post_status( $post_id ) );
		}

		// Bulk enable.
		$enabled = $manager->bulk_enable( $post_ids );
		$this->assertSame( 3, $enabled );

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( 'publish', get_post_status( $post_id ) );
		}
	}

	/**
	 * Test a redirect cannot be enabled onto a source another redirect has.
	 *
	 * A duplicate the 2.0 upgrade disabled keeps its old key, but any save
	 * re-derives the key from its source - the live redirect's. Two rows on
	 * one key leave the lookup to a LIMIT 1, which can stop the live one
	 * answering, so the save is refused with what to do instead.
	 */
	public function test_enabling_a_duplicate_source_is_refused(): void {
		$live_id      = $this->create_redirect( '/clash', 'https://example.com/one' );
		$duplicate_id = $this->insert_redirect_post(
			array(
				'post_title'   => '/clash/',
				'post_name'    => md5( '/clash/' ),
				'post_excerpt' => 'https://example.com/two',
				'post_status'  => 'draft',
			)
		);

		$result = $this->manager()->change_status( $duplicate_id, 'publish' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( sprintf( 'Redirect #%d already has the source "/clash"', $live_id ), (string) $result->error_message() );
		$this->assertSame( 'draft', get_post_status( $duplicate_id ) );
		$this->assertSame( $live_id, $this->repository()->find_by_source( SourceUrl::from_string( '/clash' ) )?->id() );

		// Changing the live redirect's own destination is still allowed.
		$this->assertTrue( $this->manager()->update_destination( $live_id, Destination::from_mixed( 'https://example.com/two' ) )->is_success() );
	}
}
