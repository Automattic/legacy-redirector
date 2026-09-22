/**
 * Add/Edit Redirect form.
 *
 * Renders the form with WordPress Design System components, but still posts
 * natively to admin-post.php, so RedirectFormPage::handle_save() stays the
 * single place a save is validated. The blur checks and the post search call
 * the same admin-ajax handlers the jQuery version did.
 *
 * Configuration comes from RedirectFormPage::enqueue_assets() as
 * `window.legacyRedirectorForm`.
 */
import {
	createInterpolateElement,
	createRoot,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, RadioControl } from '@wordpress/components';
import {
	Autocomplete,
	Field,
	InputControl,
	InputLayout,
	Link,
	Stack,
	Text,
} from '@wordpress/ui';

const settings = window.legacyRedirectorForm || {};

/**
 * POST to an admin-ajax action and return the `data` of a success response.
 *
 * @param {string} action Handler action name.
 * @param {string} nonce  Nonce for that action.
 * @param {Object} fields Extra request fields.
 * @return {Promise<Object|null>} Response data, or null on failure.
 */
async function ajax( action, nonce, fields ) {
	try {
		const response = await window.fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: new URLSearchParams( { action, nonce, ...fields } ),
		} );
		const json = await response.json();
		return json.success ? json.data : null;
	} catch {
		return null;
	}
}

const postLabel = ( post ) =>
	sprintf(
		/* translators: 1: post title, 2: post ID. */
		__( '%1$s (ID: %2$d)', 'legacy-redirector' ),
		post.title,
		post.id
	);

// Search only for text that looks like a title, not a path, ID, or URL.
const isSearchable = ( value ) =>
	value.length >= 2 &&
	! /^[\/0-9]/.test( value ) &&
	! /^https?:/i.test( value );

function RedirectForm() {
	const isEdit = settings.redirectId > 0;
	const example = settings.homePrefix + 'old-page';

	const [ source, setSource ] = useState( settings.source );
	const [ status, setStatus ] = useState( settings.status );
	const [ destinationText, setDestinationText ] = useState(
		settings.destinationDisplay
	);
	// The post picked from the suggestions, if the text still shows it.
	const [ picked, setPicked ] = useState( settings.destinationPost );
	const [ results, setResults ] = useState( [] );
	const [ open, setOpen ] = useState( false );
	const [ sourceCheck, setSourceCheck ] = useState( {} );
	const [ hostAllowed, setHostAllowed ] = useState( true );
	const [ serverError, setServerError ] = useState( settings.errorMessage );
	const [ savedMessage, setSavedMessage ] = useState( settings.savedMessage );
	const searchTimeout = useRef();

	const destination =
		picked && destinationText === postLabel( picked )
			? String( picked.id )
			: destinationText.trim();

	// A duplicate blocks the save; a reserved path only warns.
	const checkSource = async () => {
		const value = source.trim();
		if ( value === '' || value === settings.source ) {
			setSourceCheck( {} );
			return;
		}
		setSourceCheck(
			( await ajax( settings.checkAction, settings.checkNonce, {
				redirect_from: value,
				exclude_id: settings.redirectId,
			} ) ) || {}
		);
	};

	// Only absolute URLs have a host to check against allowed_redirect_hosts.
	const checkDestination = async () => {
		if ( ! /^https?:\/\//i.test( destination ) ) {
			setHostAllowed( true );
			return;
		}
		const data = await ajax(
			settings.checkDestAction,
			settings.checkDestNonce,
			{ redirect_to: destination }
		);
		setHostAllowed( data?.host_allowed !== false );
	};

	// Editing a field clears its check result, so the notice goes while the
	// field changes rather than on blur, where the layout shift can swallow
	// the click that caused the blur.
	const onSourceChange = ( value ) => {
		setSource( value );
		setSourceCheck( {} );
	};

	const onDestinationChange = ( value ) => {
		setDestinationText( value );
		setHostAllowed( true );
		// Choosing a suggestion, by pointer or keyboard, sets the text to its label.
		const match = results.find( ( post ) => postLabel( post ) === value );
		if ( match ) {
			setPicked( match );
			setResults( [] );
			return;
		}
		window.clearTimeout( searchTimeout.current );
		if ( ! isSearchable( value.trim() ) ) {
			setResults( [] );
			return;
		}
		searchTimeout.current = window.setTimeout( async () => {
			const data = await ajax(
				settings.searchAction,
				settings.searchNonce,
				{
					search: value.trim(),
				}
			);
			setResults( data?.posts ?? [] );
			setOpen( true );
		}, 300 );
	};

	return (
		<form method="post" action={ settings.adminPostUrl }>
			<input type="hidden" name="action" value="save_redirect" />
			<input
				type="hidden"
				name="redirect_nonce"
				value={ settings.saveNonce }
			/>
			{ isEdit && (
				<input
					type="hidden"
					name="redirect_id"
					value={ settings.redirectId }
				/>
			) }
			<input type="hidden" name="redirect_to" value={ destination } />
			<input type="hidden" name="redirect_status" value={ status } />

			<Stack direction="column" gap="xl">
				{ savedMessage && (
					<Notice
						status="success"
						onRemove={ () => setSavedMessage( '' ) }
						actions={ [
							{
								label: __( 'Test it', 'legacy-redirector' ),
								url: settings.testUrl,
							},
						] }
					>
						{ savedMessage }
					</Notice>
				) }
				{ serverError && (
					<Notice
						status="error"
						onRemove={ () => setServerError( '' ) }
					>
						{ serverError }
					</Notice>
				) }
				{ settings.reservedSource && (
					<Notice status="warning" isDismissible={ false }>
						{ settings.reservedMessage }
					</Notice>
				) }

				<Stack direction="column" gap="sm">
					<InputControl
						name="redirect_from"
						label={ __( 'Redirect From', 'legacy-redirector' ) }
						value={ source }
						onValueChange={ onSourceChange }
						onBlur={ checkSource }
						placeholder="old-page"
						required
						prefix={
							<InputLayout.Slot>
								<Text variant="body-md">
									{ settings.homePrefix }
								</Text>
							</InputLayout.Slot>
						}
						details={ createInterpolateElement(
							sprintf(
								/* translators: 1: example of a full URL the entered path resolves to, 2: the same URL with a trailing slash. */
								__(
									'The source path that should redirect, always read relative to this site. Entering old-page matches <code>%1$s</code>. Trailing slashes are ignored, so <code>%1$s</code> and <code>%2$s</code> are the same redirect.',
									'legacy-redirector'
								),
								example,
								example + '/'
							),
							{ code: <code /> }
						) }
					/>
					{ sourceCheck.exists && (
						<Notice status="error" isDismissible={ false }>
							{ settings.duplicateMessage }
						</Notice>
					) }
					{ sourceCheck.reserved && (
						<Notice status="warning" isDismissible={ false }>
							{ settings.reservedMessage }
						</Notice>
					) }
				</Stack>

				<Stack direction="column" gap="sm">
					<Field.Root>
						<Field.Label>
							{ __( 'Redirect To', 'legacy-redirector' ) }
						</Field.Label>
						<Autocomplete.Root
							items={ results }
							filter={ null }
							// Open only with suggestions to show: an open, empty
							// popup still hides the rest of the page from
							// assistive technology.
							open={ open && results.length > 0 }
							onOpenChange={ setOpen }
							value={ destinationText }
							onValueChange={ onDestinationChange }
							itemToStringValue={ postLabel }
						>
							<Autocomplete.Input
								required
								onBlur={ checkDestination }
							/>
							<Autocomplete.Popup>
								<Autocomplete.List>
									<Autocomplete.ListBody>
										<Autocomplete.Collection>
											{ ( post ) => (
												<Autocomplete.Item
													key={ post.id }
													value={ post }
												>
													<Stack direction="column">
														<Text variant="body-md">
															{ post.title }
														</Text>
														<Text variant="body-sm">
															{ sprintf(
																/* translators: 1: post type label, 2: post ID. */
																__(
																	'%1$s (ID: %2$d)',
																	'legacy-redirector'
																),
																post.type,
																post.id
															) }
														</Text>
													</Stack>
												</Autocomplete.Item>
											) }
										</Autocomplete.Collection>
									</Autocomplete.ListBody>
								</Autocomplete.List>
							</Autocomplete.Popup>
						</Autocomplete.Root>
						<Field.Description>
							{ __(
								'Enter a relative path (e.g., /new-page), post ID, or full URL. Start typing to search for posts.',
								'legacy-redirector'
							) }
						</Field.Description>
					</Field.Root>
					{ ! hostAllowed && (
						<Notice status="error" isDismissible={ false }>
							{ settings.hostNotAllowedMessage }
						</Notice>
					) }
				</Stack>

				<RadioControl
					label={ __( 'Status', 'legacy-redirector' ) }
					selected={ status }
					onChange={ setStatus }
					options={ [
						{
							label: __( 'Enabled', 'legacy-redirector' ),
							value: 'publish',
							description: __(
								'Redirect is active',
								'legacy-redirector'
							),
						},
						{
							label: __( 'Disabled', 'legacy-redirector' ),
							value: 'draft',
							description: __(
								'Redirect is paused',
								'legacy-redirector'
							),
						},
					] }
				/>

				<Stack direction="row" gap="md" align="center">
					<Button
						variant="primary"
						type="submit"
						__next40pxDefaultSize
					>
						{ isEdit
							? __( 'Update Redirect', 'legacy-redirector' )
							: __( 'Add Redirect', 'legacy-redirector' ) }
					</Button>
					{ isEdit && (
						<Link href={ settings.listUrl }>
							{ __( 'Back to Redirects', 'legacy-redirector' ) }
						</Link>
					) }
				</Stack>
			</Stack>
		</form>
	);
}

const root = document.getElementById( 'legacy-redirector-form' );
if ( root ) {
	createRoot( root ).render( <RedirectForm /> );
}
