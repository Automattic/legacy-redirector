<?php
/**
 * Saving redirects as a user whose writes kses filters.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration;

use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Integration tests for redirects saved by a user without unfiltered_html, as everyone is on VIP.
 *
 * WP-CLI with no user and the test suite's default user skip kses, which is
 * how a save that escaped every '&' went unnoticed.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostType::undo_ampersand_escaping
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository::save
 * @uses \Automattic\LegacyRedirector\Application\HomePath
 * @uses \Automattic\LegacyRedirector\Application\InternalDestinationNormalizer
 * @uses \Automattic\LegacyRedirector\Application\RedirectAuditor
 * @uses \Automattic\LegacyRedirector\Application\RedirectCreationResult
 * @uses \Automattic\LegacyRedirector\Application\RedirectManager
 * @uses \Automattic\LegacyRedirector\Application\RedirectValidator
 * @uses \Automattic\LegacyRedirector\Application\ValidationResult
 * @uses \Automattic\LegacyRedirector\Domain\Destination
 * @uses \Automattic\LegacyRedirector\Domain\DestinationUrl
 * @uses \Automattic\LegacyRedirector\Domain\Redirect
 * @uses \Automattic\LegacyRedirector\Domain\SourceUrl
 * @uses \Automattic\LegacyRedirector\Domain\Url
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\CachingRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Capability
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectRepository
 * @uses \Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader::forget_duplicate
 */
final class AmpersandEscapingTest extends TestCase {

	/**
	 * Log in as an author who can manage redirects, which attaches kses.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Capability() )->register();
		$user = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		$user->add_cap( Capability::MANAGE_REDIRECTS_CAPABILITY );
		wp_set_current_user( $user->ID );
	}

	/**
	 * Log out, which takes kses off again.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		kses_remove_filters();

		parent::tear_down();
	}

	/**
	 * Test a source and destination with ampersands survive saving, editing, trashing and restoring.
	 */
	public function test_ampersands_survive_every_save(): void {
		$this->assertNotFalse( has_filter( 'title_save_pre', 'wp_filter_kses' ), 'kses is not attached, so this test proves nothing.' );

		$id = $this->create_redirect( '/find?q=a&page=2', '/new?a=1&b=2' );
		$this->assertStored( $id, '/find?q=a&page=2', '/new?a=1&b=2' );

		$this->assertTrue( $this->manager()->disable( $id ) );
		$this->assertTrue( $this->manager()->enable( $id ) );
		$this->assertFalse( $this->manager()->update_destination( $id, Destination::from_mixed( '/newer?a=1&b=2' ) )->is_error() );
		wp_trash_post( $id );
		wp_untrash_post( $id );
		$this->assertTrue( $this->manager()->enable( $id ) );

		$this->assertStored( $id, '/find?q=a&page=2', '/newer?a=1&b=2' );
		$this->clear_lookup_cache( '/find?q=a&page=2' );
		$found = $this->repository()->find_by_source( SourceUrl::from_string( '/find?q=a&page=2' ) );
		$this->assertInstanceOf( Redirect::class, $found );
		$this->assertSame( '/newer?a=1&b=2', $found->destination()->as_url()->value() );
	}

	/**
	 * Test markup in a destination is still stripped: only the ampersand escaping is undone.
	 */
	public function test_markup_is_still_filtered(): void {
		$id = $this->create_redirect( '/markup', 'https://example.com/x?a=1&b=<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script', get_post( $id )->post_excerpt );
	}

	/**
	 * Test kses's escaping of an entity inside a tag is never undone.
	 *
	 * Older kses disarms 'javascript&colon;' by writing its '&' as '&amp;',
	 * the only change it makes, so without the guard on markup the escaping
	 * would be undone. Called directly, because current kses strips the
	 * attribute outright and a save cannot show the older behavior.
	 */
	public function test_escaping_inside_markup_stands(): void {
		$given = '<a href="javascript&colon;alert(1)">x</a>';
		$data  = array(
			'post_type'  => PostType::POST_TYPE,
			'post_title' => '<a href="javascript&amp;colon;alert(1)">x</a>',
		);

		$this->assertSame( $data, ( new PostType() )->undo_ampersand_escaping( $data, $data, array( 'post_title' => $given ) ) );
		$this->assertSame( $data, ( new PostType() )->undo_ampersand_escaping( $data, $data ) );
	}

	/**
	 * Test a backslash in a destination is not taken out as a slash.
	 */
	public function test_backslash_survives(): void {
		$id = $this->create_redirect( '/backslash', 'https://example.com/a\b' );

		$this->assertSame( 'https://example.com/a\b', get_post( $id )->post_excerpt );
	}

	/**
	 * Assert what a redirect's row holds.
	 *
	 * @param int    $id          The redirect.
	 * @param string $source      The expected title, whose md5 must be the key.
	 * @param string $destination The expected excerpt.
	 * @return void
	 */
	private function assertStored( int $id, string $source, string $destination ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Named like PHPUnit's own assertions.
		$post = get_post( $id );

		$this->assertSame( $source, $post->post_title );
		$this->assertSame( md5( $source ), $post->post_name );
		$this->assertSame( $destination, $post->post_excerpt );
	}
}
