<?php
/**
 * Home path provider.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

/**
 * Supplies the current site's home path for SourceUrl normalisation.
 *
 * SourceUrl lives in the Domain layer, which cannot ask WordPress where
 * home is, so the home path is passed into SourceUrl::from_string() by its
 * callers instead. This is the lowest layer allowed to know (the
 * Application layer is deliberately WordPress-coupled; see AGENTS.md).
 */
final class HomePath {

	/**
	 * The current site's home path, without a trailing slash.
	 *
	 * '' wherever home is at the domain root and there is nothing to strip,
	 * which covers most single sites and every subdomain multisite. Non-empty
	 * wherever it is not: '/subsite1' on a subdirectory multisite subsite,
	 * and equally '/blog' on a plain single site installed at
	 * example.com/blog. Multisite is not the deciding factor.
	 *
	 * @return string The home path.
	 */
	public static function current(): string {
		return rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	}
}
