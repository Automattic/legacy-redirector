<?php
/**
 * Add/Edit Redirect screen setup.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages;

use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Sets up the Add/Edit Redirect screens: contextual help tabs and the
 * admin title for the hidden edit page.
 */
final class FormScreenSetup {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'set_edit_page_title' ) );
		add_action( 'current_screen', array( $this, 'add_contextual_help' ) );
	}

	/**
	 * Set the admin title for the hidden edit-redirect page.
	 *
	 * @return void
	 */
	public function set_edit_page_title(): void {
		global $title;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking page for title display.
		if ( isset( $_GET['page'] ) && 'edit-redirect' === $_GET['page'] && empty( $title ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Setting page title for admin screen header.
			$title = __( 'Edit Redirect', 'wpcom-legacy-redirector' );
		}
	}

	/**
	 * Add contextual help to the Add/Edit Redirect pages.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 * @return void
	 */
	public function add_contextual_help( \WP_Screen $screen ): void {
		$form_pages = array(
			PostType::POST_TYPE . '_page_add-redirect',
			PostType::POST_TYPE . '_page_edit-redirect',
		);

		if ( ! in_array( $screen->id, $form_pages, true ) ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'wpcom-legacy-redirector' ),
				'content' => $this->get_form_overview_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'source',
				'title'   => __( 'Redirect From', 'wpcom-legacy-redirector' ),
				'content' => $this->get_source_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'destination',
				'title'   => __( 'Redirect To', 'wpcom-legacy-redirector' ),
				'content' => $this->get_destination_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'tips',
				'title'   => __( 'Tips', 'wpcom-legacy-redirector' ),
				'content' => $this->get_tips_help(),
			)
		);

		$screen->set_help_sidebar( $this->get_help_sidebar() );
	}

	/**
	 * Get the form overview help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_form_overview_help(): string {
		return '<p>' . __( 'Use this form to create or edit a redirect. A redirect automatically sends visitors from one URL to another.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'Redirects are only triggered when the source URL would otherwise return a 404 (Not Found) error. If content already exists at the source URL, the redirect will not activate.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the source field help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_source_help(): string {
		return '<p>' . __( 'The <strong>Redirect From</strong> field specifies the old URL path that should trigger the redirect.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'The leading forward slash is shown as a prefix, so just enter the path. For example:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><code>old-page</code></li>' .
			'<li><code>blog/2020/old-post</code></li>' .
			'<li><code>products/discontinued-item</code></li>' .
			'</ul>' .
			'<p>' . __( 'The path is relative to your site\'s root. Do not include the domain name.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p><strong>' . __( 'Note:', 'wpcom-legacy-redirector' ) . '</strong> ' . __( 'Each source path can only have one redirect. Duplicate sources are not allowed.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the destination field help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_destination_help(): string {
		return '<p>' . __( 'The <strong>Redirect To</strong> field specifies where visitors should be sent. You can use three formats:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<h4>' . __( '1. Relative Path', 'wpcom-legacy-redirector' ) . '</h4>' .
			'<p>' . __( 'A path on the same site, starting with a forward slash:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><code>/new-page</code></li>' .
			'<li><code>/blog/2024/updated-post</code></li>' .
			'</ul>' .
			'<h4>' . __( '2. Absolute URL', 'wpcom-legacy-redirector' ) . '</h4>' .
			'<p>' . __( 'A full URL including the domain, useful for external redirects:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><code>https://example.com/page</code></li>' .
			'<li><code>https://newsite.com/destination</code></li>' .
			'</ul>' .
			'<p><strong>' . __( 'Note:', 'wpcom-legacy-redirector' ) . '</strong> ' . __( 'External domains must be allowed via the <code>allowed_redirect_hosts</code> filter before a redirect can point at them.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<h4>' . __( '3. Post ID', 'wpcom-legacy-redirector' ) . '</h4>' .
			'<p>' . __( 'Select an existing post or page using the search field. The redirect will point to that content\'s permalink, which automatically updates if the slug changes.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the tips help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_tips_help(): string {
		return '<ul>' .
			'<li>' . __( '<strong>Test your redirects</strong> &mdash; After saving, use the "Test it" link or visit the source URL to verify it works.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Use Post IDs for internal content</strong> &mdash; If redirecting to a post or page on your site, selecting it by Post ID ensures the redirect stays valid even if the slug changes.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Avoid redirect chains</strong> &mdash; Don\'t create redirects where the destination is another redirect source. This slows down the user experience.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Check for existing content</strong> &mdash; Redirects only work when the source URL returns a 404. If a page exists at that URL, delete or rename it first.', 'wpcom-legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Get the help sidebar content.
	 *
	 * @return string Sidebar HTML.
	 */
	private function get_help_sidebar(): string {
		return '<p><strong>' . __( 'For more information:', 'wpcom-legacy-redirector' ) . '</strong></p>' .
			'<p><a href="https://github.com/Automattic/WPCOM-Legacy-Redirector" target="_blank">' . __( 'Plugin Documentation', 'wpcom-legacy-redirector' ) . '</a></p>' .
			'<p><a href="https://github.com/Automattic/WPCOM-Legacy-Redirector/issues" target="_blank">' . __( 'Report an Issue', 'wpcom-legacy-redirector' ) . '</a></p>';
	}
}
