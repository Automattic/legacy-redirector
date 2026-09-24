<?php
/**
 * GetCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\GetCommand;
use Automattic\LegacyRedirector\Application\RedirectFetcher;

/**
 * Integration tests for GetCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\GetCommand
 * @covers \Automattic\LegacyRedirector\Application\RedirectFetcher
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\AuditFinding
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class GetCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var GetCommand
	 */
	private GetCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new GetCommand(
			new RedirectFetcher( $this->repository() )
		);
	}

	/**
	 * Test getting a redirect by source path.
	 */
	public function test_get_by_source_with_url_destination(): void {
		$this->create_redirect( '/old-page', 'https://example.com/new-page' );

		$this->invoke_command(
			$this->command,
			array( '/old-page' ),
			array()
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( '/old-page' );
		$this->assert_stdout_contains( 'https://example.com/new-page' );
		$this->assert_stdout_contains( 'url' );
		$this->assert_stdout_contains( 'enabled' );
	}

	/**
	 * Test getting a redirect with post ID destination.
	 */
	public function test_get_by_source_with_post_destination(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/post-redirect', $post_id );

		$this->invoke_command(
			$this->command,
			array( '/post-redirect' ),
			array()
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( '/post-redirect' );
		$this->assert_stdout_contains( (string) $post_id );
		$this->assert_stdout_contains( 'post' );
	}

	/**
	 * Test getting a disabled redirect.
	 */
	public function test_get_disabled_redirect(): void {
		$redirect_id = $this->create_redirect( '/disabled-page', 'https://example.com/dest' );

		// Disable the redirect.
		wp_update_post(
			array(
				'ID'          => $redirect_id,
				'post_status' => 'draft',
			)
		);

		$this->invoke_command(
			$this->command,
			array( '/disabled-page' ),
			array()
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( 'disabled' );
	}

	/**
	 * Test error when redirect not found by source.
	 */
	public function test_get_by_source_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '/nonexistent-page' ),
			array()
		);

		$this->assert_error_contains( 'not found' );
	}

	/**
	 * Test error for invalid source path.
	 */
	public function test_get_invalid_source_path(): void {
		$this->invoke_command(
			$this->command,
			array( '' ),
			array()
		);

		$this->assert_error_contains( 'Invalid source path' );
	}

	/**
	 * Test getting a redirect by ID.
	 */
	public function test_get_by_id(): void {
		$redirect_id = $this->create_redirect( '/by-id-test', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( (string) $redirect_id ),
			array()
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( '/by-id-test' );
		$this->assert_stdout_contains( (string) $redirect_id );
	}

	/**
	 * Test error when redirect not found by ID.
	 */
	public function test_get_by_id_not_found(): void {
		$this->invoke_command(
			$this->command,
			array( '999999' ),
			array()
		);

		$this->assert_error_contains( 'not found' );
	}

	/**
	 * Test getting a single field value.
	 */
	public function test_get_single_field(): void {
		$this->create_redirect( '/field-test', 'https://example.com/destination' );

		$this->invoke_command(
			$this->command,
			array( '/field-test' ),
			array( 'field' => 'to' )
		);

		$this->assertFalse( $this->output->had_error() );
		$stdout = $this->get_stdout();
		$this->assertStringContainsString( 'https://example.com/destination', $stdout );
		// Should not contain field labels in single-field mode.
		$this->assertStringNotContainsString( 'from', $stdout );
	}

	/**
	 * Test error for invalid field name.
	 */
	public function test_get_invalid_field(): void {
		$this->create_redirect( '/field-test-invalid', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/field-test-invalid' ),
			array( 'field' => 'nonexistent' )
		);

		$this->assert_error_contains( 'Invalid field' );
		$this->assert_stderr_contains( 'Available fields' );
	}

	/**
	 * Test limiting output to selected fields.
	 */
	public function test_get_limited_fields(): void {
		$this->create_redirect( '/fields-test', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/fields-test' ),
			array( 'fields' => 'from,to' )
		);

		$this->assertFalse( $this->output->had_error() );
		$stdout = $this->get_stdout();
		$this->assertStringContainsString( '/fields-test', $stdout );
		$this->assertStringContainsString( 'https://example.com/dest', $stdout );
		$this->assertStringNotContainsString( 'hash', $stdout );
	}

	/**
	 * Test error for invalid --fields value.
	 */
	public function test_get_invalid_fields(): void {
		$this->create_redirect( '/fields-invalid', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array( '/fields-invalid' ),
			array( 'fields' => 'from,bogus' )
		);

		$this->assert_error_contains( 'Invalid fields' );
	}
}
