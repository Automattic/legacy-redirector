<?php
/**
 * PostPathResolver unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\PostPathResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests the slug a path is looked up by.
 *
 * Both the destination check and the source check look posts up by this
 * slug, so a query string or fragment left on it would match nothing.
 *
 * @covers \Automattic\LegacyRedirector\Application\PostPathResolver::slug
 */
final class PostPathResolverTest extends TestCase {

	/**
	 * Test the slug drops the query, the fragment and the surrounding slashes.
	 *
	 * @dataProvider data_slugs
	 *
	 * @param string $path     The relative path.
	 * @param string $expected The slug.
	 */
	public function test_slug( string $path, string $expected ): void {
		$this->assertSame( $expected, PostPathResolver::slug( $path ) );
	}

	/**
	 * Data provider for paths and their slugs.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function data_slugs(): array {
		return array(
			'plain'             => array( '/about', 'about' ),
			'nested'            => array( '/parent/child/', 'parent/child' ),
			'query'             => array( '/about?ref=old', 'about' ),
			'fragment'          => array( '/about#team', 'about' ),
			'fragment in query' => array( '/about/?a=1#b', 'about' ),
			'root'              => array( '/', '' ),
		);
	}
}
