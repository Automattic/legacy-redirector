<?php
/**
 * RedirectValidator service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

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
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
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

	/**
	 * Test validate returns a duplicate error when the source is already taken.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_returns_duplicate_error_when_source_is_taken(): void {
		$source   = $this->create_source( '/existing-page' );
		$redirect = Redirect::create( $source, $this->create_url_destination( '/destination' ) );

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => $s->path() === $source->path() ) )
			->andReturn( 456 );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'duplicate-redirect-uri', $result->error_code() );
	}

	/**
	 * Test a persisted redirect is not a duplicate of its own row.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_does_not_treat_a_redirect_as_its_own_duplicate(): void {
		$redirect = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/new-destination' ),
			'publish'
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 123 );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test a moved source is judged against the source it moves to.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_judges_a_moved_source_against_its_new_value(): void {
		$existing = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/old-destination' ),
			'publish'
		);
		$moved    = $existing->with_source( $this->create_source( '/taken-page' ) );

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->with( Mockery::on( fn( $s ) => '/taken-page' === $s->path() ) )
			->andReturn( 456 );

		$result = $this->validator->validate( $moved );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'duplicate-redirect-uri', $result->error_code() );
	}

	/**
	 * Test validate rejects a redirect whose destination is its own source.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_source_matching_destination(): void {
		$redirect = Redirect::create(
			$this->create_source( '/same-page' ),
			$this->create_url_destination( '/same-page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test a moved source is loop-checked against the source it moves to.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_a_moved_source_matching_its_destination(): void {
		$existing = Redirect::reconstitute(
			123,
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/old-destination' ),
			'publish'
		);
		$looping  = $existing
			->with_source( $this->create_source( '/loop' ) )
			->with_destination( $this->create_url_destination( '/loop' ) );

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		$result = $this->validator->validate( $looping );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test validate rejects an unpublished destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_an_unpublished_destination(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/draft-page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'draft' ) );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-public', $result->error_code() );
	}

	/**
	 * Test validate rejects a destination slug that only exists as a trashed post.
	 *
	 * The write gate shares the auditor's destination resolver, so the
	 * __trashed slug rename that the `validate` command understands also
	 * refuses a new write.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_a_trashed_destination_slug(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/trashed-page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'trashed-page', OBJECT, array( 'post', 'page' ) )
			->andReturn( null );

		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );

		Functions\expect( 'get_page_by_path' )
			->once()
			->with( 'trashed-page__trashed', OBJECT, array( 'post', 'page' ) )
			->andReturn( $this->create_mock_post( 'trash' ) );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-public', $result->error_code() );
	}

	/**
	 * Test validate rejects a destination post ID that does not exist.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_a_deleted_destination_post_id(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_post_id_destination( 999 )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 999 )
			->andReturn( false );

		Functions\expect( 'get_post' )
			->once()
			->with( 999 )
			->andReturn( null );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'empty-postid', $result->error_code() );
	}

	/**
	 * Test validate rejects a trashed destination post ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_a_trashed_destination_post_id(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_post_id_destination( 456 )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\expect( 'get_permalink' )
			->once()
			->with( 456 )
			->andReturn( 'https://example.com/some-page' );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Functions\expect( 'get_post' )
			->once()
			->with( 456 )
			->andReturn( $this->create_mock_post( 'trash' ) );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'non-public', $result->error_code() );
	}

	/**
	 * Test validate accepts a valid new redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_returns_valid_for_a_valid_redirect(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/new-page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\expect( 'get_post_types' )
			->once()
			->andReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate accepts an external URL on an allowed host.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_accepts_an_external_url_on_an_allowed_host(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_url_destination( 'https://external.com/page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Functions\expect( 'wp_validate_redirect' )
			->once()
			->andReturnFirstArg();

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate rejects an external URL on a host WordPress will not redirect to.
	 *
	 * Accepting it would store a redirect that silently sends every visitor
	 * to the wp_safe_redirect() fallback instead of the destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_rejects_an_external_url_on_a_disallowed_host(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_url_destination( 'https://external.com/page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );

		Functions\expect( 'wp_validate_redirect' )
			->once()
			->with( 'https://external.com/page', '' )
			->andReturn( '' );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_invalid() );
		$this->assertSame( 'external-url-not-allowed', $result->error_code() );
	}

	/**
	 * Test validate treats a relative path with no post as indeterminate.
	 *
	 * Archives and rewrite endpoints have no post to find; the HTTP 404
	 * check is the authority on reachability.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_accepts_a_relative_path_with_no_post(): void {
		$redirect = Redirect::create(
			$this->create_source( '/old-page' ),
			$this->create_url_destination( '/category/news/' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->twice()
			->andReturn( null );

		Functions\expect( 'url_to_postid' )
			->once()
			->andReturn( 0 );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate accepts a reserved source: warnings never refuse a write.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate
	 */
	public function test_validate_accepts_a_reserved_source(): void {
		$redirect = Redirect::create(
			$this->create_source( '/wp-admin' ),
			$this->create_url_destination( '/new-page' )
		);

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->once()
			->andReturn( 0 );

		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );

		Functions\expect( 'get_page_by_path' )
			->once()
			->andReturn( $this->create_mock_post( 'publish' ) );

		$result = $this->validator->validate( $redirect );

		$this->assertTrue( $result->is_valid() );
	}

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
	public function test_validate_source_destination_different_normalizes_trailing_slashes(): void {
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
	 * Test validate_source_destination_different returns valid for an external host with the same path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_returns_valid_for_same_path_on_external_host(): void {
		$source      = $this->create_source( '/contact' );
		$destination = $this->create_url_destination( 'https://othersite.com/contact' );

		Functions\expect( 'home_url' )
			->once()
			->andReturn( 'https://example.com' );

		$result = $this->validator->validate_source_destination_different( $source, $destination );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Test validate_source_destination_different returns invalid for the own host with the same path.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectValidator::validate_source_destination_different
	 */
	public function test_validate_source_destination_different_returns_invalid_for_same_path_on_own_host(): void {
		$source      = $this->create_source( '/contact' );
		$destination = $this->create_url_destination( 'https://example.com/contact' );

		Functions\expect( 'home_url' )
			->once()
			->andReturn( 'https://example.com' );

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
}
