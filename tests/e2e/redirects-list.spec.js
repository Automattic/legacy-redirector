/**
 * The Redirects list screen's scripts: the Test row action and the Test bulk
 * action (RowActionsManager::render_validate_script()), and the Validate all
 * redirects button (ValidateAllButton::render_script()).
 *
 * Test requests each source from the site itself, which wp-env answers on
 * its published port inside the container too, so the live check is real and
 * needs no network: every destination here is a page on this site.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { createRedirect, deleteRedirects } = require( './redirects' );

const ROW = '/old-e2e-list-row';
const BULK = [ '/old-e2e-list-bulk-a', '/old-e2e-list-bulk-b' ];
const SOURCES = [ ROW, ...BULK ];
const LIST_QUERY = 'post_type=vip-legacy-redirect&s=old-e2e-list';

test.describe( 'Redirects list', () => {
	let landing;

	test.beforeAll( async ( { requestUtils } ) => {
		await deleteRedirects( requestUtils, SOURCES );
		landing = await requestUtils.createPage( {
			title: 'Redirect Landing Page',
			status: 'publish',
		} );
		for ( const source of SOURCES ) {
			await createRedirect( requestUtils, source, landing.id );
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteRedirects( requestUtils, SOURCES );
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ landing.id }`,
			params: { force: true },
		} );
	} );

	test( 'the Test row action checks the redirect live and shows the result in its row', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', LIST_QUERY );

		const action = page.getByRole( 'link', {
			name: `Test redirect from “${ ROW }”`,
		} );
		const row = page.getByRole( 'row' ).filter( { has: action } );
		// Row actions only show while the row is hovered or focused.
		await row.hover();
		await action.click();

		await expect( row.locator( 'td.health' ) ).toHaveText(
			`Confirmed live: the source redirects to ${ landing.link }.`
		);
		await expect( action ).toHaveText( 'Test' );
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText(
			`Tested ${ ROW }: Confirmed live`
		);
	} );

	test( 'the Test bulk action checks every selected redirect in the browser', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', LIST_QUERY );

		for ( const source of BULK ) {
			await page
				.getByRole( 'checkbox', { name: `Select ${ source }` } )
				.check();
		}
		await page
			.getByRole( 'combobox', { name: 'Select bulk action' } )
			.first()
			.selectOption( { label: 'Test' } );
		await page.getByRole( 'button', { name: 'Apply' } ).first().click();

		const done =
			'Finished testing the selected redirects. The results are in the Health column.';
		await expect(
			page.locator( '.notice' ).filter( { hasText: done } )
		).toBeVisible();
		for ( const source of BULK ) {
			const row = page.getByRole( 'row' ).filter( {
				has: page.getByRole( 'checkbox', {
					name: `Select ${ source }`,
				} ),
			} );
			await expect( row.locator( 'td.health' ) ).toHaveText(
				`Confirmed live: the source redirects to ${ landing.link }.`
			);
		}
		// One announcement for the whole run, not one per row.
		await expect( page.locator( '#a11y-speak-polite' ) ).toHaveText( done );
		// The page never submitted, which would have reloaded it with the
		// server's "needs JavaScript" notice.
		expect( page.url() ).not.toContain( 'bulk_test_needs_js' );
	} );

	test( 'Validate all redirects runs batch after batch until the pass is done, then reloads', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'edit.php', LIST_QUERY );

		// One batch covers every redirect here, so the first answer is
		// reported as unfinished, as on a site with more redirects than a
		// batch holds. The next request waits until the progress is checked.
		const requests = [];
		let release;
		const held = new Promise( ( resolve ) => ( release = resolve ) );
		await page.route(
			'**/legacy-redirector/v1/validate**',
			async ( route ) => {
				requests.push( route.request().postDataJSON() );
				if ( requests.length > 1 ) {
					await held;
					return route.continue();
				}
				const response = await route.fetch();
				const json = await response.json();
				return route.fulfill( {
					response,
					json: { ...json, done: false },
				} );
			}
		);

		const box = page.locator( '#legacy-redirector-validate-all' );
		const button = box.getByRole( 'button', {
			name: /Validate all redirects|Continue validating/,
		} );
		// A pass already under way is continued rather than restarted.
		const restart = ( await button.getAttribute( 'data-restart' ) ) === '1';
		await button.click();

		await expect(
			box.getByRole( 'progressbar', { name: 'Validation progress' } )
		).toBeVisible();
		await expect( box.getByRole( 'status' ) ).toHaveText(
			/^Validation in progress: \d+ of \d+ redirects? checked\./
		);

		const reloaded = page.waitForEvent( 'load' );
		release();
		await reloaded;

		expect( requests ).toEqual( [ { restart }, { restart: false } ] );
		await expect( box.getByRole( 'status' ) ).toHaveText(
			/^Every redirect was last checked /
		);
	} );
} );
