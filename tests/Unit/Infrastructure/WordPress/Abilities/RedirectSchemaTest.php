<?php
/**
 * RedirectSchema unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;

/**
 * RedirectSchemaTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class RedirectSchemaTest extends MonkeyStubs {

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( '__' )->returnArg( 1 );
	}

	/**
	 * Test a URL destination is described as a URL.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::to_array
	 */
	public function test_to_array_describes_a_url_destination(): void {
		$redirect = Redirect::reconstitute(
			1,
			SourceUrl::from_string( '/old?ref=news' ),
			Destination::from_mixed( 'https://example.org/new' ),
			'publish'
		);

		$this->assertSame(
			array(
				'id'     => 1,
				'from'   => '/old?ref=news',
				'to'     => 'https://example.org/new',
				'type'   => 'url',
				'status' => 'enabled',
			),
			RedirectSchema::to_array( $redirect )
		);
	}

	/**
	 * Test a post destination is described as a post, and drafts as disabled.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::to_array
	 */
	public function test_to_array_describes_a_post_destination(): void {
		$redirect = Redirect::reconstitute(
			2,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( 42 ),
			'draft'
		);

		$this->assertSame(
			array(
				'id'     => 2,
				'from'   => '/old',
				'to'     => 42,
				'type'   => 'post',
				'status' => 'disabled',
			),
			RedirectSchema::to_array( $redirect )
		);
	}

	/**
	 * Test each stored status maps to a label the output schema allows.
	 *
	 * A label missing from the enum fails the ability at runtime.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::status_label
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::properties
	 * @dataProvider data_status_labels
	 *
	 * @param string $status The stored status.
	 * @param string $label  The expected label.
	 */
	public function test_status_label_is_in_the_schema_enum( string $status, string $label ): void {
		$redirect = Redirect::reconstitute( 4, SourceUrl::from_string( '/old' ), Destination::from_mixed( '/new' ), $status );

		$this->assertSame( $label, RedirectSchema::status_label( $redirect ) );
		$this->assertContains( $label, RedirectSchema::properties()['status']['enum'] );
	}

	/**
	 * Every stored status and its label.
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function data_status_labels(): array {
		return array(
			'publish' => array( 'publish', 'enabled' ),
			'draft'   => array( 'draft', 'disabled' ),
			'trash'   => array( 'trash', 'trashed' ),
		);
	}

	/**
	 * Test the output schema covers every field the formatter produces.
	 *
	 * A mismatch here fails the ability at runtime, because WordPress
	 * validates ability output against the schema.
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::object_schema
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities\RedirectSchema::properties
	 */
	public function test_object_schema_matches_the_formatted_output(): void {
		$redirect = Redirect::reconstitute(
			3,
			SourceUrl::from_string( '/old' ),
			Destination::from_mixed( '/new' ),
			'publish'
		);

		$schema = RedirectSchema::object_schema();

		$this->assertSame(
			array_keys( RedirectSchema::to_array( $redirect ) ),
			array_keys( $schema['properties'] )
		);
		$this->assertSame( array_keys( $schema['properties'] ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}
}
