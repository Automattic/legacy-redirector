<?php
/**
 * Redirect row formatting for CLI commands.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Domain\Redirect;

/**
 * Formats a redirect as a row for WP-CLI output.
 *
 * The one owner of the `ID`/`from`/`to`/`type`/`status` shape every command
 * that prints redirects shares. Commands that print more (`get` adds the
 * source hash, `validate` adds the issue) add their own keys to this row.
 */
trait FormatsRedirectRows {

	/**
	 * Format a redirect as a CLI output row.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return array{ID: int|null, from: string, to: string|int, type: string, status: string}
	 */
	private function redirect_row( Redirect $redirect ): array {
		$destination = $redirect->destination();
		$is_post_id  = $destination->is_post_id();

		return array(
			'ID'     => $redirect->id(),
			'from'   => $redirect->source()->path(),
			'to'     => $is_post_id ? $destination->as_post_id()->value() : $destination->as_url()->value(),
			// 'corrupt' flags a row whose from/to are placeholders because the
			// stored data is unreadable; `validate` reports the reason.
			'type'   => $redirect->is_corrupt() ? 'corrupt' : ( $is_post_id ? 'post' : 'url' ),
			'status' => $redirect->is_active() ? 'enabled' : 'disabled',
		);
	}
}
