<?php
/**
 * StatusChangeNotices integration tests.
 *
 * @package Automattic\LegacyRedirector\Tests\Integration\Admin
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Tests\Integration\Admin;

use Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Tests\Integration\TestCase;

/**
 * Integration tests for StatusChangeNotices.
 *
 * @covers \Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices\StatusChangeNotices
 */
final class StatusChangeNoticesTest extends TestCase {

	/**
	 * Show the redirects list screen.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		set_current_screen( 'edit-' . PostType::POST_TYPE );
	}

	/**
	 * Clean up request superglobals and the screen.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET = array();
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Test an encoded source is named as stored.
	 *
	 * The source arrives already decoded once by PHP. Decoding it again turned
	 * a stored %2F into a slash, and sanitize_text_field() then deleted any
	 * %xx sequence left, so the notice named a different redirect.
	 */
	public function test_single_notice_shows_encoded_source_as_stored(): void {
		$_GET = array(
			'redirect_enabled' => '1',
			'redirect_source'  => wp_slash( '/a%2Fb?q=a%26b' ),
		);

		ob_start();
		( new StatusChangeNotices() )->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<code>/a%2Fb?q=a%26b</code>', $html );
	}

	/**
	 * Test markup in the source is escaped, not rendered.
	 */
	public function test_single_notice_escapes_source(): void {
		$_GET = array(
			'redirect_disabled' => '1',
			'redirect_source'   => wp_slash( '/<b>x</b>' ),
		);

		ob_start();
		( new StatusChangeNotices() )->display();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<code>/&lt;b&gt;x&lt;/b&gt;</code>', $html );
	}
}
