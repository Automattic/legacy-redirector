<?php
/**
 * AuditFindingType enum unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Domain;

use Automattic\LegacyRedirector\Domain\AuditFindingType;
use PHPUnit\Framework\TestCase;

/**
 * AuditFindingTypeTest class.
 *
 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType
 */
final class AuditFindingTypeTest extends TestCase {

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
	public static function conclusiveness_provider(): array {
		return array(
			'post trashed'               => array( AuditFindingType::POST_TRASHED, true ),
			'post deleted'               => array( AuditFindingType::POST_DELETED, true ),
			'post unpublished'           => array( AuditFindingType::POST_UNPUBLISHED, true ),
			'external host not allowed'  => array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED, true ),
			'url not found'              => array( AuditFindingType::URL_NOT_FOUND, true ),
			'url server error'           => array( AuditFindingType::URL_SERVER_ERROR, false ),
			'url request failed'         => array( AuditFindingType::URL_REQUEST_FAILED, false ),
			'corrupt data'               => array( AuditFindingType::CORRUPT_DATA, true ),
			'reserved source'            => array( AuditFindingType::RESERVED_SOURCE, true ),
			'possible loop'              => array( AuditFindingType::POSSIBLE_LOOP, true ),
			'source redirects elsewhere' => array( AuditFindingType::SOURCE_REDIRECTS_ELSEWHERE, true ),
			'source not firing'          => array( AuditFindingType::SOURCE_NOT_FIRING, true ),
			'source serves content'      => array( AuditFindingType::SOURCE_SERVES_CONTENT, true ),
			'source request failed'      => array( AuditFindingType::SOURCE_REQUEST_FAILED, false ),
		);
	}

	/**
	 * Test every finding type says what a person can do about it.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType::remedy
	 */
	public function test_every_case_has_a_remedy(): void {
		$remedies = array();

		foreach ( AuditFindingType::cases() as $type ) {
			$remedy = $type->remedy();

			$this->assertNotSame( '', $remedy, $type->name . ' has no remedy.' );
			$this->assertStringEndsNotWith( '.', $remedy, 'Callers add the full stop, as they do for description().' );
			$remedies[] = $remedy;
		}

		$this->assertSame( $remedies, array_unique( $remedies ), 'Each finding type has its own remedy.' );
	}

	/**
	 * Test which findings are warnings rather than problems.
	 *
	 * A warning means the redirect works but a person should look: a reserved
	 * or content-serving source is a fallback working as designed. The split
	 * decides the Health column, the issues filter and the menu badge, so
	 * every case is listed and a new one has to decide which it is.
	 *
	 * @dataProvider warning_provider
	 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType::is_warning
	 *
	 * @param AuditFindingType $type    The finding type.
	 * @param bool             $warning Whether it should be a warning.
	 */
	public function test_is_warning( AuditFindingType $type, bool $warning ): void {
		$this->assertSame( $warning, $type->is_warning() );
	}

	/**
	 * Test the warning provider covers every case.
	 *
	 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType::is_warning
	 */
	public function test_warning_provider_lists_every_case(): void {
		$listed = array_map( static fn( array $row ) => $row[0], $this->warning_provider() );

		$this->assertEqualsCanonicalizing( AuditFindingType::cases(), array_values( $listed ) );
	}

	/**
	 * Every finding type and whether it is a warning.
	 *
	 * @return array<string, array{AuditFindingType, bool}>
	 */
	public static function warning_provider(): array {
		return array(
			'post trashed'               => array( AuditFindingType::POST_TRASHED, false ),
			'post deleted'               => array( AuditFindingType::POST_DELETED, false ),
			'post unpublished'           => array( AuditFindingType::POST_UNPUBLISHED, false ),
			'external host not allowed'  => array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED, false ),
			'url not found'              => array( AuditFindingType::URL_NOT_FOUND, false ),
			'url server error'           => array( AuditFindingType::URL_SERVER_ERROR, false ),
			'url request failed'         => array( AuditFindingType::URL_REQUEST_FAILED, false ),
			'corrupt data'               => array( AuditFindingType::CORRUPT_DATA, false ),
			'reserved source'            => array( AuditFindingType::RESERVED_SOURCE, true ),
			'possible loop'              => array( AuditFindingType::POSSIBLE_LOOP, true ),
			'source redirects elsewhere' => array( AuditFindingType::SOURCE_REDIRECTS_ELSEWHERE, false ),
			'source not firing'          => array( AuditFindingType::SOURCE_NOT_FIRING, false ),
			'source serves content'      => array( AuditFindingType::SOURCE_SERVES_CONTENT, true ),
			'source request failed'      => array( AuditFindingType::SOURCE_REQUEST_FAILED, false ),
		);
	}
}
