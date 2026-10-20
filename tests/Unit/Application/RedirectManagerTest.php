<?php
/**
 * RedirectManager service unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\RedirectCreationResult;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Application\RedirectValidator;
use Automattic\LegacyRedirector\Application\ValidationResult;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * RedirectManagerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Domain\InvalidRedirectValue
 * @uses \Automattic\LegacyRedirector\Application\ErrorText
 */
final class RedirectManagerTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The mock validator.
	 *
	 * @var RedirectValidator&Mockery\MockInterface
	 */
	private $validator;

	/**
	 * The manager under test.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();

		// Pass the create_redirect() context gate by default (WP_CLI is not
		// defined in the unit harness, so the gate falls through to the
		// capability check).
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->validator  = Mockery::mock( RedirectValidator::class );
		$this->manager    = new RedirectManager( $this->repository, $this->validator );

		// Stub wp_cache_delete to do nothing by default.
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	/**
	 * Test create_redirect returns success with redirect ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_returns_success_with_id(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->with( Mockery::on( fn( $r ) => $r->source()->path() === $source->path() ) )
			->andReturn( ValidationResult::valid() );

		// Repository returns redirect with assigned ID.
		$saved_redirect = Redirect::reconstitute( 123, $source, $destination, 'publish' );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $saved_redirect );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 123, $result->redirect_id() );
	}

	/**
	 * Test create_redirect is blocked on the front end by default.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_blocked_on_front_end(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'insert-not-allowed', $result->error_code() );
	}

	/**
	 * Test the legacy_redirector_allow_insert filter permits front-end creation.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_allowed_on_front_end_via_filter(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'apply_filters' )->justReturn( true );

		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( Redirect::reconstitute( 123, $source, $destination, 'publish' ) );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 123, $result->redirect_id() );
	}

	/**
	 * Test the pre-2.0 wpcom_legacy_redirector_allow_insert hook still permits creation.
	 *
	 * Its value feeds the 2.0 filter, so a 1.x callback keeps working unless a
	 * 2.0 callback overrides it.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_allowed_on_front_end_via_deprecated_filter(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Filters\expectApplied( 'wpcom_legacy_redirector_allow_insert' )->once()->with( false )->andReturn( true );
		Filters\expectApplied( 'legacy_redirector_allow_insert' )->once()->with( true )->andReturnFirstArg();

		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( Redirect::reconstitute( 123, $source, $destination, 'publish' ) );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 123, $result->redirect_id() );
	}

	/**
	 * Test a user who can manage redirects creates even when the filter says no.
	 *
	 * WordPress.com's wpcom-helper.php registers `__return_false` on
	 * `legacy_redirector_allow_insert` at priority 9999 to keep front-end
	 * code from writing redirects. A capable user — such as one arriving via an
	 * ability whose permission callback checked `manage_redirects` — must not
	 * be caught by it, so the capability is consulted before the filter.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_capability_beats_a_blocking_filter(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'apply_filters' )->justReturn( false );

		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( Redirect::reconstitute( 123, $source, $destination, 'publish' ) );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 123, $result->redirect_id() );
	}

	/**
	 * Test create_redirect returns error when validation fails.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_returns_error_on_validation_failure(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::invalid( 'duplicate-uri', 'Redirect already exists' ) );

		$this->repository->shouldNotReceive( 'save' );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'duplicate-uri', $result->error_code() );
		$this->assertSame( 'Redirect already exists', $result->error_message() );
	}

	/**
	 * Test create_redirect skips validation when validate=false.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_skips_validation_when_disabled(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator->shouldNotReceive( 'validate' );

		// Repository returns redirect with assigned ID.
		$saved_redirect = Redirect::reconstitute( 456, $source, $destination, 'publish' );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $saved_redirect );

		$result = $this->manager->create_redirect( $source, $destination, false );

		$this->assertFalse( $result->is_error() );
		$this->assertSame( 456, $result->redirect_id() );
	}

	/**
	 * Test create_redirect returns error when save fails.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::create_redirect
	 */
	public function test_create_redirect_returns_error_on_save_failure(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->validator
			->shouldReceive( 'validate' )
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andThrow( new \Exception( 'Database error' ) );

		$result = $this->manager->create_redirect( $source, $destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'save-failed', $result->error_code() );
		$this->assertSame( 'Database error', $result->error_message() );
	}


	/**
	 * Test enable returns true on success.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::enable
	 */
	public function test_enable_returns_true_on_success(): void {
		$source   = SourceUrl::from_string( '/old-page' );
		$redirect = $this->create_test_redirect( 123, $source, 'draft' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 123 )
			->andReturn( $redirect );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect );

		$result = $this->manager->enable( 123 );

		$this->assertTrue( $result );
	}

	/**
	 * Test enable returns false for non-existent redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::enable
	 */
	public function test_enable_returns_false_for_nonexistent_redirect(): void {
		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 999 )
			->andReturn( null );

		$this->repository->shouldNotReceive( 'save' );

		$result = $this->manager->enable( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test disable returns true on success.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::disable
	 */
	public function test_disable_returns_true_on_success(): void {
		$source   = SourceUrl::from_string( '/old-page' );
		$redirect = $this->create_test_redirect( 123, $source, 'publish' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 123 )
			->andReturn( $redirect );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect );

		$result = $this->manager->disable( 123 );

		$this->assertTrue( $result );
	}

	/**
	 * Test disable returns false for non-existent redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::disable
	 */
	public function test_disable_returns_false_for_nonexistent_redirect(): void {
		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 999 )
			->andReturn( null );

		$result = $this->manager->disable( 999 );

		$this->assertFalse( $result );
	}

	/**
	 * Test change_status returns false when save throws exception.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::change_status
	 */
	public function test_change_status_returns_false_on_save_failure(): void {
		$source   = SourceUrl::from_string( '/old-page' );
		$redirect = $this->create_test_redirect( 123, $source, 'draft' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->repository
			->shouldReceive( 'save' )
			->andThrow( new \Exception( 'Database error' ) );

		$result = $this->manager->enable( 123 );

		$this->assertFalse( $result );
	}

	/**
	 * Test update_destination returns true on success.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_destination
	 */
	public function test_update_destination_returns_true_on_success(): void {
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'publish' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/updated-destination' ) );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 123 )
			->andReturn( $redirect );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->with( Mockery::on( fn( $r ) => $r->destination()->as_url()->value() === $new_destination->as_url()->value() ) )
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect );

		$result = $this->manager->update_destination( 123, $new_destination );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 123, $result->redirect_id() );
	}

	/**
	 * Test update_destination returns a not-found error for non-existent redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_destination
	 */
	public function test_update_destination_returns_error_for_nonexistent(): void {
		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 999 )
			->andReturn( null );

		$new_destination = Destination::from_url( DestinationUrl::from_string( '/updated' ) );
		$result          = $this->manager->update_destination( 999, $new_destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'not-found', $result->error_code() );
	}

	/**
	 * Test update_destination can also update status.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_destination
	 */
	public function test_update_destination_with_status_change(): void {
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'draft' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/updated' ) );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect );

		$result = $this->manager->update_destination( 123, $new_destination, 'publish' );

		$this->assertTrue( $result->is_success() );
	}

	/**
	 * Test update_redirect returns true on success.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_redirect
	 */
	public function test_update_redirect_returns_true_on_success(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'publish' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/new-destination' ) );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 123 )
			->andReturn( $redirect );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::valid() );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect );

		$result = $this->manager->update_redirect( 123, '/new-source', $new_destination );

		$this->assertTrue( $result->is_success() );
	}

	/**
	 * Test update_redirect returns a not-found error for non-existent redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_redirect
	 */
	public function test_update_redirect_returns_error_for_nonexistent(): void {
		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 999 )
			->andReturn( null );

		$new_destination = Destination::from_url( DestinationUrl::from_string( '/new' ) );
		$result          = $this->manager->update_redirect( 999, '/new-source', $new_destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'not-found', $result->error_code() );
	}

	/**
	 * Test update_redirect returns an error for invalid source URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_redirect
	 */
	public function test_update_redirect_returns_error_for_invalid_source(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$source   = SourceUrl::from_string( '/old-page' );
		$redirect = $this->create_test_redirect( 123, $source, 'publish' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->repository->shouldNotReceive( 'save' );

		$new_destination = Destination::from_url( DestinationUrl::from_string( '/new' ) );
		// Empty string should fail SourceUrl validation.
		$result = $this->manager->update_redirect( 123, '', $new_destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'invalid-source', $result->error_code() );
	}



	/**
	 * Test update_destination refuses an update the validator rejects.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_destination
	 */
	public function test_update_destination_returns_false_when_validation_fails(): void {
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'publish' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/old-page' ) );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::invalid( 'invalid-values', 'Source and destination match.' ) );

		$this->repository->shouldNotReceive( 'save' );

		$result = $this->manager->update_destination( 123, $new_destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test update_redirect refuses an update the validator rejects.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_redirect
	 */
	public function test_update_redirect_returns_false_when_validation_fails(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'publish' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/new-source' ) );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::invalid( 'invalid-values', 'Source and destination match.' ) );

		$this->repository->shouldNotReceive( 'save' );

		$result = $this->manager->update_redirect( 123, '/new-source', $new_destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test update_by_source refuses an update the validator rejects.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_by_source
	 */
	public function test_update_by_source_returns_false_when_validation_fails(): void {
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'publish' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/old-page' ) );

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->andReturn( 123 );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->andReturn( ValidationResult::invalid( 'invalid-values', 'Source and destination match.' ) );

		$this->repository->shouldNotReceive( 'save' );

		$result = $this->manager->update_by_source( $source, $new_destination );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'invalid-values', $result->error_code() );
	}

	/**
	 * Test passing $validate = false skips validation, for the CSV import.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::update_by_source
	 */
	public function test_update_by_source_can_skip_validation(): void {
		$source          = SourceUrl::from_string( '/old-page' );
		$redirect        = $this->create_test_redirect( 123, $source, 'publish' );
		$new_destination = Destination::from_url( DestinationUrl::from_string( '/old-page' ) );

		$this->repository
			->shouldReceive( 'get_id_by_source' )
			->andReturn( 123 );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->validator->shouldNotReceive( 'validate' );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect );

		$this->assertTrue( $this->manager->update_by_source( $source, $new_destination, null, false )->is_success() );
	}

	/**
	 * Test change_status preserves the repository failure message.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::change_status
	 */
	public function test_change_status_reports_save_failure_message(): void {
		$source   = SourceUrl::from_string( '/old-page' );
		$redirect = $this->create_test_redirect( 123, $source, 'draft' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturn( $redirect );

		$this->repository
			->shouldReceive( 'save' )
			->andThrow( new \Exception( 'Database error' ) );

		$result = $this->manager->change_status( 123, 'publish' );

		$this->assertTrue( $result->is_error() );
		$this->assertSame( 'save-failed', $result->error_code() );
		$this->assertSame( 'Database error', $result->error_message() );
	}

	/**
	 * Test exists_unchanged compares destination and status with the stored redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::exists_unchanged
	 * @dataProvider data_exists_unchanged
	 *
	 * @param int         $existing_id The ID the source lookup returns (0 for none).
	 * @param string      $destination The destination being imported.
	 * @param string|null $status      The status being imported.
	 * @param bool        $expected    Whether the redirect is unchanged.
	 */
	public function test_exists_unchanged( int $existing_id, string $destination, ?string $status, bool $expected ): void {
		$source = SourceUrl::from_string( '/old-page' );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->repository->shouldReceive( 'get_id_by_source' )->andReturn( $existing_id );
		$this->repository->shouldReceive( 'find_by_id' )->with( 123 )->andReturn( $this->create_test_redirect( 123, $source, 'publish' ) );

		$this->assertSame(
			$expected,
			$this->manager->exists_unchanged( $source, Destination::from_mixed( $destination ), $status )
		);
	}

	/**
	 * Cases for exists_unchanged; the stored redirect is enabled and points at /destination.
	 *
	 * @return array<string, array{int, string, string|null, bool}>
	 */
	public static function data_exists_unchanged(): array {
		return array(
			'no redirect'           => array( 0, '/destination', null, false ),
			'same, default status'  => array( 123, '/destination', null, true ),
			'same, explicit status' => array( 123, '/destination', 'publish', true ),
			'same once normalized'  => array( 123, 'https://example.com/destination', null, true ),
			'different destination' => array( 123, '/elsewhere', null, false ),
			'different status'      => array( 123, '/destination', 'draft', false ),
		);
	}

	/**
	 * Test check validates a new redirect without saving it.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::check
	 */
	public function test_check_validates_new_redirect_without_saving(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );
		$invalid     = ValidationResult::invalid( 'duplicate-redirect-uri', 'A redirect for this URI already exists' );

		$this->repository->shouldNotReceive( 'get_id_by_source' );
		$this->repository->shouldNotReceive( 'save' );
		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->with( Mockery::on( fn( $r ) => null === $r->id() && '/new-page' === $r->destination()->as_url()->value() ) )
			->andReturn( $invalid );

		$this->assertSame( $invalid, $this->manager->check( $source, $destination, false ) );
	}

	/**
	 * Test check judges an update against the existing redirect's ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::check
	 */
	public function test_check_update_judges_existing_redirect(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->repository->shouldReceive( 'get_id_by_source' )->andReturn( 123 );
		$this->repository->shouldReceive( 'find_by_id' )->with( 123 )->andReturn( $this->create_test_redirect( 123, $source, 'publish' ) );
		$this->repository->shouldNotReceive( 'save' );
		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->with( Mockery::on( fn( $r ) => 123 === $r->id() && '/new-page' === $r->destination()->as_url()->value() ) )
			->andReturn( ValidationResult::valid() );

		$this->assertTrue( $this->manager->check( $source, $destination, true )->is_valid() );
	}

	/**
	 * Test check judges an update to a missing source as a create.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::check
	 */
	public function test_check_update_of_missing_source_judges_create(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$this->repository->shouldReceive( 'get_id_by_source' )->andReturn( 0 );
		$this->validator
			->shouldReceive( 'validate' )
			->once()
			->with( Mockery::on( fn( $r ) => null === $r->id() ) )
			->andReturn( ValidationResult::valid() );

		$this->assertTrue( $this->manager->check( $source, $destination, true )->is_valid() );
	}

	/**
	 * Create a real redirect object for testing.
	 *
	 * @param int       $id     The redirect ID.
	 * @param SourceUrl $source The source URL.
	 * @param string    $status The post status.
	 * @return Redirect
	 */
	private function create_test_redirect( int $id, SourceUrl $source, string $status ): Redirect {
		$destination = Destination::from_url( DestinationUrl::from_string( '/destination' ) );
		return Redirect::reconstitute( $id, $source, $destination, $status );
	}
}
