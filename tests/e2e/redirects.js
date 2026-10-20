/**
 * Redirect fixtures for the E2E specs, made through the plugin's abilities
 * (the REST face of the Abilities API), so no spec drives the admin to set up
 * the state it then tests.
 */
const ABILITIES = '/wp-abilities/v1/abilities/legacy-redirector';

/**
 * Create an enabled redirect.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils fixture.
 * @param {string}                                                      from         Source path.
 * @param {string|number}                                               to           Destination path, URL or post ID.
 * @return {Promise<number>} The redirect ID.
 */
async function createRedirect( requestUtils, from, to ) {
	const redirect = await requestUtils.rest( {
		method: 'POST',
		path: `${ ABILITIES }/create-redirect/run`,
		data: { input: { from, to } },
	} );

	return redirect.id;
}

/**
 * Delete redirects by source path. A source with no redirect is skipped, so
 * this also clears what an earlier, interrupted run left behind.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils fixture.
 * @param {string[]}                                                    sources      Source paths.
 * @return {Promise<void>}
 */
async function deleteRedirects( requestUtils, sources ) {
	const query = sources
		.map(
			( source ) => `input[redirects][]=${ encodeURIComponent( source ) }`
		)
		.join( '&' );

	await requestUtils.rest( {
		method: 'DELETE',
		path: `${ ABILITIES }/delete-redirect/run?${ query }`,
	} );
}

module.exports = { createRedirect, deleteRedirects };
