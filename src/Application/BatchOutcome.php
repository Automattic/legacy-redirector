<?php
/**
 * BatchOutcome enum.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

/**
 * What happened to one identifier in a batch.
 *
 * Kept free of user-facing wording: each transport turns an outcome into its
 * own message. The three failure cases are deliberately distinct, because
 * "that identifier is not a redirect reference", "no such redirect" and "the
 * redirect exists but the change did not stick" are different problems for
 * whoever has to act on the report.
 */
enum BatchOutcome {

	/**
	 * The identifier resolved, and the action ran successfully.
	 *
	 * A resolve-only call reports this for every identifier it resolved:
	 * resolution was all that was asked of it.
	 */
	case SUCCESS;

	/**
	 * The identifier is neither a redirect ID nor a usable source path.
	 */
	case INVALID;

	/**
	 * The identifier is well-formed, but no redirect matches it.
	 */
	case NOT_FOUND;

	/**
	 * The redirect was found, but the action on it failed.
	 */
	case FAILED;
}
