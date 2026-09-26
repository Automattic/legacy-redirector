<?php
/**
 * CreateCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\CreateCommand;

/**
 * Integration tests for CreateCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\CreateCommand
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
 * @uses \Automattic\LegacyRedirector\Domain\AuditFindingType
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class CreateCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var CreateCommand
	 */
	private CreateCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new CreateCommand( $this->manager(), $this->auditor() );
	}

	/**
	 * Test creating a redirect to a relative path.
	 */
	public function test_create_redirect_to_path(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'target-page',
			)
		);

		$this->invoke_command(
			$this->command,
			array( '/old-page', '/target-page' ),
			array()
		);

		$this->assert_success_contains( '/old-page -> /target-page' );
	}

	/**
	 * Test a source WordPress itself serves is created, with a warning.
	 */
	public function test_create_reserved_source_warns(): void {
		$this->invoke_command(
			$this->command,
			array( '/wp-login.php', '/' ),
			array()
		);

		$this->assert_warning_contains( 'locks you out of the dashboard' );
		$this->assert_success_contains( '/wp-login.php -> /' );
	}

	/**
	 * Test creating a redirect that closes a loop warns, but still creates.
	 *
	 * The other hop may be dormant behind a live page, so a cycle is a
	 * warning for a person, not a refusal.
	 */
	public function test_create_warns_when_closing_a_loop(): void {
		$this->create_redirect( '/loop-first', '/loop-second' );

		$this->invoke_command(
			$this->command,
			array( '/loop-second', '/loop-first' ),
			array()
		);

		$this->assert_warning_contains( 'leads back to this one' );
		$this->assert_success_contains( '/loop-second -> /loop-first' );
	}

	/**
	 * Test creating a redirect to a post ID.
	 */
	public function test_create_redirect_to_post_id(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->invoke_command(
			$this->command,
			array( '/post-redirect', (string) $post_id ),
			array()
		);

		$this->assert_success_contains( sprintf( '/post-redirect -> %d', $post_id ) );
	}

	/**
	 * Test creating a redirect to a full URL on an external host.
	 *
	 * External hosts are accepted at creation time; RedirectRequestHandler auto-allows
	 * the stored host at redirect time.
	 */
	public function test_create_redirect_to_external_url(): void {
		$this->invoke_command(
			$this->command,
			array( '/external', 'https://external.example.com/page' ),
			array( 'skip-validation' => true )
		);

		$this->assert_success_contains( '/external -> https://external.example.com/page' );
	}

	/**
	 * Test creating a disabled redirect.
	 */
	public function test_create_disabled_redirect(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'disabled-target',
			)
		);

		$this->invoke_command(
			$this->command,
			array( '/disabled-source', '/disabled-target' ),
			array( 'status' => 'disabled' )
		);

		$this->assert_success_contains( '(disabled)' );

		$redirect = $this->repository()->find_by_id(
			$this->repository()->get_id_by_source(
				\Automattic\LegacyRedirector\Domain\SourceUrl::from_string( '/disabled-source' )
			)
		);
		$this->assertNotNull( $redirect );
		$this->assertFalse( $redirect->is_active() );
	}

	/**
	 * Test that the new redirect ID is printed with --porcelain.
	 */
	public function test_create_porcelain_outputs_id(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'porcelain-target',
			)
		);

		$this->invoke_command(
			$this->command,
			array( '/porcelain-source', '/porcelain-target' ),
			array( 'porcelain' => true )
		);

		$this->assertFalse( $this->output->had_error() );
		$stdout = trim( $this->get_stdout() );
		$this->assertMatchesRegularExpression( '/^\d+$/', $stdout );
	}

	/**
	 * Test that validation rejects a destination post that does not exist.
	 */
	public function test_create_validates_post_exists(): void {
		$this->invoke_command(
			$this->command,
			array( '/bad-dest', '999999' ),
			array()
		);

		$this->assert_command_error();
	}

	/**
	 * Test that validation rejects an unpublished destination post.
	 */
	public function test_create_validates_post_is_published(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->invoke_command(
			$this->command,
			array( '/draft-dest', (string) $post_id ),
			array()
		);

		$this->assert_command_error();
	}

	/**
	 * Test that validation accepts an attachment destination.
	 *
	 * Attachments carry post_status 'inherit', never 'publish', so reading the
	 * raw property rejects every media destination as unpublished. Both the
	 * post ID and the attachment's slug path are exercised, as they take
	 * separate routes through the validator.
	 *
	 * @dataProvider data_attachment_destinations
	 *
	 * @param string $source   The source path for the redirect.
	 * @param bool   $use_slug Whether to pass the attachment's slug path rather than its ID.
	 */
	public function test_create_accepts_attachment_destination( string $source, bool $use_slug ): void {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'brochure.pdf',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Brochure',
			)
		);

		$destination = $use_slug
			? '/' . get_post_field( 'post_name', $attachment_id )
			: (string) $attachment_id;

		$this->invoke_command( $this->command, array( $source, $destination ), array() );

		$this->assert_command_success();
	}

	/**
	 * Data provider for attachment destination forms.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function data_attachment_destinations(): array {
		return array(
			'post ID'   => array( '/attachment-by-id', false ),
			'slug path' => array( '/attachment-by-path', true ),
		);
	}

	/**
	 * Test that --skip-validation bypasses destination validation.
	 */
	public function test_create_skip_validation(): void {
		$this->invoke_command(
			$this->command,
			array( '/unvalidated', '/nonexistent-target' ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_success();
	}

	/**
	 * Test that identical source and destination are rejected.
	 */
	public function test_create_rejects_same_source_and_destination(): void {
		$this->invoke_command(
			$this->command,
			array( '/same-page', '/same-page' ),
			array()
		);

		$this->assert_command_error();
	}

	/**
	 * Test that a duplicate source is rejected.
	 */
	public function test_create_rejects_duplicate(): void {
		$this->create_redirect( '/duplicate-source', 'https://example.com/first' );

		$this->invoke_command(
			$this->command,
			array( '/duplicate-source', 'https://example.com/second' ),
			array()
		);

		$this->assert_command_error();
	}

	/**
	 * Test that an invalid source is rejected.
	 */
	public function test_create_rejects_invalid_source(): void {
		$this->invoke_command(
			$this->command,
			array( '', '/valid-target' ),
			array( 'skip-validation' => true )
		);

		$this->assert_command_error();
	}
}
