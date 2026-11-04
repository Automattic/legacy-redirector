<?php
/**
 * Shared ability schema fragments and output formatting.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\Redirect;

/**
 * Describes a redirect to ability clients.
 *
 * Every ability that returns redirects returns the same shape, so the schema
 * and the formatting that produces it live here rather than in each ability.
 */
final class RedirectSchema {

	/**
	 * Most redirects one call may name.
	 *
	 * Each identifier is a lookup, so an uncapped list is unbounded work in a
	 * single request. Larger sets belong on the command line.
	 *
	 * @var int
	 */
	public const int MAX_IDENTIFIERS = 1000;

	/**
	 * Get the JSON Schema properties describing a single redirect.
	 *
	 * @return array<string, array<string, mixed>> The schema properties.
	 */
	public static function properties(): array {
		return array(
			'id'     => array(
				'type'        => 'integer',
				'description' => __( 'The redirect ID.', 'legacy-redirector' ),
			),
			'from'   => array(
				'type'        => 'string',
				'description' => __( 'The source path this redirect matches, including any query string.', 'legacy-redirector' ),
			),
			'to'     => array(
				'type'        => array( 'string', 'integer' ),
				'description' => __( 'The destination: a path, an absolute URL, or a post ID.', 'legacy-redirector' ),
			),
			'type'   => array(
				'type'        => 'string',
				'enum'        => array( 'post', 'url', 'corrupt' ),
				/* translators: Quoted words, ability names such as validate-redirects, and field names such as offset and next_offset are values a program reads; do not translate them. */
				'description' => __( 'Whether the destination is a post ID or a URL. "corrupt" marks a row whose stored data is unreadable; its from/to values are placeholders.', 'legacy-redirector' ),
			),
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'enabled', 'disabled', 'trashed' ),
				/* translators: Quoted words, ability names such as validate-redirects, and field names such as offset and next_offset are values a program reads; do not translate them. */
				'description' => __( 'Whether the redirect is served to visitors. "trashed" marks a redirect in the Trash, which is not served; enabling it restores it from the Trash.', 'legacy-redirector' ),
			),
		);
	}

	/**
	 * Get the JSON Schema for a single redirect object.
	 *
	 * @return array<string, mixed> The schema.
	 */
	public static function object_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'from', 'to', 'type', 'status' ),
			'properties'           => self::properties(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Format a redirect for ability output.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return array{id: int|null, from: string, to: string|int, type: string, status: string} The formatted redirect.
	 */
	public static function to_array( Redirect $redirect ): array {
		$destination = $redirect->destination();
		$is_post_id  = $destination->is_post_id();

		return array(
			'id'     => $redirect->id(),
			'from'   => $redirect->source()->path(),
			'to'     => $is_post_id ? $destination->as_post_id()->value() : $destination->as_url()->value(),
			'type'   => $redirect->is_corrupt() ? 'corrupt' : ( $is_post_id ? 'post' : 'url' ),
			'status' => self::status_label( $redirect ),
		);
	}

	/**
	 * The status a redirect is reported with, by the CLI as well as the abilities.
	 *
	 * An output label only: what is stored stays publish, draft or trash.
	 * A trashed redirect is named as one, or it would read as disabled, and
	 * enabling it would restore it from the Trash unnoticed.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return string 'enabled', 'disabled' or 'trashed'.
	 */
	public static function status_label( Redirect $redirect ): string {
		return match ( $redirect->status() ) {
			'publish' => 'enabled',
			'trash'   => 'trashed',
			default   => 'disabled',
		};
	}

	/**
	 * Get the JSON Schema for the list of identifiers the batch abilities accept.
	 *
	 * @param string $description Description of what the identifiers select.
	 * @return array<string, mixed> The schema.
	 */
	public static function identifiers_schema( string $description ): array {
		return array(
			'type'        => 'array',
			'items'       => array(
				'type' => array( 'string', 'integer' ),
			),
			'minItems'    => 1,
			'maxItems'    => self::MAX_IDENTIFIERS,
			'description' => $description,
		);
	}

	/**
	 * Get the JSON Schema for the per-item failures the batch abilities report.
	 *
	 * @return array<string, mixed> The schema.
	 */
	public static function failures_schema(): array {
		return array(
			'type'        => 'array',
			'description' => __( 'The redirects given that could not be acted on, and why.', 'legacy-redirector' ),
			'items'       => array(
				'type'                 => 'object',
				'required'             => array( 'redirect', 'reason' ),
				'properties'           => array(
					'redirect' => array(
						'type'        => 'string',
						'description' => __( 'The identifier that was passed in.', 'legacy-redirector' ),
					),
					'reason'   => array(
						'type'        => 'string',
						'description' => __( 'Why the redirect could not be acted on.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Get the JSON Schema for the warnings a write reports about what it saved.
	 *
	 * Each warning is a finding in the shape validate-redirects reports.
	 *
	 * @return array<string, mixed> The schema.
	 */
	public static function warnings_schema(): array {
		return array(
			'type'        => 'array',
			'description' => __( 'Warnings about the saved redirects: each works, but deserves a look. Empty when there are none.', 'legacy-redirector' ),
			'items'       => ValidateRedirectsAbility::finding_schema(),
		);
	}

	/**
	 * Format the warnings for saved redirects as ability output.
	 *
	 * @param RedirectAuditor $auditor   The auditor, which owns the warning rules.
	 * @param Redirect[]      $redirects The redirects as saved.
	 * @return array<int, array<string, mixed>> The warnings, in the order the redirects were given.
	 */
	public static function warnings( RedirectAuditor $auditor, array $redirects ): array {
		$warnings = array();

		foreach ( $redirects as $redirect ) {
			foreach ( $auditor->warnings( $redirect ) as $warning ) {
				$warnings[] = ValidateRedirectsAbility::finding_to_array( $warning );
			}
		}

		return $warnings;
	}
}
