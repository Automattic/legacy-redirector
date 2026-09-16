<?php
/**
 * RedirectStatus enum.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * HTTP redirect status codes.
 *
 * Represents valid HTTP redirect status codes that can be used
 * when performing a redirect.
 *
 * If per-redirect status codes are ever wanted (e.g. 302 for temporary
 * moves), this enum is the natural carrier on the Redirect entity.
 *
 * phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Enum methods can use $this.
 */
enum RedirectStatus: int {

	/**
	 * 301 Moved Permanently - The resource has been permanently moved.
	 * Search engines will update their index. This is the default.
	 */
	case MOVED_PERMANENTLY = 301;

	/**
	 * 302 Found - The resource is temporarily at a different URI.
	 * Search engines will keep the original URL indexed.
	 */
	case FOUND = 302;

	/**
	 * 303 See Other - The response can be found at another URI using GET.
	 * Typically used after a POST request.
	 */
	case SEE_OTHER = 303;

	/**
	 * 307 Temporary Redirect - Like 302, but the request method must not change.
	 */
	case TEMPORARY_REDIRECT = 307;

	/**
	 * 308 Permanent Redirect - Like 301, but the request method must not change.
	 */
	case PERMANENT_REDIRECT = 308;

	/**
	 * Get the default redirect status (301 Moved Permanently).
	 *
	 * @return self
	 */
	public static function get_default(): self {
		return self::MOVED_PERMANENTLY;
	}

	/**
	 * Check if this is a permanent redirect.
	 *
	 * @return bool True if 301 or 308.
	 */
	public function is_permanent(): bool {
		return in_array( $this, array( self::MOVED_PERMANENTLY, self::PERMANENT_REDIRECT ), true );
	}
}
