<?php
/**
 * AuditDetail value object.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * The specific fact behind a finding: which status, which host, which loop.
 *
 * Kept as a kind and a value rather than a sentence, so each surface can
 * phrase it: the CLI in English through english(), the admin and REST in the
 * user's language.
 */
final class AuditDetail {

	public const POST_STATUS        = 'post_status';
	public const POST_TYPE          = 'post_type';
	public const HOST               = 'host';
	public const HTTP_STATUS        = 'http_status';
	public const LOCATION           = 'location';
	public const LOOP               = 'loop';
	public const ERROR              = 'error';
	public const NO_STATUS_CODE     = 'no_status_code';
	public const TOO_MANY_REDIRECTS = 'too_many_redirects';
	public const CORRUPTION         = 'corruption';

	/**
	 * The kind of fact, one of the class constants.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * The fact: a slug, host, URL or message, or the paths of a loop.
	 *
	 * @var string|list<string>
	 */
	private string|array $value;

	/**
	 * Constructor.
	 *
	 * @param string              $kind  One of the class constants.
	 * @param string|list<string> $value The fact.
	 */
	private function __construct( string $kind, string|array $value ) {
		$this->kind  = $kind;
		$this->value = $value;
	}

	/**
	 * The destination post's status, such as draft.
	 *
	 * @param string $status The post status slug.
	 * @return self
	 */
	public static function post_status( string $status ): self {
		return new self( self::POST_STATUS, $status );
	}

	/**
	 * The destination post's type, when it is one visitors cannot view.
	 *
	 * @param string $post_type The post type slug.
	 * @return self
	 */
	public static function post_type( string $post_type ): self {
		return new self( self::POST_TYPE, $post_type );
	}

	/**
	 * The destination host that is not allowed.
	 *
	 * @param string $host The host.
	 * @return self
	 */
	public static function host( string $host ): self {
		return new self( self::HOST, $host );
	}

	/**
	 * The HTTP status the destination answered with.
	 *
	 * @param int $code The status code.
	 * @return self
	 */
	public static function http_status( int $code ): self {
		return new self( self::HTTP_STATUS, (string) $code );
	}

	/**
	 * Where the source redirected to instead.
	 *
	 * @param string $url The Location the source answered with.
	 * @return self
	 */
	public static function location( string $url ): self {
		return new self( self::LOCATION, $url );
	}

	/**
	 * The sources of a loop, in order, ending where it started.
	 *
	 * @param string[] $paths The source paths.
	 * @return self
	 */
	public static function loop( array $paths ): self {
		return new self( self::LOOP, $paths );
	}

	/**
	 * Why a request failed, as the HTTP API reported it.
	 *
	 * @param string $message The error message, already in the site's language.
	 * @return self
	 */
	public static function error( string $message ): self {
		return new self( self::ERROR, $message );
	}

	/**
	 * The response had no status code.
	 *
	 * @return self
	 */
	public static function no_status_code(): self {
		return new self( self::NO_STATUS_CODE, '' );
	}

	/**
	 * The destination redirected more times than the check follows.
	 *
	 * @return self
	 */
	public static function too_many_redirects(): self {
		return new self( self::TOO_MANY_REDIRECTS, '' );
	}

	/**
	 * Why a stored row cannot be read as a redirect.
	 *
	 * @param string $reason The reason.
	 * @return self
	 */
	public static function corruption( string $reason ): self {
		return new self( self::CORRUPTION, $reason );
	}

	/**
	 * The kind of fact, one of the class constants.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * The fact itself: a list of paths for a loop, a string otherwise.
	 *
	 * @return string|list<string>
	 */
	public function value(): string|array {
		return $this->value;
	}

	/**
	 * The detail in English, as the CLI shows it.
	 *
	 * @return string
	 */
	public function english(): string {
		$value = $this->value;

		return match ( $this->kind ) {
			self::POST_STATUS        => 'status: ' . $value,
			self::POST_TYPE          => 'post type: ' . $value,
			self::HOST               => 'host: ' . $value,
			self::HTTP_STATUS        => 'status: ' . $value,
			self::LOCATION           => 'to: ' . $value,
			self::LOOP               => implode( ' -> ', (array) $value ),
			self::NO_STATUS_CODE     => 'no status code',
			self::TOO_MANY_REDIRECTS => 'Too many redirects',
			default                  => (string) $value,
		};
	}
}
