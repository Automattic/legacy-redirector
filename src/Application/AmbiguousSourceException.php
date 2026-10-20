<?php
/**
 * AmbiguousSourceException.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

/**
 * Thrown when a source path names more than one redirect.
 *
 * The path itself is valid, so callers report this as it is rather than as
 * an invalid identifier. Its message is already in the user's language.
 */
final class AmbiguousSourceException extends \InvalidArgumentException {
}
