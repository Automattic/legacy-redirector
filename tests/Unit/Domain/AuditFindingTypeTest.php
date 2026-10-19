<?php
/**
 * AuditFindingType enum unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;

/**
 * AuditFindingTypeTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType
 */
final class AuditFindingTypeTest extends MonkeyStubs {

	/**
	 * Test only a failed request and a server error are inconclusive.
	 *
	 * Every case is listed, so a new one has to decide which it is.
	 *
	 * @dataProvider conclusiveness_provider
	 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType::is_conclusive
	 *
	 * @param AuditFindingType $type       The finding type.
	 * @param bool             $conclusive Whether it should be conclusive.
	 */
	public function test_is_conclusive( AuditFindingType $type, bool $conclusive ): void {
		$this->assertSame( $conclusive, $type->is_conclusive() );
	}

	/**
	 * Test the provider covers every case.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType::is_conclusive
	 */
	public function test_conclusiveness_provider_lists_every_case(): void {
		$listed = array_map( static fn( array $row ) => $row[0], $this->conclusiveness_provider() );

		$this->assertEqualsCanonicalizing( AuditFindingType::cases(), array_values( $listed ) );
	}

	/**
	 * Every finding type and whether it is conclusive.
	 *
	 * @return array<string, array{AuditFindingType, bool}>
	 */
	public function conclusiveness_provider(): array {
		return array(
			'post trashed'              => array( AuditFindingType::POST_TRASHED, true ),
			'post deleted'              => array( AuditFindingType::POST_DELETED, true ),
			'post unpublished'          => array( AuditFindingType::POST_UNPUBLISHED, true ),
			'external host not allowed' => array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED, true ),
			'url not found'             => array( AuditFindingType::URL_NOT_FOUND, true ),
			'url server error'          => array( AuditFindingType::URL_SERVER_ERROR, false ),
			'url request failed'        => array( AuditFindingType::URL_REQUEST_FAILED, false ),
			'corrupt data'              => array( AuditFindingType::CORRUPT_DATA, true ),
			'reserved source'           => array( AuditFindingType::RESERVED_SOURCE, true ),
			'possible loop'             => array( AuditFindingType::POSSIBLE_LOOP, true ),
		);
	}
}
