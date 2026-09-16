<?php
/**
 * ColumnsManager unit tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\ListTable
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Unit\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\DestinationUrl;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager;
use Automattic\LegacyRedirector\Tests\Unit\MonkeyStubs;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * ColumnsManagerTest class.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 */
final class ColumnsManagerTest extends MonkeyStubs {

	/**
	 * The mock repository.
	 *
	 * @var RedirectRepositoryInterface&Mockery\MockInterface
	 */
	private $repository;

	/**
	 * The manager under test.
	 *
	 * @var ColumnsManager
	 */
	private ColumnsManager $manager;

	/**
	 * Sets up test fixtures.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		Functions\stubs(
			array(
				'untrailingslashit' => static function ( $value ) {
					return rtrim( (string) $value, '/' );
				},
				'admin_url'         => static function ( $path = '' ) {
					return 'https://example.com/wp-admin/' . $path;
				},
			)
		);

		$this->repository = Mockery::mock( RedirectRepositoryInterface::class );
		$this->manager    = new ColumnsManager( $this->repository, Mockery::mock( RedirectAuditor::class ) );
	}

	/**
	 * Installs whose home is not the domain root, where a bare path is ambiguous.
	 *
	 * @return array<string, array{0: string, 1: bool, 2: string}>
	 */
	public function data_subdirectory_installs(): array {
		return array(
			'subdirectory multisite subsite' => array( 'https://example.com/subsite1/', true, 'https://example.com/subsite1' ),
			'subdirectory single site'       => array( 'https://example.com/blog/', false, 'https://example.com/blog' ),
		);
	}

	/**
	 * Installs whose home is the domain root, where a bare path is unambiguous.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function data_root_installs(): array {
		return array(
			'root single site'    => array( 'https://example.com/', false ),
			'subdomain multisite' => array( 'https://site1.example.com/', true ),
		);
	}

	/**
	 * Test the source column carries the home URL prefix wherever home is not the root.
	 *
	 * A single site installed at example.com/blog stores and resolves sources
	 * relative to /blog exactly as a subsite does, so it needs the same prefix.
	 *
	 * @dataProvider data_subdirectory_installs
	 *
	 * @param string $home_url  The site's home URL.
	 * @param bool   $multisite Whether this is a multisite install.
	 * @param string $expected  The prefix expected in the output.
	 * @return void
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_from_column_shows_home_url_prefix_off_root( string $home_url, bool $multisite, string $expected ): void {
		$this->prime_site( $home_url, $multisite );

		$output = $this->render_from_column();

		$this->assertStringContainsString(
			'<span style="color: #888;">' . $expected . '</span>',
			$output,
			'The grey home URL prefix should precede the source path wherever home is not the domain root.'
		);
		$this->assertStringContainsString(
			'>/old-page</a>',
			$output,
			'The prefix must sit outside the anchor so the link text stays the path.'
		);
	}

	/**
	 * Test the source column stays bare wherever home is the domain root.
	 *
	 * Multisite alone must not trigger the prefix: a subdomain multisite has
	 * home at the root and needs no disambiguation.
	 *
	 * @dataProvider data_root_installs
	 *
	 * @param string $home_url  The site's home URL.
	 * @param bool   $multisite Whether this is a multisite install.
	 * @return void
	 *
	 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable\ColumnsManager::render_column
	 */
	public function test_from_column_omits_home_url_prefix_at_root( string $home_url, bool $multisite ): void {
		$this->prime_site( $home_url, $multisite );

		$output = $this->render_from_column();

		$this->assertStringNotContainsString(
			'color: #888;',
			$output,
			'Home at the domain root has no ambiguity, so no prefix should be rendered.'
		);
		$this->assertStringContainsString( '>/old-page</a>', $output );
	}

	/**
	 * Stub the site shape under test.
	 *
	 * @param string $home_url  The site's home URL.
	 * @param bool   $multisite Whether this is a multisite install.
	 * @return void
	 */
	private function prime_site( string $home_url, bool $multisite ): void {
		Functions\when( 'home_url' )->justReturn( $home_url );
		Functions\when( 'is_multisite' )->justReturn( $multisite );
	}

	/**
	 * Render the "from" column for a stub redirect.
	 *
	 * @return string The rendered markup.
	 */
	private function render_from_column(): string {
		$redirect = Redirect::reconstitute(
			123,
			SourceUrl::from_string( '/old-page' ),
			Destination::from_url( DestinationUrl::from_string( '/new-page' ) ),
			'publish'
		);

		$this->repository->shouldReceive( 'find_by_id' )->with( 123 )->andReturn( $redirect );

		ob_start();
		$this->manager->render_column( 'from', 123 );

		return (string) ob_get_clean();
	}
}
