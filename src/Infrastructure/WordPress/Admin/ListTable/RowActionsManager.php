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
			wp_enqueue_script( 'wp-a11y' );
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
		$source          = $redirect->source()->path();
		$actions['edit'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $edit_link ),
			/* translators: %s: redirect source path */
			esc_attr( sprintf( __( 'Edit redirect from &#8220;%s&#8221;', 'legacy-redirector' ), $source ) ),
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
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $disable_link ),
				/* translators: %s: redirect source path */
				esc_attr( sprintf( __( 'Disable redirect from &#8220;%s&#8221;', 'legacy-redirector' ), $source ) ),
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
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $enable_link ),
				/* translators: %s: redirect source path */
				esc_attr( sprintf( __( 'Enable redirect from &#8220;%s&#8221;', 'legacy-redirector' ), $source ) ),
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
			'<a href="%1$s" class="validate-redirect" data-redirect-id="%2$d" data-source="%3$s" aria-label="%4$s">%5$s</a>',
			esc_url( $validate_link ),
			$post->ID,
			esc_attr( $source ),
			/* translators: %s: redirect source path */
			esc_attr( sprintf( __( 'Test redirect from &#8220;%s&#8221;', 'legacy-redirector' ), $source ) ),
			esc_html__( 'Test', 'legacy-redirector' )
		);

		// Follow link - use home_url() so the stored home-relative path is
		// resolved against the site's base URL, which is not the domain root
		// on a subsite or on a single site installed at example.com/blog.
		$actions['follow'] = sprintf(
			'<a href="%1$s" target="_blank" aria-label="%2$s">%3$s</a>',
			esc_url( home_url( $source ) ),
			/* translators: %s: redirect source path */
			esc_attr( sprintf( __( 'Follow redirect from &#8220;%s&#8221; (opens in a new tab)', 'legacy-redirector' ), $source ) ),
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
			.legacy-redirector-finding summary {
				cursor: pointer;
			}
			.legacy-redirector-finding p {
				margin: 4px 0 0;
			}
		</style>
		<script>
		jQuery(document).ready(function($) {
			var i18n = {
				testing: '<?php echo esc_js( __( 'Testing...', 'legacy-redirector' ) ); ?>',
				test: '<?php echo esc_js( __( 'Test', 'legacy-redirector' ) ); ?>',
				noIssues: '<?php echo esc_js( __( 'No issues found', 'legacy-redirector' ) ); ?>',
				requestFailed: '<?php echo esc_js( __( 'Test request failed.', 'legacy-redirector' ) ); ?>',
				problem: '<?php echo esc_js( __( 'Problem:', 'legacy-redirector' ) ); ?>',
				warning: '<?php echo esc_js( __( 'Warning:', 'legacy-redirector' ) ); ?>',
				tested: '<?php /* translators: %s: redirect source path */ echo esc_js( __( 'Tested %s:', 'legacy-redirector' ) ); ?>',
				bulkDone: '<?php echo esc_js( __( 'Finished testing the selected redirects. The results are in the Health column.', 'legacy-redirector' ) ); ?>'
			};

			// One line in the Health cell, in the same markup the column
			// renders server-side, so a tested row reads identically: a
			// severity icon and label, opening to the detail when there is one.
			function healthLine( icon, color, severity, text, detailText ) {
				var $icon = $( '<span>', {
					'class': 'dashicons ' + icon,
					'style': 'color: ' + color + ';',
					'aria-hidden': 'true'
				} );

				if ( ! detailText ) {
					return $( '<div/>' ).append( $icon, $( '<span/>' ).text( text ) );
				}

				var $summary = $( '<summary/>' ).append( $icon );
				if ( severity ) {
					$summary.append( $( '<span class="screen-reader-text"/>' ).text( severity + ' ' ) );
				}
				$summary.append( document.createTextNode( text ) );

				return $( '<details class="legacy-redirector-finding"/>' )
					.append( $summary, $( '<p/>' ).text( detailText ) );
			}

			// The detail for a line: what is wrong, then what to do.
			function detail( description, remedy ) {
				return [ description, remedy ].filter( Boolean ).join( ' ' );
			}

			// Replace the row's Health cell with the fresh, HTTP-inclusive
			// result: the grey "not fully checked" state resolves to a real
			// answer once the destination has actually been requested.
			// How each live-probe outcome renders: confirmed subsumes the plain
			// tick, dormant is a judgement call, the rest mean it is not doing
			// its job right now.
			var probeStyles = {
				'confirmed': { icon: 'dashicons-yes-alt', color: '#00a32a', severity: '' },
				'dormant': { icon: 'dashicons-flag', color: '#dba617', severity: i18n.warning },
				'diverted': { icon: 'dashicons-warning', color: '#d63638', severity: i18n.problem },
				'not-firing': { icon: 'dashicons-warning', color: '#d63638', severity: i18n.problem },
				'unreachable': { icon: 'dashicons-editor-help', color: '#787c82', severity: '' }
			};

			// Returns the lines' labels, for announcing the result.
			function renderResult( $row, result ) {
				var $healthColumn = $row.find( 'td.health' ).empty();
				var warnings = result.warnings || [];
				var probe = result.probe || null;
				var labels = [];

				function add( icon, color, severity, text, detailText ) {
					$healthColumn.append( healthLine( icon, color, severity, text, detailText ) );
					labels.push( severity ? severity + ' ' + text : text );
				}

				// The live behaviour leads - "the redirect works" - and any
				// problem or warning follows as the "but...".
				if ( probe && probeStyles[ probe.status ] ) {
					var style = probeStyles[ probe.status ];
					add( style.icon, style.color, style.severity, probe.message, probe.remedy );
				}

				if ( result.valid ) {
					// A probe line is the row's verdict, so the plain tick is
					// only for a response that has none.
					if ( ! warnings.length && ! probe ) {
						add( 'dashicons-yes-alt', '#00a32a', '', i18n.noIssues );
					}
				} else {
					add( 'dashicons-warning', '#d63638', i18n.problem, result.message || i18n.requestFailed, result.remedy );
				}

				$.each( warnings, function ( i, warning ) {
					add( 'dashicons-flag', '#dba617', i18n.warning, warning.label, detail( warning.description, warning.remedy ) );
				} );

				return labels;
			}

			// Test one row; returns the request promise so callers can chain.
			// A single test announces its result; a bulk run announces once,
			// at the end, rather than talking over itself.
			function testRow( $row, quiet ) {
				var $link = $row.find( '.validate-redirect' );
				var redirectId = $link.data( 'redirect-id' );

				function report( labels ) {
					if ( ! quiet ) {
						wp.a11y.speak( i18n.tested.replace( '%s', $link.data( 'source' ) ) + ' ' + labels.join( ' ' ) );
					}
				}

				$link.addClass( 'validating' ).text( i18n.testing );

				return wp.apiFetch( {
					path: '/legacy-redirector/v1/redirects/' + redirectId + '/test',
					method: 'POST'
				} ).then( function ( result ) {
					report( renderResult( $row, result ) );
				} ).catch( function ( error ) {
					report( renderResult( $row, { valid: false, message: ( error && error.message ) || i18n.requestFailed } ) );
				} ).then( function () {
					$link.removeClass( 'validating' ).text( i18n.test );
				} );
			}

			$(document).on('click', '.validate-redirect', function(e) {
				e.preventDefault();
				// pointer-events only stops the mouse; Enter still reaches here.
				if ( $( this ).hasClass( 'validating' ) ) {
					return;
				}
				testRow( $( this ).closest( 'tr' ), false );
			});

			// The Test bulk action runs the same per-row check for every
			// ticked row, one at a time so a full-page selection does not
			// fire a burst of HTTP checks at the server, then says it is done.
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

				$( '#legacy-redirector-bulk-test-notice' ).remove();

				( function next() {
					var row = rows.shift();
					if ( row ) {
						testRow( $( row ), true ).then( next, next );
						return;
					}

					$( '<div id="legacy-redirector-bulk-test-notice" class="notice notice-success"/>' )
						.append( $( '<p/>' ).text( i18n.bulkDone ) )
						.insertAfter( '.wp-header-end' );
					wp.a11y.speak( i18n.bulkDone );
				} )();

				$( '#bulk-action-selector-top, #bulk-action-selector-bottom' ).val( '-1' );
			} );
		});
		</script>
		<?php
	}
}
