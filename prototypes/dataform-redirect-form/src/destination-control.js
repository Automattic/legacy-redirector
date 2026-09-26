import apiFetch from '@wordpress/api-fetch';
import { useInstanceId } from '@wordpress/compose';
import { useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	Autocomplete,
	Field,
	Input,
	InputLayout,
	Spinner,
	Stack,
	Text,
	ValidityIndicator,
} from '@wordpress/ui';

// Paths, IDs and URLs are typed, not searched for.
const isSearchable = ( query ) =>
	query.length >= 2 && ! /^[\/0-9]|^https?:/i.test( query );

/**
 * DataForm has no built-in "free text, with optional suggestions" control, so
 * this custom Edit composes one from the design system's Autocomplete
 * primitive. DataForm passes the item, the field definition, an onChange for
 * edits, and the field's current validity.
 */
export default function DestinationControl( { data, field, onChange, validity } ) {
	const id = useInstanceId( DestinationControl, 'redirect-destination' );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const timer = useRef();

	const search = ( query ) => {
		clearTimeout( timer.current );
		if ( ! isSearchable( query ) ) {
			setResults( [] );
			setIsSearching( false );
			return;
		}

		setIsSearching( true );
		timer.current = setTimeout( () => {
			apiFetch( {
				path: addQueryArgs( '/wp/v2/search', {
					search: query,
					subtype: 'post,page',
					per_page: 10,
				} ),
			} )
				.then( ( posts ) =>
					setResults(
						posts.map( ( post ) => ( {
							value: String( post.id ),
							label: `${ decodeEntities( post.title ) } (ID: ${ post.id })`,
							title: decodeEntities( post.title ),
							meta: `${ post.subtype } · ID ${ post.id }`,
						} ) )
					)
				)
				.catch( () => setResults( [] ) )
				.finally( () => setIsSearching( false ) );
		}, 300 );
	};

	const onValueChange = ( text ) => {
		const picked = results.find( ( item ) => item.label === text );

		// A picked post stores its ID; anything typed is stored as typed.
		onChange(
			picked
				? { destination: picked.value, destinationLabel: picked.label }
				: { destination: text, destinationLabel: null }
		);

		if ( ! picked ) {
			search( text.trim() );
		}
	};

	const shown = validity?.custom;

	return (
		<Field.Root>
			<Field.Label htmlFor={ id }>{ field.label }</Field.Label>
			<Autocomplete.Root
				items={ results }
				filteredItems={ results }
				value={ data.destinationLabel ?? data.destination ?? '' }
				onValueChange={ onValueChange }
			>
				<Autocomplete.InputGroup>
					<Autocomplete.Input
						id={ id }
						required
						placeholder={ __( '/new-page, a post title, or https://…', 'legacy-redirector' ) }
						aria-describedby={ `${ id }-description ${ id }-validity` }
						render={
							<Input
								suffix={
									<InputLayout.Slot padding="minimal">
										<Autocomplete.Clear />
									</InputLayout.Slot>
								}
							/>
						}
					/>
				</Autocomplete.InputGroup>
				<Autocomplete.Popup>
					<Autocomplete.Status>
						{ isSearching && (
							<Stack direction="row" gap="sm" align="center">
								<Spinner />
								{ __( 'Searching…', 'legacy-redirector' ) }
							</Stack>
						) }
					</Autocomplete.Status>
					<Autocomplete.List>
						<Autocomplete.ListBody>
							<Autocomplete.Collection>
								{ ( item ) => (
									<Autocomplete.Item key={ item.value } value={ item }>
										<Stack direction="column" gap="xs">
											<Text>{ item.title }</Text>
											<Text variant="body-sm">{ item.meta }</Text>
										</Stack>
									</Autocomplete.Item>
								) }
							</Autocomplete.Collection>
						</Autocomplete.ListBody>
					</Autocomplete.List>
				</Autocomplete.Popup>
			</Autocomplete.Root>
			<Field.Description id={ `${ id }-description` }>
				{ field.description }
			</Field.Description>
			<div id={ `${ id }-validity` } aria-live="polite">
				{ shown && <ValidityIndicator type={ shown.type } message={ shown.message } /> }
			</div>
		</Field.Root>
	);
}
