<?php
/**
 * Screen enhancements for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Adds contextual help and page title actions to the redirects list screen.
 */
final class ListScreenSetup {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'current_screen', array( $this, 'add_contextual_help' ) );
		add_action( 'admin_notices', array( $this, 'add_page_title_action' ), 0 );
	}

	/**
	 * Add contextual help tabs to the redirects list screen.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 * @return void
	 */
	public function add_contextual_help( \WP_Screen $screen ): void {
		if ( 'edit-' . PostType::POST_TYPE !== $screen->id ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'legacy-redirector' ),
				'content' => $this->get_overview_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'columns',
				'title'   => __( 'Columns', 'legacy-redirector' ),
				'content' => $this->get_columns_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'actions',
				'title'   => __( 'Actions', 'legacy-redirector' ),
				'content' => $this->get_actions_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'filters',
				'title'   => __( 'Filters', 'legacy-redirector' ),
				'content' => $this->get_filters_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'scan-and-test',
				'title'   => __( 'Scan and Test', 'legacy-redirector' ),
				'content' => $this->get_scan_and_test_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'checks',
				'title'   => __( 'What is checked', 'legacy-redirector' ),
				'content' => $this->get_checks_help(),
			)
		);

		// Two separate questions, deliberately gated separately. Per-site
		// independence is a multisite fact. The base URL prefix is a home-path
		// one: it shows wherever home is not the domain root, which includes a
		// plain single site installed at example.com/blog and excludes the
		// root site of a network.
		if ( is_multisite() ) {
			$screen->add_help_tab(
				array(
					'id'      => 'multisite',
					'title'   => __( 'Multisite', 'legacy-redirector' ),
					'content' => $this->get_multisite_help(),
				)
			);
		}

		if ( '' !== HomePath::current() ) {
			$screen->add_help_tab(
				array(
					'id'      => 'base-url',
					'title'   => __( 'Base URL', 'legacy-redirector' ),
					'content' => $this->get_base_url_help(),
				)
			);
		}

		$screen->set_help_sidebar( $this->get_help_sidebar() );
	}

	/**
	 * Get the overview help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_overview_help(): string {
		return '<p>' . __( 'This screen lists all redirects managed by the Legacy Redirector plugin. Redirects automatically send visitors from old URLs to new destinations.', 'legacy-redirector' ) . '</p>' .
			'<p>' . __( 'Redirects are triggered when a visitor requests a URL that would otherwise return a 404 (Not Found) error. If a matching redirect exists and is enabled, the visitor is automatically redirected to the destination.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the columns help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_columns_help(): string {
		return '<p>' . __( 'The list table displays the following columns:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'Redirect From', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'The source URL path that triggers the redirect. Trailing slashes are ignored, so <code>/old-page</code> and <code>/old-page/</code> are the same redirect and only one of them needs creating.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Redirect To', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'The destination where visitors are sent. Can be a relative path, absolute URL, or post ID.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Status', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Whether the redirect is Enabled (active) or Disabled (inactive).', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Date', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'When the redirect was created.', 'legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'Click the column headers to sort by that column.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the actions help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_actions_help(): string {
		return '<p>' . __( 'Hover over a redirect to reveal these actions:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'Edit', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Modify the redirect source or destination.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Enable/Disable', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Toggle whether the redirect is active.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Test', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'The full, live check of one redirect: runs every stored-data check, requests the destination over HTTP, and requests the source to confirm the redirect actually fires.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Follow', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Test the redirect by visiting the source URL in a new tab.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Trash', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Move the redirect to the trash.', 'legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Get the filters help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_filters_help(): string {
		return '<p>' . __( 'Use the status filters above the table to view:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'All', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'All redirects regardless of status.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Enabled', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Only active redirects.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Disabled', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Only inactive redirects.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Trash', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Deleted redirects awaiting permanent removal.', 'legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'Additionally, you can filter by destination type:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'To Post IDs', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Redirects pointing to a specific post by ID.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'To Paths', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Redirects to relative paths on this site.', 'legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'To External URLs', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Redirects to absolute URLs (may include external sites).', 'legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'After <strong>Scan for issues</strong> has run, a <strong>Has issues</strong> filter shows only the redirects the scan flagged, so bulk actions can be applied straight to them. The filter is a snapshot labeled with its scan time: editing a redirect clears its flag until the next scan. See the Scan and Test help tab for what a scan can and cannot see.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the Scan and Test help content.
	 *
	 * The one place the two kinds of check are set side by side, so nobody
	 * reads a clean scan as a guarantee the redirects work live.
	 *
	 * @return string Help content HTML.
	 */
	private function get_scan_and_test_help(): string {
		return '<p>' . __( 'There are two ways to check redirects, and they look at different evidence:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li>' . __( '<strong>Scan for issues</strong> (the toolbar button) checks the <em>stored details</em> of every redirect, enabled and disabled, in batches. A scan never requests a URL, so it is safe to run at any size, but it cannot see problems only a live request reveals.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Test</strong> (the row action, also available as a bulk action) checks <em>one redirect live</em>: it runs every stored-data check, requests the destination over HTTP, and requests the source to confirm the redirect actually fires. Test results appear in the Health column and are not stored.', 'legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'So a clean scan does not prove a redirect works end to end - a destination page can still be down, and only a Test (or <code>wp legacy-redirector validate --check-urls</code>) can see that. A <strong>problem</strong> means the redirect is broken and will not do its job; a <strong>warning</strong> means it works but deserves a human look, and nothing automated will ever disable it. A row whose stored data cannot be read as a redirect at all is reported as corrupt: delete it, or edit it with a full new source and destination.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the help content describing every check a scan runs.
	 *
	 * @return string Help content HTML.
	 */
	private function get_checks_help(): string {
		return '<p>' . __( 'The source path (Redirect From) is checked for:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li>' . __( '<strong>Reserved WordPress paths</strong> (warning): a source WordPress itself serves, such as <code>/wp-admin</code>, <code>/wp-login.php</code>, the other root <code>wp-*.php</code> files, <code>/xmlrpc.php</code>, or anything under <code>/wp-json</code>, <code>/wp-content</code> or <code>/wp-includes</code>. Such a redirect lies dormant while the path works, because redirects only answer 404s, but it takes over the moment that path breaks; for <code>/wp-admin</code> or <code>/wp-login.php</code> that locks you out of the dashboard. Keep it only if it is a genuine legacy URL.', 'legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'The destination (Redirect To) is checked according to its form:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li>' . __( '<strong>Post ID destinations</strong>: the post must still exist, not be in the trash, and be published. Media attachments count as published.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Relative path destinations</strong>: the path is resolved to a post of any registered post type, including via dated permalinks, and that post must be published. A path whose post was trashed is recognised even though trashing renames the slug. A path that resolves to no post at all - an archive, a rewrite endpoint, a page served outside WordPress - is not reported, because only a live request can judge it.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>External URL destinations</strong>: the host must be in the <code>allowed_redirect_hosts</code> filter, or WordPress will refuse the redirect at request time and the visitor gets a 404.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Possible loops</strong> (warning): a destination that is itself another redirect\'s source, with the hops leading back to where they started. A loop only runs while every source in it returns a 404 - any member serving real content keeps it dormant - so a person should judge it; break a cycle by re-pointing or disabling one member.', 'legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'Whether a URL destination actually responds, and whether the redirect fires for a visitor, are live questions: the Test action answers them for one redirect, and <code>wp legacy-redirector validate --check-urls</code> answers them in bulk from the command line.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the multisite help content.
	 *
	 * Per-site independence only. The base URL prefix is explained separately,
	 * because it depends on where home sits rather than on multisite.
	 *
	 * @return string Help content HTML.
	 */
	private function get_multisite_help(): string {
		return '<p>' . __( 'On multisite installations, each site manages its own redirects independently. Redirects created on one site do not affect other sites in the network.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the base URL help content.
	 *
	 * Only shown where home is not the domain root, which is exactly where the
	 * gray prefix appears and where a bare path would otherwise be ambiguous.
	 *
	 * @return string Help content HTML.
	 */
	private function get_base_url_help(): string {
		return '<p>' . __( 'This site is installed below the domain root, so redirect paths are stored relative to the site\'s base URL rather than to the domain.', 'legacy-redirector' ) . '</p>' .
			'<p>' . __( 'In the Redirect From and Redirect To columns, paths are shown with a gray prefix giving that base URL. This makes clear that a path like <code>/hello-world</code> resolves against the site, not the domain root.', 'legacy-redirector' ) . '</p>' .
			'<p>' . sprintf(
				/* translators: 1: example base URL, 2: example path, 3: example resulting URL */
				esc_html__( 'For example, on a site at %1$s, the path %2$s refers to %3$s.', 'legacy-redirector' ),
				'<code>' . esc_html( untrailingslashit( home_url() ) ) . '</code>',
				'<code>/page</code>',
				'<code>' . esc_html( untrailingslashit( home_url() ) . '/page' ) . '</code>'
			) . '</p>';
	}

	/**
	 * Get the help sidebar content.
	 *
	 * @return string Sidebar HTML.
	 */
	private function get_help_sidebar(): string {
		return '<p><strong>' . __( 'For more information:', 'legacy-redirector' ) . '</strong></p>' .
			'<p><a href="https://github.com/Automattic/legacy-redirector" target="_blank">' . __( 'Plugin Documentation', 'legacy-redirector' ) . '</a></p>' .
			'<p><a href="https://github.com/Automattic/legacy-redirector/issues" target="_blank">' . __( 'Report an Issue', 'legacy-redirector' ) . '</a></p>';
	}

	/**
	 * Add "Add New" button to the page title.
	 *
	 * WordPress doesn't show the "Add New" button for post types without 'create_posts' UI,
	 * so we add it manually via JavaScript.
	 *
	 * @return void
	 */
	public function add_page_title_action(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return;
		}

		$add_new_url = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=add-redirect' );
		$button_text = __( 'Add New', 'legacy-redirector' );
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var title = document.querySelector('.wp-heading-inline');
			if (title) {
				var button = document.createElement('a');
				button.href = <?php echo wp_json_encode( $add_new_url ); ?>;
				button.className = 'page-title-action';
				button.textContent = <?php echo wp_json_encode( $button_text ); ?>;
				title.parentNode.insertBefore(button, title.nextSibling);
			}
		});
		</script>
		<?php
	}
}
