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
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

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
		add_action( 'pre_get_posts', array( $this, 'filter_by_duplicate_source' ) );
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

		// Rename "Published" to "Enabled" and "Drafts" to "Disabled".
		$views = self::relabel_view( $views, 'publish', __( 'Enabled', 'legacy-redirector' ) );
		$views = self::relabel_view( $views, 'draft', __( 'Disabled', 'legacy-redirector' ) );

		// Add destination type filters.
		$views = $this->add_destination_type_views( $views );

		// Add the "Has issues" view once a full check has completed.
		$views = $this->add_audit_flag_view( $views );

		// Add the "Duplicate sources" view while the upgrade has left any.
		$views = $this->add_duplicate_sources_view( $views );

		// Re-add Trash at the end.
		if ( null !== $trash ) {
			$views['trash'] = $trash;
		}

		return $views;
	}

	/**
	 * Replace the label of one of core's status views, keeping its link and count.
	 *
	 * Core's label is whatever the site's language calls the status, so the
	 * label is found by position - the link text before the count - rather
	 * than by matching the English word.
	 *
	 * @param array<string, string> $views The views.
	 * @param string                $key   The view to relabel.
	 * @param string                $label The new label.
	 * @return array<string, string> The views.
	 */
	private static function relabel_view( array $views, string $key, string $label ): array {
		if ( isset( $views[ $key ] ) && is_string( $views[ $key ] ) ) {
			$views[ $key ] = (string) preg_replace_callback(
				'#^(<a\b[^>]*>)[^<]*(<span class="count">)#',
				static fn( array $m ): string => $m[1] . esc_html( $label ) . ' ' . $m[2],
				$views[ $key ]
			);
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
			$views['to_id'] = self::view_link( $post_id_url, __( 'To ID', 'legacy-redirector' ), $counts['post_id'], 'post_id' === $current_dest_type );
		}

		// "To Path" filter (internal paths/URLs).
		if ( $counts['path'] > 0 ) {
			$path_url         = add_query_arg( 'destination_type', 'path', $base_url );
			$views['to_path'] = self::view_link( $path_url, __( 'To Path', 'legacy-redirector' ), $counts['path'], 'path' === $current_dest_type );
		}

		// "To External" filter.
		if ( $counts['external'] > 0 ) {
			$external_url         = add_query_arg( 'destination_type', 'external', $base_url );
			$views['to_external'] = self::view_link( $external_url, __( 'To External', 'legacy-redirector' ), $counts['external'], 'external' === $current_dest_type );
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
	 * Only shown once a full check has completed - before that there are
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

		$views['audit_flagged'] = self::view_link(
			$url,
			sprintf(
				/* translators: %s: how long ago the last full check completed, e.g. "2 hours" */
				__( 'Has issues (checked %s ago)', 'legacy-redirector' ),
				human_time_diff( $checked_at )
			),
			$counts['problem'] + $counts['warning'],
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filter display.
			isset( $_GET['audit_flagged'] )
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
	 * Add the "Duplicate sources" view while the 2.0 upgrade has left any to settle.
	 *
	 * Each is a redirect the upgrade disabled because another redirect has the
	 * same source and a different destination. Hidden at zero: unlike the
	 * background check, there is nothing to check, only something to settle.
	 *
	 * @param array<string, string> $views Existing views.
	 * @return array<string, string> Modified views.
	 */
	private function add_duplicate_sources_view( array $views ): array {
		$count = Upgrader::duplicate_count();

		if ( 0 === $count ) {
			return $views;
		}

		$views['duplicate_sources'] = self::view_link(
			self::duplicate_sources_url(),
			__( 'Duplicate sources', 'legacy-redirector' ),
			$count,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filter display.
			isset( $_GET['duplicate_sources'] )
		);

		return $views;
	}

	/**
	 * Build one view link the way core builds its own status views,
	 * including aria-current on the view being shown.
	 *
	 * @param string $url        The view's URL.
	 * @param string $label      The view's label.
	 * @param int    $count      How many redirects it holds.
	 * @param bool   $is_current Whether it is the view being shown.
	 * @return string The link markup.
	 */
	private static function view_link( string $url, string $label, int $count, bool $is_current ): string {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$is_current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			number_format_i18n( $count )
		);
	}

	/**
	 * The admin URL of the "Duplicate sources" view.
	 *
	 * @return string The URL.
	 */
	public static function duplicate_sources_url(): string {
		return add_query_arg( 'duplicate_sources', '1', admin_url( 'edit.php?post_type=' . PostType::POST_TYPE ) );
	}

	/**
	 * Restrict the admin list to disabled duplicates when the "Duplicate sources" view is active.
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function filter_by_duplicate_source( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( PostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for filtering.
		if ( ! isset( $_GET['duplicate_sources'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only disabled duplicates carry the key; admin list screen only.
		$query->set( 'meta_key', Upgrader::DUPLICATE_META_KEY );
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
