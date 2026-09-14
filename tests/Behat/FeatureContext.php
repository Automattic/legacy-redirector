<?php
/**
 * Feature tests context class for wp-env based Behat testing.
 *
 * @package Automattic\LegacyRedirector
 */

namespace Automattic\LegacyRedirector\Tests\Behat;

use Automattic\BehatWpEnv\WpEnvFeatureContext;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use RuntimeException;

/**
 * Feature tests context class for WPCOM Legacy Redirector.
 *
 * Extends the Automattic Behat wp-env context with plugin-specific steps.
 *
 * Two performance overrides live here: run_in_container() (used by
 * run_wp_cli_command() and every other container call) and
 * reset_database_state(). Between them they cut suite runtime by roughly 6x.
 * See the docblock on each for the reasoning. Both are candidates for
 * upstreaming into automattic/behat-wp-env-context, which would benefit every
 * plugin using it; they live here for now so this repository gets the win
 * without waiting on a package release.
 */
final class FeatureContext extends WpEnvFeatureContext {

	/**
	 * Absolute path inside the container to the plugins directory.
	 *
	 * @var string
	 */
	private const CONTAINER_PLUGIN_PATH = '/var/www/html/wp-content/plugins/';

	/**
	 * Resolved tests-cli container name, or empty string when unavailable.
	 *
	 * Null means "not yet looked up". Empty string means "looked up and not
	 * found", so a failing lookup is not repeated for every step.
	 *
	 * @var string|null
	 */
	private static $cli_container = null;

	/**
	 * Whether the plugin has been activated during this run.
	 *
	 * @var bool
	 */
	private static $plugin_activated = false;

	/**
	 * Hosts added to allowed_redirect_hosts for cleanup.
	 *
	 * @var string[]
	 */
	private array $added_hosts = array();

	/**
	 * Get the plugin slug for wp-env command execution.
	 *
	 * Derived rather than hardcoded because wp-env mounts the plugin at
	 * wp-content/plugins/<basename of repo dir>, which differs from the repo
	 * name for some checkouts (e.g. worktrees).
	 *
	 * @return string Plugin directory name.
	 */
	protected function get_plugin_slug(): string {
		return self::plugin_slug();
	}

	/**
	 * Static counterpart to get_plugin_slug(), for use in suite-level hooks.
	 *
	 * @return string Plugin directory name.
	 */
	private static function plugin_slug(): string {
		return basename( dirname( __DIR__, 2 ) );
	}

	/**
	 * Locate the wp-env tests-cli container serving THIS checkout.
	 *
	 * Container names are derived by wp-env from a hash of the environment
	 * path, and a developer may have several environments running at once (one
	 * per git worktree). Picking the first container matching "tests-cli" would
	 * therefore run this suite's destructive resets against somebody else's
	 * database, so the container is identified by matching a bind-mount source
	 * against this checkout's path instead of by name.
	 *
	 * There is deliberately no fallback to `wp-env run` here. Every condition
	 * that breaks this lookup - Docker absent, Docker not running, environment
	 * not started - also breaks `wp-env run`, so a fallback would not rescue a
	 * real failure; all it would do is silently cost the suite a 5x slowdown,
	 * which is exactly how the same lookup sat broken and unnoticed in
	 * co-authors-plus. Failing loudly here, once, at suite start, is cheaper.
	 *
	 * @throws RuntimeException If the container cannot be identified.
	 * @return string Container name.
	 */
	private static function get_cli_container(): string {
		if ( null !== self::$cli_container ) {
			return self::$cli_container;
		}

		$repo_root = realpath( dirname( __DIR__, 2 ) );

		$names = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test harness; must shell out to Docker.
		exec( 'docker ps --filter name=tests-cli --format "{{.Names}}" 2>&1', $names, $exit_code );
		$names = array_values( array_filter( array_map( 'trim', $names ) ) );

		if ( 0 !== $exit_code ) {
			$message = "Could not list Docker containers (exit code {$exit_code}):\n"
				. implode( "\n", $names )
				. "\n\nThe Behat suite talks to the wp-env tests-cli container directly. Is Docker running?";

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( $message );
		}

		// Record what was inspected, so a failure says why rather than just that.
		$inspected = array();

		foreach ( $names as $name ) {
			$mounts = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test harness; must shell out to Docker.
			exec(
				sprintf(
					// println, rather than a \n in the template, because Docker
					// passes the format string through verbatim: an escape here
					// would come back as a literal backslash-n on a single line.
					'docker inspect %s --format "{{range .Mounts}}{{println .Source}}{{end}}" 2>/dev/null',
					escapeshellarg( $name )
				),
				$mounts
			);

			$mounts             = array_values( array_filter( array_map( 'trim', $mounts ) ) );
			$inspected[ $name ] = $mounts;

			if ( in_array( $repo_root, $mounts, true ) ) {
				self::$cli_container = $name;
				return self::$cli_container;
			}
		}

		$report = '';
		foreach ( $inspected as $name => $mounts ) {
			$report .= "\n  {$name}\n    " . implode( "\n    ", $mounts );
		}

		$message = "No running wp-env tests-cli container has a bind mount for this checkout.\n\n"
			. "Looking for: {$repo_root}\n"
			. ( empty( $inspected )
				? 'No tests-cli containers are running. Start one with: npx wp-env start'
				: "Mounts of the tests-cli containers that are running:{$report}" );

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
		throw new RuntimeException( $message );
	}

	/**
	 * Run a shell command inside the wp-env tests-cli container.
	 *
	 * `wp-env run` creates and tears down a fresh container for every single
	 * invocation (measured at ~1s each, before npx startup); `docker exec`
	 * against the container wp-env is already running is roughly half that.
	 * A scenario issues a dozen or more invocations, so the difference
	 * dominates total suite runtime.
	 *
	 * @param string $shell_command Shell command to run inside the container.
	 * @param bool   $in_plugin_dir Whether to run from the plugin directory.
	 * @return array{0: string[], 1: int} Output lines and exit code.
	 */
	private static function run_in_container( string $shell_command, bool $in_plugin_dir = false ): array {
		$exec_command = sprintf(
			'docker exec %s%s sh -c %s',
			$in_plugin_dir ? '-w ' . escapeshellarg( self::CONTAINER_PLUGIN_PATH . self::plugin_slug() ) . ' ' : '',
			escapeshellarg( self::get_cli_container() ),
			escapeshellarg( $shell_command )
		);

		$output_lines = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Test harness; must shell out to Docker.
		exec( $exec_command, $output_lines, $exit_code );

		return array( $output_lines, $exit_code );
	}

	/**
	 * Execute a WP-CLI command inside the wp-env tests container.
	 *
	 * Overrides the parent purely for speed; see run_in_container(). Output
	 * handling deliberately mirrors the parent: STDERR is folded into STDOUT by
	 * the shell, then lines beginning "Error:"/"Warning:" (plus their indented
	 * continuations) are split back out into $error_output.
	 *
	 * @param string $command     The WP-CLI command to execute (without 'wp').
	 * @param bool   $should_fail Whether the command is expected to fail.
	 * @return void
	 */
	protected function run_wp_cli_command( string $command, bool $should_fail = false ): void {
		list( $output_lines, $exit_code ) = self::run_in_container(
			'wp ' . $this->replace_variables( $command ) . ' 2>&1',
			true
		);

		// Drop blank padding lines, matching the parent's filtering.
		$filtered_lines = array_filter(
			$output_lines,
			static function ( $line ) use ( $output_lines ) {
				return ! ( '' === trim( $line ) && count( $output_lines ) > 1 );
			}
		);

		$this->output       = implode( "\n", $filtered_lines );
		$this->error_output = '';
		$this->exit_code    = $exit_code;

		if ( 0 === $exit_code && ! $should_fail ) {
			return;
		}

		$error_lines    = array();
		$in_error_block = false;

		foreach ( $filtered_lines as $line ) {
			if ( 0 === strpos( $line, 'Error:' ) || 0 === strpos( $line, 'Warning:' ) ) {
				$error_lines[]  = $line;
				$in_error_block = true;
			} elseif ( $in_error_block && ( 0 === strpos( $line, ' ' ) || 0 === strpos( $line, "\t" ) ) ) {
				$error_lines[] = $line;
			} else {
				$in_error_block = false;
			}
		}

		if ( ! empty( $error_lines ) ) {
			$this->error_output = implode( "\n", $error_lines );
			$this->output       = implode( "\n", array_diff( $filtered_lines, $error_lines ) );
		}
	}

	/**
	 * Reset database state between scenarios.
	 *
	 * Overrides the parent purely for speed. The parent issues four WP-CLI
	 * calls plus one per non-admin user, and plugin_specific_cleanup() adds two
	 * more plus one per allowed host, so a scenario paid for roughly eight
	 * WP-CLI invocations after asserting anything. This performs the identical
	 * work in one.
	 *
	 * The sequence below deliberately matches the parent's ordering, with the
	 * redirect post type removed last to mirror plugin_specific_cleanup(); it
	 * is excluded from a post_type=any query because the post type is not
	 * public.
	 *
	 * @throws RuntimeException If the reset fails.
	 * @return void
	 */
	protected function reset_database_state(): void {
		$php = <<<'RESET'
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( get_users( array( 'fields' => 'ID' ) ) as $uid ) {
	if ( 1 !== (int) $uid ) {
		wp_delete_user( (int) $uid );
	}
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%'" );
wp_cache_flush();
foreach ( get_posts( array( 'post_type' => 'vip-legacy-redirect', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}

RESET;

		// Remove mu-plugins created for allowed_redirect_hosts, in the same call.
		foreach ( $this->added_hosts as $host ) {
			$php .= sprintf(
				'$f = WPMU_PLUGIN_DIR . "/allowed_redirect_hosts-%s.php"; if ( file_exists( $f ) ) { unlink( $f ); }' . "\n",
				$host
			);
		}
		$this->added_hosts = array();

		// Base64 keeps the payload free of single quotes, so wrapping it in them
		// is enough to hand WP-CLI the whole program as a single argument
		// through docker exec's sh -c.
		$this->run_wp_cli_command(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding for shell safety.
			sprintf( 'eval \'eval( base64_decode( "%s" ) );\'', base64_encode( $php ) ),
			false
		);

		// A silently failing reset would leak state into the next scenario and
		// produce a confusing failure far from its cause, so fail loudly here.
		if ( 0 !== $this->exit_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( 'Failed to reset database state: ' . $this->output );
		}

		$this->variables = array();
	}

	/**
	 * Plugin-specific database cleanup.
	 *
	 * Retained so that the parent's reset_database_state() remains correct if it
	 * is ever called directly; the fast reset above folds this work into its
	 * single call.
	 *
	 * @return void
	 */
	protected function plugin_specific_cleanup(): void {
		// Delete all redirect posts (custom post type).
		$this->run_wp_cli_command( 'post list --post_type=vip-legacy-redirect --format=ids', false );
		$redirect_ids = trim( $this->output );

		if ( ! empty( $redirect_ids ) ) {
			$this->run_wp_cli_command( "post delete {$redirect_ids} --force", false );
		}

		// Remove mu-plugins created for allowed_redirect_hosts.
		foreach ( $this->added_hosts as $host ) {
			$this->remove_mu_plugin( 'allowed_redirect_hosts-' . $host );
		}
		$this->added_hosts = array();
	}

	/**
	 * Create bypass 404 mu-plugin before test suite runs.
	 *
	 * This is created once for all scenarios, avoiding per-scenario overhead.
	 *
	 * @BeforeSuite
	 * @param BeforeSuiteScope $scope Suite scope.
	 * @return void
	 */
	public static function setup_bypass_mu_plugin( BeforeSuiteScope $scope ): void {
		$bypass_validation = <<<'PHP'
<?php
/**
 * Bypass 404 validation for Behat tests.
 */
add_filter( 'pre_http_request', function( $preempt, $args, $url ) {
	if ( false !== strpos( $url, home_url() ) ) {
		return array( 'response' => array( 'code' => 404 ) );
	}
	return $preempt;
}, 10, 3 );
PHP;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding for shell safety.
		$encoded = base64_encode( $bypass_validation );
		self::run_in_container(
			sprintf(
				'wp eval \'if ( ! is_dir( WPMU_PLUGIN_DIR ) ) { mkdir( WPMU_PLUGIN_DIR, 0755, true ); } file_put_contents( WPMU_PLUGIN_DIR . "/behat-bypass-404-check.php", base64_decode( "%s" ) );\' 2>&1',
				$encoded
			)
		);
	}

	/**
	 * Remove bypass 404 mu-plugin after test suite completes.
	 *
	 * @AfterSuite
	 * @param AfterSuiteScope $scope Suite scope.
	 * @return void
	 */
	public static function teardown_bypass_mu_plugin( AfterSuiteScope $scope ): void {
		self::run_in_container(
			'wp eval \'if ( file_exists( WPMU_PLUGIN_DIR . "/behat-bypass-404-check.php" ) ) { unlink( WPMU_PLUGIN_DIR . "/behat-bypass-404-check.php" ); }\' 2>&1'
		);
	}

	/**
	 * Set up a WP installation with WPCOM Legacy Redirector plugin activated.
	 *
	 * @Given a WP install(ation) with the WPCOM Legacy Redirector plugin
	 * @throws RuntimeException If plugin activation fails.
	 * @return void
	 */
	public function given_a_wp_installation_with_the_wpcomlr_plugin(): void {
		// Activation survives the between-scenario reset, which only clears
		// posts, users, transients and cache, and no scenario deactivates the
		// plugin, so this is checked once per run rather than once per scenario.
		if ( self::$plugin_activated ) {
			return;
		}

		$slug = $this->get_plugin_slug();

		// WP-CLI identifies a plugin by its main file's basename, which is not
		// the directory name in a worktree checkout, so both forms are tried;
		// is-active first, as it is the common case on a warm environment.
		$attempts = array(
			"plugin is-active {$slug}",
			"plugin is-active {$slug}/wpcom-legacy-redirector.php",
			"plugin activate {$slug}",
			"plugin activate {$slug}/wpcom-legacy-redirector.php",
		);

		foreach ( $attempts as $attempt ) {
			$this->run_wp_cli_command( $attempt, false );

			if ( 0 === $this->exit_code ) {
				self::$plugin_activated = true;
				return;
			}
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
		throw new RuntimeException(
			'Failed to activate WPCOM Legacy Redirector plugin: ' . $this->output . ' ' . $this->error_output
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Create a published post with a specific slug.
	 *
	 * @Given there is a published post with a slug of :post_name
	 * @throws RuntimeException If post creation fails.
	 * @param string $post_name Post slug to use.
	 * @return void
	 */
	public function there_is_a_published_post( string $post_name ): void {
		$command = sprintf(
			"post create --post_title='%s' --post_name='%s' --post_status='publish'",
			$post_name,
			$post_name
		);
		$this->run_wp_cli_command( $command, false );

		if ( 0 !== $this->exit_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( 'Failed to create post: ' . $this->output );
		}
	}

	/**
	 * Add host to allowed_redirect_hosts.
	 *
	 * @Given :host is allowed to be redirected
	 * @throws RuntimeException If filter setup fails.
	 * @param string $host Host name to add.
	 * @return void
	 */
	public function i_add_host_to_allowed_redirect_hosts( string $host ): void {
		$filter_code = sprintf(
			"<?php add_filter( 'allowed_redirect_hosts', function( \$hosts ) { return array_merge( \$hosts, array( '%s' ) ); } );",
			$host
		);

		$this->create_mu_plugin( 'allowed_redirect_hosts-' . $host, $filter_code );
		$this->added_hosts[] = $host;
	}

	/**
	 * Temporary files created for cleanup.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	/**
	 * Create a CSV file with given content.
	 *
	 * @Given a CSV file :filename with content:
	 * @throws RuntimeException If file creation fails.
	 * @param string                           $filename The filename to create.
	 * @param \Behat\Gherkin\Node\PyStringNode $content The CSV content.
	 * @return void
	 */
	public function given_a_csv_file_with_content( string $filename, \Behat\Gherkin\Node\PyStringNode $content ): void {
		$csv_content = (string) $content;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding for shell safety.
		$encoded = base64_encode( $csv_content );

		// Create file in the container's /tmp directory.
		$file_path = '/tmp/' . $filename;

		list( $output, $exit_code ) = self::run_in_container(
			sprintf(
				'echo %s | base64 -d > %s 2>&1',
				escapeshellarg( $encoded ),
				escapeshellarg( $file_path )
			)
		);

		if ( 0 !== $exit_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( 'Failed to create CSV file: ' . implode( "\n", $output ) );
		}

		$this->temp_files[] = $file_path;
	}

	/**
	 * Clean up temporary files created during tests.
	 *
	 * @AfterScenario
	 * @return void
	 */
	public function cleanup_temp_files(): void {
		if ( empty( $this->temp_files ) ) {
			return;
		}

		// One call for every file, rather than one call per file.
		self::run_in_container( 'rm -f ' . implode( ' ', array_map( 'escapeshellarg', $this->temp_files ) ) );

		$this->temp_files = array();
	}

	/**
	 * Create a redirect via WP-CLI.
	 *
	 * @Given there is a redirect from :from to :to
	 * @throws RuntimeException If redirect creation fails.
	 * @param string $from The source URL path.
	 * @param string $to   The destination URL or post ID.
	 * @return void
	 */
	public function there_is_a_redirect_from_to( string $from, string $to ): void {
		$this->run_wp_cli_command(
			sprintf( 'wpcom-legacy-redirector create %s %s', $from, $to ),
			false
		);

		if ( 0 !== $this->exit_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( 'Failed to create redirect: ' . $this->output . ' ' . $this->error_output );
		}
	}

	/**
	 * Save STDOUT value to a placeholder for later use.
	 *
	 * This allows capturing post IDs and other output for use in subsequent steps.
	 *
	 * @Given save STDOUT as {VARIABLE}
	 * @throws RuntimeException If no output to save.
	 * @return void
	 */
	public function save_stdout_as_variable(): void {
		// This step is handled by the base class or WP-CLI Behat framework.
		// Kept here for documentation purposes.
	}

	/**
	 * Request a front-end path without following redirects.
	 *
	 * Issues a real HTTP request against the test site from inside the
	 * tests-cli container, so redirect behaviour is asserted at the HTTP
	 * level (status line and headers are captured into STDOUT).
	 *
	 * @When I request the front-end path :path
	 * @param string $path URL path to request, e.g. "/old-page".
	 * @return void
	 */
	public function i_request_the_front_end_path( string $path ): void {
		// Resolve the site's Host header from home_url(), then curl the
		// tests-wordpress service directly (the site port is not reachable
		// from inside the CLI container).
		$container_script = sprintf(
			'HOST_HEADER=$(wp eval \'$p = wp_parse_url( home_url() ); echo $p["host"] . ( isset( $p["port"] ) ? ":" . $p["port"] : "" );\'); curl -sI -H "Host: ${HOST_HEADER}" %s 2>&1',
			escapeshellarg( 'http://tests-wordpress' . $path )
		);

		list( $output_lines, $exit_code ) = self::run_in_container( $container_script, true );

		$this->output    = implode( "\n", $output_lines );
		$this->exit_code = $exit_code;
	}

	/**
	 * Move a post to the trash.
	 *
	 * @Given the post :post_name is trashed
	 * @throws RuntimeException If the post cannot be trashed.
	 * @param string $post_name The post slug.
	 * @return void
	 */
	public function the_post_is_trashed( string $post_name ): void {
		// Get post ID by slug.
		$command = sprintf(
			"post list --post_name='%s' --field=ID --post_status=any",
			$post_name
		);
		$this->run_wp_cli_command( $command, false );

		if ( 0 !== $this->exit_code || empty( trim( $this->output ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( 'Could not find post to trash: ' . $post_name );
		}

		$post_id = trim( $this->output );

		// Move post to trash.
		$this->run_wp_cli_command( "post update {$post_id} --post_status=trash", false );

		if ( 0 !== $this->exit_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages don't require escaping.
			throw new RuntimeException( 'Failed to trash post: ' . $this->output );
		}
	}
}
