<?php
/**
 * Get redirect CLI command.
 *
 * @package Automattic\LegacyRedirector
 */

declare( strict_types = 1 );

namespace Automattic\LegacyRedirector\Infrastructure\WordPress\Cli;

use Automattic\LegacyRedirector\Domain\Redirect;
use WP_CLI;
use WP_CLI_Command;

/**
 * Get details of a single redirect.
 */
final class GetCommand extends WP_CLI_Command {

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
	 *     $ wp wpcom-legacy-redirector get /old-page
	 *
	 *     # Get redirect by ID.
	 *     $ wp wpcom-legacy-redirector get 123
	 *
	 *     # Get just the destination.
	 *     $ wp wpcom-legacy-redirector get /old-page --field=to
	 *
	 *     # Get redirect as JSON.
	 *     $ wp wpcom-legacy-redirector get /old-page --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Key-value associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$identifier = $args[0];
		$field      = $assoc_args['field'] ?? null;
		$format     = $assoc_args['format'] ?? 'table';

		try {
			$redirect = $this->fetcher->fetch( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( sprintf( 'Invalid source path: %s', $e->getMessage() ) );
			return;
		}

		if ( null === $redirect ) {
			WP_CLI::error( sprintf( 'Redirect not found: %s', $identifier ) );
			return;
		}

		$data = $this->redirect_to_array( $redirect );

		// Return single field if requested.
		if ( null !== $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				WP_CLI::error( sprintf( 'Invalid field: %s. Available fields: %s', $field, implode( ', ', array_keys( $data ) ) ) );
				return;
			}
			WP_CLI::line( (string) $data[ $field ] );
			return;
		}

		// Limit to requested fields.
		if ( isset( $assoc_args['fields'] ) ) {
			$requested = array_map( 'trim', explode( ',', $assoc_args['fields'] ) );
			$invalid   = array_diff( $requested, array_keys( $data ) );
			if ( ! empty( $invalid ) ) {
				WP_CLI::error( sprintf( 'Invalid fields: %s. Available fields: %s', implode( ', ', $invalid ), implode( ', ', array_keys( $data ) ) ) );
				return;
			}
			$data = array_intersect_key( $data, array_flip( $requested ) );
		}

		// Format as key-value pairs for table.
		if ( 'table' === $format ) {
			$items = array();
			foreach ( $data as $key => $value ) {
				$items[] = array(
					'Field' => $key,
					'Value' => $value,
				);
			}
			\WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
			return;
		}

		// Other formats.
		\WP_CLI\Utils\format_items( $format, array( $data ), array_keys( $data ) );
	}

	/**
	 * Convert a redirect to an output array.
	 *
	 * @param Redirect $redirect The redirect.
	 * @return array<string, int|string|null> The output data.
	 */
	private function redirect_to_array( Redirect $redirect ): array {
		$dest = $redirect->destination();

		return array(
			'ID'     => $redirect->id(),
			'from'   => $redirect->source()->path(),
			'to'     => $dest->is_post_id()
				? $dest->as_post_id()->value()
				: $dest->as_url()->value(),
			'type'   => $dest->is_post_id() ? 'post' : 'url',
			'status' => $redirect->is_active() ? 'enabled' : 'disabled',
			'hash'   => $redirect->source()->hash(),
		);
	}
}
