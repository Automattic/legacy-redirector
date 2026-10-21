<?php
/**
 * Per-row audit flags.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\AuditFinding;

/**
 * The per-row outcome of the last check, stored as post meta.
 *
 * A flag is a snapshot, not a live judgement: whether a row has a problem
 * depends on other data (a destination post being unpublished, another
 * redirect closing a loop), so a stored flag goes stale. The list table's
 * "Has issues" view therefore labels itself with the run's check time, and a
 * row's flag is cleared the moment that row is saved - it drops out of the
 * report until the next run rather than showing a stale verdict. What is
 * actually wrong with a flagged row is recomputed live by the Health column,
 * so the flag only needs to say that the last check found something.
 */
final class AuditFlags {

	/**
	 * The meta key marking a row whose last check found something.
	 *
	 * Only flagged rows carry the key, so an EXISTS query over it stays
	 * proportional to the number of problem rows, not the table size. The
	 * value is the worst severity found: 'problem' or 'warning'.
	 *
	 * @var string
	 */
	public const string META_KEY = '_legacy_redirector_audit_issues';

	/**
	 * The option recording when the last full check completed.
	 *
	 * Not autoloaded: only the list screen reads it.
	 *
	 * @var string
	 */
	public const string CHECKED_AT_OPTION = 'legacy_redirector_audit_flags_checked_at';

	/**
	 * The option holding the flagged-row counts the menu badge shows.
	 *
	 * Stored when a pass completes and whenever a flag is cleared, and
	 * autoloaded because the badge reads it on every admin page.
	 *
	 * @var string
	 */
	public const string COUNTS_OPTION = 'legacy_redirector_audit_flag_counts';

	/**
	 * Register hooks.
	 *
	 * Registered in every context, not just the admin: WP-CLI and code can
	 * save a redirect too, and a flag that survives the save it describes is
	 * exactly the staleness the snapshot rule exists to prevent.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post_' . PostType::POST_TYPE, array( $this, 'clear' ), 10, 3 );
	}

	/**
	 * Clear a row's flag.
	 *
	 * Hooked to every save of a redirect row (create, edit, status change,
	 * trash, untrash - they all run through wp_insert_post), so a saved row
	 * never shows a verdict about its pre-save self.
	 *
	 * A new row has no flag to clear. Only a row that was flagged changes the
	 * counts, and the recount runs once, at the end of the request: a bulk
	 * action or import saving a thousand flagged rows would otherwise recount
	 * every flagged row a thousand times.
	 *
	 * @param int           $post_id The redirect post ID.
	 * @param \WP_Post|null $post    The redirect post.
	 * @param bool          $update  Whether an existing row was saved.
	 * @return void
	 */
	public function clear( int $post_id, ?\WP_Post $post = null, bool $update = true ): void {
		if ( $update && delete_post_meta( $post_id, self::META_KEY ) ) {
			add_action( 'shutdown', array( $this, 'store_counts' ) );
		}
	}

	/**
	 * Record rows' audit outcomes.
	 *
	 * The rows' current flags are read in one query and only the flags that
	 * change are written, so a pass over an unchanged table writes nothing:
	 * delete_post_meta() and update_post_meta() would each query every row
	 * to find out there was nothing to do, and update_post_meta() would load
	 * the row's meta into the object cache too. Writes go through those
	 * functions, which do not fire save_post, so recording a flag does not
	 * immediately clear it again.
	 *
	 * @param array<int, AuditFinding[]> $findings The findings the audit produced, keyed by redirect post ID.
	 * @return void
	 */
	public function record_rows( array $findings ): void {
		global $wpdb;

		if ( array() === $findings ) {
			return;
		}

		$ids = array_keys( $findings );

		// A refused read says nothing about the stored flags, so every row is
		// then written, as each would be on its own. query()'s return value
		// says so; last_error may still hold an earlier query's error, or none.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One read for the batch, where the meta cache would load every key of every row; the interpolated fragment is only %d placeholders.
		$known = false !== $wpdb->query(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				self::META_KEY,
				...$ids
			)
		);
		// phpcs:enable
		$current = $known ? array_column( $wpdb->last_result, 'meta_value', 'post_id' ) : array();

		foreach ( $findings as $post_id => $found ) {
			$severity = self::severity( $found );

			if ( $known && ( $current[ $post_id ] ?? null ) === $severity ) {
				continue;
			}

			if ( null === $severity ) {
				delete_post_meta( $post_id, self::META_KEY );
			} else {
				update_post_meta( $post_id, self::META_KEY, $severity );
			}
		}
	}

	/**
	 * The flag a row's findings earn: the worst severity, or none.
	 *
	 * @param AuditFinding[] $findings The row's findings.
	 * @return string|null 'problem', 'warning', or null for no flag.
	 */
	private static function severity( array $findings ): ?string {
		if ( array() === $findings ) {
			return null;
		}

		foreach ( $findings as $finding ) {
			if ( ! $finding->is_warning() ) {
				return 'problem';
			}
		}

		return 'warning';
	}

	/**
	 * Record that a full check run has completed.
	 *
	 * @return void
	 */
	public function mark_run_complete(): void {
		update_option( self::CHECKED_AT_OPTION, time(), false );
	}

	/**
	 * When the last full check completed.
	 *
	 * @return int|null The Unix timestamp, or null when no run has completed.
	 */
	public function checked_at(): ?int {
		$checked_at = (int) get_option( self::CHECKED_AT_OPTION, 0 );

		return $checked_at > 0 ? $checked_at : null;
	}

	/**
	 * Store the current flagged-row counts for the menu badge.
	 *
	 * @return void
	 */
	public function store_counts(): void {
		update_option( self::COUNTS_OPTION, $this->counts(), true );
	}

	/**
	 * How many rows were flagged as problems when the counts were last stored.
	 *
	 * Read on every admin page, so it reads the stored number rather than
	 * counting.
	 *
	 * @return int The problem count; zero before any pass has completed.
	 */
	public function problem_count(): int {
		return $this->stored_counts()['problem'];
	}

	/**
	 * The flagged-row counts as last stored, by severity, without counting.
	 *
	 * @return array{problem: int, warning: int} The counts; zero before any pass has completed.
	 */
	public function stored_counts(): array {
		$counts = get_option( self::COUNTS_OPTION );
		$counts = is_array( $counts ) ? $counts : array();

		return array(
			'problem' => (int) ( $counts['problem'] ?? 0 ),
			'warning' => (int) ( $counts['warning'] ?? 0 ),
		);
	}

	/**
	 * How many rows the last run flagged, by severity.
	 *
	 * Counted from the stored flags rather than accumulated across batches,
	 * so the numbers describe what the flags actually say right now - a row
	 * saved (and so cleared) since the run leaves the counts, as it should.
	 *
	 * @return array{problem: int, warning: int} Flagged row counts by severity.
	 */
	public function counts(): array {
		return array(
			'problem' => $this->count_by_severity( 'problem' ),
			'warning' => $this->count_by_severity( 'warning' ),
		);
	}

	/**
	 * Count flagged rows of one severity.
	 *
	 * @param string $severity The flag value: 'problem' or 'warning'.
	 * @return int The count.
	 */
	private function count_by_severity( string $severity ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => PostType::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only flagged rows carry the key, so the join stays proportional to problem rows; admin-only.
				'meta_key'       => self::META_KEY,
				'meta_value'     => $severity, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
			)
		);

		return (int) $query->found_posts;
	}
}
