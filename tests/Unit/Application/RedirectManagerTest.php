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
use Brain\Monkey\Functions;
use Mockery;

/**
 * RedirectManagerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormaliser
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
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

	// =========================================================================
	// create_redirect() tests
	// =========================================================================

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
	 * Test the wpcom_legacy_redirector_allow_insert filter permits front-end creation.
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
	 * Test a user who can manage redirects creates even when the filter says no.
	 *
	 * WordPress.com's wpcom-helper.php registers `__return_false` on
	 * `wpcom_legacy_redirector_allow_insert` at priority 9999 to keep front-end
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


	// =========================================================================
	// enable() / disable() tests
	// =========================================================================

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

	// =========================================================================
	// bulk_enable() / bulk_disable() tests
	// =========================================================================

	/**
	 * Test bulk_enable returns count of successful updates.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::bulk_enable
	 */
	public function test_bulk_enable_returns_success_count(): void {
		$source1   = SourceUrl::from_string( '/page-1' );
		$source2   = SourceUrl::from_string( '/page-2' );
		$redirect1 = $this->create_test_redirect( 1, $source1, 'draft' );
		$redirect2 = $this->create_test_redirect( 2, $source2, 'draft' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 1 )
			->andReturn( $redirect1 );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 2 )
			->andReturn( $redirect2 );

		$this->repository
			->shouldReceive( 'save' )
			->twice()
			->andReturnUsing(
				function ( $r ) {
					return $r;
				}
			);

		$result = $this->manager->bulk_enable( array( 1, 2 ) );

		$this->assertSame( 2, $result );
	}

	/**
	 * Test bulk_enable handles partial failures.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::bulk_enable
	 */
	public function test_bulk_enable_handles_partial_failures(): void {
		$source1   = SourceUrl::from_string( '/page-1' );
		$redirect1 = $this->create_test_redirect( 1, $source1, 'draft' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 1 )
			->andReturn( $redirect1 );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->with( 999 )
			->andReturn( null );

		$this->repository
			->shouldReceive( 'save' )
			->once()
			->andReturn( $redirect1 );

		$result = $this->manager->bulk_enable( array( 1, 999 ) );

		$this->assertSame( 1, $result );
	}

	/**
	 * Test bulk_disable returns count of successful updates.
	 *
	 * @covers \Automattic\LegacyRedirector\Application\RedirectManager::bulk_disable
	 */
	public function test_bulk_disable_returns_success_count(): void {
		$source1   = SourceUrl::from_string( '/page-1' );
		$source2   = SourceUrl::from_string( '/page-2' );
		$source3   = SourceUrl::from_string( '/page-3' );
		$redirect1 = $this->create_test_redirect( 1, $source1, 'publish' );
		$redirect2 = $this->create_test_redirect( 2, $source2, 'publish' );
		$redirect3 = $this->create_test_redirect( 3, $source3, 'publish' );

		$this->repository
			->shouldReceive( 'find_by_id' )
			->andReturnUsing(
				function ( $id ) use ( $redirect1, $redirect2, $redirect3 ) {
					return match ( $id ) {
						1 => $redirect1,
						2 => $redirect2,
						3 => $redirect3,
						default => null,
					};
				}
			);

		$this->repository
			->shouldReceive( 'save' )
			->times( 3 )
			->andReturnUsing(
				function ( $r ) {
					return $r;
				}
			);

		$result = $this->manager->bulk_disable( array( 1, 2, 3 ) );

		$this->assertSame( 3, $result );
	}

	// =========================================================================
	// update_destination() tests
	// =========================================================================

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

	// =========================================================================
	// update_redirect() tests
	// =========================================================================

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



	// =========================================================================
	// update validation tests
	// =========================================================================

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

	// =========================================================================
	// Helper methods
	// =========================================================================

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
