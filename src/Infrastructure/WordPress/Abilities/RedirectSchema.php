<?php
/**
 * Shared ability schema fragments and output formatting.
 *
 * @package Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Abilities;

use Automattic\LegacyRedirector\Domain\Redirect;

/**
 * Describes a redirect to ability clients.
 *
 * Every ability that returns redirects returns the same shape, so the schema
 * and the formatting that produces it live here rather than in each ability.
 */
final class RedirectSchema {

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
				'description' => __( 'Whether the destination is a post ID or a URL. "corrupt" marks a row whose stored data is unreadable; its from/to values are placeholders.', 'legacy-redirector' ),
			),
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'enabled', 'disabled' ),
				'description' => __( 'Whether the redirect is served to visitors.', 'legacy-redirector' ),
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
			'status' => $redirect->is_active() ? 'enabled' : 'disabled',
		);
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
			'description' => __( 'Redirects that could not be changed, and why.', 'legacy-redirector' ),
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
						'description' => __( 'Why the redirect could not be changed.', 'legacy-redirector' ),
					),
				),
				'additionalProperties' => false,
			),
		);
	}
}
