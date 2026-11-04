<?php
/**
 * Problem-count badge on the Redirects menu.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin;

use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditFlags;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Shows how many redirects are flagged as problems on the Redirects menu item,
 * the way core badges the Plugins menu with its update count.
 *
 * Problems only: a warning is a judgement call for a person who is already
 * looking, but a problem means visitors are hitting broken redirects, and
 * the badge points at the list screen, whose "Has issues" view shows the
 * same flagged rows, so the two always agree. Reads the stored count rather
 * than counting, because it renders on every admin page.
 */
final class MenuBadge {

	/**
	 * The per-row audit flags.
	 *
	 * @var AuditFlags
	 */
	private AuditFlags $flags;

	/**
	 * Constructor.
	 *
	 * @param AuditFlags $flags The per-row audit flags.
	 */
	public function __construct( AuditFlags $flags ) {
		$this->flags = $flags;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// After every registrar has added its menus.
		add_action( 'admin_menu', array( $this, 'add_badge' ), 99 );
	}

	/**
	 * Append the problem count to the Redirects menu label.
	 *
	 * @return void
	 */
	public function add_badge(): void {
		$problems = $this->flags->problem_count();

		if ( $problems < 1 ) {
			return;
		}

		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		$slug = 'edit.php?post_type=' . PostType::POST_TYPE;

		foreach ( $menu as $index => $item ) {
			if ( ( $item[2] ?? '' ) !== $slug ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Appending a count bubble to our own menu entry, the same way core badges the Plugins menu.
			$menu[ $index ][0] .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$problems,
				esc_html( number_format_i18n( $problems ) ),
				esc_html(
					sprintf(
						/* translators: %s: number of redirects with problems */
						_n( '%s redirect with problems', '%s redirects with problems', $problems, 'legacy-redirector' ),
						number_format_i18n( $problems )
					)
				)
			);

			return;
		}
	}
}
