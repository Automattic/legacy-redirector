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
use InvalidArgumentException;
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
 * 4. Sources are re-keyed to the form SourceUrl gives them, which is the form
 *    a request is looked up by. 1.x hashed each source as esc_url_raw()
 *    left it, so '/caf%C3%A9', '/a%20b' and '/old-page/' all kept keys 2.0
 *    never looks up. They lose their trailing slash, because 2.0 treats
 *    '/old-page' and '/old-page/' as one redirect rather than two, and their
 *    plain-text escapes are decoded. Sites that stored two spellings of one
 *    source, such as both slash forms, will have the two rows converge on
 *    one key; see plan() for how that is resolved.
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
 * and source passes have no such ambiguity and run on every walk.
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
	public const int DB_VERSION = 7;

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
	 * Meta key marking a redirect waiting for a later one to leave the key it re-keys onto.
	 *
	 * See plan(). Holds the ID of the redirect it waits for and the key, as
	 * 'ID:hash'. Almost never set: only a double-prefixed source created before
	 * its prefixed twin waits. Kept on the waiting row rather than in one
	 * option so that two batches running at once, as web requests can, each
	 * add and remove only their own; one overwriting the other's list would
	 * strand a row below the cursor, never migrated.
	 */
	private const string WAITING_META_KEY = '_legacy_redirector_upgrade_waits_for';

	/**
	 * Meta key marking a redirect the migration disabled as a duplicate source.
	 *
	 * Holds the ID of the live redirect whose source it shares, so the
	 * duplicates can be listed long after the run that found them. Saving the
	 * row clears it: a person has acted on it. See forget_duplicate().
	 */
	public const string DUPLICATE_META_KEY = '_legacy_redirector_duplicate_of';

	/**
	 * Meta key marking a disabled duplicate that never fired under 1.x.
	 *
	 * Set when the disabled row's own stored spelling is one no browser ever
	 * requested - see reachable_in_1x() - so disabling it changed nothing for
	 * visitors and it can simply be deleted. Unlike the live row, which the walk
	 * may have re-keyed, the disabled row keeps its 1.x spelling, so this is
	 * known exactly. Cleared with DUPLICATE_META_KEY.
	 */
	public const string NEVER_FIRED_META_KEY = '_legacy_redirector_duplicate_never_fired';

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
	 * Every redirect holding each source hash the batch being processed may re-key onto.
	 *
	 * Keyed hash => (post ID => sort key), an empty list where none does.
	 * Filled by one query per batch in prime_owners() and kept current by
	 * move_owner() as rows move, in place of a lookup query per re-keyed row.
	 * It holds every holder, not only the one a lookup would return, so it
	 * stays exact when a holder moves away - which is what lets a whole batch
	 * be planned before any of it is written. A hash missing from it is
	 * looked up with find_post_by_hash(); see owner_of().
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $owners = array();

	/**
	 * Writes planned but not yet made, by position in the batch; see flush_writes().
	 *
	 * @var array<int, array{post: WP_Post, update: array<string, string>}>
	 */
	private array $pending_writes = array();

	/**
	 * Source hashes the pending writes move a redirect onto or off, as keys.
	 *
	 * A row whose plan would look one of these up waits until the pending
	 * writes are made, so it is planned against what they really did.
	 *
	 * @var array<string, true>
	 */
	private array $touched = array();

	/**
	 * Each written column's limit, or false where a bulk write cannot be trusted with it; see fits_in_bulk().
	 *
	 * @var array<string, array{type: string, length: int, charset: string}|false>
	 */
	private array $column_limits = array();

	/**
	 * Statuses a lookup ignores, as WP_Query's 'any' does; see prime_owners().
	 *
	 * @var string[]
	 */
	private array $ignored_statuses = array( 'trash', 'auto-draft' );

	/**
	 * IDs written in the batch being processed, whose stale audit flags go at its end.
	 *
	 * @var int[]
	 */
	private array $unflagged = array();

	/**
	 * Whether the walk under way repairs what 1.x left in stored destinations; see normalized_excerpt().
	 *
	 * @var bool
	 */
	private bool $from_1x = false;

	/**
	 * Redirects waiting for another to leave a key, by ID, with the ID of the one each waits for and the key; see WAITING_META_KEY.
	 *
	 * @var array<int, array{0: int, 1: string}>
	 */
	private array $waiting = array();

	/**
	 * The highest redirect ID the walk has reached.
	 *
	 * Rows above it, up to the ceiling, are still to be visited. No walk is
	 * under way outside run_batch() and count_pending(), so nothing waits.
	 *
	 * @var int
	 */
	private int $reached = PHP_INT_MAX;

	/**
	 * The ceiling of the walk under way; see CEILING_OPTION.
	 *
	 * @var int
	 */
	private int $walk_ceiling = 0;

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
			// A waiting row is behind the cursor but still to be processed.
			'done'  => $cursor > 0 ? $count( $cursor ) - count( $this->stored_waits() ) : 0,
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
	 * @return array{processed: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], unfired: int, failed: string[], complete: bool}
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
		$stored    = $this->stored_waits();

		$this->waiting      = $stored;
		$this->reached      = $cursor;
		$this->walk_ceiling = $ceiling;

		$posts = $this->query_batch( $cursor, $ceiling, $size );
		$last  = count( $posts ) < $size;

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$cursor = $post->ID;
			}
		}

		$this->process( $posts, $started, $home_path, $publish, $result );

		// Whatever still waits, waits for a redirect deleted since, or for one
		// that is itself waiting on such a redirect.
		while ( $last && array() !== $this->waiting ) {
			$this->reached = PHP_INT_MAX;
			$this->process( $this->query_ids( $this->waiting_on_nobody() ), $started, $home_path, $publish, $result );
		}

		$this->remember_failures( $started, $publish );

		$this->store_waits( $stored );
		update_option( self::CURSOR_OPTION, $cursor, false );
		$this->reached = PHP_INT_MAX;

		// Fewer rows than asked for means the walk has reached the end. The
		// processed count cannot say so: a row can wait for a later batch.
		if ( $last ) {
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
	 * @return array{processed: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], unfired: int, failed: string[], complete: bool}
	 *
	 * @throws RuntimeException When the database refuses a read or bulk write.
	 */
	public function retry_failed(): array {
		$result        = self::empty_result();
		$this->waiting = array();
		$this->reached = PHP_INT_MAX;

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
	 * @return array<int, array{of: int, never_fired: bool}> Each disabled redirect's ID, mapped to the ID of the live redirect whose source it shares, and whether it never fired under 1.x.
	 */
	public function duplicates(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A one-off listing on demand; only duplicate rows carry the keys.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT d.post_id, d.meta_value, n.meta_id AS never_fired FROM {$wpdb->postmeta} d LEFT JOIN {$wpdb->postmeta} n ON n.post_id = d.post_id AND n.meta_key = %s WHERE d.meta_key = %s ORDER BY d.post_id", self::NEVER_FIRED_META_KEY, self::DUPLICATE_META_KEY ) );

		$duplicates = array();
		foreach ( $rows as $row ) {
			$duplicates[ (int) $row->post_id ] = array(
				'of'          => (int) $row->meta_value,
				'never_fired' => null !== $row->never_fired,
			);
		}

		return $duplicates;
	}

	/**
	 * How many redirects the migration disabled as duplicate sources.
	 *
	 * @return int The number of redirects.
	 */
	public static function duplicate_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counts only rows carrying the key; admin screen only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::DUPLICATE_META_KEY ) );
	}

	/**
	 * Drop a redirect's duplicate marker once someone has saved it.
	 *
	 * Hooked to every save of a redirect: trashing the row, or giving it a
	 * source of its own, settles it. Re-pointing or enabling it while the live
	 * redirect holds its source is refused by the repository.
	 *
	 * @param int $post_id The redirect post ID.
	 * @return void
	 */
	public static function forget_duplicate( int $post_id ): void {
		delete_post_meta( $post_id, self::DUPLICATE_META_KEY );
		delete_post_meta( $post_id, self::NEVER_FIRED_META_KEY );
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
	 * @throws RuntimeException When the database refuses a bulk write or the read behind it.
	 */
	private function process( array $posts, string $started, string $home_path, bool $publish, array &$result ): void {
		$this->from_1x = $publish;

		// Without a complete map of who holds each key, rows can only be
		// planned against writes that have already been made.
		if ( ! $this->prime_owners( $posts, $home_path ) ) {
			$this->process_row_by_row( $posts, $started, $home_path, $publish, $result );
			return;
		}

		$this->process_in_bulk( $posts, $started, $home_path, $publish, $result );
	}

	/**
	 * Apply every pass to a batch, writing its changes together wherever that changes nothing.
	 *
	 * Row by row, each write is made before the next row is planned, and a
	 * later row can depend on it: two rows converging on one key collide, and
	 * a row can take a key an earlier one has left. Here each planned write
	 * is recorded in the key map by move_owner() and held back, and the held
	 * writes are made together only when a row about to be planned would look
	 * up a key they touch, and at the end of the batch. So every row is still
	 * planned against the writes before it as they really landed - including
	 * one skipped because a user edited its row meanwhile - and the outcome is
	 * the one row-by-row writing gives, in far fewer statements.
	 *
	 * @param array<WP_Post|null>  $posts     The redirects.
	 * @param string               $started   The GMT timestamp at which the walk began.
	 * @param string               $home_path The home path to strip, or ''.
	 * @param bool                 $publish   Whether draft redirects should be published.
	 * @param array<string, mixed> $result    Running totals, updated by reference.
	 * @return void
	 *
	 * @throws RuntimeException When the database refuses the bulk publish or a read.
	 */
	private function process_in_bulk( array $posts, string $started, string $home_path, bool $publish, array &$result ): void {
		$rows                 = array();
		$this->pending_writes = array();
		$this->touched        = array();

		foreach ( $this->in_walk_order( $posts ) as $post ) {
			if ( $post->post_modified_gmt > $started ) {
				$rows[] = array( 'skip', $post, null, null );
				continue;
			}

			// plan() looks up exactly this key, so if a held write moves a
			// redirect onto or off it, make the held writes first.
			$target = $this->canonical_source( self::hashed_source( $post ), $home_path );
			if ( null !== $target && isset( $this->touched[ md5( $target ) ] ) ) {
				$this->flush_writes( $rows );
			}

			$plan   = $this->plan( $post, $home_path, $publish );
			$update = $plan['update'];

			if ( null !== $plan['wait'] ) {
				$this->waiting[ $post->ID ] = $plan['wait'];
				continue;
			}

			if ( array() === $update ) {
				$rows[] = array( 'unchanged', $post, $plan, null );
				continue;
			}

			if ( array( 'post_status' => 'publish' ) === $update ) {
				$rows[] = array( 'publish', $post, $plan, null );
				continue;
			}

			$position = count( $rows );
			$rows[]   = array( 'write', $post, $plan, null );

			if ( ! $this->fits_in_bulk( $update ) ) {
				// The database would refuse it, and it must be refused
				// against its own row, so it goes alone - after the held
				// writes, to keep their order.
				$this->flush_writes( $rows );
				$rows[ $position ][3] = $this->write_alone( $post, $update );
				continue;
			}

			$this->pending_writes[ $position ] = array(
				'post'   => $post,
				'update' => $update,
			);
			$this->move_owner( $post, $update );

			if ( $this->changes_holders( $post, $update ) ) {
				$this->touched[ $post->post_name ]                         = true;
				$this->touched[ $update['post_name'] ?? $post->post_name ] = true;
			}
		}

		$this->flush_writes( $rows );

		foreach ( $rows as list( $kind, $post, $plan, $outcome ) ) {
			++$result['processed'];

			if ( 'skip' === $kind || 'skipped' === $outcome ) {
				++$result['skipped'];
				continue;
			}

			if ( is_string( $outcome ) && str_starts_with( $outcome, 'failed:' ) ) {
				$result['failed'][] = sprintf( '#%d (%s): %s', $post->ID, $post->post_title, substr( $outcome, 7 ) );
				$this->failed_ids[] = $post->ID;
				continue;
			}

			if ( 'unchanged' === $kind ) {
				++$result['unchanged'];
			} elseif ( 'publish' === $kind ) {
				$this->publish_queue[ $post->ID ] = $post->post_name;
			} else {
				self::tally( $plan['update'], $result );
			}

			$this->mark_duplicate( $post->ID, $plan, $result );

			if ( null !== $plan['conflict'] ) {
				$result['conflicts'][] = $plan['conflict'];
			}
		}

		self::drop_audit_flags( $this->unflagged );
		$this->unflagged = array();

		$this->flush_publish_queue( $started, $result );
	}

	/**
	 * Apply every pass one redirect at a time, writing each change as it is planned.
	 *
	 * For a batch whose key map could not be read, so each row's collision
	 * check has to look in the database, after the writes before it are made.
	 *
	 * @param array<WP_Post|null>  $posts     The redirects.
	 * @param string               $started   The GMT timestamp at which the walk began.
	 * @param string               $home_path The home path to strip, or ''.
	 * @param bool                 $publish   Whether draft redirects should be published.
	 * @param array<string, mixed> $result    Running totals, updated by reference.
	 * @return void
	 *
	 * @throws RuntimeException When the database refuses the bulk publish.
	 */
	private function process_row_by_row( array $posts, string $started, string $home_path, bool $publish, array &$result ): void {
		foreach ( $this->in_walk_order( $posts ) as $post ) {
			// A redirect touched since the upgrade began was acted on by a user
			// under 2.0 rules, where 'draft' means "deliberately disabled".
			// Republishing it would override an explicit choice.
			if ( $post->post_modified_gmt > $started ) {
				++$result['processed'];
				++$result['skipped'];
				continue;
			}

			$plan = $this->plan( $post, $home_path, $publish );

			if ( null !== $plan['wait'] ) {
				$this->waiting[ $post->ID ] = $plan['wait'];
				continue;
			}

			++$result['processed'];

			$conflict = $this->migrate_post( $post, $plan, $result );
			if ( null !== $conflict ) {
				$result['conflicts'][] = $conflict;
			}
		}

		self::drop_audit_flags( $this->unflagged );
		$this->unflagged = array();

		$this->flush_publish_queue( $started, $result );
	}

	/**
	 * A set of redirects in the order the walk plans them.
	 *
	 * ID order, with each redirect waiting for one of them (see plan()) read
	 * afresh and planned straight after it. A row released while its holder
	 * is itself still to move waits again.
	 *
	 * @param array<WP_Post|null> $posts The redirects, in ascending ID order.
	 * @return \Generator<int, WP_Post>
	 */
	private function in_walk_order( array $posts ): \Generator {
		$queue = array_filter( $posts, static fn( $post ): bool => $post instanceof WP_Post );

		while ( array() !== $queue ) {
			// ponytail: scans every wait per row; free while waits are a handful, index by holder if a site ever has thousands.
			$post          = array_shift( $queue );
			$this->reached = max( $this->reached, $post->ID );
			$released      = array_keys( array_filter( $this->waiting, static fn( array $wait ): bool => $post->ID === $wait[0] ) );

			if ( array() !== $released ) {
				$this->waiting = array_diff_key( $this->waiting, array_flip( $released ) );
				$queue         = array_merge( $this->query_ids( $released ), $queue );
			}

			yield $post;
		}
	}

	/**
	 * Whether a redirect holding a key is one the walk has yet to re-key off it.
	 *
	 * True for a row still ahead of the walk, or waiting itself, whose own
	 * source re-keys elsewhere. One a user has edited since is left where it
	 * is when reached, and whatever waited for it then collides with it, as
	 * it would have without waiting.
	 *
	 * @param WP_Post $holder    The redirect holding the key.
	 * @param string  $hash      The key.
	 * @param string  $home_path The home path to strip, or ''.
	 * @return bool True when the walk will move it later.
	 */
	private function moves_later( WP_Post $holder, string $hash, string $home_path ): bool {
		if ( $holder->ID > $this->walk_ceiling || ( $holder->ID <= $this->reached && ! isset( $this->waiting[ $holder->ID ] ) ) ) {
			return false;
		}

		$target = $this->canonical_source( self::hashed_source( $holder ), $home_path );

		return null !== $target && md5( $target ) !== $hash;
	}

	/**
	 * Stop waiting, for the walk's last batch, every redirect whose holder is not itself waiting.
	 *
	 * Such a holder was deleted before the walk reached it. Those waiting on
	 * one that still waits are released after it, by in_walk_order().
	 *
	 * @return int[] The IDs released.
	 */
	private function waiting_on_nobody(): array {
		$ids = array_keys( array_filter( $this->waiting, fn( array $wait ): bool => ! isset( $this->waiting[ $wait[0] ] ) ) );

		// Only a cycle would leave none, and a re-key cannot close one: it
		// guards the caller's loop all the same.
		if ( array() === $ids ) {
			$ids = array_keys( $this->waiting );
		}

		$this->waiting = array_diff_key( $this->waiting, array_flip( $ids ) );

		return $ids;
	}

	/**
	 * The redirects recorded as waiting; see WAITING_META_KEY.
	 *
	 * @return array<int, array{0: int, 1: string}> Each waiting redirect's ID, mapped to the ID of the one it waits for and the key.
	 *
	 * @throws RuntimeException When the database refuses the read.
	 */
	private function stored_waits(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One read per batch; only waiting rows carry the key.
		if ( false === $wpdb->query( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::WAITING_META_KEY ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message for WP-CLI.
			throw new RuntimeException( 'the database could not read the redirects: ' . self::write_error() );
		}

		$waits = array();
		foreach ( $wpdb->last_result as $row ) {
			list( $holder, $hash ) = explode( ':', (string) $row->meta_value, 2 ) + array( '', '' );

			$waits[ (int) $row->post_id ] = array( (int) $holder, $hash );
		}

		return $waits;
	}

	/**
	 * Record the batch's changes to who waits, once the batch has done its work.
	 *
	 * Only this batch's own changes: a row it started waiting, and a row it
	 * released. A batch that throws records nothing, so its rerun releases the
	 * same rows again.
	 *
	 * @param array<int, array{0: int, 1: string}> $stored The waits recorded when the batch began.
	 * @return void
	 */
	private function store_waits( array $stored ): void {
		foreach ( array_diff_key( $this->waiting, $stored ) as $id => list( $holder, $hash ) ) {
			update_post_meta( $id, self::WAITING_META_KEY, $holder . ':' . $hash );
		}

		foreach ( array_keys( array_diff_key( $stored, $this->waiting ) ) as $id ) {
			delete_post_meta( $id, self::WAITING_META_KEY );
		}
	}

	/**
	 * Totals with nothing counted yet.
	 *
	 * @return array{processed: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], unfired: int, failed: string[], complete: bool}
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
			'unfired'    => 0,
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
	 * @return array{total: int, changed: int, unchanged: int, skipped: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], unfired: int}
	 *
	 * @throws RuntimeException When the database refuses a read.
	 */
	public function count_pending( ?callable $progress = null ): array {
		$started   = $this->started_at( false );
		$ceiling   = $this->ceiling( false );
		$publish   = $this->from_pre_2_0_data();
		$home_path = $publish ? $this->home_path() : '';
		$after_id  = 0;
		$claimed   = array();
		$left      = str_repeat( "\0", intdiv( $ceiling, 8 ) + 1 );

		$this->from_1x = $publish;

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
			'unfired'    => 0,
		);

		$this->waiting      = array();
		$this->reached      = 0;
		$this->walk_ceiling = $ceiling;

		do {
			$posts   = $this->query_batch( $after_id, $ceiling, self::BATCH_SIZE );
			$fetched = count( $posts );

			if ( $fetched > 0 ) {
				$after_id = (int) end( $posts )->ID;
			}

			$this->predict( $posts, $started, $home_path, $publish, $pending, $claimed, $left );

			if ( null !== $progress ) {
				$progress( $pending['total'] );
			}
		} while ( self::BATCH_SIZE === $fetched );

		// As in the run's last batch; see run_batch().
		while ( array() !== $this->waiting ) {
			$this->reached = PHP_INT_MAX;
			$this->predict( $this->query_ids( $this->waiting_on_nobody() ), $started, $home_path, $publish, $pending, $claimed, $left );
		}

		$this->reached = PHP_INT_MAX;

		return $pending;
	}

	/**
	 * Predict, for a dry run, what the run does to a set of redirects.
	 *
	 * @param array<WP_Post|null>  $posts     The redirects, in ascending ID order.
	 * @param string               $started   The GMT timestamp at which the walk began.
	 * @param string               $home_path The home path to strip, or ''.
	 * @param bool                 $publish   Whether draft redirects would be published.
	 * @param array<string, mixed> $pending   Running predictions, updated by reference.
	 * @param array<string, int>   $claimed   Keys held since earlier batches, updated by reference; see predict_move().
	 * @param string               $left      Bitmap of rows no longer on their stored key, updated by reference.
	 * @return void
	 *
	 * @throws RuntimeException When the database refuses a read.
	 */
	private function predict( array $posts, string $started, string $home_path, bool $publish, array &$pending, array &$claimed, string &$left ): void {
		if ( ! $this->prime_owners( $posts, $home_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message for WP-CLI.
			throw new RuntimeException( 'the database could not read the redirects: ' . self::write_error() );
		}

		// The database still shows rows an earlier batch would have moved
		// at the keys they would have left.
		foreach ( $this->owners as $hash => $holders ) {
			foreach ( array_keys( $holders ) as $id ) {
				if ( self::has_left( $left, $id ) ) {
					unset( $this->owners[ $hash ][ $id ] );
				}
			}
		}

		foreach ( $this->in_walk_order( $posts ) as $post ) {
			if ( $post->post_modified_gmt > $started ) {
				++$pending['total'];
				++$pending['skipped'];
				continue;
			}

			$plan = $this->plan( $post, $home_path, $publish, $claimed );

			if ( null !== $plan['wait'] ) {
				$this->waiting[ $post->ID ] = $plan['wait'];
				continue;
			}

			++$pending['total'];

			$this->predict_move( $post, $plan['update'], $claimed, $left );

			if ( array() === $plan['update'] ) {
				++$pending['unchanged'];
			} else {
				self::tally( $plan['update'], $pending );
			}

			if ( null !== $plan['conflict'] ) {
				$pending['conflicts'][] = $plan['conflict'];
				$pending['unfired']    += (int) $plan['never_fired'];
			}
		}
	}

	/**
	 * Record, for a dry run, a change the run would make to who holds a key.
	 *
	 * The run writes each change before later rows are planned against it;
	 * the dry run writes nothing, so it keeps them in memory instead. Within a
	 * batch the key map tracks them, as in the run. Across batches, the key a
	 * row comes to hold is remembered by row ID, and a row leaving the key the
	 * database shows it on is marked in a bitmap of row IDs - an eighth of a
	 * byte per row, however large the set.
	 *
	 * @param WP_Post               $post    The redirect as read.
	 * @param array<string, string> $update  The fields the run would change.
	 * @param array<string, int>    $claimed Keys held since earlier batches, updated by reference.
	 * @param string                $left    Bitmap of rows no longer on their stored key, updated by reference.
	 * @return void
	 */
	private function predict_move( WP_Post $post, array $update, array &$claimed, string &$left ): void {
		if ( ! $this->changes_holders( $post, $update ) ) {
			return;
		}

		$this->move_owner( $post, $update );

		if ( ! in_array( $update['post_status'] ?? $post->post_status, $this->ignored_statuses, true ) ) {
			$claimed[ $update['post_name'] ?? $post->post_name ] = $post->ID;
		}

		if ( ! in_array( $post->post_status, $this->ignored_statuses, true ) ) {
			$byte          = intdiv( $post->ID, 8 );
			$left[ $byte ] = chr( ord( $left[ $byte ] ) | ( 1 << ( $post->ID % 8 ) ) );
		}
	}

	/**
	 * Whether a dry run has marked a row as no longer on its stored key; see predict_move().
	 *
	 * @param string $left The bitmap of row IDs.
	 * @param int    $id   The row ID.
	 * @return bool True when the row has left its stored key.
	 */
	private static function has_left( string $left, int $id ): bool {
		$byte = intdiv( $id, 8 );

		return $byte < strlen( $left ) && 0 !== ( ord( $left[ $byte ] ) & ( 1 << ( $id % 8 ) ) );
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

		self::drop_audit_flags( $ids );

		// Batched: the rows, core's cached post queries, and the lookup cache,
		// which holds 0 for a path requested while its redirect was still a
		// draft.
		wp_cache_delete_multiple( $ids, 'posts' );
		wp_cache_set_posts_last_changed();
		wp_cache_delete_multiple(
			array_map( CachingRedirectRepository::cache_key( ... ), array_values( $this->publish_queue ) ),
			CachingRedirectRepository::CACHE_GROUP
		);

		$this->publish_queue = array();
	}

	/**
	 * Look up, in one query, who owns each source hash a batch may re-key onto.
	 *
	 * Mirrors find_post_by_hash(): every status WP_Query's 'any' includes,
	 * which leaves out the trash, and the newest row where several share a
	 * hash. A failed read leaves the map empty, so each row falls back to its
	 * own lookup rather than trusting an answer that was never given.
	 *
	 * @param array<WP_Post|null> $posts     The batch.
	 * @param string              $home_path The home path to strip, or ''.
	 * @return bool False when the read failed, so the map is empty.
	 */
	private function prime_owners( array $posts, string $home_path ): bool {
		global $wpdb;

		$this->owners = array();

		foreach ( $posts as $post ) {
			$new_path = $post instanceof WP_Post ? $this->canonical_source( self::hashed_source( $post ), $home_path ) : null;
			if ( null !== $new_path ) {
				$this->owners[ md5( $new_path ) ] = array();
			}
		}

		// A waiting row can be released into this batch; see in_walk_order().
		foreach ( $this->waiting as list( , $hash ) ) {
			$this->owners[ $hash ] = array();
		}

		if ( array() === $this->owners ) {
			return true;
		}

		$hashes                 = array_keys( $this->owners );
		$excluded               = array_values( get_post_stati( array( 'exclude_from_search' => true ) ) );
		$this->ignored_statuses = $excluded;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One read per batch in place of one per row; the interpolated fragments are only %s placeholders. Prepared just above.
		$sql = $wpdb->prepare(
			"SELECT ID, post_name, post_date FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (" . implode( ',', array_fill( 0, count( $excluded ), '%s' ) ) . ') AND post_name IN (' . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')',
			array_merge( array( PostType::POST_TYPE ), $excluded, $hashes )
		);

		if ( false === $wpdb->query( $sql ) ) {
			$this->owners = array();
			return false;
		}
		// phpcs:enable

		foreach ( $wpdb->last_result as $row ) {
			$this->owners[ $row->post_name ][ (int) $row->ID ] = self::holder_rank( (string) $row->post_date, (int) $row->ID );
		}

		return true;
	}

	/**
	 * The redirect a lookup of a hash would find, as far as the key map knows.
	 *
	 * The newest holder, as find_post_by_hash() orders them, with the ID
	 * settling a tie.
	 *
	 * @param string $hash The source hash.
	 * @return int|null The holder's ID, 0 when the hash is free, or null when the map does not track it.
	 */
	private function owner_of( string $hash ): ?int {
		if ( ! isset( $this->owners[ $hash ] ) ) {
			return null;
		}

		if ( array() === $this->owners[ $hash ] ) {
			return 0;
		}

		return (int) array_search( max( $this->owners[ $hash ] ), $this->owners[ $hash ], true );
	}

	/**
	 * Record in the key map a redirect's planned or written move.
	 *
	 * A row leaves the key it holds, unless a lookup already ignored it there,
	 * and holds its final key, new or old, unless its final status is one a
	 * lookup ignores, such as the trash. Only tracked keys change: an
	 * untracked one is looked up in the database instead.
	 *
	 * @param WP_Post               $post   The redirect as it was read.
	 * @param array<string, string> $update The fields changing.
	 * @return void
	 */
	private function move_owner( WP_Post $post, array $update ): void {
		if ( ! in_array( $post->post_status, $this->ignored_statuses, true ) ) {
			unset( $this->owners[ $post->post_name ][ $post->ID ] );
		}

		$key = $update['post_name'] ?? $post->post_name;
		if ( isset( $this->owners[ $key ] ) && ! in_array( $update['post_status'] ?? $post->post_status, $this->ignored_statuses, true ) ) {
			$this->owners[ $key ][ $post->ID ] = self::holder_rank( $post->post_date, $post->ID );
		}
	}

	/**
	 * Undo move_owner() for a write that did not land after all.
	 *
	 * @param WP_Post               $post   The redirect as it was read.
	 * @param array<string, string> $update The fields that were to change.
	 * @return void
	 */
	private function unmove_owner( WP_Post $post, array $update ): void {
		if ( ! in_array( $update['post_status'] ?? $post->post_status, $this->ignored_statuses, true ) ) {
			unset( $this->owners[ $update['post_name'] ?? $post->post_name ][ $post->ID ] );
		}

		if ( isset( $this->owners[ $post->post_name ] ) && ! in_array( $post->post_status, $this->ignored_statuses, true ) ) {
			$this->owners[ $post->post_name ][ $post->ID ] = self::holder_rank( $post->post_date, $post->ID );
		}
	}

	/**
	 * Whether a write changes which redirects hold a key, as a lookup sees them.
	 *
	 * It does when the row moves, or passes between a status lookups ignore
	 * and one they see. Nothing else a write changes - a destination, or a
	 * status between two that lookups see - can alter another row's plan, as
	 * plan() compares destinations normalized.
	 *
	 * @param WP_Post               $post   The redirect as it was read.
	 * @param array<string, string> $update The fields changing.
	 * @return bool True when the key map changes.
	 */
	private function changes_holders( WP_Post $post, array $update ): bool {
		return isset( $update['post_name'] )
			|| in_array( $post->post_status, $this->ignored_statuses, true ) !== in_array( $update['post_status'] ?? $post->post_status, $this->ignored_statuses, true );
	}

	/**
	 * A sort key ordering a hash's holders as find_post_by_hash() would.
	 *
	 * @param string $post_date The holder's post date.
	 * @param int    $id        The holder's ID.
	 * @return string A string that sorts newest last.
	 */
	private static function holder_rank( string $post_date, int $id ): string {
		return $post_date . ' ' . str_pad( (string) $id, 20, '0', STR_PAD_LEFT );
	}

	/**
	 * Drop the stale audit flags of redirects just written, in one statement.
	 *
	 * @param int[] $ids The redirect post IDs.
	 * @return void
	 */
	private static function drop_audit_flags( array $ids ): void {
		if ( array() === $ids ) {
			return;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The interpolated fragment is only %d placeholders, one per ID. The meta cache is cleaned below.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$placeholders})", array_merge( array( AuditFlags::META_KEY ), $ids ) ) );

		wp_cache_delete_multiple( $ids, 'post_meta' );
	}

	/**
	 * Apply every pass to a single redirect.
	 *
	 * A row needing only the draft→publish flip is not written here: it joins
	 * the publish queue, which run_batch() flushes as one bulk UPDATE.
	 *
	 * @param WP_Post              $post   The redirect post.
	 * @param array<string, mixed> $plan   The redirect's plan; see plan().
	 * @param array<string, mixed> $result Running totals, updated by reference.
	 * @return string|null A description of the conflict, or null when there was none.
	 */
	private function migrate_post( WP_Post $post, array $plan, array &$result ): ?string {
		$update   = $plan['update'];
		$conflict = $plan['conflict'];

		if ( array() === $update ) {
			++$result['unchanged'];
			$this->mark_duplicate( $post->ID, $plan, $result );
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
		$this->mark_duplicate( $post->ID, $plan, $result );

		return $conflict;
	}

	/**
	 * Record which live redirect a disabled duplicate shares its source with; see DUPLICATE_META_KEY.
	 *
	 * Only once the row has reached its planned state: a row whose write
	 * failed is marked when a retry gets it written.
	 *
	 * @param int                  $post_id The drafted redirect.
	 * @param array<string, mixed> $plan    The row's plan; see plan().
	 * @param array<string, mixed> $result  Running totals, updated by reference.
	 * @return void
	 */
	private function mark_duplicate( int $post_id, array $plan, array &$result ): void {
		if ( null === $plan['rival'] ) {
			return;
		}

		update_post_meta( $post_id, self::DUPLICATE_META_KEY, $plan['rival'] );

		if ( $plan['never_fired'] ) {
			update_post_meta( $post_id, self::NEVER_FIRED_META_KEY, 1 );
			++$result['unfired'];
		}
	}

	/**
	 * Whether a browser could ever have reached a source under 1.x.
	 *
	 * 1.x compared each request, as esc_url_raw() left it, with the stored
	 * text. Browsers percent-encode non-ASCII characters and spaces, so a
	 * source stored with them raw never matched; and wherever home is not the
	 * domain root, every request for this site carries the home path, so a
	 * source stored without it never matched either. Anything else could.
	 *
	 * @param string $source    The source as 1.x stored it.
	 * @param string $home_path The site's home path, or '' when home is the domain root.
	 * @return bool False when no browser request could have matched it.
	 */
	private static function reachable_in_1x( string $source, string $home_path ): bool {
		if ( 1 === preg_match( '/[\x80-\xff ]/', $source ) ) {
			return false;
		}

		return '' === $home_path
			|| null !== HomePath::make_relative( substr( $source, 0, strcspn( $source, '?' ) ), $home_path );
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
		$totals['repathed']   += (int) isset( $update['post_title'] );
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
	 * @return array{update: array<string, string>, conflict: string|null, rival: int|null, never_fired: bool, wait: array{0: int, 1: string}|null} The changed fields, a description of any collision with a redirect going somewhere else, that redirect's ID, whether this one never fired under 1.x, and the redirect it must wait for and the key, if any.
	 */
	private function plan( WP_Post $post, string $home_path, bool $publish, array $claimed = array() ): array {
		$update      = array();
		$conflict    = null;
		$rival       = null;
		$collided    = false;
		$never_fired = false;

		$hashed   = self::hashed_source( $post );
		$new_path = $this->canonical_source( $hashed, $home_path );

		if ( null !== $new_path ) {
			$new_hash = md5( $new_path );

			$owner    = $claimed[ $new_hash ] ?? $this->owner_of( $new_hash );
			$existing = null === $owner ? $this->find_post_by_hash( $new_hash ) : ( 0 === $owner ? null : get_post( $owner ) );
			$collided = null !== $existing && $existing->ID !== $post->ID;

			// The key's holder is one the walk has yet to re-key off it: on a
			// site at /sub, a 1.x '/sub/x' holds the key '/sub/sub/x' re-keys
			// onto, until it becomes '/x'. Settling that as a collision would
			// disable this row for a key about to be free, so it waits and is
			// planned again straight after the holder; see in_walk_order().
			if ( $collided && $this->moves_later( $existing, $new_hash, $home_path ) ) {
				return array(
					'update'      => array(),
					'conflict'    => null,
					'rival'       => null,
					'never_fired' => false,
					'wait'        => array( $existing->ID, $new_hash ),
				);
			}

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
					// Only a 1.x row can have fired under 1.x, and its spelling is
					// still the one 1.x stored: a disabled row is never re-keyed.
					$never_fired = $publish && ! self::reachable_in_1x( self::hashed_source( $post ), $home_path );
					$rival       = $existing->ID;
					$conflict    = sprintf(
						'%s → %s (#%d) has the same source as %s → %s (#%d)',
						$post->post_title,
						$this->destination_label( $post ),
						$post->ID,
						$new_path,
						$this->destination_label( $existing ),
						$existing->ID
					);
					if ( $never_fired ) {
						$conflict .= ' (never fired under 1.x)';
					}
				}
			}
		}

		// A kses-escaped title on a row keeping its key - already canonical, or
		// disabled as a duplicate - gets back the text the key was hashed from:
		// every save rebuilds the key from the title, so the next one would
		// move the row.
		if ( ! isset( $update['post_title'] ) && $hashed !== $post->post_title ) {
			$update['post_title'] = $hashed;
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
			'update'      => $update,
			'conflict'    => $conflict,
			'rival'       => $rival,
			'never_fired' => $never_fired,
			'wait'        => null,
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately bypasses wp_update_post(); see above. Caches are cleaned below and in process().
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

		$this->move_owner( $post, $update );
		$this->after_write( $post, $update );

		return $written;
	}

	/**
	 * Make the held writes, all in one statement, and settle each row's outcome.
	 *
	 * Each row keeps write()'s condition - its modified date as read - so a
	 * row a user edited mid-batch is left alone. When every row lands, that is
	 * the end of it. When some do not - an edit, or a refusal of the whole
	 * statement - the rows are read back to see which landed, with no reliance
	 * on a transaction, which not every host's database routing honors. A row
	 * edited since it was read is skipped, and taken back out of the key map
	 * so later rows are planned against where it really is. Any other row that
	 * did not land is written alone with write(), so a refusal is reported
	 * against the row it belongs to.
	 *
	 * @param array<int, array{0: string, 1: WP_Post, 2: array<string, mixed>|null, 3: string|null}> $rows The batch so far; each held row's outcome is set.
	 * @return void
	 *
	 * @throws RuntimeException When the rows cannot be read back.
	 */
	private function flush_writes( array &$rows ): void {
		$writes               = $this->pending_writes;
		$this->pending_writes = array();
		$this->touched        = array();

		if ( array() === $writes ) {
			return;
		}

		if ( count( $writes ) === $this->write_all( $writes ) ) {
			foreach ( $writes as $position => $write ) {
				$this->after_write( $write['post'], $write['update'] );
				$rows[ $position ][3] = 'written';
			}
			return;
		}

		try {
			$now = $this->read_back( array_map( static fn( array $write ): int => $write['post']->ID, $writes ) );
		} catch ( RuntimeException $e ) {
			// Some of these may have landed, and the batch is about to stop
			// before recording which. A rerun will find them already done, so
			// their cached copies must go now or never.
			foreach ( $writes as $write ) {
				$this->after_write( $write['post'], $write['update'] );
			}
			throw $e;
		}

		foreach ( $writes as $position => $write ) {
			$post   = $write['post'];
			$update = $write['update'];
			$row    = $now[ $post->ID ] ?? null;

			// Ours only if the modified date is still as read: a user's edit can
			// leave the planned values in place, and then it, not we, wrote them.
			if ( null !== $row && $row->post_modified_gmt === $post->post_modified_gmt && self::holds( $row, $update ) ) {
				$this->after_write( $post, $update );
				$rows[ $position ][3] = 'written';
				continue;
			}

			$this->unmove_owner( $post, $update );

			$rows[ $position ][3] = null === $row || $row->post_modified_gmt !== $post->post_modified_gmt
				? 'skipped'
				: $this->write_alone( $post, $update );
		}
	}

	/**
	 * Write one redirect's changes with write(), as an outcome for the batch.
	 *
	 * @param WP_Post               $post   The redirect as it was read.
	 * @param array<string, string> $update The fields to change.
	 * @return string 'written-alone', 'skipped', or 'failed:' and the reason.
	 */
	private function write_alone( WP_Post $post, array $update ): string {
		$written = $this->write( $post, $update );

		if ( false === $written ) {
			return 'failed:' . self::write_error();
		}

		return 0 === $written ? 'skipped' : 'written-alone';
	}

	/**
	 * Write held changes in one UPDATE, each row guarded by its modified date as read.
	 *
	 * One CASE per changing column, so each row gets only its own planned
	 * fields and every other row keeps what it has. The column names come
	 * from plan(), never from the data.
	 *
	 * @param array<int, array{post: WP_Post, update: array<string, string>}> $writes The held writes.
	 * @return int|false How many rows changed, or false when the database refused the statement.
	 */
	private function write_all( array $writes ): int|false {
		global $wpdb;

		$ids     = array();
		$columns = array();
		$guard   = array();
		foreach ( $writes as $write ) {
			$id      = $write['post']->ID;
			$ids[]   = $id;
			$guard[] = $id;
			$guard[] = $write['post']->post_modified_gmt;
			foreach ( $write['update'] as $column => $value ) {
				$columns[ $column ][] = $id;
				$columns[ $column ][] = $value;
			}
		}

		$sets = array();
		$args = array();
		foreach ( $columns as $column => $pairs ) {
			$sets[] = "`{$column}` = CASE ID" . str_repeat( ' WHEN %d THEN %s', count( $pairs ) / 2 ) . " ELSE `{$column}` END";
			$args   = array_merge( $args, $pairs );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Every value is a placeholder; the interpolated parts are column names from plan() and placeholder lists. Deliberately bypasses wp_update_post(); see write(). Caches are cleaned in after_write().
		$written = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->posts}` SET " . implode( ', ', $sets )
					. ' WHERE ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')'
					. ' AND post_modified_gmt = CASE ID' . str_repeat( ' WHEN %d THEN %s', count( $ids ) ) . ' END',
				array_merge( $args, $ids, $guard )
			)
		);
		// phpcs:enable

		return false === $written ? false : (int) $written;
	}

	/**
	 * Read back the fields the migration writes, for the given redirects.
	 *
	 * @param int[] $ids The redirect post IDs.
	 * @return array<int, object> The rows that still exist, by ID.
	 *
	 * @throws RuntimeException When the database refuses the read.
	 */
	private function read_back( array $ids ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Must see the table as it is now; the interpolated fragment is only %d placeholders.
		if ( false === $wpdb->query( $wpdb->prepare( "SELECT ID, post_title, post_name, post_status, post_excerpt, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message for WP-CLI.
			throw new RuntimeException( 'the database could not read back a batch of redirects: ' . self::write_error() );
		}

		$rows = array();
		foreach ( $wpdb->last_result as $row ) {
			$rows[ (int) $row->ID ] = $row;
		}

		return $rows;
	}

	/**
	 * Whether a row as read back holds every value a write planned for it.
	 *
	 * @param object                $row    The row as read back.
	 * @param array<string, string> $update The planned fields.
	 * @return bool True when the write landed.
	 */
	private static function holds( object $row, array $update ): bool {
		foreach ( $update as $column => $value ) {
			if ( (string) $row->$column !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a planned write can go in a bulk statement.
	 *
	 * $wpdb->update() refuses a value too long for its column, or not valid in
	 * the column's character set, where one multi-row statement could store it
	 * cut short or mangled instead. So a value goes in a bulk statement only
	 * when it passes the same checks here: it fits, and it is valid UTF-8 for
	 * a UTF-8 column, with no four-byte characters where the column cannot
	 * hold them. Anything else, including a column in another character set,
	 * is written alone, so it is refused and reported exactly as before.
	 *
	 * @param array<string, string> $update The planned fields.
	 * @return bool True when the bulk statement stores it exactly as write() would.
	 */
	private function fits_in_bulk( array $update ): bool {
		global $wpdb;

		foreach ( $update as $column => $value ) {
			if ( ! array_key_exists( $column, $this->column_limits ) ) {
				$length  = $wpdb->get_col_length( $wpdb->posts, $column );
				$charset = $wpdb->get_col_charset( $wpdb->posts, $column );

				$this->column_limits[ $column ] = is_array( $length ) && in_array( $charset, array( 'utf8mb4', 'utf8', 'utf8mb3' ), true )
					? array(
						'type'    => $length['type'],
						'length'  => $length['length'],
						'charset' => $charset,
					)
					: false;
			}

			$limit = $this->column_limits[ $column ];
			if ( false === $limit ) {
				return false;
			}

			if ( 1 !== preg_match( '//u', $value ) ) {
				return false;
			}

			if ( 'utf8mb4' !== $limit['charset'] && 1 === preg_match( '/[\x{10000}-\x{10FFFF}]/u', $value ) ) {
				return false;
			}

			$size = 'byte' === $limit['type'] ? strlen( $value ) : mb_strlen( $value, 'UTF-8' );
			if ( $size > $limit['length'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clean up after a redirect's changes have been written.
	 *
	 * @param WP_Post               $post   The redirect as it was read.
	 * @param array<string, string> $update The fields that changed.
	 * @return void
	 */
	private function after_write( WP_Post $post, array $update ): void {
		// The row itself, and core's cached post queries that could still list
		// it under its old key or status.
		wp_cache_delete( $post->ID, 'posts' );
		wp_cache_set_posts_last_changed();

		// Whatever the last scan said about the row described it before this
		// write, so it goes, as on any save; see AuditFlags. All at once at
		// the end of the batch, rather than two queries per row here.
		$this->unflagged[] = $post->ID;

		// The lookup cache stores 0 for "no redirect here", so a path that was
		// requested while the redirect was still a draft is cached as missing.
		$this->invalidate( $post->post_name );
		if ( isset( $update['post_name'] ) ) {
			$this->invalidate( $update['post_name'] );
		}
	}

	/**
	 * The source text a redirect's key was hashed from.
	 *
	 * Usually the title. But a title saved in a web request by a user without
	 * unfiltered_html, as everyone is on VIP, passes through kses, which
	 * writes a lone '&' as '&amp;' - after the key was hashed from the '&'.
	 * Re-keying from such a title would move the row onto a key no request
	 * produces, so the key decides which text it was.
	 *
	 * A row core trashed carries its key with a '__trashed' suffix.
	 *
	 * @param WP_Post $post The redirect post.
	 * @return string The source text.
	 */
	private static function hashed_source( WP_Post $post ): string {
		return self::hashed_text( $post->post_title, $post->post_name );
	}

	/**
	 * The text a key was hashed from, given the title stored beside it; see hashed_source().
	 *
	 * @param string $title The stored title.
	 * @param string $key   The stored key, with or without core's trash suffix.
	 * @return string The title, unless it is the kses-escaped form of the text the key was hashed from.
	 */
	public static function hashed_text( string $title, string $key ): string {
		$key = str_ends_with( $key, '__trashed' ) ? substr( $key, 0, -9 ) : $key;

		if ( md5( $title ) !== $key ) {
			$unescaped = str_replace( '&amp;', '&', $title );

			if ( md5( $unescaped ) === $key ) {
				return $unescaped;
			}
		}

		return $title;
	}

	/**
	 * The canonical stored form of a source path, or null when already canonical.
	 *
	 * The home path comes off first (only for a site coming from 1.x, where
	 * $home_path is set), then whatever is left goes through SourceUrl, as a
	 * source saved today would. That takes off the trailing slash and settles
	 * the encoding: 1.x keyed each source by the md5 of the text esc_url_raw()
	 * produced, while 2.0 looks a request up by its SourceUrl form, so a 1.x
	 * '/caf%C3%A9' or '/a%20b' keeps a key no request produces until it is
	 * re-keyed to '/café' or '/a b'.
	 *
	 * Delegating rather than repeating the rules means a migrated row and a
	 * freshly saved one cannot disagree. The query is split off only for the
	 * home path, which applies to the path alone.
	 *
	 * SourceUrl's form is a fixed point, so a row already in it comes back
	 * unchanged and the pass is safe to repeat on every walk.
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

		try {
			$canonical = SourceUrl::from_string( $path . $query )->path();
		} catch ( InvalidArgumentException ) {
			// A source SourceUrl cannot parse, such as '//?q=1', still loses
			// its trailing slash, which is often enough to settle it as a
			// duplicate of the parseable form beside it.
			$canonical = SourceUrl::strip_trailing_slash( $path ) . $query;
		}

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
	 * In its canonical form, which it has once this walk has written it, so
	 * the report reads the same however far the walk has got.
	 *
	 * @param WP_Post $post The redirect post.
	 * @return string The destination URL, or the post it points at.
	 */
	private function destination_label( WP_Post $post ): string {
		return $post->post_parent > 0 ? 'post #' . $post->post_parent : ( $this->normalized_excerpt( $post->post_excerpt ) ?? $post->post_excerpt );
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
	 * A destination 1.x saved in a web request by a user without
	 * unfiltered_html went through kses, which wrote each '&' as '&amp;', and
	 * 1.x then sent visitors to the escaped URL. On 1.x data it is un-escaped
	 * first, like the publish pass, only then: 2.0 saves keep the '&', and a
	 * deliberate '&amp;' - or one canonicalizing decoded from '%26amp%3B' - must
	 * survive a later walk.
	 *
	 * @param string $excerpt The stored destination.
	 * @return string|null The normalized destination, or null when already canonical.
	 */
	private function normalized_excerpt( string $excerpt ): ?string {
		$unescaped = $this->from_1x ? str_replace( '&amp;', '&', $excerpt ) : $excerpt;

		if ( str_starts_with( $unescaped, 'http' ) ) {
			$normalized = $this->normalizer->to_internal_path( $unescaped ) ?? $unescaped;
		} elseif ( str_starts_with( $unescaped, '/' ) ) {
			// A relative destination may have been stored in whichever encoding
			// it was entered in; version 4 canonicalizes it the same way saving
			// does now.
			$normalized = $this->normalizer->canonicalize( $unescaped ) ?? $unescaped;
		} else {
			$normalized = $unescaped;
		}

		// Null when nothing changes, so an already-canonical row is neither
		// rewritten nor counted.
		return $normalized === $excerpt ? null : $normalized;
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
		delete_post_meta_by_key( self::WAITING_META_KEY );
		delete_transient( self::CLI_LOCK );
	}
}
