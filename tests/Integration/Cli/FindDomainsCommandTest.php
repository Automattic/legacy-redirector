<?php
/**
 * FindDomainsCommand CLI integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Cli
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Cli;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\FindDomainsCommand;

/**
 * Integration tests for FindDomainsCommand.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Cli\FindDomainsCommand
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::invalidate_lookup_cache
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::key_redirect_leaving_the_trash
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\DestinationPostWatcher
 */
final class FindDomainsCommandTest extends CliTestCase {

	/**
	 * Test a host only trashed redirects use is left out, and the count agrees with the admin's.
	 */
	public function test_find_domains_leaves_out_the_trash(): void {
		$this->insert_redirect_post(
			array(
				'post_title'   => '/live',
				'post_name'    => md5( '/live' ),
				'post_excerpt' => 'https://live.example/a',
			)
		);
		$this->insert_redirect_post(
			array(
				'post_title'   => '/disabled',
				'post_name'    => md5( '/disabled' ),
				'post_excerpt' => 'https://disabled.example/b',
				'post_status'  => 'draft',
			)
		);
		wp_trash_post(
			$this->insert_redirect_post(
				array(
					'post_title'   => '/trashed',
					'post_name'    => md5( '/trashed' ),
					'post_excerpt' => 'https://trashed.example/c',
				)
			)
		);

		$this->invoke_command( new FindDomainsCommand( $this->query_repository() ), array(), array( 'format' => 'csv' ) );

		$this->assertSame( array( 'disabled.example', 'live.example' ), explode( "\n", trim( $this->get_stdout() ) ) );
		$this->assertSame( 2, $this->query_repository()->count_external_destinations() );
		$this->assertSame( $this->query_repository()->count_by_destination_type()['external'], $this->query_repository()->count_external_destinations() );
	}
}
