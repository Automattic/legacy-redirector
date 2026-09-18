<?php
/**
 * Handles validation notices and the non-AJAX validation action.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Admin\Notices;

use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Domain\AuditFindingType;
use Automattic\LegacyRedirector\Domain\RedirectRepositoryInterface;
use Automattic\LegacyRedirector\Infrastructure\WordPress\Capability;
use Automattic\LegacyRedirector\Infrastructure\WordPress\PostType;

/**
 * Handles validation notices and the fallback validation action.
 *
 * Reads from the auditor, so this action, the To column, and the `validate`
 * CLI command report the same findings for the same redirect.
 */
final class ValidationNotices {

	/**
	 * The query value for a redirect that passed validation.
	 *
	 * Every other value carried by the `validate` query arg is an
	 * AuditFindingType backing value.
	 *
	 * @var string
	 */
	private const string RESULT_VALID = 'valid';

	/**
	 * The query value for a redirect ID that no longer exists.
	 *
	 * @var string
	 */
	private const string RESULT_NOT_FOUND = 'not-found';

	/**
	 * Notice arguments for a failed validation.
	 *
	 * The `message` ID is the identifier core's list tables have always used
	 * for this slot, so it is kept for anything styling or scripting against it.
	 *
	 * @var array<string, bool|string>
	 */
	private const array ERROR_NOTICE_ARGS = array(
		'type'        => 'error',
		'id'          => 'message',
		'dismissible' => true,
	);

	/**
	 * Notice arguments for a successful validation.
	 *
	 * @var array<string, bool|string>
	 */
	private const array SUCCESS_NOTICE_ARGS = array(
		'type'        => 'success',
		'id'          => 'message',
		'dismissible' => true,
	);

	/**
	 * Notice arguments for a warning riding along on a validation result.
	 *
	 * No `message` ID: the main result notice owns that slot, and these
	 * render alongside it.
	 *
	 * @var array<string, bool|string>
	 */
	private const array WARNING_NOTICE_ARGS = array(
		'type'        => 'warning',
		'dismissible' => true,
	);

	/**
	 * Redirect repository.
	 *
	 * @var RedirectRepositoryInterface
	 */
	private RedirectRepositoryInterface $repository;

	/**
	 * Redirect auditor.
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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'display_validation_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_validation_action' ) );
		add_filter( 'removable_query_args', array( $this, 'add_removable_args' ) );
	}

	/**
	 * Add query args that should be removed after displaying notices.
	 *
	 * @param array<string> $args Existing removable args.
	 * @return array<string> Modified args.
	 */
	public function add_removable_args( array $args ): array {
		$args[] = 'validate';
		$args[] = 'ids';
		$args[] = 'warnings';
		return $args;
	}

	/**
	 * Display validation notices.
	 *
	 * @return void
	 */
	public function display_validation_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		if ( ! isset( $_GET['validate'] ) ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		$result = sanitize_text_field( wp_unslash( $_GET['validate'] ) );

		// Get redirect details for context in the notice. The repository
		// returns null for IDs of other post types, so no foreign title leaks.
		$redirect_context = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		if ( isset( $_GET['ids'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
			$redirect = $this->repository->find_by_id( absint( $_GET['ids'] ) );
			if ( null !== $redirect ) {
				$redirect_context = sprintf(
					/* translators: %s: source URL path */
					' ' . __( 'for %s', 'legacy-redirector' ),
					'<code>' . esc_html( $redirect->source()->path() ) . '</code>'
				);
			}
		}

		if ( self::RESULT_VALID === $result ) {
			/* translators: %s: context showing which redirect (e.g. "for /old-page") */
			$message = sprintf( __( 'Redirect is valid%s.', 'legacy-redirector' ), $redirect_context );
			wp_admin_notice( wp_kses_post( $message ), self::SUCCESS_NOTICE_ARGS );
			$this->display_warning_notices( $redirect_context );
			return;
		}

		if ( self::RESULT_NOT_FOUND === $result ) {
			wp_admin_notice( esc_html__( 'Redirect not found.', 'legacy-redirector' ), self::ERROR_NOTICE_ARGS );
			return;
		}

		$finding_type = AuditFindingType::tryFrom( $result );
		if ( null === $finding_type ) {
			return;
		}

		wp_admin_notice(
			esc_html__( 'Redirect is not valid', 'legacy-redirector' ) . wp_kses_post( $redirect_context ) . '<br />' . esc_html( $finding_type->description() . '.' ),
			self::ERROR_NOTICE_ARGS
		);
		$this->display_warning_notices( $redirect_context );
	}

	/**
	 * Display a notice per warning carried on the request.
	 *
	 * Only warning-severity finding types render: the query arg is
	 * uncontrolled, and a crafted URL must not be able to present a problem
	 * finding under warning styling.
	 *
	 * @param string $redirect_context The "for /path" context fragment, already escaped.
	 * @return void
	 */
	private function display_warning_notices( string $redirect_context ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		if ( ! isset( $_GET['warnings'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for notice display after redirect.
		$types = explode( ',', sanitize_text_field( wp_unslash( $_GET['warnings'] ) ) );

		foreach ( $types as $type_value ) {
			$type = AuditFindingType::tryFrom( $type_value );

			if ( null === $type || ! $type->is_warning() ) {
				continue;
			}

			wp_admin_notice(
				esc_html( $type->label() ) . wp_kses_post( $redirect_context ) . '<br />' . esc_html( $type->description() . '.' ),
				self::WARNING_NOTICE_ARGS
			);
		}
	}

	/**
	 * Handle the non-AJAX validation action.
	 *
	 * @return void
	 */
	public function handle_validation_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET['action'] ) || 'validate' !== $_GET['action'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET['post'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		$post_id = absint( $_GET['post'] );
		if ( ! $post_id ) {
			return;
		}

		if ( ! isset( $_REQUEST['_validate_redirect'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_validate_redirect'] ) ), 'validate_vip_legacy_redirect' ) ) {
			return;
		}

		if ( ! current_user_can( Capability::MANAGE_REDIRECTS_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to validate redirects.', 'legacy-redirector' ) );
		}

		$redirect = $this->repository->find_by_id( $post_id );

		if ( null === $redirect ) {
			$this->redirect_with_result( self::RESULT_NOT_FOUND, $post_id );
			return;
		}

		$findings = $this->auditor->audit( $redirect, true );
		$problems = array();
		$warnings = array();

		foreach ( $findings as $finding ) {
			if ( $finding->is_warning() ) {
				$warnings[] = $finding->type()->value;
			} else {
				$problems[] = $finding;
			}
		}

		// A warning does not fail the redirect - it works, but a person should
		// look - so it rides along rather than turning the result red.
		$result = array() !== $problems
			? reset( $problems )->type()->value
			: self::RESULT_VALID;

		$this->redirect_with_result( $result, $post_id, $warnings );
	}

	/**
	 * Redirect back with validation result.
	 *
	 * @param string   $validate Result status code.
	 * @param int      $post_id  The post ID.
	 * @param string[] $warnings Warning finding type values to carry along.
	 * @return void
	 */
	private function redirect_with_result( string $validate, int $post_id, array $warnings = array() ): void {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = admin_url( 'edit.php?post_type=' . PostType::POST_TYPE );
		}
		$sendback = remove_query_arg( array( 'validate', 'ids', 'warnings' ), $referer );

		$args = array(
			'validate' => $validate,
			'ids'      => $post_id,
		);

		if ( array() !== $warnings ) {
			$args['warnings'] = implode( ',', $warnings );
		}

		wp_safe_redirect( add_query_arg( $args, $sendback ) );
		exit();
	}
}
