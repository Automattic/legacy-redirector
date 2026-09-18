<?php
/**
 * Bulk actions handler for redirects.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles bulk enable/disable actions from the redirects list table.
 */
final class BulkActionsHandler {

	/**
	 * Redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager Redirect manager.
	 */
	public function __construct( RedirectManager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'bulk_actions-edit-' . PostType::POST_TYPE, array( $this, 'modify_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . PostType::POST_TYPE, array( $this, 'handle_bulk_actions' ), 10, 3 );
	}

	/**
	 * Modify bulk actions for the redirect post type.
	 *
	 * Removes the default bulk edit (which doesn't make sense for redirects)
	 * and adds Enable/Disable bulk actions.
	 *
	 * @param array<string, string> $actions Current bulk actions.
	 * @return array<string, string> Modified bulk actions.
	 */
	public function modify_bulk_actions( array $actions ): array {
		unset( $actions['edit'] );

		$actions['enable_redirects']  = __( 'Enable', 'legacy-redirector' );
		$actions['disable_redirects'] = __( 'Disable', 'legacy-redirector' );
		$actions['test_redirects']    = __( 'Test', 'legacy-redirector' );

		return $actions;
	}

	/**
	 * Handle bulk actions for redirects.
	 *
	 * @param string $sendback The redirect URL.
	 * @param string $doaction The action being performed.
	 * @param int[]  $post_ids Array of post IDs.
	 * @return string Modified redirect URL.
	 */
	public function handle_bulk_actions( string $sendback, string $doaction, array $post_ids ): string {
		if ( ! in_array( $doaction, array( 'enable_redirects', 'disable_redirects' ), true ) ) {
			return $sendback;
		}

		// Check capabilities.
		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return $sendback;
		}

		// Perform the bulk operation.
		$updated = 'enable_redirects' === $doaction
			? $this->manager->bulk_enable( $post_ids )
			: $this->manager->bulk_disable( $post_ids );

		// Remove previous notification parameters.
		$sendback = remove_query_arg(
			array( 'redirect_enabled', 'redirect_disabled', 'redirect_source', 'bulk_redirects_enabled', 'bulk_redirects_disabled' ),
			$sendback
		);

		$status_label = 'enable_redirects' === $doaction ? 'enabled' : 'disabled';

		return add_query_arg( 'bulk_redirects_' . $status_label, $updated, $sendback );
	}
}
