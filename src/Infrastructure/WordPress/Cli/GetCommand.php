<?php
/**
 * Get redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Application\AmbiguousSourceException;
use Automattic\LegacyRedirector\Application\RedirectFetcher;
use WP_CLI;
use WP_CLI\Formatter;
use WP_CLI_Command;

/**
 * Get details of a single redirect.
 */
final class GetCommand extends WP_CLI_Command {

	use FormatsRedirectRows;

	/**
	 * The redirect fetcher.
	 *
	 * @var RedirectFetcher
	 */
	private RedirectFetcher $fetcher;

	/**
	 * Constructor.
	 *
	 * @param RedirectFetcher $fetcher The redirect fetcher.
	 */
	public function __construct( RedirectFetcher $fetcher ) {
		$this->fetcher = $fetcher;
	}

	/**
	 * Get details of a redirect.
	 *
	 * ## OPTIONS
	 *
	 * <redirect>
	 * : The redirect ID or source path (e.g. /old-page).
	 *
	 * [--field=<field>]
	 * : Return a single field value.
	 *
	 * [--fields=<fields>]
	 * : Limit output to specific fields (comma-separated).
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Get redirect by source path.
	 *     $ wp legacy-redirector get /old-page
	 *
	 *     # Get redirect by ID.
	 *     $ wp legacy-redirector get 123
	 *
	 *     # Get just the destination.
	 *     $ wp legacy-redirector get /old-page --field=to
	 *
	 *     # Get redirect as JSON.
	 *     $ wp legacy-redirector get /old-page --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$identifier = $args[0];
		$field      = $assoc_args['field'] ?? null;

		try {
			$redirect = $this->fetcher->fetch( $identifier );
		} catch ( AmbiguousSourceException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( sprintf( 'Invalid source path: %s', $e->getMessage() ) );
			return;
		}

		if ( null === $redirect ) {
			WP_CLI::error( sprintf( 'Redirect not found: %s', $identifier ) );
			return;
		}

		$data = $this->redirect_row( $redirect ) + array( 'hash' => $redirect->source()->hash() );

		if ( null !== $field && ! isset( $data[ $field ] ) ) {
			WP_CLI::error( sprintf( 'Invalid field: %s. Available fields: %s', $field, implode( ', ', array_keys( $data ) ) ) );
			return;
		}

		// Limit to requested fields.
		if ( null === $field && isset( $assoc_args['fields'] ) ) {
			$requested = array_map( 'trim', explode( ',', $assoc_args['fields'] ) );
			$invalid   = array_diff( $requested, array_keys( $data ) );
			if ( ! empty( $invalid ) ) {
				WP_CLI::error( sprintf( 'Invalid fields: %s. Available fields: %s', implode( ', ', $invalid ), implode( ', ', array_keys( $data ) ) ) );
				return;
			}
			$data = array_intersect_key( $data, array_flip( $requested ) );
		}

		// One item, as `wp post get` renders it: Field/Value rows for table and
		// csv, a single object for json and yaml, the bare value for --field.
		$assoc_args['fields'] = array_keys( $data );
		( new Formatter( $assoc_args ) )->display_item( $data );
	}
}
