<?php
/**
 * Manages custom columns for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles column definitions, content rendering, and sorting for the redirects list table.
 */
final class ColumnsManager {

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Redirect auditor, the single owner of destination-health rules.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 * @param RedirectAuditor             $auditor    Redirect auditor.
	 */
	public function __construct( RedirectRepositoryInterface $repository, RedirectAuditor $auditor ) {
		$this->repository = $repository;
		$this->auditor    = $auditor;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'manage_' . PostType::POST_TYPE . '_posts_columns', array( $this, 'set_columns' ) );
		add_action( 'manage_' . PostType::POST_TYPE . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . PostType::POST_TYPE . '_sortable_columns', array( $this, 'set_sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_sorting' ) );
		add_filter( 'list_table_primary_column', array( $this, 'set_primary_column' ), 10, 2 );
	}

	/**
	 * Set column definitions.
	 *
	 * @return array<string, string> Column definitions.
	 */
	public function set_columns(): array {
		return array(
			'cb'     => '<input type="checkbox" />',
			'from'   => __( 'Redirect From', 'legacy-redirector' ),
			'to'     => __( 'Redirect To', 'legacy-redirector' ),
			'health' => __( 'Health', 'legacy-redirector' ),
			'status' => __( 'Status', 'legacy-redirector' ),
			'date'   => __( 'Date', 'legacy-redirector' ),
		);
	}

	/**
	 * Set sortable columns.
	 *
	 * @param array<string, string> $columns Existing sortable columns.
	 * @return array<string, string> Modified sortable columns.
	 */
	public function set_sortable_columns( array $columns ): array {
		$columns['from'] = 'from';
		$columns['to']   = 'to';
		return $columns;
	}

	/**
	 * Set the primary column for row actions placement.
	 *
	 * @param string $column    Current primary column.
	 * @param string $screen_id The screen ID.
	 * @return string Primary column name.
	 */
	public function set_primary_column( string $column, string $screen_id ): string {
		if ( 'edit-' . PostType::POST_TYPE === $screen_id ) {
			return 'from';
		}
		return $column;
	}

	/**
	 * Handle custom column sorting.
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function handle_sorting( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( PostType::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'from' === $orderby ) {
			$query->set( 'orderby', 'title' );
		}

		if ( 'to' === $orderby ) {
			$query->set( 'orderby', 'post_excerpt' );
		}

		// The default date ordering needs an ID tiebreaker: an import creates
		// hundreds of rows in the same second, and ties ordered arbitrarily by
		// the database make pagination unstable - a row can appear on no page
		// (and another on two) while the item count says otherwise.
		if ( '' === $orderby || 'date' === $orderby ) {
			$order = strtoupper( (string) $query->get( 'order' ) );
			$order = 'ASC' === $order ? 'ASC' : 'DESC';
			$query->set(
				'orderby',
				array(
					'date' => $order,
					'ID'   => $order,
				)
			);
		}
	}

	/**
	 * Render column content.
	 *
	 * @param string $column  The column name.
	 * @param int    $post_id The post ID.
	 * @return void
	 */
	public function render_column( string $column, int $post_id ): void {
		$redirect = $this->repository->find_by_id( $post_id );
		if ( null === $redirect ) {
			return;
		}

		switch ( $column ) {
			case 'from':
				$this->render_from_column( $redirect );
				break;
			case 'to':
				$this->render_to_column( $redirect );
				break;
			case 'health':
				$this->render_health_column( $redirect );
				break;
			case 'status':
				$this->render_status_column( $redirect );
				break;
		}
	}

	/**
	 * Render the "from" column.
	 *
	 * The source path is site-relative, so it gets the same gray home URL
	 * prefix as the "to" column. The prefix sits outside the anchor so the
	 * clickable row title stays the path itself.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_from_column( Redirect $redirect ): void {
		$source    = $redirect->source()->path();
		$edit_link = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $redirect->id() );
		$this->render_home_url_prefix( $source );
		printf(
			'<strong><a class="row-title" href="%1$s" aria-label="%2$s">%3$s</a></strong>',
			esc_url( $edit_link ),
			/* translators: %s: redirect source path */
			esc_attr( sprintf( __( 'Edit redirect from &#8220;%s&#8221;', 'legacy-redirector' ), $source ) ),
			esc_html( $source )
		);
	}

	/**
	 * Render the "to" column: the destination, and nothing else.
	 *
	 * Findings about the destination live in the Health column, so this cell
	 * stays a stable place to read where the redirect points.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_to_column( Redirect $redirect ): void {
		// A corrupt row's stored destination is a placeholder; showing it would
		// present invented data as real. The Health column names the corruption.
		if ( $redirect->is_corrupt() ) {
			echo '&mdash;';
			return;
		}

		$destination = $redirect->destination();

		if ( $destination->is_post_id() ) {
			$post_id   = $destination->as_post_id()->value();
			$permalink = get_permalink( $post_id );

			// A deleted destination has no permalink to show; name the post it
			// pointed at so the row still reads.
			if ( ! is_string( $permalink ) ) {
				/* translators: %d: destination post ID */
				echo esc_html( sprintf( __( 'Post %d', 'legacy-redirector' ), $post_id ) );
				return;
			}

			$this->render_relative_path_with_prefix( str_replace( home_url(), '', $permalink ) );
		} elseif ( $destination->as_url()->is_absolute() ) {
			$url = $destination->as_url()->value();
			// Bold for consistency with the prefixed relative paths alongside it.
			if ( '' !== $this->home_url_prefix() ) {
				printf( '<strong>%s</strong>', esc_url( $url ) );
			} else {
				echo esc_url( $url );
			}
		} else {
			$this->render_relative_path_with_prefix( $destination->as_url()->value() );
		}
	}

	/**
	 * Render the "health" column: every finding the auditor can report
	 * without HTTP requests, or a tick when there are none.
	 *
	 * The same auditor backs the `validate` CLI command, the Validate page,
	 * and the per-row Validate action - whose fresh, HTTP-inclusive result
	 * replaces this cell's content - so no surface can disagree.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_health_column( Redirect $redirect ): void {
		$findings = $this->auditor->audit( $redirect );

		if ( array() === $findings ) {
			// A tick must not overclaim: for a destination only an HTTP request
			// can judge, "no findings" means "nothing conclusive", not "fine".
			if ( $this->auditor->destination_needs_http( $redirect ) ) {
				printf(
					'<span class="dashicons dashicons-editor-help" style="color: #787c82;" aria-hidden="true"></span><span title="%1$s">%2$s</span>',
					esc_attr__( 'No problems found without requesting the destination. Use Test to check it responds.', 'legacy-redirector' ),
					esc_html__( 'Not fully checked', 'legacy-redirector' )
				);
				return;
			}

			echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'No issues found', 'legacy-redirector' ) . '</span>';
			return;
		}

		foreach ( $findings as $index => $finding ) {
			if ( $index > 0 ) {
				echo '<br />';
			}

			$is_warning = $finding->is_warning();

			printf(
				'<span class="dashicons %1$s" style="color: %2$s;" aria-hidden="true"></span><span class="screen-reader-text">%3$s </span><span title="%4$s">%5$s</span>',
				$is_warning ? 'dashicons-flag' : 'dashicons-warning',
				$is_warning ? '#dba617' : '#d63638',
				$is_warning ? esc_html__( 'Warning:', 'legacy-redirector' ) : esc_html__( 'Problem:', 'legacy-redirector' ),
				esc_attr( $finding->description() . '.' ),
				esc_html( $finding->label() )
			);
		}
	}

	/**
	 * Render a relative path with the site's base URL as a gray prefix.
	 *
	 * Where home is not the domain root, this clarifies that /path resolves
	 * against the site's base URL. Shows the prefix in gray followed by the
	 * path in bold; where home is the root, the path is plain text, because
	 * there is nothing to disambiguate.
	 *
	 * @param string $path The relative path (e.g., "/hello-world").
	 * @return void
	 */
	private function render_relative_path_with_prefix( string $path ): void {
		if ( '' === $this->home_url_prefix() || ! str_starts_with( $path, '/' ) ) {
			echo esc_html( $path );
			return;
		}

		$this->render_home_url_prefix( $path );
		printf( '<strong>%s</strong>', esc_html( $path ) );
	}

	/**
	 * Render this site's base URL as a gray prefix ahead of a relative path.
	 *
	 * Prints nothing where home is the domain root, and nothing for anything
	 * that is not a site-relative path.
	 *
	 * @param string $path The relative path (e.g., "/hello-world").
	 * @return void
	 */
	private function render_home_url_prefix( string $path ): void {
		$prefix = $this->home_url_prefix();

		if ( '' === $prefix || ! str_starts_with( $path, '/' ) ) {
			return;
		}

		printf( '<span style="color: #888;">%s</span>', esc_html( $prefix ) );
	}

	/**
	 * This site's base URL, when stored paths hang off something other than
	 * the domain root.
	 *
	 * Sources are stored relative to home, and RedirectResolver strips the
	 * home path on every lookup regardless of multisite, so the question the
	 * display has to answer is "is home the domain root?", not "is this
	 * multisite?". A single site installed at example.com/blog is every bit
	 * as ambiguous as a subsite at example.com/subsite1, and on such a site
	 * example.com/old-page never reaches WordPress at all, so a bare
	 * /old-page in the UI points at the one reading that cannot work.
	 *
	 * @return string The base URL without a trailing slash, or '' when home
	 *                is the domain root and no prefix is warranted.
	 */
	private function home_url_prefix(): string {
		if ( '' === HomePath::current() ) {
			return '';
		}

		return untrailingslashit( home_url() );
	}

	/**
	 * Render the "status" column.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_status_column( Redirect $redirect ): void {
		if ( $redirect->is_active() ) {
			echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' . esc_attr__( 'Enabled', 'legacy-redirector' ) . '"></span> ';
			echo esc_html__( 'Enabled', 'legacy-redirector' );
		} else {
			echo '<span class="dashicons dashicons-no" style="color: #dc3232;" title="' . esc_attr__( 'Disabled', 'legacy-redirector' ) . '"></span> ';
			echo esc_html__( 'Disabled', 'legacy-redirector' );
		}
	}
}
