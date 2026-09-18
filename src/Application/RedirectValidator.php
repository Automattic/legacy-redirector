<?php
/**
 * Redirect validator service.
 *
 * @package Automattic\LegacyRedirector\Application
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Application;

use Automattic\LegacyRedirector\Domain\AuditFinding;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Domain\Url;

/**
 * The write gate: may this redirect be stored?
 *
 * Owns only the rules that exist for writes - the duplicate-source check
 * (which needs the repository), the self-loop check, and URL format policy -
 * plus the policy over RedirectAuditor's findings deciding which of them
 * refuse a write. What is actually wrong with a redirect is the auditor's
 * question; which findings block a save is answered here, in one place.
 */
class RedirectValidator {

	/**
	 * The redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository The redirect repository.
	 * @param RedirectAuditor|null        $auditor    The redirect auditor (optional, created if not provided).
	 */
	public function __construct( RedirectRepositoryInterface $repository, ?RedirectAuditor $auditor = null ) {
		$this->repository = $repository;
		$this->auditor    = $auditor ?? new RedirectAuditor();
	}

	/**
	 * Validate the redirect a write would leave behind.
	 *
	 * The rules are the same whether the row is being created or updated:
	 * - The source must not already be taken by a different redirect
	 * - Source and destination must be different
	 * - Destination must be valid (post exists and is published, or URL is allowed)
	 *
	 * There is no separate update rule set, for the same reason wp_update_post()
	 * is wp_insert_post() with an ID: the only thing an update changes is that
	 * the row it is updating is not a duplicate of itself. Callers pass the
	 * redirect the write would produce, so a moved source is judged as the
	 * source it is moving to.
	 *
	 * @param Redirect $redirect The redirect as it would be stored.
	 * @return ValidationResult The validation result.
	 */
	public function validate( Redirect $redirect ): ValidationResult {
		$source = $redirect->source();

		// Check for duplicate source URL.
		$existing_id = $this->repository->get_id_by_source( $source );
		if ( $existing_id > 0 && $existing_id !== $redirect->id() ) {
			return ValidationResult::invalid(
				'duplicate-redirect-uri',
				__( 'A redirect for this URI already exists', 'legacy-redirector' )
			);
		}

		// Validate source and destination are different.
		$same_result = $this->validate_source_destination_different( $source, $redirect->destination() );
		if ( $same_result->is_invalid() ) {
			return $same_result;
		}

		// No format check is needed here: DestinationUrl refuses a malformed
		// absolute URL at construction, so no Redirect can carry one.

		// Destination health is the auditor's question; which findings refuse
		// a write is decided here.
		return $this->refusal_for( $this->auditor->audit_destination( $redirect ) );
	}

	/**
	 * Validate that source and destination are different.
	 *
	 * Prevents redirect loops where source equals destination.
	 *
	 * @param SourceUrl   $source      The source URL.
	 * @param Destination $destination The destination.
	 * @return ValidationResult The validation result.
	 */
	public function validate_source_destination_different( SourceUrl $source, Destination $destination ): ValidationResult {
		// If destination is a post ID, resolve to URL for comparison.
		if ( $destination->is_post_id() ) {
			$post_permalink = get_permalink( $destination->as_post_id()->value() );
			if ( false !== $post_permalink ) {
				// Url::parse() so the comparison below is like for like: the
				// source path arrives decoded from SourceUrl, and a bare
				// parse_url() would also corrupt a multibyte permalink.
				$destination_path = Url::parse( (string) $post_permalink )['path'] ?? '';
				if ( $destination_path && $this->normalize_path( $source->path() ) === $this->normalize_path( $destination_path ) ) {
					return ValidationResult::invalid(
						'invalid-values',
						__( '"Redirect From" and "Redirect To" values are required and should not match.', 'legacy-redirector' )
					);
				}
			}
			return ValidationResult::valid();
		}

		// Compare source path with destination URL path.
		$destination_url  = $destination->as_url()->value();
		$parsed           = Url::parse( $destination_url ) ?? array();
		$destination_path = $parsed['path'] ?? '';

		// A destination on another host can never be a self-loop, whatever its path.
		if ( ! empty( $parsed['host'] ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( 0 !== strcasecmp( $parsed['host'], (string) $home_host ) ) {
				return ValidationResult::valid();
			}
		}

		if ( $this->normalize_path( $source->path() ) === $this->normalize_path( $destination_path ) ) {
			return ValidationResult::invalid(
				'invalid-values',
				__( '"Redirect From" and "Redirect To" values are required and should not match.', 'legacy-redirector' )
			);
		}

		return ValidationResult::valid();
	}

	/**
	 * The write-gate policy over an audit finding.
	 *
	 * The same fact carries a different weight when writing than when
	 * auditing: an unpublished destination is merely reported on a stored
	 * row, but refuses a new write. Warnings never block; they are reported
	 * by the surfaces that asked the auditor directly.
	 *
	 * @param AuditFinding|null $finding The destination finding, if any.
	 * @return ValidationResult The validation result.
	 */
	private function refusal_for( ?AuditFinding $finding ): ValidationResult {
		if ( null === $finding || $finding->is_warning() ) {
			return ValidationResult::valid();
		}

		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Match on enum, not $this.
		return match ( $finding->type() ) {
			AuditFindingType::POST_DELETED => ValidationResult::invalid(
				'empty-postid',
				__( 'Redirect is pointing to a Post ID that does not exist.', 'legacy-redirector' )
			),
			AuditFindingType::POST_TRASHED,
			AuditFindingType::POST_UNPUBLISHED => ValidationResult::invalid(
				'non-public',
				__( 'You are trying to redirect to content that is not published.', 'legacy-redirector' )
			),
			// The finding's description, so the error names the refused host.
			AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED => ValidationResult::invalid(
				'external-url-not-allowed',
				$finding->description() . '.'
			),
			default => ValidationResult::valid(),
		};
	}

	/**
	 * Normalize a path for comparison.
	 *
	 * @param string $path The path.
	 * @return string Normalized path.
	 */
	private function normalize_path( string $path ): string {
		return strtolower( trim( $path, '/' ) );
	}
}
