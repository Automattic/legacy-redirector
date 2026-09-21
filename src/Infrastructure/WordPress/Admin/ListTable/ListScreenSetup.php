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
			'<li><strong>' . __( 'Validate', 'legacy-redirector' ) . '</strong> &mdash; ' . __( 'Check if the redirect works correctly and the destination is accessible.', 'legacy-redirector' ) . '</li>' .
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
			'<p>' . __( 'The <strong>Check all</strong> button in the toolbar above the list audits every redirect (enabled and disabled) in batches, without making HTTP requests. Afterwards, a <strong>Has issues</strong> filter shows only the redirects whose last check found a problem or warning, so bulk actions can be applied straight to them. The filter is a snapshot labelled with its check time: editing a redirect clears its flag until the next check.', 'legacy-redirector' ) . '</p>';
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
