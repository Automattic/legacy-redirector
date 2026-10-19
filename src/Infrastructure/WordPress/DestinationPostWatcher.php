<?php
/**
 * Re-audits redirects when their destination post changes status.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;

/**
 * Flags redirects the moment the post they point at stops (or starts) being public.
 *
 * The commonest breakage - a destination post unpublished, trashed or
 * deleted - happens through an event WordPress announces, so there is no
 * need to wait for the background sweep to reach the affected rows: they
 * are re-audited and flagged straight away, and the menu badge recounted.
 * Republishing or restoring the post clears the flags the same way.
 *
 * Only post-ID destinations are watched: they are stored in the indexed
 * post_parent column, so finding them is one index lookup. Path
 * destinations, loops and allowed-host changes have no such event and are
 * left to the sweep.
 */
final class DestinationPostWatcher {

	/**
	 * Most redirects re-audited for one post's change.
	 *
	 * Bounds the work a single save or delete can trigger in a web request.
	 * ponytail: rows past the cap wait for the sweep; batch them through cron if one post ever has thousands of redirects.
	 *
	 * @var int
	 */
	private const int LIMIT = 1000;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 * @param AuditFlags                       $flags            The per-row audit flags.
	 */
	public function __construct(
		RedirectQueryRepositoryInterface $query_repository,
		RedirectAuditor $auditor,
		AuditFlags $flags
	) {
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->flags            = $flags;
	}

	/**
	 * Register hooks.
	 *
	 * Registered in every context: posts are unpublished and deleted from
	 * WP-CLI, REST and cron as well as the admin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_delete' ), 10, 2 );
	}

	/**
	 * Re-audit a post's redirects when its status changes.
	 *
	 * Any change of status, not only into or out of 'publish': whether the
	 * post is still a working destination is the auditor's rule to apply,
	 * not this hook's. A save that leaves the status alone, and a brand-new
	 * post that nothing can point at yet, cost nothing.
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status || in_array( $old_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		$this->reaudit( $post );
	}

	/**
	 * Re-audit a post's redirects once it has been permanently deleted.
	 *
	 * @param int           $post_id The deleted post's ID.
	 * @param \WP_Post|null $post    The deleted post.
	 * @return void
	 */
	public function on_delete( int $post_id, ?\WP_Post $post = null ): void {
		if ( null === $post ) {
			return;
		}

		// Core clears the deleted post from the object cache only after this
		// hook, so the auditor would still find it there. Attachments have no
		// later hook to wait for, so clear it now; core's clear is harmless.
		clean_post_cache( $post );

		$this->reaudit( $post );
	}

	/**
	 * Re-audit and flag the redirects pointing at a post, then recount the badge.
	 *
	 * @param \WP_Post $post The destination post.
	 * @return void
	 */
	private function reaudit( \WP_Post $post ): void {
		// A redirect row changing status is a redirect being saved, which
		// clears its own flag; it is never another redirect's destination.
		if ( PostType::POST_TYPE === $post->post_type ) {
			return;
		}

		$redirects = $this->query_repository->find_by_destination_post( $post->ID, self::LIMIT );

		if ( array() === $redirects ) {
			return;
		}

		foreach ( $redirects as $redirect ) {
			$this->flags->record_row( (int) $redirect->id(), $this->auditor->audit( $redirect ) );
		}

		$this->flags->store_counts();
	}
}
