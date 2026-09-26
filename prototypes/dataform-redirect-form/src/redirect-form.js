import apiFetch from '@wordpress/api-fetch';
import { DataForm, useFormValidity } from '@wordpress/dataviews';
import { useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	Button,
	Card,
	InputLayout,
	Link,
	Notice,
	Stack,
	Text,
} from '@wordpress/ui';
import DestinationControl from './destination-control';

const EMPTY = {
	source: '',
	destination: '',
	destinationLabel: null,
	status: 'publish',
};

const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

// Which fields render, and how. The same `fields` could drive a DataViews
// table later; `form` is what makes this particular screen.
const FORM = {
	layout: { type: 'regular', labelPosition: 'top' },
	fields: [ 'source', 'destination', 'status' ],
};

export default function RedirectForm( { settings } ) {
	// `saved` is the last version the server confirmed; `item` holds the
	// user's unsaved edits. React re-renders whenever either changes.
	const [ saved, setSaved ] = useState( settings.redirect );
	const [ item, setItem ] = useState( settings.redirect ?? EMPTY );
	const [ reserved, setReserved ] = useState( settings.redirect?.reserved ?? false );
	const [ notice, setNotice ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const latestSource = useRef( '' );

	const fields = useMemo( () => {
		// An async `custom` rule: DataForm shows "Validating…" while the
		// promise is pending, then the message it resolves to (or nothing).
		// Superseded checks are discarded by DataForm, so typing quickly
		// never shows a stale answer.
		const checkSource = ( candidate ) => {
			const source = ( candidate.source ?? '' ).trim();
			latestSource.current = source;

			if ( '' === source || source === saved?.source ) {
				setReserved( '' !== source && !! saved?.reserved );
				return null;
			}

			return wait( 400 )
				.then( () =>
					latestSource.current === source
						? apiFetch( {
								path: addQueryArgs( '/legacy-redirector/v1/check-source', {
									source,
									exclude_id: saved?.id ?? 0,
								} ),
						  } )
						: null
				)
				.then( ( result ) => {
					if ( ! result ) {
						return null;
					}
					setReserved( result.reserved );
					return result.exists
						? __( 'A redirect already exists for this source URL.', 'legacy-redirector' )
						: null;
				} )
				.catch( () => null );
		};

		const checkDestination = ( candidate ) => {
			const destination = ( candidate.destination ?? '' ).trim();
			if ( ! /^https?:\/\//i.test( destination ) ) {
				return null;
			}

			return apiFetch( {
				path: addQueryArgs( '/legacy-redirector/v1/check-destination', { destination } ),
			} )
				.then( ( result ) =>
					false === result.host_allowed
						? __( 'The destination domain is not allowed, so the redirect would never run. Add the domain to the "allowed_redirect_hosts" filter first.', 'legacy-redirector' )
						: null
				)
				.catch( () => null );
		};

		return [
			{
				id: 'source',
				type: 'text',
				label: __( 'Redirect from', 'legacy-redirector' ),
				placeholder: 'old-page',
				description: sprintf(
					/* translators: %s: example full URL. */
					__( 'The path that should redirect, relative to this site. Entering old-page matches %s. Trailing slashes are ignored.', 'legacy-redirector' ),
					`${ settings.homePrefix }old-page`
				),
				Edit: {
					control: 'text',
					prefix: () => (
						<InputLayout.Slot>
							<Text variant="body-sm">{ settings.homePrefix }</Text>
						</InputLayout.Slot>
					),
				},
				isValid: { required: true, custom: checkSource },
			},
			{
				id: 'destination',
				type: 'text',
				label: __( 'Redirect to', 'legacy-redirector' ),
				description: __( 'A relative path such as /new-page, a post ID, or a full URL. Type a title to search posts and pages.', 'legacy-redirector' ),
				Edit: DestinationControl,
				isValid: { required: true, custom: checkDestination },
			},
			{
				id: 'status',
				type: 'text',
				label: __( 'Status', 'legacy-redirector' ),
				description: __( 'A disabled redirect is kept, but does not fire.', 'legacy-redirector' ),
				Edit: 'radio',
				elements: [
					{ value: 'publish', label: __( 'Enabled', 'legacy-redirector' ) },
					{ value: 'draft', label: __( 'Disabled', 'legacy-redirector' ) },
				],
				isValid: { required: true },
			},
		];
	}, [ saved, settings.homePrefix ] );

	const { validity, isValid } = useFormValidity( item, fields, FORM );

	const onSubmit = async ( event ) => {
		event.preventDefault();
		setIsSaving( true );
		setNotice( null );

		try {
			const result = await apiFetch( {
				path: `/legacy-redirector-prototype/v1/redirects${ saved ? `/${ saved.id }` : '' }`,
				method: 'POST',
				data: {
					source: item.source,
					destination: item.destination,
					status: item.status,
				},
			} );

			// A new redirect becomes an edit in place: no page reload, and the
			// URL now points at the edit screen for this redirect.
			if ( ! saved ) {
				window.history.replaceState( null, '', addQueryArgs( settings.editUrl, { redirect_id: result.id } ) );
			}

			setNotice( {
				intent: 'success',
				message: saved
					? __( 'Redirect updated.', 'legacy-redirector' )
					: __( 'Redirect created.', 'legacy-redirector' ),
				testUrl: result.testUrl,
			} );
			setSaved( result );
			setItem( result );
			setReserved( result.reserved );
		} catch ( error ) {
			setNotice( { intent: 'error', message: error.message } );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Stack direction="column" gap="lg" className="lr-dataform">
			<Stack direction="row" gap="md" align="center" justify="space-between">
				<h1>
					{ saved
						? __( 'Edit Redirect', 'legacy-redirector' )
						: __( 'Add Redirect', 'legacy-redirector' ) }
				</h1>
				<Link href={ settings.classicUrl }>
					{ __( 'Compare with the current screen', 'legacy-redirector' ) }
				</Link>
			</Stack>

			{ notice && (
				<Notice.Root intent={ notice.intent }>
					<Notice.Description>{ notice.message }</Notice.Description>
					{ notice.testUrl && (
						<Notice.Actions>
							<Notice.ActionLink href={ notice.testUrl } target="_blank">
								{ __( 'Test it', 'legacy-redirector' ) }
							</Notice.ActionLink>
						</Notice.Actions>
					) }
				</Notice.Root>
			) }

			<Card.Root>
				<Card.Content>
					<form onSubmit={ onSubmit }>
						<Stack direction="column" gap="xl">
							<DataForm
								data={ item }
								fields={ fields }
								form={ FORM }
								validity={ validity }
								onChange={ ( edits ) => setItem( ( current ) => ( { ...current, ...edits } ) ) }
							/>

							{ reserved && (
								<Notice.Root intent="warning">
									<Notice.Title>
										{ __( 'WordPress itself serves this path', 'legacy-redirector' ) }
									</Notice.Title>
									<Notice.Description>
										{ __( 'The redirect stays dormant while that path works, but takes over if the path ever returns a 404. For wp-admin or wp-login.php, that locks you out of the dashboard. Keep it only if this is a genuine legacy URL.', 'legacy-redirector' ) }
									</Notice.Description>
								</Notice.Root>
							) }

							<Stack direction="row" gap="sm" align="center">
								<Button
									type="submit"
									variant="solid"
									loading={ isSaving }
									disabled={ ! isValid || isSaving }
								>
									{ saved
										? __( 'Update Redirect', 'legacy-redirector' )
										: __( 'Add Redirect', 'legacy-redirector' ) }
								</Button>
								<Link href={ settings.listUrl }>
									{ __( 'Back to Redirects', 'legacy-redirector' ) }
								</Link>
							</Stack>
						</Stack>
					</form>
				</Card.Content>
			</Card.Root>
		</Stack>
	);
}
