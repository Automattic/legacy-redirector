<?php
/**
 * RedirectValidator service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test file includes testable subclass.

use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationPostId;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;
use Mockery;
use WP_Post;

/**
 * RedirectValidatorTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 */
final class RedirectValidatorTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The validator under test.
	 *
	 * @var RedirectValidator
	 */
	private RedirectValidator $validator;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->validator  = new RedirectValidator( $this->repository );

		// Default stub for __ translation function.
		Functions\stubs(
			array(
				'__' => static function ( $text ) {
					return $text;
				},
			)
		);
	}

	/**
	 * Creates a test source URL.
	 *
	 * @param string $path The URL path.
	 * @return SourceUrl
	 */
	private function create_source( string $path = '/old-page' ): SourceUrl {
		return SourceUrl::from_string( $path );
	}

	/**
	 * Creates a URL destination.
	 *
	 * @param string $url The destination URL.
	 * @return Destination
	 */
	private function create_url_destination( string $url = '/new-page' ): Destination {
		return Destination::from_url( DestinationUrl::from_string( $url ) );
	}

	/**
	 * Creates a post ID destination.
	 *
	 * @param int $post_id The post ID.
	 * @return Destination
	 */
	private function create_post_id_destination( int $post_id = 123 ): Destination {
		return Destination::from_post_id( DestinationPostId::from_int( $post_id ) );
	}

	/**
	 * Creates a mock WP_Post object.
	 *
	 * @param string $status The post status.
	 * @return WP_Post
	 */
	private function create_mock_post( string $status = 'publish' ): WP_Post {
		return new WP_Post( $status );
	}

	// =========================================================================
	// validate_for_creation tests
	// =========================================================================

	/**
	 * Test validate_for_creation returns duplicate error when redirect exists.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_creation
	 */
	public function test_validate_for_creation_returns_duplicate_error_when_redirect_exists(): void {
		$source      = $this->create_source( '/existing-page' );
		$destination = $this->create_url_destination( '/destination' );

		$this->repository
			->shouldReceive( 'exists' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === $source->path() ) )
			->andReturn( true );

		$result = $this->validator->validate_for_creation( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'duplicate-redirect-uri', $result->error_code() );
	}

	/**
	 * Test validate_for_creation validates source and destination are different.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_creation
	 */
	public function test_validate_for_creation_validates_source_destination_different(): void {
		$source      = $this->create_source( '/same-page' );
		$destination = $this->create_url_destination( '/same-page' );

		$this->repository
			->shouldReceive( 'exists' )
			->once()
			->andReturn( false );

		$result = $this->validator->validate_for_creation( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate_for_creation validates destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_creation
	 */
	public function test_validate_for_creation_validates_destination(): void {
		$source      = $this->create_source( '/old-page' );
		$destination = $this->create_url_destination( '/nonexistent-page' );

		$this->repository
			->shouldReceive( 'exists' )
			->once()
			->andReturn( false );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );

		$result = $this->validator->validate_for_creation( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid', $result->error_code() );
	}

	/**
	 * Test validate_for_creation returns valid for valid redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_creation
	 */
	public function test_validate_for_creation_returns_valid_for_valid_redirect(): void {
		$source      = $this->create_source( '/old-page' );
		$destination = $this->create_url_destination( '/new-page' );

		$this->repository
			->shouldReceive( 'exists' )
			->once()
			->andReturn( false );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate_for_creation( $source, $destination );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_source_destination_different tests
	// =========================================================================

	/**
	 * Test validate_source_destination_different returns invalid when paths match.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_returns_invalid_when_same_path(): void {
		$source      = $this->create_source( '/same-path' );
		$destination = $this->create_url_destination( '/same-path' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate_source_destination_different returns invalid with trailing slash difference.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_normalises_trailing_slashes(): void {
		$source      = $this->create_source( '/same-path/' );
		$destination = $this->create_url_destination( '/same-path' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate_source_destination_different handles case insensitivity.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_is_case_insensitive(): void {
		$source      = $this->create_source( '/Same-Path' );
		$destination = $this->create_url_destination( '/same-path' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate_source_destination_different handles post ID destinations.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_handles_post_id_destination(): void {
		$source      = $this->create_source( '/sample-page' );
		$destination = $this->create_post_id_destination( 123 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 123 )
			->andReturn( 'https://example.com/sample-page' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate_source_destination_different returns valid when post ID resolves to different path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_valid_for_different_post_id_path(): void {
		$source      = $this->create_source( '/old-page' );
		$destination = $this->create_post_id_destination( 123 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 123 )
			->andReturn( 'https://example.com/different-page' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_source_destination_different returns valid when get_permalink fails.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_valid_when_permalink_fails(): void {
		$source      = $this->create_source( '/old-page' );
		$destination = $this->create_post_id_destination( 123 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 123 )
			->andReturn( false );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_source_destination_different returns valid for different paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_returns_valid_for_different_paths(): void {
		$source      = $this->create_source( '/old-page' );
		$destination = $this->create_url_destination( '/new-page' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_destination_post_id tests
	// =========================================================================

	/**
	 * Test validate_destination_post_id returns invalid when post does not exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_post_id
	 */
	public function test_validate_destination_post_id_returns_invalid_when_post_does_not_exist(): void {
		Functions\expect( 'get_post' )
			->once()
			->with( 999 )
			->andReturn( null );

		$result = $this->validator->validate_destination_post_id( 999 );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'empty-postid', $result->error_code() );
	}

	/**
	 * Test validate_destination_post_id returns invalid when post is not published.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_post_id
	 */
	public function test_validate_destination_post_id_returns_invalid_when_post_not_published(): void {
		$post = $this->create_mock_post( 'draft' );

		Functions\expect( 'get_post' )
			->once()
			->with( 123 )
			->andReturn( $post );

		$result = $this->validator->validate_destination_post_id( 123 );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-public', $result->error_code() );
	}

	/**
	 * Test validate_destination_post_id returns invalid for trashed post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_post_id
	 */
	public function test_validate_destination_post_id_returns_invalid_for_trashed_post(): void {
		$post = $this->create_mock_post( 'trash' );

		Functions\expect( 'get_post' )
			->once()
			->with( 456 )
			->andReturn( $post );

		$result = $this->validator->validate_destination_post_id( 456 );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-public', $result->error_code() );
	}

	/**
	 * Test validate_destination_post_id returns valid for published post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_post_id
	 */
	public function test_validate_destination_post_id_returns_valid_for_published_post(): void {
		$post = $this->create_mock_post( 'publish' );

		Functions\expect( 'get_post' )
			->once()
			->with( 789 )
			->andReturn( $post );

		$result = $this->validator->validate_destination_post_id( 789 );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_destination_url tests
	// =========================================================================

	/**
	 * Test validate_destination_url root path is always valid.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_url
	 */
	public function test_validate_destination_url_root_path_is_always_valid(): void {
		$result = $this->validator->validate_destination_url( '/' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_url validates relative paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_url
	 */
	public function test_validate_destination_url_validates_relative_paths(): void {
		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'some-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate_destination_url( '/some-page' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_url returns invalid for nonexistent relative path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_url
	 */
	public function test_validate_destination_url_returns_invalid_for_nonexistent_relative_path(): void {
		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );

		$result = $this->validator->validate_destination_url( '/nonexistent' );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid', $result->error_code() );
	}

	/**
	 * Test validate_destination_url accepts external URLs with valid http/https scheme.
	 *
	 * The plugin automatically adds the destination host to allowed_redirect_hosts
	 * at redirect time (see RedirectExecutor::allow_redirect_host), so we only
	 * validate that the URL has a valid scheme here.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_url
	 */
	public function test_validate_destination_url_accepts_valid_external_urls(): void {
		$result = $this->validator->validate_destination_url( 'https://external.com/page' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_url rejects URLs without scheme.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_url
	 */
	public function test_validate_destination_url_returns_invalid_for_url_without_scheme(): void {
		// wp_parse_url is already stubbed in MonkeyStubs to use native parse_url.
		// URL without scheme will not have 'host' or 'scheme' keys.
		$result = $this->validator->validate_destination_url( 'example.com/page' );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-url', $result->error_code() );
	}

	/**
	 * Test validate_destination_url rejects URLs with non-http schemes.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_url
	 */
	public function test_validate_destination_url_returns_invalid_for_non_http_scheme(): void {
		// wp_parse_url is already stubbed in MonkeyStubs to use native parse_url.
		$result = $this->validator->validate_destination_url( 'ftp://example.com/file' );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-scheme', $result->error_code() );
	}

	// =========================================================================
	// validate_relative_path tests
	// =========================================================================

	/**
	 * Test validate_relative_path returns invalid when post does not exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_relative_path
	 */
	public function test_validate_relative_path_returns_invalid_when_post_does_not_exist(): void {
		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'nonexistent-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( null );

		$result = $this->validator->validate_relative_path( '/nonexistent-page' );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid', $result->error_code() );
	}

	/**
	 * Test validate_relative_path returns invalid when post is not published.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_relative_path
	 */
	public function test_validate_relative_path_returns_invalid_when_post_not_published(): void {
		$post = $this->create_mock_post( 'draft' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'draft-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $post );

		$result = $this->validator->validate_relative_path( '/draft-page' );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-public', $result->error_code() );
	}

	/**
	 * Test validate_relative_path returns valid for published page.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_relative_path
	 */
	public function test_validate_relative_path_returns_valid_for_published_page(): void {
		$post = $this->create_mock_post( 'publish' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'published-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $post );

		$result = $this->validator->validate_relative_path( '/published-page' );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_source_is_404 tests
	// =========================================================================

	/**
	 * Test validate_source_is_404 returns invalid when URL is not 404.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_is_404
	 */
	public function test_validate_source_is_404_returns_invalid_when_url_is_not_404(): void {
		$source = $this->create_source( '/existing-page' );

		Functions\expect( 'home_url' )
			->once()
			->with( '/existing-page' )
			->andReturn( 'https://example.com/existing-page' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/existing-page' )
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
				)
			);

		Functions\expect( 'is_wp_error' )
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		$result = $this->validator->validate_source_is_404( $source );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-404', $result->error_code() );
	}

	/**
	 * Test validate_source_is_404 returns valid when URL returns 404.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_is_404
	 */
	public function test_validate_source_is_404_returns_valid_when_url_returns_404(): void {
		$source = $this->create_source( '/nonexistent-page' );

		Functions\expect( 'home_url' )
			->once()
			->with( '/nonexistent-page' )
			->andReturn( 'https://example.com/nonexistent-page' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/nonexistent-page' )
			->andReturn(
				array(
					'response' => array( 'code' => 404 ),
				)
			);

		Functions\expect( 'is_wp_error' )
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 404 );

		$result = $this->validator->validate_source_is_404( $source );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_source_is_404 returns invalid when request fails.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_is_404
	 */
	public function test_validate_source_is_404_returns_invalid_when_request_fails(): void {
		$source = $this->create_source( '/some-page' );

		Functions\expect( 'home_url' )
			->once()
			->with( '/some-page' )
			->andReturn( 'https://example.com/some-page' );

		$wp_error = Mockery::mock( 'WP_Error' );

		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturn( $wp_error );

		Functions\expect( 'is_wp_error' )
			->andReturn( true );

		$result = $this->validator->validate_source_is_404( $source );

		// Response code 0 means error, which is not 404.
		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-404', $result->error_code() );
	}

	// =========================================================================
	// validate_source_not_private tests
	// =========================================================================

	/**
	 * Test validate_source_not_private returns invalid when source is private post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_not_private
	 */
	public function test_validate_source_not_private_returns_invalid_when_source_is_private_post(): void {
		$source = $this->create_source( '/private-page' );
		$post   = $this->create_mock_post( 'private' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'private-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $post );

		$result = $this->validator->validate_source_not_private( $source );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'private-url', $result->error_code() );
	}

	/**
	 * Test validate_source_not_private returns invalid when source is draft post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_not_private
	 */
	public function test_validate_source_not_private_returns_invalid_when_source_is_draft_post(): void {
		$source = $this->create_source( '/draft-page' );
		$post   = $this->create_mock_post( 'draft' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'draft-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $post );

		$result = $this->validator->validate_source_not_private( $source );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'private-url', $result->error_code() );
	}

	/**
	 * Test validate_source_not_private returns valid when source does not exist as post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_not_private
	 */
	public function test_validate_source_not_private_returns_valid_when_source_does_not_exist(): void {
		$source = $this->create_source( '/nonexistent-page' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'nonexistent-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( null );

		$result = $this->validator->validate_source_not_private( $source );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_source_not_private returns valid when source is published post.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_not_private
	 */
	public function test_validate_source_not_private_returns_valid_when_source_is_published_post(): void {
		$source = $this->create_source( '/published-page' );
		$post   = $this->create_mock_post( 'publish' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'published-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( $post );

		$result = $this->validator->validate_source_not_private( $source );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_for_update tests
	// =========================================================================

	/**
	 * Test validate_for_update validates source and destination are different.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_update
	 */
	public function test_validate_for_update_validates_source_destination_different(): void {
		$existing        = Redirect::reconstitute(
			123,
			$this->create_source( '/same-page' ),
			$this->create_url_destination( '/old-destination' ),
			'publish'
		);
		$new_destination = $this->create_url_destination( '/same-page' );

		$result = $this->validator->validate_for_update( $existing, $new_destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate_for_update validates destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_update
	 */
	public function test_validate_for_update_validates_destination(): void {
		$existing        = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/old-destination' ),
			'publish'
		);
		$new_destination = $this->create_url_destination( '/nonexistent-page' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( null );

		$result = $this->validator->validate_for_update( $existing, $new_destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid', $result->error_code() );
	}

	/**
	 * Test validate_for_update returns valid for valid update.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_for_update
	 */
	public function test_validate_for_update_returns_valid_for_valid_update(): void {
		$existing        = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/old-destination' ),
			'publish'
		);
		$new_destination = $this->create_url_destination( '/new-destination' );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate_for_update( $existing, $new_destination );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_destination tests
	// =========================================================================

	/**
	 * Test validate_destination routes to validate_destination_post_id for post ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination
	 */
	public function test_validate_destination_routes_to_post_id_validation(): void {
		$destination = $this->create_post_id_destination( 123 );

		Functions\expect( 'get_post' )
			->once()
			->with( 123 )
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate_destination( $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination routes to validate_destination_url for URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination
	 */
	public function test_validate_destination_routes_to_url_validation(): void {
		$destination = $this->create_url_destination( '/' );

		$result = $this->validator->validate_destination( $destination );

		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// validate_destination_not_404 tests
	// =========================================================================

	/**
	 * Test validate_destination_not_404 returns valid when URL returns 200.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_returns_valid_when_url_returns_200(): void {
		$destination = $this->create_url_destination( 'https://example.com/existing-page' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/existing-page' )
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
				)
			);

		Functions\expect( 'is_wp_error' )
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		$result = $this->validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_not_404 returns invalid when URL returns 404.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_returns_invalid_when_url_returns_404(): void {
		$destination = $this->create_url_destination( 'https://example.com/nonexistent-page' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/nonexistent-page' )
			->andReturn(
				array(
					'response' => array( 'code' => 404 ),
				)
			);

		Functions\expect( 'is_wp_error' )
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 404 );

		$result = $this->validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( '404', $result->error_code() );
	}

	/**
	 * Test validate_destination_not_404 resolves relative paths via home_url.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_resolves_relative_paths(): void {
		$destination = $this->create_url_destination( '/relative-page' );

		Functions\expect( 'home_url' )
			->once()
			->with( '/relative-page' )
			->andReturn( 'https://example.com/relative-page' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/relative-page' )
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
				)
			);

		Functions\expect( 'is_wp_error' )
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		$result = $this->validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_not_404 resolves post ID destinations via get_permalink.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_resolves_post_id_destinations(): void {
		$destination = $this->create_post_id_destination( 123 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 123 )
			->andReturn( 'https://example.com/post-123' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->with( 'https://example.com/post-123' )
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
				)
			);

		Functions\expect( 'is_wp_error' )
			->andReturn( false );

		Functions\expect( 'wp_remote_retrieve_response_code' )
			->once()
			->andReturn( 200 );

		$result = $this->validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_not_404 returns invalid when post ID cannot be resolved.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_returns_invalid_when_post_id_unresolvable(): void {
		$destination = $this->create_post_id_destination( 999 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 999 )
			->andReturn( false );

		$result = $this->validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( '404', $result->error_code() );
	}

	/**
	 * Test validate_destination_not_404 handles HTTP request errors gracefully.
	 *
	 * When the HTTP request fails, the response code is 0, which is not 404,
	 * so the validation passes. This matches the existing behavior.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_handles_request_errors(): void {
		$destination = $this->create_url_destination( 'https://example.com/page' );
		$wp_error    = Mockery::mock( 'WP_Error' );

		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturn( $wp_error );

		Functions\expect( 'is_wp_error' )
			->andReturn( true );

		$result = $this->validator->validate_destination_not_404( $destination );

		// Response code 0 is not 404, so validation passes.
		$this->assertTrue( $result->is_valid() );
	}

	// =========================================================================
	// resolve_destination_url tests
	// =========================================================================

	/**
	 * Test resolve_destination_url returns permalink for post ID destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::resolve_destination_url
	 */
	public function test_resolve_destination_url_returns_permalink_for_post_id(): void {
		$destination = $this->create_post_id_destination( 123 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 123 )
			->andReturn( 'https://example.com/post-123' );

		$result = $this->validator->resolve_destination_url( $destination );

		$this->assertSame( 'https://example.com/post-123', $result );
	}

	/**
	 * Test resolve_destination_url returns null when permalink fails.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::resolve_destination_url
	 */
	public function test_resolve_destination_url_returns_null_when_permalink_fails(): void {
		$destination = $this->create_post_id_destination( 999 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 999 )
			->andReturn( false );

		$result = $this->validator->resolve_destination_url( $destination );

		$this->assertNull( $result );
	}

	/**
	 * Test resolve_destination_url prepends home_url for relative paths.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::resolve_destination_url
	 */
	public function test_resolve_destination_url_prepends_home_url_for_relative_paths(): void {
		$destination = $this->create_url_destination( '/relative-page' );

		Functions\expect( 'home_url' )
			->once()
			->with( '/relative-page' )
			->andReturn( 'https://example.com/relative-page' );

		$result = $this->validator->resolve_destination_url( $destination );

		$this->assertSame( 'https://example.com/relative-page', $result );
	}

	/**
	 * Test resolve_destination_url returns absolute URLs unchanged.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::resolve_destination_url
	 */
	public function test_resolve_destination_url_returns_absolute_urls_unchanged(): void {
		$destination = $this->create_url_destination( 'https://external.com/page' );

		$result = $this->validator->resolve_destination_url( $destination );

		$this->assertSame( 'https://external.com/page', $result );
	}

	// =========================================================================
	// Tests using testable subclass (demonstrates protected get_response_code)
	// =========================================================================

	/**
	 * Test validate_source_is_404 using testable subclass.
	 *
	 * This approach provides cleaner isolation by overriding get_response_code()
	 * instead of mocking WordPress HTTP functions.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_is_404
	 */
	public function test_validate_source_is_404_with_testable_subclass(): void {
		$testable_validator = new TestableRedirectValidator( $this->repository, 404 );

		Functions\expect( 'home_url' )
			->once()
			->with( '/nonexistent-page' )
			->andReturn( 'https://example.com/nonexistent-page' );

		$source = $this->create_source( '/nonexistent-page' );
		$result = $testable_validator->validate_source_is_404( $source );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_not_404 using testable subclass.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_with_testable_subclass(): void {
		$testable_validator = new TestableRedirectValidator( $this->repository, 200 );

		$destination = $this->create_url_destination( 'https://example.com/existing-page' );
		$result      = $testable_validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_destination_not_404 returns invalid using testable subclass.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_destination_not_404
	 */
	public function test_validate_destination_not_404_invalid_with_testable_subclass(): void {
		$testable_validator = new TestableRedirectValidator( $this->repository, 404 );

		$destination = $this->create_url_destination( 'https://example.com/missing-page' );
		$result      = $testable_validator->validate_destination_not_404( $destination );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( '404', $result->error_code() );
	}
}

/**
 * Testable subclass that allows controlling HTTP response codes.
 *
 * This demonstrates the benefit of making get_response_code() protected:
 * tests can override HTTP behavior without mocking WordPress functions.
 */
class TestableRedirectValidator extends RedirectValidator {

	/**
	 * The response code to return.
	 *
	 * @var int
	 */
	private int $response_code;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository    The redirect repository.
	 * @param int                         $response_code The response code to return.
	 */
	public function __construct( RedirectRepositoryInterface $repository, int $response_code ) {
		parent::__construct( $repository );
		$this->response_code = $response_code;
	}

	/**
	 * Override to return a fixed response code.
	 *
	 * @param string $url The URL to check (ignored).
	 * @return int The configured response code.
	 */
	protected function get_response_code( string $url ): int {
		return $this->response_code;
	}
}
