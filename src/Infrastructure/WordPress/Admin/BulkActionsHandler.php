<?php
/**
 * Bulk actions handler for redirects.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Application\BatchOutcome;
use Automattic\LegacyRedirector\Application\RedirectBatch;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

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
	 * Redirect batch.
	 *
	 * @var RedirectBatch
	 */
	private RedirectBatch $batch;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager Redirect manager.
	 * @param RedirectBatch   $batch   Redirect batch.
	 */
	public function __construct( RedirectManager $manager, RedirectBatch $batch ) {
		$this->manager = $manager;
		$this->batch   = $batch;
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

		$new_status = 'enable_redirects' === $doaction ? 'publish' : 'draft';

		$items = $this->batch->apply(
			$post_ids,
			fn( Redirect $redirect ) => $this->manager->change_status( (int) $redirect->id(), $new_status )
		);

		$failed = array_values(
			array_filter(
				$items,
				static fn( array $item ): bool => BatchOutcome::SUCCESS !== $item['outcome']
			)
		);

		// Remove previous notification parameters.
		$sendback = remove_query_arg(
			array( 'redirect_enabled', 'redirect_disabled', 'redirect_source', 'bulk_redirects_enabled', 'bulk_redirects_disabled', 'bulk_redirects_failed', 'bulk_redirects_reason' ),
			$sendback
		);

		$status_label = 'publish' === $new_status ? 'enabled' : 'disabled';
		$args         = array( 'bulk_redirects_' . $status_label => RedirectBatch::count_succeeded( $items ) );

		// The reason travels as a code, so the notice words it and nothing
		// from the URL is shown. The usual refusal is a duplicate the 2.0
		// upgrade disabled, whose source another redirect already has.
		if ( array() !== $failed ) {
			$first = $failed[0]['redirect'];

			$args['bulk_redirects_failed'] = count( $failed );
			$args['bulk_redirects_reason'] = null !== $first && '' !== get_post_meta( (int) $first->id(), Upgrader::DUPLICATE_META_KEY, true ) ? 'duplicate' : 'failed';
		}

		return add_query_arg( $args, $sendback );
	}
}
