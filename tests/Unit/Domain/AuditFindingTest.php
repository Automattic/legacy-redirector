<?php
/**
 * AuditFinding unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * AuditFindingTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class AuditFindingTest extends MonkeyStubs {

	/**
	 * Test the severity the CLI and the abilities both report.
	 *
	 * @dataProvider severity_provider
	 * @covers \Automattic\LegacyRedirector\Domain\AuditFinding::severity
	 *
	 * @param AuditFindingType $type     The finding type.
	 * @param string           $severity The expected severity.
	 */
	public function test_severity( AuditFindingType $type, string $severity ): void {
		$redirect = Redirect::create( SourceUrl::from_string( '/old' ), Destination::from_mixed( '/new' ) );

		$this->assertSame( $severity, ( new AuditFinding( $redirect, $type ) )->severity() );
	}

	/**
	 * One finding type of each severity.
	 *
	 * @return array<string, array{AuditFindingType, string}>
	 */
	public static function severity_provider(): array {
		return array(
			'problem'      => array( AuditFindingType::POST_DELETED, 'problem' ),
			'warning'      => array( AuditFindingType::RESERVED_SOURCE, 'warning' ),
			'inconclusive' => array( AuditFindingType::URL_REQUEST_FAILED, 'inconclusive' ),
		);
	}
}
