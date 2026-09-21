<?php
/**
 * Manages view filters for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostTypeRedirectQueryRepository;

/**
 * Handles status view filters and destination type filters for the redirects list table.
 */
final class ViewFilters {

	/**
	 * The redirect query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The redirect query repository.
	 * @param AuditFlags                       $flags            The per-row audit flags.
	 */
	public function __construct( RedirectQueryRepositoryInterface $query_repository, AuditFlags $flags ) {
		$this->query_repository = $query_repository;
		$this->flags            = $flags;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'views_edit-' . PostType::POST_TYPE, array( $this, 'customize_views' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_by_destination_type' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_by_audit_flag' ) );
		add_filter( 'posts_where', array( $this, 'add_destination_type_where_clause' ), 10, 2 );
	}

	/**
	 * Customize the status view filters.
	 *
	 * Renames status labels and adds destination type filters.
	 *
	 * @param array<string, string> $views Status filters.
	 * @return array<string, string> Modified views.
	 */
	public function customize_views( array $views ): array {
		// Remove "Mine" filter - redirect authorship isn't relevant.
		unset( $views['mine'] );

		// Save and remove Trash so we can add it back at the end.
		$trash = $views['trash'] ?? null;
		unset( $views['trash'] );

		// Rename "Published" to "Enabled" for clarity.
		if ( isset( $views['publish'] ) && is_string( $views['publish'] ) ) {
			$views['publish'] = preg_replace(
				'/\bPublished\b/',
				__( 'Enabled', 'legacy-redirector' ),
				$views['publish']
			);
		}

		// Rename "Draft" / "Drafts" to "Disabled" for clarity.
		if ( isset( $views['draft'] ) && is_string( $views['draft'] ) ) {
			$views['draft'] = preg_replace(
				'/\bDrafts?\b/',
				__( 'Disabled', 'legacy-redirector' ),
				$views['draft']
			);
		}

		// Add destination type filters.
		$views = $this->add_destination_type_views( $views );

		// Add the "Has issues" view once a scan has completed.
		$views = $this->add_audit_flag_view( $views );

		// Re-add Trash at the end.
		if ( null !== $trash ) {
			$views['trash'] = $trash;
		}

		return $views;
	}

	/**
	 * Add destination type filter views.
	 *
	 * @param array<string, string> $views Existing views.
	 * @return array<string, string> Modified views.
	 */
	private function add_destination_type_views( array $views ): array {
		$base_url = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filter display.
		$current_dest_type = isset( $_GET['destination_type'] ) ? sanitize_key( $_GET['destination_type'] ) : '';

		$counts = $this->get_destination_type_counts();

		// "To ID" filter.
		if ( $counts['post_id'] > 0 ) {
			$post_id_url    = add_query_arg( 'destination_type', 'post_id', $base_url );
			$post_id_class  = 'post_id' === $current_dest_type ? 'current' : '';
			$views['to_id'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_url( $post_id_url ),
				esc_attr( $post_id_class ),
				esc_html__( 'To ID', 'legacy-redirector' ),
				number_format_i18n( $counts['post_id'] )
			);
		}

		// "To Path" filter (internal paths/URLs).
		if ( $counts['path'] > 0 ) {
			$path_url         = add_query_arg( 'destination_type', 'path', $base_url );
			$path_class       = 'path' === $current_dest_type ? 'current' : '';
			$views['to_path'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_url( $path_url ),
				esc_attr( $path_class ),
				esc_html__( 'To Path', 'legacy-redirector' ),
				number_format_i18n( $counts['path'] )
			);
		}

		// "To External" filter.
		if ( $counts['external'] > 0 ) {
			$external_url         = add_query_arg( 'destination_type', 'external', $base_url );
			$external_class       = 'external' === $current_dest_type ? 'current' : '';
			$views['to_external'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_url( $external_url ),
				esc_attr( $external_class ),
				esc_html__( 'To External', 'legacy-redirector' ),
				number_format_i18n( $counts['external'] )
			);
		}

		return $views;
	}

	/**
	 * Get counts of redirects by destination type.
	 *
	 * @return array{post_id: int, path: int, external: int} Counts by type.
	 */
	private function get_destination_type_counts(): array {
		return $this->query_repository->count_by_destination_type();
	}

	/**
	 * Add the "Has issues" view reading the stored per-row flags.
	 *
	 * Only shown once a scan has completed - before that there are
	 * no flags to read. Shown even at zero, because "checked and clean" is an
	 * answer, not an absence. The label carries the run's check time: the
	 * flags are a snapshot, and the time is what makes that honest.
	 *
	 * @param array<string, string> $views Existing views.
	 * @return array<string, string> Modified views.
	 */
	private function add_audit_flag_view( array $views ): array {
		$checked_at = $this->flags->checked_at();

		if ( null === $checked_at ) {
			return $views;
		}

		$counts = $this->flags->counts();
		$url    = add_query_arg(
			'audit_flagged',
			'1',
			admin_url( 'edit.php?post_type=' . PostType::POST_TYPE )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filter display.
		$current = isset( $_GET['audit_flagged'] ) ? 'current' : '';

		$views['audit_flagged'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			esc_attr( $current ),
			esc_html(
				sprintf(
					/* translators: %s: how long ago the last scan completed, e.g. "2 hours" */
					__( 'Has issues (scanned %s ago)', 'legacy-redirector' ),
					human_time_diff( $checked_at )
				)
			),
			number_format_i18n( $counts['problem'] + $counts['warning'] )
		);

		return $views;
	}

	/**
	 * Restrict the admin list to flagged rows when the "Has issues" view is active.
	 *
	 * Setting meta_key alone joins postmeta on the flag key: only flagged rows
	 * carry it, so the join stays proportional to the number of problem rows,
	 * not the table size, and the ordinary bulk actions apply to the result.
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function filter_by_audit_flag( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( PostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filtering.
		if ( ! isset( $_GET['audit_flagged'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only flagged rows carry the key; admin list screen only.
		$query->set( 'meta_key', AuditFlags::META_KEY );
	}

	/**
	 * Filter redirects by destination type in admin list.
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function filter_by_destination_type( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( PostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filtering.
		if ( ! isset( $_GET['destination_type'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filtering.
		$destination_type = sanitize_key( $_GET['destination_type'] );

		if ( in_array( $destination_type, array( 'post_id', 'path', 'external' ), true ) ) {
			// Applied by add_destination_type_where_clause.
			$query->set( 'legacy_redirector_destination_type', $destination_type );
		}
	}

	/**
	 * Add WHERE clause for destination-type filtering.
	 *
	 * The classification rule itself lives in the query repository, which owns
	 * the storage mapping; this hook only applies it to the admin list query.
	 *
	 * @param string    $where The WHERE clause.
	 * @param \WP_Query $query The query object.
	 * @return string Modified WHERE clause.
	 */
	public function add_destination_type_where_clause( string $where, \WP_Query $query ): string {
		$destination_type = (string) $query->get( 'legacy_redirector_destination_type' );

		if ( '' === $destination_type ) {
			return $where;
		}

		return $where . PostTypeRedirectQueryRepository::destination_type_where( $destination_type );
	}
}
