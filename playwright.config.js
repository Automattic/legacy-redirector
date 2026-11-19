/**
 * Playwright configuration for the admin end-to-end tests.
 *
 * Extends the `@wordpress/scripts` default, which logs in once and shares the
 * session. That default points at wp-env's tests site (8889), but this
 * plugin's .wp-env.json runs a single environment, so the tests use the
 * development site instead: WP_ENV_PORT when it is set, else wp-env's 8888.
 * wp-env is started separately, locally and in CI, so the default webServer
 * (which would start it) is turned off.
 */
process.env.WP_BASE_URL ??= `http://localhost:${
	process.env.WP_ENV_PORT || 8888
}`;

const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: undefined,
};
