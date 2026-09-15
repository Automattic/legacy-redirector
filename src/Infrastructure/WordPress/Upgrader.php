<?php
/**
 * Data upgrade routine for sites coming from 1.x.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Application\InternalDestinationNormaliser;
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
 * 2. On a subdirectory multisite, 1.x stored source paths network-absolute
 *    ('/subsite1/old-page'), because it prefixed home_url() before saving.
 *    2.0 strips the subsite prefix before looking a request up, so it
 *    searches for '/old-page' and never matches what 1.x wrote.
 *
 * Running only the first migration would publish the redirects but leave
 * subsite ones still pointing at unreachable keys, which looks like success
 * and is not. Both are therefore handled in a single pass.
 *
 * Version 3 adds destination normalisation: absolute destination URLs that
 * point at this site (e.g. 'https://example.com/foo') are rewritten to the
 * relative form ('/foo') that 2.0 stores canonically, so that anything left
 * absolute is external by construction. All three migrations are idempotent
 * per redirect, so a site already at version 2 safely re-walks the set.
 *
 * The publish and repath passes only apply when the site is coming from a
 * pre-2.0 data version. Both are 1.x-shape corrections that become unsafe
 * once 2.0 has written data of its own: a 'draft' now means "deliberately
 * disabled", and a source that starts with the site's own home path now has
 * a legitimate reading (on a subsite at /subsite1, the stored '/subsite1/x'
 * is how you redirect the real URL /subsite1/subsite1/x). A later version
 * bump re-walks the whole set, so ungated passes would republish disabled
 * redirects and rewrite those sources into something else. Destination
 * normalisation has no such ambiguity and runs on every walk.
 *
 * The routine is version-gated so it runs exactly once, and processes in
 * batches so that a site with a very large redirect set completes over
 * several requests rather than timing out on one. `wp wpcom-legacy-redirector
 * migrate` runs the whole thing in one go and is the better option for large
 * sites.
 */
final class Upgrader {

	/**
	 * The internal destination normaliser.
	 *
	 * @var InternalDestinationNormaliser
	 */
	private InternalDestinationNormaliser $normaliser;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->normaliser = new InternalDestinationNormaliser();
	}

	/**
	 * Current data schema version.
	 */
	public const DB_VERSION = 3;

	/**
	 * The first data version written under 2.0's rules.
	 *
	 * At and above this version a 'draft' redirect was disabled on purpose and
	 * a home-path-prefixed source can be a deliberate double-prefix redirect,
	 * so neither the publish pass nor the repath pass may touch them.
	 */
	private const FIRST_2_0_VERSION = 2;

	/**
	 * Option holding the site's current data schema version.
	 */
	public const VERSION_OPTION = 'wpcom_legacy_redirector_db_version';

	/**
	 * Option holding the GMT timestamp at which the upgrade began.
	 */
	private const STARTED_OPTION = 'wpcom_legacy_redirector_upgrade_started_gmt';

	/**
	 * Option holding how far through the redirect set the upgrade has reached.
	 */
	private const CURSOR_OPTION = 'wpcom_legacy_redirector_upgrade_cursor';

	/**
	 * Redirects processed per batch when running on a web request.
	 *
	 * Deliberately modest: this runs on `init`, so the cost lands on whichever
	 * visitor happens to trigger it.
	 */
	public const BATCH_SIZE = 100;

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
	 * @return array{processed: int, published: int, repathed: int, normalised: int, conflicts: string[], complete: bool}
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
			'normalised' => 0,
			'conflicts'  => array(),
			'complete'   => false,
		);

		$query = new WP_Query(
			array(
				'post_type'              => PostType::POST_TYPE,
				// Deliberately not filtered by status: a stable result set keeps
				// offset paging honest while we mutate statuses as we go.
				'post_status'            => 'any',
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
	 * @return array{total: int, to_publish: int, to_repath: int, to_normalise: int, conflicts: string[]}
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
			'to_normalise' => 0,
			'conflicts'    => array(),
		);

		do {
			$query = new WP_Query(
				array(
					'post_type'              => PostType::POST_TYPE,
					'post_status'            => 'any',
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

				if ( null !== $this->normalised_excerpt( $post->post_excerpt ) ) {
					++$pending['to_normalise'];
				}

				if ( '' === $home_path || ! $this->has_home_prefix( $post->post_title, $home_path ) ) {
					continue;
				}

				$new_path = $this->strip_home_prefix( $post->post_title, $home_path );
				$existing = $this->find_post_id_by_hash( md5( $new_path ) );

				if ( 0 !== $existing && $existing !== $post->ID ) {
					$pending['conflicts'][] = sprintf(
						'#%d (%s) would collide with #%d (%s)',
						$post->ID,
						$post->post_title,
						$existing,
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

		if ( '' !== $home_path && $this->has_home_prefix( $source_path, $home_path ) ) {
			$new_path = $this->strip_home_prefix( $source_path, $home_path );
			$new_hash = md5( $new_path );

			$existing = $this->find_post_id_by_hash( $new_hash );
			if ( 0 !== $existing && $existing !== $post->ID ) {
				// Rewriting would collide with a redirect that already uses the
				// subsite-relative form. Leaving the legacy row untouched keeps
				// the working redirect working; the operator can reconcile.
				$conflict = sprintf(
					'#%d (%s) would collide with #%d (%s)',
					$post->ID,
					$source_path,
					$existing,
					$new_path
				);
			} else {
				$update['post_title'] = $new_path;
				$update['post_name']  = $new_hash;
				++$result['repathed'];
			}
		}

		if ( $publish && 'draft' === $post->post_status ) {
			$update['post_status'] = 'publish';
			++$result['published'];
		}

		$normalised = $this->normalised_excerpt( $post->post_excerpt );
		if ( null !== $normalised ) {
			$update['post_excerpt'] = $normalised;
			++$result['normalised'];
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
	 * Find a redirect post ID by its source hash.
	 *
	 * @param string $hash The MD5 hash of the source path.
	 * @return int The post ID, or 0 when none exists.
	 */
	private function find_post_id_by_hash( string $hash ): int {
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

		return isset( $query->posts[0] ) ? (int) $query->posts[0] : 0;
	}

	/**
	 * Whether a stored path carries the site's home path prefix.
	 *
	 * @param string $path      The stored source path.
	 * @param string $home_path The site's home path, without a trailing slash.
	 * @return bool True when the prefix is present.
	 */
	private function has_home_prefix( string $path, string $home_path ): bool {
		return $path === $home_path || str_starts_with( $path, $home_path . '/' );
	}

	/**
	 * Remove the site's home path prefix from a stored path.
	 *
	 * @param string $path      The stored source path.
	 * @param string $home_path The site's home path, without a trailing slash.
	 * @return string The subsite-relative path.
	 */
	private function strip_home_prefix( string $path, string $home_path ): string {
		$stripped = substr( $path, strlen( $home_path ) );

		return '' === $stripped ? '/' : $stripped;
	}

	/**
	 * The relative form of an internal absolute destination, or null when no rewrite is due.
	 *
	 * @param string $excerpt The stored destination.
	 * @return string|null The normalised destination, or null when already canonical.
	 */
	private function normalised_excerpt( string $excerpt ): ?string {
		if ( ! str_starts_with( $excerpt, 'http' ) ) {
			return null;
		}

		return $this->normaliser->to_internal_path( $excerpt );
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
	 * Empty for single sites and for subdomain multisites, where there is no
	 * prefix for 1.x to have baked in.
	 *
	 * @return string The home path, or '' when there is none.
	 */
	private function home_path(): string {
		if ( ! is_multisite() ) {
			return '';
		}

		return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	}

	/**
	 * Invalidate the lookup cache for a source hash.
	 *
	 * @param string $hash The MD5 hash of the source path.
	 * @return void
	 */
	private function invalidate( string $hash ): void {
		wp_cache_delete(
			sprintf( '%d:%s', get_current_blog_id(), $hash ),
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
