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
	 * Optional extra information about the finding.
	 *
	 * @var string|null
	 */
	private ?string $extra_info;

	/**
	 * Constructor.
	 *
	 * @param Redirect         $redirect   The redirect the finding is about.
	 * @param AuditFindingType $type       The type of finding.
	 * @param string|null      $extra_info Optional extra information (e.g., HTTP status code, post status).
	 */
	public function __construct(
		Redirect $redirect,
		AuditFindingType $type,
		?string $extra_info = null
	) {
		$this->redirect   = $redirect;
		$this->type       = $type;
		$this->extra_info = $extra_info;
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
	 * Get the extra info.
	 *
	 * @return string|null
	 */
	public function extra_info(): ?string {
		return $this->extra_info;
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
		return $this->type->description( $this->extra_info );
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
