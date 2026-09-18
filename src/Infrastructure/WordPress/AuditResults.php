<?php
/**
 * Stored audit results.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress;

use Automattic\LegacyRedirector\Domain\AuditFinding;

/**
 * The recorded outcome of the most recent batch audit.
 *
 * Findings are computed live wherever they are displayed, but surfaces that
 * cannot afford to compute - the menu badge on every admin page, and later a
 * findings filter - read this summary instead. Batch audits (the Validate
 * page, `validate` over a batch, and the scheduled daily run) record here;
 * audits of explicitly named redirects do not, so a spot check of one row
 * cannot masquerade as the site-wide count.
 */
final class AuditResults {

	/**
	 * The option holding the summary.
	 *
	 * Autoloaded: the menu badge reads it on every admin page.
	 *
	 * @var string
	 */
	public const string OPTION = 'legacy_redirector_audit_summary';

	/**
	 * Record the outcome of a batch audit.
	 *
	 * @param AuditFinding[] $findings   The findings the audit produced.
	 * @param int            $checked    How many redirects were checked.
	 * @param bool           $with_urls  Whether URL destinations were requested over HTTP.
	 * @return void
	 */
	public function record( array $findings, int $checked, bool $with_urls ): void {
		$problems = 0;
		$warnings = 0;

		foreach ( $findings as $finding ) {
			if ( $finding->is_warning() ) {
				++$warnings;
			} else {
				++$problems;
			}
		}

		update_option(
			self::OPTION,
			array(
				'problems'     => $problems,
				'warnings'     => $warnings,
				'checked'      => $checked,
				'with_urls'    => $with_urls,
				'completed_at' => time(),
			),
			true
		);
	}

	/**
	 * The most recent recorded summary.
	 *
	 * @return array{problems: int, warnings: int, checked: int, with_urls: bool, completed_at: int}|null
	 *         The summary, or null when no audit has been recorded.
	 */
	public function summary(): ?array {
		$summary = get_option( self::OPTION );

		return is_array( $summary ) ? $summary : null;
	}

	/**
	 * How many problems the last recorded audit found.
	 *
	 * @return int The problem count; zero when no audit has been recorded.
	 */
	public function problem_count(): int {
		return (int) ( $this->summary()['problems'] ?? 0 );
	}
}
