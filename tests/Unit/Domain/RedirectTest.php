<?php
/**
 * Redirect entity unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * RedirectTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class RedirectTest extends MonkeyStubs {

	/**
	 * Test create returns new redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::create
	 */
	public function test_create_returns_new_redirect(): void {
		$source      = SourceUrl::from_string( '/old-page' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new-page' ) );

		$redirect = Redirect::create( $source, $destination );

		$this->assertNull( $redirect->id() );
		$this->assertSame( $source, $redirect->source() );
		$this->assertSame( $destination, $redirect->destination() );
	}

	/**
	 * Test create sets status to publish.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::create
	 */
	public function test_create_sets_publish_status(): void {
		$redirect = Redirect::create(
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) )
		);

		$this->assertSame( 'publish', $redirect->status() );
		$this->assertTrue( $redirect->is_active() );
	}

	/**
	 * Test create sets created_at timestamp.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::create
	 */
	public function test_create_sets_created_at(): void {
		$before   = new DateTimeImmutable();
		$redirect = Redirect::create(
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) )
		);
		$after    = new DateTimeImmutable();

		$created = $redirect->created_at();
		$this->assertNotNull( $created );
		$this->assertGreaterThanOrEqual( $before->getTimestamp(), $created->getTimestamp() );
		$this->assertLessThanOrEqual( $after->getTimestamp(), $created->getTimestamp() );
	}

	/**
	 * Test reconstitute creates redirect with ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::reconstitute
	 */
	public function test_reconstitute_creates_redirect_with_id(): void {
		$source      = SourceUrl::from_string( '/old' );
		$destination = Destination::from_url( DestinationUrl::from_string( '/new' ) );
		$created_at  = new DateTimeImmutable( '2024-01-15 10:00:00' );

		$redirect = Redirect::reconstitute( 123, $source, $destination, 'publish', $created_at );

		$this->assertSame( 123, $redirect->id() );
		$this->assertSame( 'publish', $redirect->status() );
		$this->assertSame( $created_at, $redirect->created_at() );
	}

	/**
	 * Test is_persisted returns false for new redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_persisted
	 */
	public function test_is_persisted_false_for_new(): void {
		$redirect = Redirect::create(
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) )
		);

		$this->assertFalse( $redirect->is_persisted() );
	}

	/**
	 * Test is_persisted returns true for reconstituted redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_persisted
	 */
	public function test_is_persisted_true_for_reconstituted(): void {
		$redirect = Redirect::reconstitute(
			456,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'publish'
		);

		$this->assertTrue( $redirect->is_persisted() );
	}

	/**
	 * Test is_active returns true for published redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_active
	 */
	public function test_is_active_for_published(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'publish'
		);

		$this->assertTrue( $redirect->is_active() );
	}

	/**
	 * Test is_active returns false for draft redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_active
	 */
	public function test_is_active_false_for_draft(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'draft'
		);

		$this->assertFalse( $redirect->is_active() );
	}

	/**
	 * Test with_id creates copy with new ID.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_id
	 */
	public function test_with_id_creates_copy(): void {
		$redirect = Redirect::create(
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) )
		);

		$persisted = $redirect->with_id( 999 );

		$this->assertNull( $redirect->id() );
		$this->assertSame( 999, $persisted->id() );
		$this->assertSame( $redirect->source(), $persisted->source() );
	}

	/**
	 * Test with_status creates copy with new status.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_status
	 */
	public function test_with_status_creates_copy(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'publish'
		);

		$trashed = $redirect->with_status( 'trash' );

		$this->assertSame( 'publish', $redirect->status() );
		$this->assertSame( 'trash', $trashed->status() );
	}

	/**
	 * Test with_status accepts each known status.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_status
	 *
	 * @dataProvider data_known_statuses
	 *
	 * @param string $status A known status.
	 */
	public function test_with_status_accepts_known_statuses( string $status ): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'publish'
		);

		$this->assertSame( $status, $redirect->with_status( $status )->status() );
	}

	/**
	 * Data provider of known statuses.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_known_statuses(): array {
		return array(
			'publish' => array( 'publish' ),
			'draft'   => array( 'draft' ),
			'trash'   => array( 'trash' ),
		);
	}

	/**
	 * Test with_status rejects an unknown status.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_status
	 */
	public function test_with_status_rejects_unknown_status(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'publish'
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( "The status must be 'publish', 'draft', or 'trash'." );

		$redirect->with_status( 'banana' );
	}

	/**
	 * Test with_destination creates copy with new destination.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_destination
	 */
	public function test_with_destination_creates_copy(): void {
		$original_dest = Destination::from_url( DestinationUrl::from_string( '/original' ) );
		$new_dest      = Destination::from_url( DestinationUrl::from_string( '/updated' ) );

		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			$original_dest,
			'publish'
		);

		$updated = $redirect->with_destination( $new_dest );

		$this->assertSame( $original_dest, $redirect->destination() );
		$this->assertSame( $new_dest, $updated->destination() );
		$this->assertSame( $redirect->source(), $updated->source() );
	}

	/**
	 * Test immutability - changes don't affect original.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect
	 */
	public function test_immutability(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) ),
			'publish'
		);

		$redirect->with_status( 'trash' );
		$redirect->with_id( 999 );

		$this->assertSame( 1, $redirect->id() );
		$this->assertSame( 'publish', $redirect->status() );
	}

	/**
	 * Test redirects are healthy by default.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_corrupt
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::corruption
	 */
	public function test_redirects_are_healthy_by_default(): void {
		$redirect = Redirect::create(
			SourceUrl::from_string( '/old' ),
			Destination::from_url( DestinationUrl::from_string( '/new' ) )
		);

		$this->assertFalse( $redirect->is_corrupt() );
		$this->assertNull( $redirect->corruption() );
	}

	/**
	 * Test reconstitute with a corruption reason flags the redirect.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::reconstitute
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_corrupt
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::corruption
	 */
	public function test_reconstitute_with_corruption_reason(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/__corrupt__/1' ),
			Destination::from_url( DestinationUrl::home() ),
			'publish',
			null,
			'Invalid source: The URL does not validate.'
		);

		$this->assertTrue( $redirect->is_corrupt() );
		$this->assertSame( 'Invalid source: The URL does not validate.', $redirect->corruption() );
	}

	/**
	 * Test with_* copies preserve the corruption flag.
	 *
	 * A status or destination change alone does not repair a corrupt row;
	 * only a full source-and-destination rebuild does.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_status
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::with_destination
	 * @covers \Automattic\LegacyRedirector\Domain\Redirect::is_corrupt
	 */
	public function test_with_copies_preserve_corruption(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/__corrupt__/1' ),
			Destination::from_url( DestinationUrl::home() ),
			'publish',
			null,
			'Invalid destination: Absolute destination URLs must use http or https scheme.'
		);

		$this->assertTrue( $redirect->with_status( 'draft' )->is_corrupt() );
		$this->assertTrue(
			$redirect->with_destination( Destination::from_url( DestinationUrl::from_string( '/ok' ) ) )->is_corrupt()
		);
	}
}
