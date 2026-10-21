<?php
/**
 * AuditDetail unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\AuditDetail;
use PHPUnit\Framework\TestCase;

/**
 * AuditDetailTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\AuditDetail
 */
final class AuditDetailTest extends TestCase {

	/**
	 * Test each detail keeps the English the CLI has always shown.
	 *
	 * @dataProvider english_provider
	 *
	 * @param AuditDetail $detail   The detail.
	 * @param string      $expected Its English.
	 */
	public function test_english( AuditDetail $detail, string $expected ): void {
		$this->assertSame( $expected, $detail->english() );
	}

	/**
	 * Details and their English.
	 *
	 * @return array<string, array{AuditDetail, string}>
	 */
	public static function english_provider(): array {
		return array(
			'post status'        => array( AuditDetail::post_status( 'draft' ), 'status: draft' ),
			'post type'          => array( AuditDetail::post_type( 'wp_template' ), 'post type: wp_template' ),
			'host'               => array( AuditDetail::host( 'example.com' ), 'host: example.com' ),
			'http status'        => array( AuditDetail::http_status( 503 ), 'status: 503' ),
			'location'           => array( AuditDetail::location( 'https://example.com/b' ), 'to: https://example.com/b' ),
			'loop'               => array( AuditDetail::loop( array( '/a', '/b', '/a' ) ), '/a -> /b -> /a' ),
			'error'              => array( AuditDetail::error( 'Timed out' ), 'Timed out' ),
			'no status code'     => array( AuditDetail::no_status_code(), 'no status code' ),
			'too many redirects' => array( AuditDetail::too_many_redirects(), 'Too many redirects' ),
			'corruption'         => array( AuditDetail::corruption( 'Invalid source' ), 'Invalid source' ),
		);
	}

	/**
	 * Test a loop keeps its paths as a list, for each surface to join its own way.
	 */
	public function test_loop_keeps_its_paths(): void {
		$detail = AuditDetail::loop( array( '/a', '/b', '/a' ) );

		$this->assertSame( AuditDetail::LOOP, $detail->kind() );
		$this->assertSame( array( '/a', '/b', '/a' ), $detail->value() );
	}
}
