<?php
/**
 * Counted phrases for CLI output.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

/**
 * Writes a count with the phrase that agrees with it: "1 redirect", "2 redirects".
 *
 * CLI output is not translated, so English singular and plural are enough;
 * the admin uses _n() for the same job. Numbers are formatted the English
 * way too, whatever the site's locale, so they agree with the words around
 * them.
 */
trait CountsInWords {

	/**
	 * A count followed by the phrase that agrees with it.
	 *
	 * @param int    $count The count.
	 * @param string $one   The phrase for exactly one, e.g. "redirect needs".
	 * @param string $many  The phrase for any other count, e.g. "redirects need".
	 * @return string The count and phrase, e.g. "2 redirects need".
	 */
	private static function count_of( int $count, string $one, string $many ): string {
		return number_format( $count ) . ' ' . ( 1 === $count ? $one : $many );
	}
}
