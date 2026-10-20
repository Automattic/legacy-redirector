/**
 * The Add Redirect form's live checks and destination search, which run in
 * js/admin-redirect-form.js.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { createRedirect, deleteRedirects } = require( './redirects' );

const TAKEN = '/old-e2e-form-taken';
const NEW = '/old-e2e-form-new';

test.describe( 'Add Redirect form', () => {
	let landing;

	test.beforeAll( async ( { requestUtils } ) => {
		await deleteRedirects( requestUtils, [ TAKEN, NEW ] );
		landing = await requestUtils.createPage( {
			title: 'Redirect Landing Page',
			status: 'publish',
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deleteRedirects( requestUtils, [ TAKEN, NEW ] );
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ landing.id }`,
			params: { force: true },
		} );
	} );

	test( 'flags a taken source and a disallowed host as each field is left', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const existingId = await createRedirect(
			requestUtils,
			TAKEN,
			landing.id
		);
		await admin.visitAdminPage(
			'edit.php',
			'post_type=vip-legacy-redirect&page=add-redirect'
		);

		const source = page.getByRole( 'textbox', { name: /Redirect From/ } );
		await source.fill( TAKEN.slice( 1 ) );
		await source.blur();

		await expect( source ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( source ).toHaveAccessibleDescription(
			/A redirect already exists for this source URL\./
		);
		await expect(
			page.getByRole( 'link', { name: 'Edit the existing redirect' } )
		).toHaveAttribute(
			'href',
			new RegExp( `redirect_id=${ existingId }$` )
		);

		const destination = page.getByRole( 'combobox', {
			name: /Redirect To/,
		} );
		await destination.fill( 'https://not-allowed.example/page' );
		await destination.blur();

		await expect( destination ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( destination ).toHaveAccessibleDescription(
			/The destination domain is not allowed/
		);
	} );

	test( 'picks a destination from the search suggestions by keyboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'post_type=vip-legacy-redirect&page=add-redirect'
		);

		await page
			.getByRole( 'textbox', { name: /Redirect From/ } )
			.fill( NEW.slice( 1 ) );

		const destination = page.getByRole( 'combobox', {
			name: /Redirect To/,
		} );
		await destination.fill( 'Landing' );

		const option = page.getByRole( 'option', {
			name: /Redirect Landing Page/,
		} );
		await expect( option ).toBeVisible();
		await expect( destination ).toHaveAttribute( 'aria-expanded', 'true' );

		await destination.press( 'ArrowDown' );
		await expect( option ).toHaveAttribute( 'aria-selected', 'true' );
		await destination.press( 'Enter' );

		await expect( option ).toBeHidden();
		await expect( destination ).toHaveValue(
			`Redirect Landing Page (ID: ${ landing.id })`
		);

		await page.getByRole( 'button', { name: 'Add Redirect' } ).click();

		await expect(
			page.getByText( 'Redirect created successfully.' )
		).toBeVisible();
		await expect( destination ).toHaveValue(
			`Redirect Landing Page (ID: ${ landing.id })`
		);
	} );
} );
