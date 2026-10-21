<?php
/**
 * FindingText unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Application;

use Automattic\LegacyRedirector\Application\FindingText;
use Automattic\LegacyRedirector\Domain\AuditDetail;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey\Functions;

/**
 * FindingTextTest class.
 *
 * @covers \Automattic\LegacyRedirector\Application\FindingText
 * @uses \Automattic\LegacyRedirector\Domain\AuditDetail
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 */
final class FindingTextTest extends MonkeyStubs {

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\stubTranslationFunctions();
	}

	/**
	 * Test the translatable text matches the Domain's English, case by case.
	 *
	 * The CLI shows the Domain's words and every other surface shows these,
	 * so in English the two must read the same.
	 *
	 * @dataProvider type_provider
	 *
	 * @param AuditFindingType $type The finding type.
	 */
	public function test_text_matches_the_domain_english( AuditFindingType $type ): void {
		$this->assertSame( $type->label(), FindingText::label( $type ) );
		$this->assertSame( $type->description() . '.', FindingText::description( $type ) );
		$this->assertSame( $type->remedy() . '.', FindingText::remedy( $type ) );
	}

	/**
	 * Every finding type.
	 *
	 * @return array<string, array{AuditFindingType}>
	 */
	public static function type_provider(): array {
		$cases = array();

		foreach ( AuditFindingType::cases() as $type ) {
			$cases[ $type->value ] = array( $type );
		}

		return $cases;
	}

	/**
	 * Test the summary is the description followed by the remedy.
	 */
	public function test_summary_is_description_then_remedy(): void {
		$this->assertSame(
			'The destination post has been moved to trash. Restore the post from the trash, or point this redirect elsewhere.',
			FindingText::summary( AuditFindingType::POST_TRASHED )
		);
	}

	/**
	 * Test a post status detail uses the status's own label, not its slug.
	 */
	public function test_post_status_detail_uses_the_status_label(): void {
		Functions\when( 'get_post_status_object' )->justReturn( (object) array( 'label' => 'Draft' ) );

		$this->assertSame(
			'The destination post is not published. Post status: Draft.',
			FindingText::description( AuditFindingType::POST_UNPUBLISHED, AuditDetail::post_status( 'draft' ) )
		);
	}

	/**
	 * Test a post type detail uses the type's singular name.
	 */
	public function test_post_type_detail_uses_the_type_name(): void {
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Template' ) ) );

		$this->assertSame(
			'The destination post is not published. Post type: Template.',
			FindingText::description( AuditFindingType::POST_UNPUBLISHED, AuditDetail::post_type( 'wp_template' ) )
		);
	}

	/**
	 * Test a status or type WordPress no longer knows falls back to its slug.
	 */
	public function test_unregistered_status_falls_back_to_the_slug(): void {
		Functions\when( 'get_post_status_object' )->justReturn( null );

		$this->assertStringEndsWith( 'Post status: retired.', FindingText::description( AuditFindingType::POST_UNPUBLISHED, AuditDetail::post_status( 'retired' ) ) );
	}

	/**
	 * Test each remaining detail kind reads as its own sentence.
	 *
	 * @dataProvider detail_provider
	 *
	 * @param AuditDetail $detail   The detail.
	 * @param string      $expected The sentence it should become.
	 */
	public function test_detail_sentences( AuditDetail $detail, string $expected ): void {
		$this->assertSame(
			'Failed to connect to the destination URL. ' . $expected,
			FindingText::description( AuditFindingType::URL_REQUEST_FAILED, $detail )
		);
	}

	/**
	 * Details and the sentences they become.
	 *
	 * @return array<string, array{AuditDetail, string}>
	 */
	public static function detail_provider(): array {
		return array(
			'host'               => array( AuditDetail::host( 'example.com' ), 'Host: example.com.' ),
			'http status'        => array( AuditDetail::http_status( 503 ), 'HTTP status: 503.' ),
			'location'           => array( AuditDetail::location( 'https://example.com/b' ), 'It redirected to https://example.com/b.' ),
			'loop'               => array( AuditDetail::loop( array( '/a', '/b', '/a' ) ), 'Loop: /a → /b → /a.' ),
			'no status code'     => array( AuditDetail::no_status_code(), 'The response had no status code.' ),
			'too many redirects' => array( AuditDetail::too_many_redirects(), 'The destination redirected too many times.' ),
			'error'              => array( AuditDetail::error( 'cURL error 28: Operation timed out' ), 'cURL error 28: Operation timed out' ),
		);
	}
}
