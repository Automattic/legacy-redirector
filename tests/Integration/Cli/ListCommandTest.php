<?php
/**
 * ListCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ListCommand;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Integration tests for ListCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\ListCommand
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\LoopDetector
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationPostId
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\RedirectCriteria
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 */
final class ListCommandTest extends CliTestCase {

	/**
	 * The command under test.
	 *
	 * @var ListCommand
	 */
	private ListCommand $command;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->command = new ListCommand( $this->query_repository(), $this->repository(), new Upgrader() );
	}

	/**
	 * Test listing all redirects.
	 */
	public function test_list_all_redirects(): void {
		$this->create_redirect( '/page-one', 'https://example.com/one' );
		$this->create_redirect( '/page-two', 'https://example.com/two' );

		$this->invoke_command(
			$this->command,
			array(),
			array()
		);

		$this->assertFalse( $this->output->had_error() );
		$this->assert_stdout_contains( '/page-one' );
		$this->assert_stdout_contains( '/page-two' );
	}

	/**
	 * Test listing shows redirect details.
	 */
	public function test_list_shows_redirect_details(): void {
		$this->create_redirect( '/detail-test', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array(),
			array()
		);

		$this->assert_stdout_contains( '/detail-test' );
		$this->assert_stdout_contains( 'https://example.com/dest' );
		$this->assert_stdout_contains( 'url' );
		$this->assert_stdout_contains( 'enabled' );
	}

	/**
	 * Test warning when no redirects found.
	 */
	public function test_list_empty_shows_warning(): void {
		$this->invoke_command(
			$this->command,
			array(),
			array()
		);

		$this->assertTrue( $this->output->had_warning() );
		$this->assert_stdout_contains( 'No redirects found' );
	}

	/**
	 * Test listing only enabled redirects.
	 */
	public function test_list_enabled_only(): void {
		$enabled_id  = $this->create_redirect( '/enabled-page', 'https://example.com/one' );
		$disabled_id = $this->create_redirect( '/disabled-page', 'https://example.com/two' );

		wp_update_post(
			array(
				'ID'          => $disabled_id,
				'post_status' => 'draft',
			)
		);

		$this->invoke_command(
			$this->command,
			array(),
			array( 'status' => 'enabled' )
		);

		$this->assert_stdout_contains( '/enabled-page' );
		$this->assert_stdout_not_contains( '/disabled-page' );
	}

	/**
	 * Test listing only disabled redirects.
	 */
	public function test_list_disabled_only(): void {
		$enabled_id  = $this->create_redirect( '/enabled-page2', 'https://example.com/one' );
		$disabled_id = $this->create_redirect( '/disabled-page2', 'https://example.com/two' );

		wp_update_post(
			array(
				'ID'          => $disabled_id,
				'post_status' => 'draft',
			)
		);

		$this->invoke_command(
			$this->command,
			array(),
			array( 'status' => 'disabled' )
		);

		$this->assert_stdout_contains( '/disabled-page2' );
		$this->assert_stdout_not_contains( '/enabled-page2' );
	}

	/**
	 * Test listing only URL redirects.
	 */
	public function test_list_url_type_only(): void {
		$this->create_redirect( '/url-redirect', 'https://example.com/dest' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/post-redirect', $post_id );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'destination-type' => 'url' )
		);

		$this->assert_stdout_contains( '/url-redirect' );
		$this->assert_stdout_not_contains( '/post-redirect' );
	}

	/**
	 * Test listing only post redirects.
	 */
	public function test_list_post_type_only(): void {
		$this->create_redirect( '/url-redirect2', 'https://example.com/dest' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->create_redirect( '/post-redirect2', $post_id );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'destination-type' => 'post' )
		);

		$this->assert_stdout_contains( '/post-redirect2' );
		$this->assert_stdout_not_contains( '/url-redirect2' );
	}

	/**
	 * Test searching redirects.
	 */
	public function test_list_with_search(): void {
		$this->create_redirect( '/blog/article-one', 'https://example.com/one' );
		$this->create_redirect( '/news/story-two', 'https://example.com/two' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'search' => 'blog' )
		);

		$this->assert_stdout_contains( '/blog/article-one' );
		$this->assert_stdout_not_contains( '/news/story-two' );
	}

	/**
	 * Test listing with limit.
	 */
	public function test_list_with_limit(): void {
		$this->create_redirect( '/limit-one', 'https://example.com/one' );
		$this->create_redirect( '/limit-two', 'https://example.com/two' );
		$this->create_redirect( '/limit-three', 'https://example.com/three' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'limit' => '2' )
		);

		// Should show pagination info when more results exist.
		$stdout = $this->get_stdout();
		// Count how many redirects are shown (look for paths).
		$matches = preg_match_all( '/\/limit-(one|two|three)/', $stdout );
		$this->assertEquals( 2, $matches, 'Should only show 2 redirects with limit=2' );
	}

	/**
	 * Test count format.
	 */
	public function test_list_count_format(): void {
		$this->create_redirect( '/count-one', 'https://example.com/one' );
		$this->create_redirect( '/count-two', 'https://example.com/two' );
		$this->create_redirect( '/count-three', 'https://example.com/three' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'format' => 'count' )
		);

		$stdout = trim( $this->get_stdout() );
		$this->assertEquals( '3', $stdout );
	}

	/**
	 * Test ids format.
	 */
	public function test_list_ids_format(): void {
		$id1 = $this->create_redirect( '/ids-one', 'https://example.com/one' );
		$id2 = $this->create_redirect( '/ids-two', 'https://example.com/two' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'format' => 'ids' )
		);

		$stdout = trim( $this->get_stdout() );
		$this->assertStringContainsString( (string) $id1, $stdout );
		$this->assertStringContainsString( (string) $id2, $stdout );
		// IDs should be space-separated.
		$ids = explode( ' ', $stdout );
		$this->assertCount( 2, $ids );
	}

	/**
	 * Test combining status and destination type filters.
	 */
	public function test_list_combined_filters(): void {
		// Create various redirects.
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$enabled_url_id  = $this->create_redirect( '/enabled-url', 'https://example.com/dest' );
		$enabled_post_id = $this->create_redirect( '/enabled-post', $post_id );
		$disabled_url_id = $this->create_redirect( '/disabled-url', 'https://example.com/dest2' );

		wp_update_post(
			array(
				'ID'          => $disabled_url_id,
				'post_status' => 'draft',
			)
		);

		// Filter for enabled URL redirects only.
		$this->invoke_command(
			$this->command,
			array(),
			array(
				'status'           => 'enabled',
				'destination-type' => 'url',
			)
		);

		$this->assert_stdout_contains( '/enabled-url' );
		$this->assert_stdout_not_contains( '/enabled-post' );
		$this->assert_stdout_not_contains( '/disabled-url' );
	}

	/**
	 * Test limiting output to selected fields.
	 */
	public function test_list_limited_fields(): void {
		$this->create_redirect( '/fields-limited', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'fields' => 'from' )
		);

		$this->assert_stdout_contains( '/fields-limited' );
		$this->assert_stdout_not_contains( 'https://example.com/dest' );
	}

	/**
	 * Test error for invalid --fields value.
	 */
	public function test_list_invalid_fields(): void {
		$this->create_redirect( '/fields-bad', 'https://example.com/dest' );

		$this->invoke_command(
			$this->command,
			array(),
			array( 'fields' => 'from,bogus' )
		);

		$this->assert_error_contains( 'Invalid fields' );
	}

	/**
	 * Test listing the duplicate sources the migration disabled.
	 */
	public function test_list_duplicates(): void {
		foreach ( array( 'started_gmt', 'cursor', 'ceiling' ) as $option ) {
			delete_option( 'wpcom_legacy_redirector_upgrade_' . $option );
		}

		$live_id     = $this->insert_legacy_redirect( '/clash', 'https://example.com/one' );
		$disabled_id = $this->insert_legacy_redirect( '/clash/', 'https://example.com/two' );
		$this->insert_legacy_redirect( '/unrelated', 'https://example.com/three' );

		( new Upgrader() )->run_batch( 100 );

		$GLOBALS['wp_cli_format_items_calls'] = array();
		$this->invoke_command(
			$this->command,
			array(),
			array(
				'duplicates' => true,
				'format'     => 'csv',
			)
		);

		$this->assertSame(
			array(
				array(
					'csv',
					array(
						array(
							'ID'                => $disabled_id,
							'from'              => '/clash/',
							'to'                => 'https://example.com/two',
							'never_fired'       => 'no',
							'duplicate_of'      => $live_id,
							'duplicate_of_from' => '/clash',
							'duplicate_of_to'   => 'https://example.com/one',
						),
					),
					array( 'ID', 'from', 'to', 'never_fired', 'duplicate_of', 'duplicate_of_from', 'duplicate_of_to' ),
				),
			),
			$GLOBALS['wp_cli_format_items_calls']
		);

		$this->invoke_command(
			$this->command,
			array(),
			array(
				'duplicates' => true,
				'format'     => 'ids',
			)
		);
		$this->assert_stdout_contains( (string) $disabled_id );
	}

	/**
	 * Test listing duplicates when there are none says so.
	 */
	public function test_list_duplicates_with_none(): void {
		$this->invoke_command( $this->command, array(), array( 'duplicates' => true ) );

		$this->assert_success_contains( 'No redirects are disabled as duplicate sources.' );
	}

	/**
	 * Test --duplicates validates fields against its own set.
	 */
	public function test_list_duplicates_rejects_fields_it_does_not_have(): void {
		$this->invoke_command(
			$this->command,
			array(),
			array(
				'duplicates' => true,
				'fields'     => 'ID,status',
			)
		);

		$this->assert_error_contains( 'Invalid fields: status. Available fields: ID, from, to, never_fired, duplicate_of, duplicate_of_from, duplicate_of_to' );
	}

	/**
	 * Store a redirect exactly as version 1.x did.
	 *
	 * @param string $source      The source path.
	 * @param string $destination The destination URL.
	 * @return int The post ID.
	 */
	private function insert_legacy_redirect( string $source, string $destination ): int {
		return (int) wp_insert_post(
			array(
				'post_name'    => md5( $source ),
				'post_title'   => $source,
				'post_excerpt' => $destination,
				'post_type'    => PostType::POST_TYPE,
				'post_status'  => 'draft',
			)
		);
	}
}
