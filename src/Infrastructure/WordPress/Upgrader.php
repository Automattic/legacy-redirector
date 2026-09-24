<?php
/**
 * Data upgrade routine for sites coming from 1.x.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\InternalDestinationNormalizer;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use RuntimeException;
use WP_Post;
use WP_Query;

/**
 * Migrates redirect data created by version 1.x into the shape 2.0 expects.
 *
 * Two independent changes between 1.x and 2.0 stop legacy redirects from
 * firing, and neither reports an error when it happens:
 *
 * 1. 1.x called wp_insert_post() without a post_status, so WordPress stored
 *    every redirect as a draft. 2.0 only serves redirects with the 'publish'
 *    status, so every 1.x redirect is silently inert after an upgrade.
 *
 * 2. Wherever home is not the domain root, 1.x stored source paths including
 *    the home path ('/subsite1/old-page', or '/blog/old-page' on a single
 *    site installed at example.com/blog). 1.x never called home_url(); it
 *    read the raw REQUEST_URI path for both storage and matching, so the two
 *    agreed. 2.0 strips the home path before looking a request up, so it
 *    searches for '/old-page' and never matches what 1.x wrote.
 *
 *    Note this is a home-path question, not a multisite one. A subdirectory
 *    single site is affected exactly as a subsite is, and on such a site
 *    example.com/old-page never reaches WordPress at all.
 *
 * Running only the first migration would publish the redirects but leave the
 * prefixed ones pointing at unreachable keys, which looks like success and is
 * not. Both are therefore handled in a single pass.
 *
 * Two further passes bring stored data into the canonical forms 2.0 writes,
 * and apply to any site, not only one coming from 1.x:
 *
 * 3. Destinations are canonicalized. An absolute destination pointing at this
 *    site ('https://example.com/foo') is rewritten to the relative form
 *    ('/foo'), so anything left absolute is external by construction, and a
 *    relative destination stored as whichever of '/café' or '/caf%C3%A9' was
 *    typed is rewritten to the form the normalizer now produces on save
 *    (path and fragment decoded, query kept percent-encoded).
 *
 * 4. Source paths lose their trailing slash, because 2.0 treats '/old-page'
 *    and '/old-page/' as one redirect rather than two. See
 *    SourceUrl::strip_trailing_slash(). Sites that worked around the old
 *    behavior by storing both forms will have the two rows converge on one
 *    key; see plan() for how that is resolved.
 *
 * Every pass is idempotent per redirect, so re-walking the set is safe.
 *
 * The publish and repath passes only apply when the site is coming from a
 * pre-2.0 data version. Both are 1.x-shape corrections that become unsafe
 * once 2.0 has written data of its own: a 'draft' now means "deliberately
 * disabled", and a source that starts with the site's own home path now has
 * a legitimate reading (on a subsite at /subsite1, the stored '/subsite1/x'
 * is how you redirect the real URL /subsite1/subsite1/x). A later version
 * bump re-walks the whole set, so ungated passes would republish disabled
 * redirects and rewrite those sources into something else. The destination
 * and trailing-slash passes have no such ambiguity and run on every walk.
 *
 * The routine is version-gated so it runs exactly once, and processes in
 * batches so that a site with a very large redirect set completes over
 * several requests rather than timing out on one. `wp legacy-redirector
 * migrate` runs the whole thing in one go and is the better option for large
 * sites.
 */
final class Upgrader {

	/**
	 * The internal destination normalizer.
	 *
	 * @var InternalDestinationNormalizer
	 */
	private InternalDestinationNormalizer $normalizer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->normalizer = new InternalDestinationNormalizer();
	}

	/**
	 * Current data schema version.
	 */
	public const int DB_VERSION = 5;

	/**
	 * The first data version written under 2.0's rules.
	 *
	 * At and above this version a 'draft' redirect was disabled on purpose and
	 * a home-path-prefixed source can be a deliberate double-prefix redirect,
	 * so neither the publish pass nor the repath pass may touch them.
	 */
	private const int FIRST_2_0_VERSION = 2;

	/**
	 * Option holding the site's current data schema version.
	 */
	public const string VERSION_OPTION = 'wpcom_legacy_redirector_db_version';

	/**
	 * Option holding the GMT timestamp at which the upgrade began.
	 */
	private const string STARTED_OPTION = 'wpcom_legacy_redirector_upgrade_started_gmt';

	/**
	 * Option holding the ID of the last redirect a batch processed.
	 *
	 * A keyset cursor: each batch queries ID > cursor, so query cost stays
	 * flat however deep the walk is, where an offset re-reads and discards
	 * every earlier row (O(n²) across a multi-million-row set). A site that
	 * began upgrading while this stored an offset loses nothing: the Nth row's
	 * ID is at least N, so reading an offset as an ID can only re-walk rows,
	 * never skip them, and every pass is idempotent.
	 */
	private const string CURSOR_OPTION = 'wpcom_legacy_redirector_upgrade_cursor';

	/**
	 * Option holding the highest redirect ID when the upgrade began.
	 *
	 * The walk stops here. Anything created later was written by the current
	 * version, so has nothing to migrate - and a redirect created disabled is
	 * a never-modified draft, exactly what a 1.x redirect looks like, so
	 * walking it would publish it.
	 */
	private const string CEILING_OPTION = 'wpcom_legacy_redirector_upgrade_ceiling';

	/**
	 * Transient set while `wp legacy-redirector migrate` runs.
	 *
	 * Web requests hold off their batches while it exists: they would walk
	 * the same rows as the CLI and pull the shared cursor back under it,
	 * sending it over rows it has already done. Short-lived and refreshed
	 * every batch, so a CLI run that dies hands back to web requests within
	 * minutes.
	 */
	private const string CLI_LOCK = 'wpcom_legacy_redirector_upgrade_cli';

	/**
	 * Option holding redirects whose migration write failed, for a later run to retry.
	 *
	 * Keyed by the start time of the walk that failed them, each with whether
	 * that walk published 1.x drafts: a retry has to apply the same passes,
	 * and after completion the version gate would otherwise forbid publishing.
	 * Kept past completion, until every one has been written.
	 */
	private const string RETRY_OPTION = 'wpcom_legacy_redirector_upgrade_retry';

	/**
	 * Meta key marking a redirect the migration disabled as a duplicate source.
	 *
	 * Holds the ID of the live redirect whose source it shares, so the
	 * duplicates can be listed long after the run that found them. Saving the
	 * row clears it: a person has acted on it. See forget_duplicate().
	 */
	public const string DUPLICATE_META_KEY = '_legacy_redirector_duplicate_of';

	/**
	 * Redirects processed per batch when running on a web request.
	 *
	 * Deliberately modest: this runs on `init`, so the cost lands on whichever
	 * visitor happens to trigger it.
	 */
	public const int BATCH_SIZE = 100;

	/**
	 * Drafts needing only the publish flip, keyed ID => stored hash.
	 *
	 * Filled by migrate_post() during a batch and applied by
	 * flush_publish_queue() as one bulk UPDATE at the end of it.
	 *
	 * @var array<int, string>
	 */
	private array $publish_queue = array();

	/**
	 * IDs of redirects whose write failed in the batch being processed.
	 *
	 * @var int[]
	 */
	private array $failed_ids = array();

	/**
	 * Whether this site still has upgrade work outstanding.
	 *
	 * @return bool True if the upgrade has not yet completed.
	 */
	public function needs_upgrade(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::DB_VERSION;
	}

	/**
	 * Run a single batch if the site needs upgrading.
	 *
	 * Safe to call on every request: it costs one autoloaded option read once
	 * the upgrade has completed.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->needs_upgrade() || false !== get_transient( self::CLI_LOCK ) ) {
			return;
		}

		try {
			$this->run_batch( self::BATCH_SIZE );
		} catch ( RuntimeException $e ) {
			// The batch stopped without advancing the cursor, so the next
			// request picks it up again. Nothing to tell a visitor.
			return;
		}
	}

	/**
	 * Keep web requests from running batches for the next few minutes.
	 *
	 * For the CLI to call before each of its batches; see CLI_LOCK.
	 *
	 * @return void
	 */
	public function hold_web_batches(): void {
		set_transient( self::CLI_LOCK, 1, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * How far through the redirect set the upgrade is.
	 *
	 * Counts rather than IDs, for progress reporting: 'total' is every
	 * redirect the walk will visit, 'done' those it has already passed, which
	 * is non-zero when resuming an interrupted run.
	 *
	 * @return array{done: int, total: int}
	 */
	public function position(): array {
		global $wpdb;

		$ceiling = $this->ceiling( false );
		$cursor  = min( (int) get_option( self::CURSOR_OPTION, 0 ), $ceiling );
		$count   = static fn( int $up_to ): int => (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Two counts per CLI run, for its progress output.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND ID <= %d", PostType::POST_TYPE, $up_to )
		);

		return array(
			'done'  => $cursor > 0 ? $count( $cursor ) : 0,
			'total' => $count( $ceiling ),
		);
	}

	/**
	 * Process one batch of redirects.
	 *
	 * Every processed redirect lands in exactly one of 'changed', 'unchanged'
	 * (already in the current shape), 'skipped' (edited since the upgrade
	 * began, so left alone) and 'failed' (the database refused the write), so
	 * those four add up to 'processed'. The per-pass counts break 'changed'
	 * down and can overlap: one redirect can be published and re-keyed.
	 *
	 * A redirect whose write fails is recorded for retry_failed(). A failed
	 * read or bulk write is a database problem rather than a problem with one
	 * row, so it throws instead, leaving the cursor where it was so the next
	 * run redoes the batch.
	 *
	 * @param int $size Maximum number of redirects to process.
	 * @return array{processed: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], failed: string[], complete: bool}
	 *
	 * @throws RuntimeException When the database refuses the batch's read or bulk write.
	 */
	public function run_batch( int $size ): array {
		$started   = $this->started_at();
		$ceiling   = $this->ceiling();
		$cursor    = (int) get_option( self::CURSOR_OPTION, 0 );
		$publish   = $this->from_pre_2_0_data();
		$home_path = $publish ? $this->home_path() : '';
		$result    = self::empty_result();

		$posts = $this->query_batch( $cursor, $ceiling, $size );

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$cursor = $post->ID;
			}
		}

		$this->process( $posts, $started, $home_path, $publish, $result );
		$this->remember_failures( $started, $publish );

		update_option( self::CURSOR_OPTION, $cursor, false );

		if ( $result['processed'] < $size ) {
			$this->complete();
			$result['complete'] = true;
		}

		return $result;
	}

	/**
	 * How many redirects are waiting to have their failed write retried.
	 *
	 * @return int The number of redirects.
	 */
	public function pending_retries(): int {
		return array_sum( array_map( static fn( array $set ): int => count( $set['ids'] ), $this->retry_sets() ) );
	}

	/**
	 * Retry every redirect whose migration write failed, and only those.
	 *
	 * Each is planned again with the passes of the walk that failed it, so a
	 * 1.x draft is still published even though the upgrade has since
	 * completed. Those that fail again stay recorded for the next retry;
	 * those deleted in the meantime drop out.
	 *
	 * @return array{processed: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], failed: string[], complete: bool}
	 *
	 * @throws RuntimeException When the database refuses a read or bulk write.
	 */
	public function retry_failed(): array {
		$result = self::empty_result();

		foreach ( $this->retry_sets() as $started => $set ) {
			$home_path = $set['publish'] ? $this->home_path() : '';

			foreach ( array_chunk( $set['ids'], 2000 ) as $ids ) {
				$this->process( $this->query_ids( $ids ), $started, $home_path, $set['publish'], $result );
			}

			$this->replace_failures( $started, $set['publish'] );
		}

		$result['complete'] = true;

		return $result;
	}

	/**
	 * The redirects the migration disabled as duplicate sources.
	 *
	 * @return array<int, int> Each disabled redirect's ID, mapped to the ID of the live redirect whose source it shares.
	 */
	public function duplicates(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A one-off listing on demand; only duplicate rows carry the key.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", self::DUPLICATE_META_KEY ) );

		return array_map( 'intval', array_column( $rows, 'meta_value', 'post_id' ) );
	}

	/**
	 * Drop a redirect's duplicate marker once someone has saved it.
	 *
	 * Hooked to every save of a redirect, as re-pointing, enabling or
	 * trashing the row are all ways of settling it.
	 *
	 * @param int $post_id The redirect post ID.
	 * @return void
	 */
	public static function forget_duplicate( int $post_id ): void {
		delete_post_meta( $post_id, self::DUPLICATE_META_KEY );
	}

	/**
	 * Apply every pass to a set of redirects, then flush the bulk publish.
	 *
	 * @param array<WP_Post|null>  $posts     The redirects.
	 * @param string               $started   The GMT timestamp at which the walk began.
	 * @param string               $home_path The home path to strip, or ''.
	 * @param bool                 $publish   Whether draft redirects should be published.
	 * @param array<string, mixed> $result    Running totals, updated by reference.
	 * @return void
	 *
	 * @throws RuntimeException When the database refuses the bulk write.
	 */
	private function process( array $posts, string $started, string $home_path, bool $publish, array &$result ): void {
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			++$result['processed'];

			// A redirect touched since the upgrade began was acted on by a user
			// under 2.0 rules, where 'draft' means "deliberately disabled".
			// Republishing it would override an explicit choice.
			if ( $post->post_modified_gmt > $started ) {
				++$result['skipped'];
				continue;
			}

			$conflict = $this->migrate_post( $post, $home_path, $publish, $result );
			if ( null !== $conflict ) {
				$result['conflicts'][] = $conflict;
			}
		}

		$this->flush_publish_queue( $started, $result );
	}

	/**
	 * Totals with nothing counted yet.
	 *
	 * @return array{processed: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], failed: string[], complete: bool}
	 */
	private static function empty_result(): array {
		return array(
			'processed'  => 0,
			'changed'    => 0,
			'unchanged'  => 0,
			'skipped'    => 0,
			'published'  => 0,
			'repathed'   => 0,
			'deduped'    => 0,
			'normalized' => 0,
			'conflicts'  => array(),
			'failed'     => array(),
			'complete'   => false,
		);
	}

	/**
	 * The recorded failures, by the start time of the walk that failed them.
	 *
	 * @return array<string, array{publish: bool, ids: int[]}>
	 */
	private function retry_sets(): array {
		$sets = get_option( self::RETRY_OPTION, array() );

		return is_array( $sets ) ? $sets : array();
	}

	/**
	 * Add this batch's failed redirects to those awaiting a retry.
	 *
	 * @param string $started The GMT timestamp at which the walk began.
	 * @param bool   $publish Whether the walk published 1.x drafts.
	 * @return void
	 */
	private function remember_failures( string $started, bool $publish ): void {
		if ( array() === $this->failed_ids ) {
			return;
		}

		$sets             = $this->retry_sets();
		$sets[ $started ] = array(
			'publish' => $publish,
			'ids'     => array_values( array_unique( array_merge( $sets[ $started ]['ids'] ?? array(), $this->failed_ids ) ) ),
		);

		$this->failed_ids = array();
		update_option( self::RETRY_OPTION, $sets, false );
	}

	/**
	 * Replace one walk's recorded failures with those that failed again.
	 *
	 * @param string $started The GMT timestamp at which the walk began.
	 * @param bool   $publish Whether the walk published 1.x drafts.
	 * @return void
	 */
	private function replace_failures( string $started, bool $publish ): void {
		$sets = $this->retry_sets();
		unset( $sets[ $started ] );

		if ( array() !== $this->failed_ids ) {
			$sets[ $started ] = array(
				'publish' => $publish,
				'ids'     => $this->failed_ids,
			);
		}

		$this->failed_ids = array();

		if ( array() === $sets ) {
			delete_option( self::RETRY_OPTION );
		} else {
			update_option( self::RETRY_OPTION, $sets, false );
		}
	}

	/**
	 * Report what a full run would change, without writing anything.
	 *
	 * Walks the whole redirect set, so it is proportional to the number of
	 * redirects rather than constant time. The counts mean what run_batch()'s
	 * do, as predictions, apart from failures, which only a write can reveal.
	 *
	 * @param callable(int): void|null $progress Called after each batch with the number of redirects checked so far.
	 * @return array{total: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[]}
	 */
	public function count_pending( ?callable $progress = null ): array {
		$started   = $this->started_at( false );
		$ceiling   = $this->ceiling( false );
		$publish   = $this->from_pre_2_0_data();
		$home_path = $publish ? $this->home_path() : '';
		$after_id  = 0;
		$claimed   = array();

		$pending = array(
			'total'      => 0,
			'changed'    => 0,
			'unchanged'  => 0,
			'skipped'    => 0,
			'published'  => 0,
			'repathed'   => 0,
			'deduped'    => 0,
			'normalized' => 0,
			'conflicts'  => array(),
		);

		do {
			$posts = $this->query_batch( $after_id, $ceiling, self::BATCH_SIZE );

			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				++$pending['total'];
				$after_id = $post->ID;

				if ( $post->post_modified_gmt > $started ) {
					++$pending['skipped'];
					continue;
				}

				$plan = $this->plan( $post, $home_path, $publish, $claimed );

				// The run writes each re-key before checking the next row, so
				// two rows re-keyed onto one source collide there. The dry run
				// writes nothing, so it remembers the keys instead - by row ID
				// alone, as a large set can re-key hundreds of thousands.
				if ( isset( $plan['update']['post_name'] ) ) {
					$claimed[ $plan['update']['post_name'] ] = $post->ID;
				}

				if ( array() === $plan['update'] ) {
					++$pending['unchanged'];
				} else {
					self::tally( $plan['update'], $pending );
				}

				if ( null !== $plan['conflict'] ) {
					$pending['conflicts'][] = $plan['conflict'];
				}
			}

			if ( null !== $progress ) {
				$progress( $pending['total'] );
			}

			$fetched = count( $posts );
		} while ( self::BATCH_SIZE === $fetched );

		return $pending;
	}

	/**
	 * Fetch the next batch of redirects after a given post ID.
	 *
	 * Every row of the post type whatever its status, trash included: a row
	 * trashed under 1.x still gets 2.0 shape here, so restoring it later does
	 * not resurrect a stale key.
	 *
	 * A direct query rather than WP_Query, because WP_Query primes the post
	 * cache with every row it returns whenever it splits the query - which it
	 * always does under a persistent object cache - regardless of
	 * cache_results. Across a multi-million-row walk that is millions of cache
	 * writes of rows about to change, and a dry run holding every row in
	 * memory at once.
	 *
	 * @param int $after_id Only redirects with an ID above this are returned.
	 * @param int $ceiling  Nor any with an ID above this; see CEILING_OPTION.
	 * @param int $size     Maximum number of redirects to return.
	 * @return array<WP_Post|null> The redirects, in ascending ID order.
	 */
	private function query_batch( int $after_id, int $ceiling, int $size ): array {
		global $wpdb;

		return $this->fetch(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
				PostType::POST_TYPE,
				$after_id,
				$ceiling,
				$size
			)
		);
	}

	/**
	 * Fetch the redirects with the given IDs, for a retry.
	 *
	 * @param int[] $ids The post IDs.
	 * @return array<WP_Post|null> The redirects that still exist, in ascending ID order.
	 */
	private function query_ids( array $ids ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The interpolated fragment is only %d placeholders, one per ID.
		return $this->fetch( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ({$placeholders}) ORDER BY ID ASC", array_merge( array( PostType::POST_TYPE ), $ids ) ) );
	}

	/**
	 * Run a prepared SELECT of redirect rows, telling an error from no rows.
	 *
	 * $wpdb->get_results() returns an empty array either way, and an error
	 * mistaken for "no more rows" would end the walk early and mark the
	 * upgrade complete with rows never visited.
	 *
	 * @param string $sql The prepared query.
	 * @return array<WP_Post|null> The rows as posts.
	 *
	 * @throws RuntimeException When the database refuses the read.
	 */
	private function fetch( string $sql ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by the callers; must not prime the post cache (see query_batch()).
		if ( false === $wpdb->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message for WP-CLI.
			throw new RuntimeException( 'the database could not read the redirects: ' . self::write_error() );
		}

		// get_post() on a raw row sanitizes it into a WP_Post, with integer
		// IDs, without reading or writing the object cache.
		return array_map( 'get_post', $wpdb->last_result );
	}

	/**
	 * Publish every queued draft in one UPDATE.
	 *
	 * Capped at one batch per statement, so each UPDATE stays small enough to
	 * replicate without lagging replicas. Only the status changes, as with
	 * every migration write; see write().
	 *
	 * @param string               $started The GMT timestamp at which the upgrade began.
	 * @param array<string, mixed> $result  Running totals, updated by reference.
	 * @return void
	 *
	 * @throws RuntimeException When the database refuses the bulk write.
	 */
	private function flush_publish_queue( string $started, array &$result ): void {
		if ( array() === $this->publish_queue ) {
			return;
		}

		global $wpdb;

		$ids          = array_keys( $this->publish_queue );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// The modified-date condition skips any row a user edited between
		// this batch reading it and writing it, so the edit is not overwritten.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Bulk status flip; the interpolated fragment is only %d placeholders, one per ID. Caches are cleaned below.
		$published = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE ID IN ({$placeholders}) AND post_modified_gmt <= %s", array_merge( $ids, array( $started ) ) ) );

		if ( false === $published ) {
			// A status flip on rows that exist has no row-specific way to
			// fail, so this is the database, not the data: stop, and let the
			// next run redo the batch.
			$this->publish_queue = array();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message for WP-CLI.
			throw new RuntimeException( 'the database could not publish a batch of redirects: ' . self::write_error() );
		} else {
			$result['changed']   += (int) $published;
			$result['published'] += (int) $published;
			$result['skipped']   += count( $ids ) - (int) $published;
		}

		// Stale audit flags go in one statement rather than a delete_post_meta()
		// per row, for the reason write() drops them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- As above.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})", array_merge( array( AuditFlags::META_KEY ), $ids ) ) );

		// Batched: the rows and their meta, core's cached post queries, and
		// the lookup cache, which holds 0 for a path requested while its
		// redirect was still a draft.
		wp_cache_delete_multiple( $ids, 'posts' );
		wp_cache_delete_multiple( $ids, 'post_meta' );
		wp_cache_set_posts_last_changed();
		wp_cache_delete_multiple(
			array_map( CachingRedirectRepository::cache_key( ... ), array_values( $this->publish_queue ) ),
			CachingRedirectRepository::CACHE_GROUP
		);

		$this->publish_queue = array();
	}

	/**
	 * Apply every pass to a single redirect.
	 *
	 * A row needing only the draft→publish flip is not written here: it joins
	 * the publish queue, which run_batch() flushes as one bulk UPDATE.
	 *
	 * @param WP_Post              $post      The redirect post.
	 * @param string               $home_path The site's home path, or '' when not a subdirectory site.
	 * @param bool                 $publish   Whether draft redirects should be published.
	 * @param array<string, mixed> $result    Running totals, updated by reference.
	 * @return string|null A description of the conflict, or null when there was none.
	 */
	private function migrate_post( WP_Post $post, string $home_path, bool $publish, array &$result ): ?string {
		$plan     = $this->plan( $post, $home_path, $publish );
		$update   = $plan['update'];
		$conflict = $plan['conflict'];

		if ( array() === $update ) {
			++$result['unchanged'];
			$this->mark_duplicate( $post->ID, $plan['rival'] );
			return $conflict;
		}

		// A row needing nothing but the status flip - no repath, no dedupe, no
		// destination rewrite - queues for one bulk UPDATE per batch instead of
		// a write per row. On a 1.x site that is nearly every row. It is
		// counted when the queue is flushed.
		if ( array( 'post_status' => 'publish' ) === $update ) {
			$this->publish_queue[ $post->ID ] = $post->post_name;
			return $conflict;
		}

		$written = $this->write( $post, $update );

		if ( false === $written ) {
			$result['failed'][] = sprintf( '#%d (%s): %s', $post->ID, $post->post_title, self::write_error() );
			$this->failed_ids[] = $post->ID;
			return null;
		}

		if ( 0 === $written ) {
			++$result['skipped'];
			return null;
		}

		self::tally( $update, $result );
		$this->mark_duplicate( $post->ID, $plan['rival'] );

		return $conflict;
	}

	/**
	 * Record which live redirect a disabled duplicate shares its source with; see DUPLICATE_META_KEY.
	 *
	 * Only once the row has reached its planned state: a row whose write
	 * failed is marked when a retry gets it written.
	 *
	 * @param int      $post_id The drafted redirect.
	 * @param int|null $rival   The live redirect whose source it shares, or null when it is not a duplicate.
	 * @return void
	 */
	private function mark_duplicate( int $post_id, ?int $rival ): void {
		if ( null !== $rival ) {
			update_post_meta( $post_id, self::DUPLICATE_META_KEY, $rival );
		}
	}

	/**
	 * Count one redirect's planned changes into a set of totals.
	 *
	 * @param array<string, string> $update The fields being changed.
	 * @param array<string, mixed>  $totals Running totals, updated by reference.
	 * @return void
	 */
	private static function tally( array $update, array &$totals ): void {
		$status = $update['post_status'] ?? '';

		++$totals['changed'];
		$totals['published']  += (int) ( 'publish' === $status );
		$totals['deduped']    += (int) ( 'trash' === $status );
		$totals['repathed']   += (int) isset( $update['post_name'] );
		$totals['normalized'] += (int) isset( $update['post_excerpt'] );
	}

	/**
	 * Why the last write failed, for the failure report.
	 *
	 * @return string The database's error, or a generic reason when it gave none.
	 */
	private static function write_error(): string {
		global $wpdb;

		return '' !== $wpdb->last_error ? $wpdb->last_error : 'the database did not accept the write';
	}

	/**
	 * Work out what the migration changes about a single redirect.
	 *
	 * The one place that decides, so a dry run cannot report something other
	 * than what the run then does. Only fields whose value actually changes
	 * are included.
	 *
	 * @param WP_Post            $post      The redirect post.
	 * @param string             $home_path The site's home path, or '' when not a subdirectory site.
	 * @param bool               $publish   Whether draft redirects should be published.
	 * @param array<string, int> $claimed   Source hashes a dry run's earlier rows would have been re-keyed to, and the ID of the row.
	 * @return array{update: array<string, string>, conflict: string|null, rival: int|null} The changed fields, a description of any collision with a redirect going somewhere else, and that redirect's ID.
	 */
	private function plan( WP_Post $post, string $home_path, bool $publish, array $claimed = array() ): array {
		$update   = array();
		$conflict = null;
		$rival    = null;
		$collided = false;

		$new_path = $this->canonical_source( $post->post_title, $home_path );

		if ( null !== $new_path ) {
			$new_hash = md5( $new_path );

			$existing = isset( $claimed[ $new_hash ] ) ? get_post( $claimed[ $new_hash ] ) : $this->find_post_by_hash( $new_hash );
			$collided = null !== $existing && $existing->ID !== $post->ID;

			if ( ! $collided ) {
				$update['post_title'] = $new_path;
				$update['post_name']  = $new_hash;
			} elseif ( 'trash' !== $post->post_status ) {
				// Two rows now want one key, and a key can answer for only one
				// redirect. The other row keeps its old key - which no request
				// can produce any more - and is taken out of service. A row
				// already in the trash is out of service, so it has nothing to
				// settle, and drafting it would quietly restore it.
				if ( $this->same_destination( $post, $existing ) ) {
					// Both send visitors to the same place, so the loser is
					// pure redundancy - typically a site that worked around
					// the old trailing-slash behavior by storing both forms.
					// Trash rather than delete: an upgrade running quietly on
					// someone's site should not destroy rows outright.
					$update['post_status'] = 'trash';
				} else {
					// They disagree about where the visitor should land, which
					// only a human can settle. Draft means "deliberately
					// disabled" here, so the row stays visible and editable
					// while plainly not firing.
					if ( 'draft' !== $post->post_status ) {
						$update['post_status'] = 'draft';
					}
					$rival    = $existing->ID;
					$conflict = sprintf(
						'%s → %s (#%d) has the same source as %s → %s (#%d)',
						$post->post_title,
						self::destination_label( $post ),
						$post->ID,
						$new_path,
						self::destination_label( $existing ),
						$existing->ID
					);
				}
			}
		}

		// A row the collision branch has just trashed or drafted must not be
		// resurrected by the publish pass.
		if ( $publish && 'draft' === $post->post_status && ! $collided ) {
			$update['post_status'] = 'publish';
		}

		$normalized = $this->normalized_excerpt( $post->post_excerpt );
		if ( null !== $normalized ) {
			$update['post_excerpt'] = $normalized;
		}

		return array(
			'update'   => $update,
			'conflict' => $conflict,
			'rival'    => $rival,
		);
	}

	/**
	 * Write a single redirect's changes straight to its row.
	 *
	 * Every migration write, this one and the bulk publish alike, changes the
	 * planned fields and nothing else. wp_update_post() would also restamp the
	 * post and modified dates, record the old slug in post meta, suffix the
	 * slug of a trashed row and fire every save hook. The dates matter most:
	 * when a redirect was added is what someone auditing the set wants to
	 * see, and the migration's own write time would bury it.
	 *
	 * @param WP_Post               $post   The redirect post.
	 * @param array<string, string> $update The fields to change.
	 * @return int|false 1 when written, 0 when the row was edited since it was read and so left alone, false when the database refused the write.
	 */
	private function write( WP_Post $post, array $update ): int|false {
		global $wpdb;

		// Matching the modified date as read skips the write if a user has
		// edited the row since, so the edit is not overwritten.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately bypasses wp_update_post(); see above. Caches are cleaned below.
		$written = $wpdb->update(
			$wpdb->posts,
			$update,
			array(
				'ID'                => $post->ID,
				'post_modified_gmt' => $post->post_modified_gmt,
			)
		);

		if ( ! $written ) {
			return $written;
		}

		// The row itself, and core's cached post queries that could still list
		// it under its old key or status.
		wp_cache_delete( $post->ID, 'posts' );
		wp_cache_set_posts_last_changed();

		// Whatever the last scan said about the row described it before this
		// write, so it goes, as on any save; see AuditFlags.
		delete_post_meta( $post->ID, AuditFlags::META_KEY );

		// The lookup cache stores 0 for "no redirect here", so a path that was
		// requested while the redirect was still a draft is cached as missing.
		$this->invalidate( $post->post_name );
		if ( isset( $update['post_name'] ) ) {
			$this->invalidate( $update['post_name'] );
		}

		return $written;
	}

	/**
	 * The canonical stored form of a source path, or null when already canonical.
	 *
	 * Two corrections, in the order storage applies them: the home path comes
	 * off first (only for a site coming from 1.x, where $home_path is set),
	 * then the trailing slash comes off whatever is left.
	 *
	 * The slash rule is delegated to SourceUrl rather than repeated, so a
	 * migrated row and a freshly saved one cannot disagree. Only the query
	 * split is done here: SourceUrl works on the path alone, and a query can
	 * legitimately end in a slash ('/a?b=c/') that must survive.
	 *
	 * @param string $source_path The stored source path, with optional query string.
	 * @param string $home_path   The site's home path, or '' when there is nothing to strip.
	 * @return string|null The canonical path, or null when no rewrite is due.
	 */
	private function canonical_source( string $source_path, string $home_path ): ?string {
		$path  = $source_path;
		$query = '';

		$separator = strpos( $path, '?' );
		if ( false !== $separator ) {
			$query = substr( $path, $separator );
			$path  = substr( $path, 0, $separator );
		}

		if ( '' !== $home_path ) {
			$path = HomePath::make_relative( $path, $home_path ) ?? $path;
		}

		$canonical = SourceUrl::strip_trailing_slash( $path ) . $query;

		return $canonical === $source_path ? null : $canonical;
	}

	/**
	 * Whether two redirects send visitors to the same place.
	 *
	 * Compares post IDs and URLs in their normalized forms, so that a pair
	 * differing only in an encoding this upgrade is about to canonicalize
	 * anyway is not mistaken for a genuine disagreement.
	 *
	 * @param WP_Post $a One redirect.
	 * @param WP_Post $b The other redirect.
	 * @return bool True when both resolve to the same destination.
	 */
	private function same_destination( WP_Post $a, WP_Post $b ): bool {
		if ( $a->post_parent > 0 || $b->post_parent > 0 ) {
			return $a->post_parent === $b->post_parent;
		}

		return ( $this->normalized_excerpt( $a->post_excerpt ) ?? $a->post_excerpt )
			=== ( $this->normalized_excerpt( $b->post_excerpt ) ?? $b->post_excerpt );
	}

	/**
	 * Where a redirect sends visitors, for a duplicate-source report.
	 *
	 * @param WP_Post $post The redirect post.
	 * @return string The destination URL, or the post it points at.
	 */
	private static function destination_label( WP_Post $post ): string {
		return $post->post_parent > 0 ? 'post #' . $post->post_parent : $post->post_excerpt;
	}

	/**
	 * Find a redirect post by its source hash.
	 *
	 * @param string $hash The MD5 hash of the source path.
	 * @return WP_Post|null The post, or null when none exists.
	 */
	private function find_post_by_hash( string $hash ): ?WP_Post {
		$query = new WP_Query(
			array(
				'post_type'              => PostType::POST_TYPE,
				'post_status'            => 'any',
				'name'                   => $hash,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				// Each key is looked up about once per walk, so a cached result
				// is never reused - but it would be stale the moment a row is
				// re-keyed onto that key, and a big walk would pile up a cache
				// entry per lookup.
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! isset( $query->posts[0] ) ) {
			return null;
		}

		// Deliberately an ID query followed by get_post(), rather than letting
		// WP_Query hydrate the post: asking this query for full objects while
		// another WP_Query is mid-iteration returns an empty result set even
		// when the row is plainly there. A collision is settled by comparing
		// the two rows' destinations, so the object is needed either way.
		$post = get_post( (int) $query->posts[0] );

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * The relative form of an internal absolute destination, or null when no rewrite is due.
	 *
	 * @param string $excerpt The stored destination.
	 * @return string|null The normalized destination, or null when already canonical.
	 */
	private function normalized_excerpt( string $excerpt ): ?string {
		if ( str_starts_with( $excerpt, 'http' ) ) {
			return $this->normalizer->to_internal_path( $excerpt );
		}

		// A relative destination may have been stored in whichever encoding
		// it was entered in; version 4 canonicalizes it the same way saving
		// does now. Null when nothing changes, so an already-canonical row is
		// neither rewritten nor counted.
		if ( ! str_starts_with( $excerpt, '/' ) ) {
			return null;
		}

		$canonical = $this->normalizer->canonicalize( $excerpt );

		return null === $canonical || $canonical === $excerpt ? null : $canonical;
	}

	/**
	 * Whether this site's redirect data predates 2.0.
	 *
	 * Gates the publish and repath passes: both correct 1.x shapes that are
	 * legitimate shapes under 2.0, so on a re-walk of already-2.0 data they
	 * would override deliberate choices rather than repair legacy residue.
	 *
	 * @return bool True when the 1.x-shape corrections should run.
	 */
	private function from_pre_2_0_data(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) < self::FIRST_2_0_VERSION;
	}

	/**
	 * The current site's home path, without a trailing slash.
	 *
	 * Empty wherever home is the domain root and there is no prefix for 1.x
	 * to have baked in. What decides that is the home path, not multisite: a
	 * single site installed at example.com/blog carries '/blog' in its 1.x
	 * data exactly as a subsite carries '/subsite1'.
	 *
	 * @return string The home path, or '' when there is none.
	 */
	private function home_path(): string {
		return HomePath::current();
	}

	/**
	 * Invalidate the lookup cache for a source hash.
	 *
	 * @param string $hash The MD5 hash of the source path.
	 * @return void
	 */
	private function invalidate( string $hash ): void {
		wp_cache_delete(
			CachingRedirectRepository::cache_key( $hash ),
			CachingRedirectRepository::CACHE_GROUP
		);
	}

	/**
	 * The GMT timestamp marking the start of this site's upgrade.
	 *
	 * Recorded on first use so that redirects disabled after the upgrade began
	 * can be told apart from 1.x redirects that were never published.
	 *
	 * @param bool $persist Whether to record the timestamp when none is stored yet.
	 * @return string A MySQL-format GMT datetime.
	 */
	private function started_at( bool $persist = true ): string {
		$started = get_option( self::STARTED_OPTION );

		if ( is_string( $started ) && '' !== $started ) {
			return $started;
		}

		$started = current_time( 'mysql', true );

		if ( $persist ) {
			update_option( self::STARTED_OPTION, $started, false );
		}

		return $started;
	}

	/**
	 * The highest redirect ID when the upgrade began; see CEILING_OPTION.
	 *
	 * @param bool $persist Whether to record the ceiling when none is stored yet.
	 * @return int The post ID.
	 */
	private function ceiling( bool $persist = true ): int {
		$ceiling = get_option( self::CEILING_OPTION );

		if ( is_numeric( $ceiling ) ) {
			return (int) $ceiling;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read once per upgrade, then stored.
		$ceiling = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = %s", PostType::POST_TYPE ) );

		if ( $persist ) {
			update_option( self::CEILING_OPTION, $ceiling, false );
		}

		return $ceiling;
	}

	/**
	 * Mark the upgrade as finished and clean up its working state.
	 *
	 * @return void
	 */
	private function complete(): void {
		update_option( self::VERSION_OPTION, self::DB_VERSION );
		delete_option( self::STARTED_OPTION );
		delete_option( self::CURSOR_OPTION );
		delete_option( self::CEILING_OPTION );
		delete_transient( self::CLI_LOCK );
	}
}
