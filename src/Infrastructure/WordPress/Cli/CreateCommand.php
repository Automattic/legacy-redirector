<?php
/**
 * Create redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\HomePath;
use Automattic\LegacyRedirector\Application\RedirectAuditor;
use Automattic\LegacyRedirector\Application\RedirectManager;
use Automattic\LegacyRedirector\Domain\Destination;
use Automattic\LegacyRedirector\Domain\Redirect;
use Automattic\LegacyRedirector\Domain\SourceUrl;
use WP_CLI;
use WP_CLI_Command;

/**
 * Create a redirect.
 */
final class CreateCommand extends WP_CLI_Command {

	/**
	 * The redirect manager.
	 *
	 * @var RedirectManager
	 */
	private RedirectManager $manager;

	/**
	 * The redirect auditor, which owns the source warning rules.
	 *
	 * @var RedirectAuditor
	 */
	private RedirectAuditor $auditor;

	/**
	 * Constructor.
	 *
	 * @param RedirectManager $manager The redirect manager.
	 * @param RedirectAuditor $auditor The redirect auditor.
	 */
	public function __construct( RedirectManager $manager, RedirectAuditor $auditor ) {
		$this->manager = $manager;
		$this->auditor = $auditor;
	}

	/**
	 * Create a redirect.
	 *
	 * ## OPTIONS
	 *
	 * <from>
	 * : The path to redirect from.
	 *
	 * <to>
	 * : The redirect destination. A path, a full URL, or a post ID.
	 *
	 * [--status=<status>]
	 * : The initial status of the redirect.
	 * ---
	 * default: enabled
	 * options:
	 *   - enabled
	 *   - disabled
	 * ---
	 *
	 * [--skip-validation]
	 * : Skip destination validation (not recommended).
	 *
	 * [--porcelain]
	 * : Output just the new redirect ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a redirect from /foo (must not exist) to /bar.
	 *     $ wp legacy-redirector create /foo /bar
	 *     Success: Created redirect 123: /foo -> /bar
	 *
	 *     # Create a redirect from /bar to post ID 5.
	 *     $ wp legacy-redirector create /bar 5
	 *
	 *     # Create a disabled redirect.
	 *     $ wp legacy-redirector create /old /new --status=disabled
	 *
	 *     # Create a redirect and capture its ID.
	 *     $ wp legacy-redirector create /old /new --porcelain
	 *     123
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$from_url    = $args[0];
		$to_value    = ctype_digit( $args[1] ) ? (int) $args[1] : $args[1];
		$status_flag = $assoc_args['status'] ?? 'enabled';
		$post_status = 'disabled' === $status_flag ? 'draft' : 'publish';
		$validate    = ! isset( $assoc_args['skip-validation'] );
		$porcelain   = isset( $assoc_args['porcelain'] );

		try {
			$source      = SourceUrl::from_string( $from_url, HomePath::current() );
			$destination = Destination::from_mixed( $to_value );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( sprintf( "Couldn't create %s -> %s (%s)", $from_url, $to_value, $e->getMessage() ) );
			return;
		}

		$result = $this->manager->create_redirect( $source, $destination, $validate, $post_status );

		if ( $result->is_error() ) {
			WP_CLI::error( sprintf( "Couldn't create %s -> %s (%s)", $from_url, $to_value, $result->error_message() ?? 'Unknown error' ) );
			return;
		}

		foreach ( $this->auditor->warnings( Redirect::create( $source, $destination ) ) as $warning ) {
			WP_CLI::warning( $warning->description() . '.' );
		}

		if ( $porcelain ) {
			WP_CLI::line( (string) $result->redirect_id() );
			return;
		}

		$status_msg = 'disabled' === $status_flag ? ' (disabled)' : '';
		WP_CLI::success( sprintf( 'Created redirect %d: %s -> %s%s', $result->redirect_id(), $from_url, $to_value, $status_msg ) );
	}
}
