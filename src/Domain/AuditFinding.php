<?php
/**
 * AuditFinding value object.
 *
 * @package Automattic\LegacyRedirector\Domain
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Domain;

/**
 * Represents something the auditor found wrong with a redirect.
 *
 * Immutable value object that captures which redirect has the finding,
 * what type of finding it is, and any additional details.
 */
final class AuditFinding {

	/**
	 * The redirect the finding is about.
	 *
	 * @var Redirect
	 */
	private Redirect $redirect;

	/**
	 * The type of finding.
	 *
	 * @var AuditFindingType
	 */
	private AuditFindingType $type;

	/**
	 * The specific fact behind the finding, if there is one.
	 *
	 * @var AuditDetail|null
	 */
	private ?AuditDetail $detail;

	/**
	 * Constructor.
	 *
	 * @param Redirect         $redirect   The redirect the finding is about.
	 * @param AuditFindingType $type       The type of finding.
	 * @param AuditDetail|null $detail   The specific fact behind the finding, such as a post status or host.
	 */
	public function __construct(
		Redirect $redirect,
		AuditFindingType $type,
		?AuditDetail $detail = null
	) {
		$this->redirect = $redirect;
		$this->type     = $type;
		$this->detail   = $detail;
	}

	/**
	 * Get the redirect.
	 *
	 * @return Redirect
	 */
	public function redirect(): Redirect {
		return $this->redirect;
	}

	/**
	 * Get the finding type.
	 *
	 * @return AuditFindingType
	 */
	public function type(): AuditFindingType {
		return $this->type;
	}

	/**
	 * Get the specific fact behind the finding.
	 *
	 * @return AuditDetail|null
	 */
	public function detail(): ?AuditDetail {
		return $this->detail;
	}

	/**
	 * Get the specific fact behind the finding, in English.
	 *
	 * @return string|null
	 */
	public function extra_info(): ?string {
		return $this->detail?->english();
	}

	/**
	 * Whether this finding is a warning rather than a problem.
	 *
	 * @return bool
	 */
	public function is_warning(): bool {
		return $this->type->is_warning();
	}

	/**
	 * Whether this finding says something definite about the redirect.
	 *
	 * @return bool
	 */
	public function is_conclusive(): bool {
		return $this->type->is_conclusive();
	}

	/**
	 * What a person can do about this finding.
	 *
	 * @return string
	 */
	public function remedy(): string {
		return $this->type->remedy();
	}

	/**
	 * How seriously to take this finding.
	 *
	 * @return string 'problem', 'warning', or 'inconclusive' when the check could not tell.
	 */
	public function severity(): string {
		if ( $this->is_warning() ) {
			return 'warning';
		}

		return $this->is_conclusive() ? 'problem' : 'inconclusive';
	}

	/**
	 * Get the human-readable finding label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->type->label();
	}

	/**
	 * Get the detailed finding description.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->type->description( $this->extra_info() );
	}

	/**
	 * Get the redirect ID.
	 *
	 * @return int|null
	 */
	public function redirect_id(): ?int {
		return $this->redirect->id();
	}
}
