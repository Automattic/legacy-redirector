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
 *    key; see migrate_post() for how that is resolved.
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
 * several requests rather than timing out on one. `wp wpcom-legacy-redirector
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
	 * Option holding how far through the redirect set the upgrade has reached.
	 */
	private const string CURSOR_OPTION = 'wpcom_legacy_redirector_upgrade_cursor';

	/**
	 * Redirects processed per batch when running on a web request.
	 *
	 * Deliberately modest: this runs on `init`, so the cost lands on whichever
	 * visitor happens to trigger it.
	 */
	public const int BATCH_SIZE = 100;

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
		if ( ! $this->needs_upgrade() ) {
			return;
		}

		$this->run_batch( self::BATCH_SIZE );
	}

	/**
	 * Process one batch of redirects.
	 *
	 * @param int $size Maximum number of redirects to process.
	 * @return array{processed: int, published: int, repathed: int, deduped: int, normalized: int, conflicts: string[], complete: bool}
	 */
	public function run_batch( int $size ): array {
		$started   = $this->started_at();
		$cursor    = (int) get_option( self::CURSOR_OPTION, 0 );
		$publish   = $this->from_pre_2_0_data();
		$home_path = $publish ? $this->home_path() : '';

		$result = array(
			'processed'  => 0,
			'published'  => 0,
			'repathed'   => 0,
			'deduped'    => 0,
			'normalized' => 0,
			'conflicts'  => array(),
			'complete'   => false,
		);

		$query = new WP_Query(
			array(
				'post_type'              => PostType::POST_TYPE,
				// Every status by name, because 'any' excludes trash: a duplicate
				// trashed by an earlier batch would shrink an 'any' result set
				// and shift unprocessed rows under the offset cursor, silently
				// skipping them. Naming trash keeps the set stable while we
				// mutate statuses as we go.
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => $size,
				'offset'                 => $cursor,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			++$result['processed'];

			// A redirect touched since the upgrade began was acted on by a user
			// under 2.0 rules, where 'draft' means "deliberately disabled".
			// Republishing it would override an explicit choice.
			if ( $post->post_modified_gmt > $started ) {
				continue;
			}

			$conflict = $this->migrate_post( $post, $home_path, $publish, $result );
			if ( null !== $conflict ) {
				$result['conflicts'][] = $conflict;
			}
		}

		$cursor += $result['processed'];
		update_option( self::CURSOR_OPTION, $cursor, false );

		if ( $result['processed'] < $size ) {
			$this->complete();
			$result['complete'] = true;
		}

		return $result;
	}

	/**
	 * Report what a full run would change, without writing anything.
	 *
	 * Walks the whole redirect set, so it is proportional to the number of
	 * redirects rather than constant time.
	 *
	 * @return array{total: int, to_publish: int, to_repath: int, to_dedupe: int, to_normalize: int, conflicts: string[]}
	 */
	public function count_pending(): array {
		$started   = $this->started_at( false );
		$publish   = $this->from_pre_2_0_data();
		$home_path = $publish ? $this->home_path() : '';
		$paged     = 1;

		$pending = array(
			'total'        => 0,
			'to_publish'   => 0,
			'to_repath'    => 0,
			'to_dedupe'    => 0,
			'to_normalize' => 0,
			'conflicts'    => array(),
		);

		do {
			$query = new WP_Query(
				array(
					'post_type'              => PostType::POST_TYPE,
					// The same status list run_batch() walks, so the dry-run
					// counts describe the same set of rows the run will touch.
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => self::BATCH_SIZE,
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				)
			);

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				++$pending['total'];

				if ( $post->post_modified_gmt > $started ) {
					continue;
				}

				if ( $publish && 'draft' === $post->post_status ) {
					++$pending['to_publish'];
				}

				if ( null !== $this->normalized_excerpt( $post->post_excerpt ) ) {
					++$pending['to_normalize'];
				}

				$new_path = $this->canonical_source( $post->post_title, $home_path );

				if ( null === $new_path ) {
					continue;
				}

				$existing = $this->find_post_by_hash( md5( $new_path ) );

				if ( null !== $existing && $existing->ID !== $post->ID ) {
					if ( $this->same_destination( $post, $existing ) ) {
						++$pending['to_dedupe'];
						continue;
					}

					$pending['conflicts'][] = sprintf(
						'#%d (%s) would collide with #%d (%s) and be drafted',
						$post->ID,
						$post->post_title,
						$existing->ID,
						$new_path
					);
					continue;
				}

				++$pending['to_repath'];
			}

			$fetched = count( $query->posts );
			++$paged;
		} while ( self::BATCH_SIZE === $fetched );

		return $pending;
	}

	/**
	 * Apply both migrations to a single redirect.
	 *
	 * @param WP_Post              $post      The redirect post.
	 * @param string               $home_path The site's home path, or '' when not a subdirectory site.
	 * @param bool                 $publish   Whether draft redirects should be published.
	 * @param array<string, mixed> $result    Running totals, updated by reference.
	 * @return string|null A description of the conflict, or null when there was none.
	 */
	private function migrate_post( WP_Post $post, string $home_path, bool $publish, array &$result ): ?string {
		$update      = array();
		$old_hash    = $post->post_name;
		$source_path = $post->post_title;
		$conflict    = null;

		$new_path = $this->canonical_source( $source_path, $home_path );

		if ( null !== $new_path ) {
			$new_hash = md5( $new_path );

			$existing = $this->find_post_by_hash( $new_hash );
			if ( null !== $existing && $existing->ID !== $post->ID ) {
				// Two rows now want one key. Only one can survive: the loser
				// must not keep its old post_name (no request will produce it
				// again) and must not take the new one either, because two
				// rows contending for one slug would send this through
				// wp_unique_post_slug() and silently suffix it, leaving the
				// redirect findable under neither.
				if ( $this->same_destination( $post, $existing ) ) {
					// Both send visitors to the same place, so the loser is
					// pure redundancy - typically a site that worked around
					// the old trailing-slash behavior by storing both forms.
					// Trash rather than delete: an upgrade running quietly on
					// someone's site should not destroy rows outright.
					$update['post_status'] = 'trash';
					++$result['deduped'];
				} else {
					// They disagree about where the visitor should land, which
					// only a human can settle. Draft means "deliberately
					// disabled" here, so the row stays visible and editable
					// while plainly not firing.
					$update['post_status'] = 'draft';
					$conflict              = sprintf(
						'#%d (%s) collides with #%d (%s) and has been drafted',
						$post->ID,
						$source_path,
						$existing->ID,
						$new_path
					);
				}
			} else {
				$update['post_title'] = $new_path;
				$update['post_name']  = $new_hash;
				++$result['repathed'];
			}
		}

		// isset(): a row the collision branch has just trashed or drafted must
		// not be resurrected by the publish pass a moment later.
		if ( $publish && 'draft' === $post->post_status && ! isset( $update['post_status'] ) ) {
			$update['post_status'] = 'publish';
			++$result['published'];
		}

		$normalized = $this->normalized_excerpt( $post->post_excerpt );
		if ( null !== $normalized ) {
			$update['post_excerpt'] = $normalized;
			++$result['normalized'];
		}

		if ( array() === $update ) {
			return $conflict;
		}

		$update['ID'] = $post->ID;
		wp_update_post( $update );

		// The lookup cache stores 0 for "no redirect here", so a path that was
		// requested while the redirect was still a draft is cached as missing.
		$this->invalidate( $old_hash );
		if ( isset( $update['post_name'] ) ) {
			$this->invalidate( $update['post_name'] );
		}

		return $conflict;
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
	 * Mark the upgrade as finished and clean up its working state.
	 *
	 * @return void
	 */
	private function complete(): void {
		update_option( self::VERSION_OPTION, self::DB_VERSION );
		delete_option( self::STARTED_OPTION );
		delete_option( self::CURSOR_OPTION );
	}
}
