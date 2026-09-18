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
 * The label() and description() matches have no default arm, so a case added
 * without extending them throws UnhandledMatchError at the moment it is first
 * reported. Walking every case here turns that runtime crash into a test
 * failure at the point the case is added.
 *
 * @covers \Automattic\LegacyRedirector\Domain\AuditFindingType
 */
final class AuditFindingTypeTest extends MonkeyStubs {

	/**
	 * Test every case has a non-empty label and description.
	 */
	public function test_every_case_has_a_label_and_description(): void {
		foreach ( AuditFindingType::cases() as $case ) {
			$this->assertNotSame( '', $case->label(), $case->value . ' has no label' );
			$this->assertNotSame( '', $case->description(), $case->value . ' has no description' );
		}
	}

	/**
	 * Test a source that does not redirect is a problem.
	 *
	 * A source serving its own content is the redirect failing at its job, so
	 * --fix may disable it.
	 */
	public function test_source_did_not_redirect_is_a_problem(): void {
		$this->assertFalse( AuditFindingType::SOURCE_DID_NOT_REDIRECT->is_warning() );
	}

	/**
	 * Test a redirect landing elsewhere is a problem.
	 */
	public function test_redirect_mismatch_is_a_problem(): void {
		$this->assertFalse( AuditFindingType::REDIRECT_MISMATCH->is_warning() );
	}

	/**
	 * Test a failed source request is a warning.
	 *
	 * The request can fail for reasons that say nothing about the redirect, so
	 * --fix must not disable a redirect because a request timed out.
	 */
	public function test_source_request_failed_is_a_warning(): void {
		$this->assertTrue( AuditFindingType::SOURCE_REQUEST_FAILED->is_warning() );
	}

	/**
	 * Test a reserved source is still a warning.
	 */
	public function test_reserved_source_is_a_warning(): void {
		$this->assertTrue( AuditFindingType::RESERVED_SOURCE->is_warning() );
	}

	/**
	 * Test extra info is appended to the description.
	 */
	public function test_description_appends_extra_info(): void {
		$this->assertStringContainsString(
			'(status: 503)',
			AuditFindingType::SOURCE_DID_NOT_REDIRECT->description( 'status: 503' )
		);
	}
}
