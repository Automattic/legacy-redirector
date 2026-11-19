<?php
/**
 * Re-audits redirects when their destination post changes status.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;

/**
 * Flags redirects the moment the post they point at stops (or starts) being public.
 *
 * The commonest breakage - a destination post unpublished, trashed or
 * deleted - happens through an event WordPress announces, so there is no
 * need to wait for the background sweep to reach the affected rows: they
 * are re-audited and flagged straight away, and the menu badge recounted.
 * Republishing or restoring the post clears the flags the same way. A post
 * with more redirects than a request should audit has them re-audited by a
 * one-off cron event instead, moments later.
 *
 * Only post-ID destinations are watched: they are stored in the indexed
 * post_parent column, so finding them is one index lookup. Path
 * destinations, loops and allowed-host changes have no such event and are
 * left to the sweep.
 */
final class DestinationPostWatcher {

	/**
	 * The one-off cron event that re-audits a post's redirects.
	 *
	 * @var string
	 */
	public const string HOOK = 'legacy_redirector_reaudit_destination';

	/**
	 * Most redirects re-audited in the request that changed their post.
	 *
	 * Each audit costs a few queries, so a landing page that hundreds of
	 * legacy URLs point at would otherwise slow the editor's save by
	 * thousands of queries. Past this, the work moves to cron.
	 *
	 * @var int
	 */
	private const int REQUEST_LIMIT = 20;

	/**
	 * Most redirects re-audited for one post's change.
	 *
	 * Bounds the work one cron event does.
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
		add_action( self::HOOK, array( $this, 'reaudit_later' ) );
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
		if ( null === $post || ! self::can_be_destination( $post ) ) {
			return;
		}

		// Core clears the deleted post from the object cache only after this
		// hook, so the auditor would still find it there. Attachments have no
		// later hook to wait for, so clear it now; core's clear is harmless.
		clean_post_cache( $post );

		$this->reaudit( $post );
	}

	/**
	 * Re-audit the redirects pointing at a post now, or hand them to cron
	 * when there are too many for the request.
	 *
	 * @param \WP_Post $post The destination post.
	 * @return void
	 */
	private function reaudit( \WP_Post $post ): void {
		if ( ! self::can_be_destination( $post ) ) {
			return;
		}

		$redirects = $this->query_repository->find_by_destination_post( $post->ID, self::REQUEST_LIMIT + 1 );

		if ( count( $redirects ) > self::REQUEST_LIMIT ) {
			// Refused while the same event is already pending, which is fine:
			// it reads the post's redirects when it runs, not now.
			wp_schedule_single_event( time(), self::HOOK, array( $post->ID ) );
			return;
		}

		$this->record( $redirects );
	}

	/**
	 * Re-audit the redirects pointing at a post, from the cron event.
	 *
	 * @param int $post_id The destination post ID.
	 * @return void
	 */
	public function reaudit_later( int $post_id ): void {
		$this->record( $this->query_repository->find_by_destination_post( $post_id, self::LIMIT ) );
	}

	/**
	 * Whether a post could be a redirect's destination at all.
	 *
	 * A redirect row changing status is a redirect being saved, which clears
	 * its own flag, and a revision is never a working destination. Revisions
	 * matter: once a post reaches its revision limit, every save deletes one.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool Whether to look for redirects pointing at it.
	 */
	private static function can_be_destination( \WP_Post $post ): bool {
		return ! in_array( $post->post_type, array( PostType::POST_TYPE, 'revision' ), true );
	}

	/**
	 * Flag a set of redirects from a fresh audit, then recount the badge.
	 *
	 * @param Redirect[] $redirects The redirects.
	 * @return void
	 */
	private function record( array $redirects ): void {
		if ( array() === $redirects ) {
			return;
		}

		$this->auditor->prime( $redirects );

		$findings = array();
		foreach ( $redirects as $redirect ) {
			$findings[ (int) $redirect->id() ] = $this->auditor->audit( $redirect );
		}

		$this->flags->record_rows( $findings );
		$this->flags->store_counts();
	}
}
