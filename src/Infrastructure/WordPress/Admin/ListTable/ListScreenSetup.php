<?php
/**
 * Screen enhancements for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

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
				'title'   => __( 'Overview', 'wpcom-legacy-redirector' ),
				'content' => $this->get_overview_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'columns',
				'title'   => __( 'Columns', 'wpcom-legacy-redirector' ),
				'content' => $this->get_columns_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'actions',
				'title'   => __( 'Actions', 'wpcom-legacy-redirector' ),
				'content' => $this->get_actions_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'filters',
				'title'   => __( 'Filters', 'wpcom-legacy-redirector' ),
				'content' => $this->get_filters_help(),
			)
		);

		if ( is_multisite() ) {
			$screen->add_help_tab(
				array(
					'id'      => 'multisite',
					'title'   => __( 'Multisite', 'wpcom-legacy-redirector' ),
					'content' => $this->get_multisite_help(),
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
		return '<p>' . __( 'This screen lists all redirects managed by the WPCOM Legacy Redirector plugin. Redirects automatically send visitors from old URLs to new destinations.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'Redirects are triggered when a visitor requests a URL that would otherwise return a 404 (Not Found) error. If a matching redirect exists and is enabled, the visitor is automatically redirected to the destination.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the columns help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_columns_help(): string {
		return '<p>' . __( 'The list table displays the following columns:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'Redirect From', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'The source URL path that triggers the redirect.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Redirect To', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'The destination where visitors are sent. Can be a relative path, absolute URL, or post ID.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Status', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Whether the redirect is Enabled (active) or Disabled (inactive).', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Date', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'When the redirect was created.', 'wpcom-legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'Click the column headers to sort by that column.', 'wpcom-legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the actions help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_actions_help(): string {
		return '<p>' . __( 'Hover over a redirect to reveal these actions:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'Edit', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Modify the redirect source or destination.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Enable/Disable', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Toggle whether the redirect is active.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Validate', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Check if the redirect works correctly and the destination is accessible.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Follow', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Test the redirect by visiting the source URL in a new tab.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Trash', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Move the redirect to the trash.', 'wpcom-legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Get the filters help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_filters_help(): string {
		return '<p>' . __( 'Use the status filters above the table to view:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'All', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'All redirects regardless of status.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Enabled', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Only active redirects.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Disabled', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Only inactive redirects.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'Trash', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Deleted redirects awaiting permanent removal.', 'wpcom-legacy-redirector' ) . '</li>' .
			'</ul>' .
			'<p>' . __( 'Additionally, you can filter by destination type:', 'wpcom-legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li><strong>' . __( 'To Post IDs', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Redirects pointing to a specific post by ID.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'To Paths', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Redirects to relative paths on this site.', 'wpcom-legacy-redirector' ) . '</li>' .
			'<li><strong>' . __( 'To External URLs', 'wpcom-legacy-redirector' ) . '</strong> &mdash; ' . __( 'Redirects to absolute URLs (may include external sites).', 'wpcom-legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Get the multisite help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_multisite_help(): string {
		return '<p>' . __( 'On multisite installations, each site manages its own redirects independently. Redirects created on one site do not affect other sites in the network.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'In the Redirect To column, relative paths are shown with a grey prefix indicating the site\'s base URL. This helps clarify that a path like <code>/hello-world</code> resolves to the current site, not the network root.', 'wpcom-legacy-redirector' ) . '</p>' .
			'<p>' . __( 'For example, on a subsite at <code>example.com/site2</code>, the destination <code>/page</code> redirects to <code>example.com/site2/page</code>.', 'wpcom-legacy-redirector' ) . '</p>';
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
		$button_text = __( 'Add New', 'wpcom-legacy-redirector' );
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
