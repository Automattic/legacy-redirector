<?php
/**
 * REST routes backing the admin redirect UI checks.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Rest;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;

/**
 * Registers the REST routes the Add/Edit form and the list table call while
 * a redirect is being entered or tested: the source duplicate/reserved
 * check, the destination allowed-host check, and the validate-and-probe
 * test behind the Test row action.
 *
 * Destination autocomplete has no route here: the form searches with core's
 * /wp/v2/search endpoint.
 *
 * Reads from the auditor, so these routes, the Health column, and the
 * `validate` CLI command report the same findings for the same redirect.
 */
final class ChecksController {

	private const string ROUTE_NAMESPACE = 'legacy-redirector/v1';

	/**
	 * Repository for redirect lookups.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Auditor, which owns the source and destination rules.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectRepositoryInterface $repository Redirect repository.
	 * @param RedirectAuditor             $auditor    Redirect auditor.
	 */
	public function __construct( RedirectRepositoryInterface $repository, RedirectAuditor $auditor ) {
		$this->repository = $repository;
		$this->auditor    = $auditor;
	}

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/check-source',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_source' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'source'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'exclude_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/check-destination',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_destination' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'destination' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/redirects/(?P<id>\d+)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_redirect' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'type' => 'integer',
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may manage redirects.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY );
	}

	/**
	 * Check if a source URL already has a redirect, or is a path WordPress
	 * itself serves.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{exists: bool, reserved: bool} Whether the source is taken, and whether it is reserved.
	 */
	public function check_source( \WP_REST_Request $request ): array {
		$raw        = (string) $request->get_param( 'source' );
		$exclude_id = (int) $request->get_param( 'exclude_id' );

		$none = array(
			'exists'   => false,
			'reserved' => false,
		);

		if ( '' === $raw ) {
			return $none;
		}

		try {
			$source = SourceUrl::from_string( $raw, HomePath::current() );
		} catch ( \InvalidArgumentException $e ) {
			return $none;
		}

		$existing = $this->repository->get_id_by_source( $source );

		// Reserved is a warning, not a refusal: the form still saves.
		return array(
			'exists'   => $existing > 0 && $existing !== $exclude_id,
			'reserved' => in_array( AuditFindingType::RESERVED_SOURCE, $this->auditor->source_warnings( $source ), true ),
		);
	}

	/**
	 * Check a destination before the form is submitted: an external host
	 * missing from allowed_redirect_hosts will be refused at save, so the
	 * form flags it as soon as the field loses focus.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{host_allowed: bool} Whether the destination host is allowed.
	 */
	public function check_destination( \WP_REST_Request $request ): array {
		$raw = (string) $request->get_param( 'destination' );

		if ( '' === $raw ) {
			return array( 'host_allowed' => true );
		}

		try {
			$destination = Destination::from_mixed(
				is_numeric( $raw ) ? (int) $raw : $raw
			);
		} catch ( \InvalidArgumentException $e ) {
			// A malformed destination is the submit-time validation's report to
			// make; this check only answers the allowed-host question.
			return array( 'host_allowed' => true );
		}

		return array(
			'host_allowed' => ! in_array( AuditFindingType::EXTERNAL_HOST_NOT_ALLOWED, $this->auditor->destination_checks( $destination ), true ),
		);
	}

	/**
	 * Audit a redirect and probe its source live.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{valid: bool, status: string, message: string, warnings: array<int, array{label: string, description: string}>, probe: array{status: string, message: string}}|\WP_Error The test result, or a 404 error for an unknown redirect.
	 */
	public function test_redirect( \WP_REST_Request $request ): array|\WP_Error {
		$redirect = $this->repository->find_by_id( (int) $request->get_param( 'id' ) );

		if ( null === $redirect ) {
			return new \WP_Error(
				'legacy_redirector_not_found',
				__( 'Redirect not found.', 'legacy-redirector' ),
				array( 'status' => 404 )
			);
		}

		$findings = $this->auditor->audit( $redirect, true );
		$problems = array();
		$warnings = array();

		foreach ( $findings as $finding ) {
			if ( $finding->is_warning() ) {
				$warnings[] = array(
					'label'       => $finding->label(),
					'description' => $finding->description() . '.',
				);
			} else {
				$problems[] = $finding;
			}
		}

		// The live probe: request the source and see what actually happens,
		// which no static check can - a source serving content, a redirect
		// not firing, or a hop to somewhere other than the stored destination.
		$probe = $this->probe( $redirect );

		// A warning does not fail the redirect - it works, but a person should
		// look - so it rides along rather than turning the result red.
		if ( array() !== $problems ) {
			$finding = reset( $problems );

			return array(
				'valid'    => false,
				'status'   => $finding->type()->value,
				'message'  => $finding->description() . '.',
				'warnings' => $warnings,
				'probe'    => $probe,
			);
		}

		return array(
			'valid'    => true,
			'status'   => 'valid',
			'message'  => __( 'Redirect is valid.', 'legacy-redirector' ),
			'warnings' => $warnings,
			'probe'    => $probe,
		);
	}

	/**
	 * Probe the source and phrase the outcome for display.
	 *
	 * @param Redirect $redirect The redirect to probe.
	 * @return array{status: string, message: string} The probe outcome and its message.
	 */
	private function probe( Redirect $redirect ): array {
		$probe    = $this->auditor->probe_source( $redirect );
		$location = $probe['location'] ?? '';

		$message = match ( $probe['status'] ) {
			/* translators: %s: the URL the source redirected to */
			'confirmed'  => sprintf( __( 'Confirmed live: the source redirects to %s.', 'legacy-redirector' ), $location ),
			/* translators: %s: the URL the source redirected to */
			'diverted'   => sprintf( __( 'The source redirects, but to %s rather than the stored destination.', 'legacy-redirector' ), $location ),
			'dormant'    => __( 'The source currently serves content, so the redirect lies dormant and did not fire.', 'legacy-redirector' ),
			'not-firing' => __( 'The source returns a 404 without redirecting: the redirect did not fire.', 'legacy-redirector' ),
			default      => __( 'Could not confirm live behaviour: the site could not request itself.', 'legacy-redirector' ),
		};

		return array(
			'status'  => $probe['status'],
			'message' => $message,
		);
	}
}
