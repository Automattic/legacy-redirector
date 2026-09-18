<?php
/**
 * Validate Redirects admin page.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Pages;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\RedirectCriteria;
use Automattic\LegacyRedirector\Domain\RedirectQueryRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\AuditResults;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * A findings report in the admin, mirroring the `validate` CLI command.
 *
 * Runs the same auditor batch the CLI runs and renders every finding, so a
 * check added to the auditor is immediately visible here without CLI access,
 * and the two surfaces cannot disagree.
 */
final class ValidatePage {

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	public const string PAGE_SLUG = 'validate-redirects';

	/**
	 * Largest number of redirects a single run will check.
	 *
	 * Mirrors the validate ability's cap; one admin request has tighter time
	 * limits than a CLI process.
	 *
	 * @var int
	 */
	private const int MAX_LIMIT = 1000;

	/**
	 * The query repository.
	 *
	 * @var RedirectQueryRepositoryInterface
	 */
	private RedirectQueryRepositoryInterface $query_repository;

	/**
	 * The redirect auditor.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * The stored audit results.
	 *
	 * @var AuditResults
	 */
	private AuditResults $results;

	/**
	 * Constructor.
	 *
	 * @param RedirectQueryRepositoryInterface $query_repository The query repository.
	 * @param RedirectAuditor                  $auditor          The redirect auditor.
	 * @param AuditResults                     $results          The stored audit results.
	 */
	public function __construct( RedirectQueryRepositoryInterface $query_repository, RedirectAuditor $auditor, AuditResults $results ) {
		$this->query_repository = $query_repository;
		$this->auditor          = $auditor;
		$this->results          = $results;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'current_screen', array( $this, 'add_contextual_help' ) );
	}

	/**
	 * Add contextual help describing every check the report runs.
	 *
	 * @param \WP_Screen $screen The current screen object.
	 * @return void
	 */
	public function add_contextual_help( \WP_Screen $screen ): void {
		if ( PostType::POST_TYPE . '_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'legacy-redirector' ),
				'content' => $this->get_overview_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'source-checks',
				'title'   => __( 'Source checks', 'legacy-redirector' ),
				'content' => $this->get_source_checks_help(),
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'destination-checks',
				'title'   => __( 'Destination checks', 'legacy-redirector' ),
				'content' => $this->get_destination_checks_help(),
			)
		);
	}

	/**
	 * Get the overview help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_overview_help(): string {
		return '<p>' . __( 'This report checks stored redirects and lists everything it finds wrong. It is the same report as the <code>wp legacy-redirector validate</code> CLI command.', 'legacy-redirector' ) . '</p>' .
			'<p>' . __( 'A <strong>problem</strong> means the redirect is broken and will not do its job. A <strong>warning</strong> means the redirect works but deserves a human look; nothing automated will ever disable it.', 'legacy-redirector' ) . '</p>' .
			'<p>' . __( 'A row whose stored data cannot be read as a redirect at all is reported as corrupt: delete it, or edit it with a full new source and destination.', 'legacy-redirector' ) . '</p>';
	}

	/**
	 * Get the source checks help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_source_checks_help(): string {
		return '<p>' . __( 'The source path (Redirect From) is checked for:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li>' . __( '<strong>Reserved WordPress paths</strong> (warning): a source WordPress itself serves, such as <code>/wp-admin</code>, <code>/wp-login.php</code>, the other root <code>wp-*.php</code> files, <code>/xmlrpc.php</code>, or anything under <code>/wp-json</code>, <code>/wp-content</code> or <code>/wp-includes</code>. Such a redirect lies dormant while the path works, because redirects only answer 404s, but it takes over the moment that path breaks; for <code>/wp-admin</code> or <code>/wp-login.php</code> that locks you out of the dashboard. Keep it only if it is a genuine legacy URL.', 'legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Get the destination checks help content.
	 *
	 * @return string Help content HTML.
	 */
	private function get_destination_checks_help(): string {
		return '<p>' . __( 'The destination (Redirect To) is checked according to its form:', 'legacy-redirector' ) . '</p>' .
			'<ul>' .
			'<li>' . __( '<strong>Post ID destinations</strong>: the post must still exist, not be in the trash, and be published. Media attachments count as published.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Relative path destinations</strong>: the path is resolved to a post of any registered post type, including via dated permalinks, and that post must be published. A path whose post was trashed is recognised even though trashing renames the slug. A path that resolves to no post at all - an archive, a rewrite endpoint, a page served outside WordPress - is not reported, because only an HTTP request can judge it.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>External URL destinations</strong>: the host must be in the <code>allowed_redirect_hosts</code> filter, or WordPress will refuse the redirect at request time and the visitor gets a 404.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>Possible loops</strong> (warning): a destination that is itself another redirect\'s source, with the hops leading back to where they started. A loop only runs while every source in it returns a 404 - any member serving real content keeps it dormant - so a person should judge it; break a cycle by re-pointing or disabling one member.', 'legacy-redirector' ) . '</li>' .
			'<li>' . __( '<strong>URL checks</strong> (optional): with the checkbox ticked, each URL destination is requested over HTTP, following redirects, and reported if it fails, returns 404, or returns a server error. Slow, because it makes one request per redirect.', 'legacy-redirector' ) . '</li>' .
			'</ul>';
	}

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::POST_TYPE,
			__( 'Validate Redirects', 'legacy-redirector' ),
			__( 'Validate', 'legacy-redirector' ),
			Capability::MANAGE_REDIRECTS_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the page: run the audit and show every finding.
	 *
	 * The audit runs on plain page load with the same defaults as the CLI
	 * command, so the report is one click away. The URL check, which makes an
	 * HTTP request per URL destination, only runs on an explicit, nonce-carrying
	 * submission.
	 *
	 * @return void
	 */
	public function render_page(): void {
		list( $status, $limit, $check_urls ) = $this->read_request();

		$redirects = $this->query_repository->find_matching(
			new RedirectCriteria(
				'any' === $status ? null : $status,
				null, // destination_type.
				null, // search.
				'date',
				'DESC',
				$limit,
				0
			)
		);

		$findings = $this->auditor->audit_batch( $redirects, $check_urls );

		$problems = 0;
		$warnings = 0;
		foreach ( $findings as $finding ) {
			if ( $finding->is_warning() ) {
				++$warnings;
			} else {
				++$problems;
			}
		}

		$checked = count( $redirects );

		// This run becomes the recorded summary the menu badge reads, and
		// the scheduled daily run keeps it fresh between visits here.
		$this->results->record( $findings, $checked, $check_urls );
		$summary = $this->results->summary();

		include __DIR__ . '/views/validate-redirects.php';
	}

	/**
	 * Read and constrain the report parameters from the request.
	 *
	 * Without a valid nonce the defaults are used, which keeps the free-form
	 * parameters out of reach of a crafted link - notably check_urls, the one
	 * that makes the server issue HTTP requests.
	 *
	 * @return array{0: string, 1: int, 2: bool} Status filter, limit, and whether to check URLs.
	 */
	private function read_request(): array {
		$defaults = array( 'enabled', 100, false );

		if ( ! isset( $_GET['_validate_report'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_validate_report'] ) ), self::PAGE_SLUG ) ) {
			return $defaults;
		}

		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'enabled';
		if ( ! in_array( $status, array( 'any', 'enabled', 'disabled' ), true ) ) {
			$status = 'enabled';
		}

		$limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		return array( $status, $limit, isset( $_GET['check_urls'] ) );
	}
}
