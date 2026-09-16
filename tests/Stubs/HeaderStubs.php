<?php
/**
 * PHP header function stubs for unit tests.
 *
 * Under the CLI SAPI, header() is a no-op and headers_list() is always
 * empty, so a response header sent by the code under test leaves no trace
 * to assert on. Because the callers declare a namespace and call these
 * functions unqualified, PHP resolves them to the namespace first: the
 * definitions below shadow the internal ones for that namespace only, and
 * record what was sent.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Stubs match the internal functions' signatures.

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

/**
 * Headers recorded by the header() stub below, in the order they were sent.
 *
 * @var string[]
 */
$GLOBALS['wpcom_legacy_redirector_sent_headers'] = array();

/**
 * Stub for headers_sent(), which is true under PHPUnit once output starts.
 *
 * @return bool Always false, so the code under test reaches its header() call.
 */
function headers_sent(): bool {
	return false;
}

/**
 * Stub for header(), recording the header instead of sending it.
 *
 * @param string $header        The header line.
 * @param bool   $replace       Whether to replace a previous similar header.
 * @param int    $response_code The response code to force.
 * @return void
 */
function header( string $header, bool $replace = true, int $response_code = 0 ): void {
	$GLOBALS['wpcom_legacy_redirector_sent_headers'][] = $header;
}
