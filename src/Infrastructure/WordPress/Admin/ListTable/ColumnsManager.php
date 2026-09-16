<?php
/**
 * Manages custom columns for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\ValidationIssueType;
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
			'from'   => __( 'Redirect From', 'wpcom-legacy-redirector' ),
			'to'     => __( 'Redirect To', 'wpcom-legacy-redirector' ),
			'status' => __( 'Status', 'wpcom-legacy-redirector' ),
			'date'   => __( 'Date', 'wpcom-legacy-redirector' ),
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
			case 'status':
				$this->render_status_column( $redirect );
				break;
		}
	}

	/**
	 * Render the "from" column.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_from_column( Redirect $redirect ): void {
		$source    = $redirect->source()->path();
		$edit_link = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $redirect->id() );
		printf(
			'<strong><a class="row-title" href="%1$s" aria-label="%2$s">%3$s</a></strong>',
			esc_url( $edit_link ),
			/* translators: %s: redirect source path */
			esc_attr( sprintf( __( 'Edit redirect from &#8220;%s&#8221;', 'wpcom-legacy-redirector' ), $source ) ),
			esc_html( $source )
		);
	}

	/**
	 * Render the "to" column.
	 *
	 * Destination-health warnings come from the auditor, so this column and
	 * the `validate` CLI command report the same problems.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_to_column( Redirect $redirect ): void {
		$issue      = $this->auditor->validate_redirect_destination( $redirect );
		$issue_type = null !== $issue ? $issue->type() : null;

		if ( ValidationIssueType::CORRUPT_DATA === $issue_type ) {
			echo '<em>' . esc_html( (string) $redirect->corruption() ) . '</em>';
			return;
		}

		if ( ValidationIssueType::POST_DELETED === $issue_type ) {
			echo '<em>' . esc_html__( 'Redirect is pointing to a Post ID that does not exist.', 'wpcom-legacy-redirector' ) . '</em>';
			return;
		}

		$destination = $redirect->destination();

		if ( $destination->is_post_id() ) {
			$permalink     = get_permalink( $destination->as_post_id()->value() );
			$relative_path = is_string( $permalink ) ? str_replace( home_url(), '', $permalink ) : '';
			$this->render_relative_path_with_prefix( $relative_path );
		} elseif ( $destination->as_url()->is_absolute() ) {
			$url = $destination->as_url()->value();
			// On multisite, use bold for consistency with relative paths.
			if ( is_multisite() ) {
				printf( '<strong>%s</strong>', esc_url( $url ) );
			} else {
				echo esc_url( $url );
			}
		} else {
			$this->render_relative_path_with_prefix( $destination->as_url()->value() );
		}

		if ( ValidationIssueType::POST_TRASHED === $issue_type || ValidationIssueType::POST_UNPUBLISHED === $issue_type ) {
			echo '<br /><em>' . esc_html__( 'Warning: Redirect is not a public URL.', 'wpcom-legacy-redirector' ) . '</em>';
		}
	}

	/**
	 * Render a relative path with the site's base URL as a grey prefix.
	 *
	 * On multisite, this helps clarify that /path resolves to the current site's
	 * base URL, not the network root. Shows the home_url prefix in grey followed
	 * by the path in bold.
	 *
	 * On single site, displays the path as plain text (no prefix or bold needed).
	 *
	 * @param string $path The relative path (e.g., "/hello-world").
	 * @return void
	 */
	private function render_relative_path_with_prefix( string $path ): void {
		if ( ! is_multisite() || ! str_starts_with( $path, '/' ) ) {
			echo esc_html( $path );
			return;
		}

		$home_url = untrailingslashit( home_url() );
		printf(
			'<span style="color: #888;">%s</span><strong>%s</strong>',
			esc_html( $home_url ),
			esc_html( $path )
		);
	}

	/**
	 * Render the "status" column.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return void
	 */
	private function render_status_column( Redirect $redirect ): void {
		if ( $redirect->is_active() ) {
			echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' . esc_attr__( 'Enabled', 'wpcom-legacy-redirector' ) . '"></span> ';
			echo esc_html__( 'Enabled', 'wpcom-legacy-redirector' );
		} else {
			echo '<span class="dashicons dashicons-no" style="color: #dc3232;" title="' . esc_attr__( 'Disabled', 'wpcom-legacy-redirector' ) . '"></span> ';
			echo esc_html__( 'Disabled', 'wpcom-legacy-redirector' );
		}
	}
}
