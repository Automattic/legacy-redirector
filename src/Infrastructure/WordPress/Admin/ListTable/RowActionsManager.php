<?php
/**
 * Manages row actions for the redirects list table.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\ListTable;

use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Upgrader;

/**
 * Handles row actions for the redirects list table.
 */
final class RowActionsManager {

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 */
	public function __construct( RedirectRepositoryInterface $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'post_row_actions', array( $this, 'modify_row_actions' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer-edit.php', array( $this, 'render_validate_script' ) );
	}

	/**
	 * Enqueue apiFetch for the inline Test script on the redirects list page.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( $screen && 'edit-' . PostType::POST_TYPE === $screen->id ) {
			wp_enqueue_script( 'wp-api-fetch' );
		}
	}

	/**
	 * Modify row actions for redirect posts.
	 *
	 * @param array<string, string> $actions Default actions.
	 * @param \WP_Post              $post    The current post.
	 * @return array<string, string> Modified actions.
	 */
	public function modify_row_actions( array $actions, \WP_Post $post ): array {
		if ( PostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for admin list display.
		if ( isset( $_GET['post_status'] ) && 'trash' === $_GET['post_status'] ) {
			return $actions;
		}

		$trash   = $actions['trash'] ?? '';
		$actions = array();

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return $actions;
		}

		$redirect = $this->repository->find_by_id( $post->ID );
		if ( null === $redirect ) {
			return $actions;
		}

		// Edit link - use our custom Edit Redirect page.
		$edit_link       = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE . '&page=edit-redirect&redirect_id=' . $post->ID );
		$actions['edit'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $edit_link ),
			esc_html__( 'Edit', 'legacy-redirector' )
		);

		// Enable/Disable link based on current status.
		if ( $redirect->is_active() ) {
			$disable_link       = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'disable_redirect',
						'redirect_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'disable_redirect_' . $post->ID
			);
			$actions['disable'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $disable_link ),
				esc_html__( 'Disable', 'legacy-redirector' )
			);
		} elseif ( '' === (string) get_post_meta( $post->ID, Upgrader::DUPLICATE_META_KEY, true ) ) {
			// Not for a duplicate the 2.0 upgrade disabled: another redirect holds
			// its source, so enabling it is refused. The Status column says what to
			// do instead.
			$enable_link       = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'enable_redirect',
						'redirect_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'enable_redirect_' . $post->ID
			);
			$actions['enable'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $enable_link ),
				esc_html__( 'Enable', 'legacy-redirector' )
			);
		}

		// Validate link (tested over REST, with a PHP fallback).
		$validate_link       = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'validate',
					'post'   => $post->ID,
				),
				admin_url( 'edit.php?post_type=' . PostType::POST_TYPE )
			),
			'validate_vip_legacy_redirect',
			'_validate_redirect'
		);
		$actions['validate'] = sprintf(
			'<a href="%1$s" class="validate-redirect" data-redirect-id="%2$d" data-source="%3$s">%4$s</a>',
			esc_url( $validate_link ),
			$post->ID,
			esc_attr( $redirect->source()->path() ),
			esc_html__( 'Test', 'legacy-redirector' )
		);

		// Follow link - use home_url() so the stored home-relative path is
		// resolved against the site's base URL, which is not the domain root
		// on a subsite or on a single site installed at example.com/blog.
		$actions['follow'] = sprintf(
			'<a href="%1$s" target="_blank">%2$s</a>',
			esc_url( home_url( $redirect->source()->path() ) ),
			esc_html__( 'Follow', 'legacy-redirector' )
		);

		// Re-insert trash link.
		if ( $trash ) {
			$actions['trash'] = $trash;
		}

		return $actions;
	}

	/**
	 * Render the JavaScript for AJAX validation on the list page.
	 *
	 * @return void
	 */
	public function render_validate_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . PostType::POST_TYPE !== $screen->id ) {
			return;
		}
		?>
		<style>
			.validate-redirect.validating {
				opacity: 0.5;
				pointer-events: none;
			}
		</style>
		<script>
		jQuery(document).ready(function($) {
			var i18n = {
				testing: '<?php echo esc_js( __( 'Testing...', 'legacy-redirector' ) ); ?>',
				test: '<?php echo esc_js( __( 'Test', 'legacy-redirector' ) ); ?>',
				noIssues: '<?php echo esc_js( __( 'No issues found', 'legacy-redirector' ) ); ?>',
				requestFailed: '<?php echo esc_js( __( 'Test request failed.', 'legacy-redirector' ) ); ?>'
			};

			// One line in the Health cell, in the same visual language the
			// column renders with: severity icon plus text.
			function healthLine( icon, color, text, title ) {
				// No text node between icon and label, matching the markup the
				// column renders server-side, so a tested row reads identically.
				var $line = $( '<div/>' )
					.append( $( '<span>', {
						'class': 'dashicons ' + icon,
						'style': 'color: ' + color + ';',
						'aria-hidden': 'true'
					} ) );

				var $text = $( '<span/>' ).text( text );
				if ( title ) {
					$text.attr( 'title', title );
				}

				return $line.append( $text );
			}

			// Replace the row's Health cell with the fresh, HTTP-inclusive
			// result: the grey "not fully checked" state resolves to a real
			// answer once the destination has actually been requested.
			// How each live-probe outcome renders: confirmed subsumes the plain
			// tick, dormant is a judgement call, the rest mean it is not doing
			// its job right now.
			var probeStyles = {
				'confirmed': { icon: 'dashicons-yes-alt', color: '#46b450' },
				'dormant': { icon: 'dashicons-flag', color: '#dba617' },
				'diverted': { icon: 'dashicons-warning', color: '#d63638' },
				'not-firing': { icon: 'dashicons-warning', color: '#d63638' },
				'unreachable': { icon: 'dashicons-editor-help', color: '#787c82' }
			};

			function renderResult( $row, result ) {
				var $healthColumn = $row.find( 'td.health' ).empty();
				var warnings = result.warnings || [];
				var probe = result.probe || null;
				var confirmed = probe && 'confirmed' === probe.status;

				// The live behaviour leads - "the redirect works" - and any
				// problem or warning follows as the "but...".
				if ( probe && probeStyles[ probe.status ] ) {
					var style = probeStyles[ probe.status ];
					$healthColumn.append( healthLine( style.icon, style.color, probe.message ) );
				}

				if ( result.valid ) {
					if ( ! warnings.length && ! confirmed ) {
						$healthColumn.append( healthLine( 'dashicons-yes-alt', '#46b450', i18n.noIssues ) );
					}
				} else {
					$healthColumn.append( healthLine( 'dashicons-warning', '#d63638', result.message || i18n.requestFailed ) );
				}

				$.each( warnings, function ( i, warning ) {
					$healthColumn.append( healthLine( 'dashicons-flag', '#dba617', warning.label, warning.description ) );
				} );
			}

			// Test one row; returns the request promise so callers can chain.
			function testRow( $row ) {
				var $link = $row.find( '.validate-redirect' );
				var redirectId = $link.data( 'redirect-id' );

				$link.addClass( 'validating' ).text( i18n.testing );

				return wp.apiFetch( {
					path: '/legacy-redirector/v1/redirects/' + redirectId + '/test',
					method: 'POST'
				} ).then( function ( result ) {
					renderResult( $row, result );
				} ).catch( function ( error ) {
					renderResult( $row, { valid: false, message: ( error && error.message ) || i18n.requestFailed } );
				} ).then( function () {
					$link.removeClass( 'validating' ).text( i18n.test );
				} );
			}

			$(document).on('click', '.validate-redirect', function(e) {
				e.preventDefault();
				testRow( $( this ).closest( 'tr' ) );
			});

			// The Test bulk action runs the same per-row check for every
			// ticked row, one at a time so a full-page selection does not
			// fire a burst of HTTP checks at the server.
			$( '#posts-filter' ).on( 'submit', function ( e ) {
				var action = $( '#bulk-action-selector-top' ).val();
				var action2 = $( '#bulk-action-selector-bottom' ).val();

				if ( 'test_redirects' !== action && 'test_redirects' !== action2 ) {
					return;
				}

				e.preventDefault();

				var rows = $( 'input[name="post[]"]:checked' ).map( function () {
					return $( this ).closest( 'tr' )[ 0 ];
				} ).get();

				( function next() {
					var row = rows.shift();
					if ( row ) {
						testRow( $( row ) ).then( next, next );
					}
				} )();

				$( '#bulk-action-selector-top, #bulk-action-selector-bottom' ).val( '-1' );
			} );
		});
		</script>
		<?php
	}
}
