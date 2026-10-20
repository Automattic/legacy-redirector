/**
 * Redirect form behavior: duplicate and reserved-source checking, and
 * destination autocomplete for the Add/Edit Redirect admin pages.
 *
 * Requests go through wp.apiFetch, which handles the REST nonce: the source
 * and destination checks call the plugin's legacy-redirector/v1 routes, and
 * the autocomplete searches with core's /wp/v2/search endpoint.
 *
 * The autocomplete follows the ARIA combobox pattern: the destination field
 * owns a listbox, the highlighted option is exposed through
 * aria-activedescendant, and wp.a11y.speak() says when suggestions appear,
 * as do the inline source and destination messages.
 *
 * Configuration is provided by wp_localize_script() as
 * `legacyRedirectorForm`:
 * - postId            Redirect post ID being edited (0 on the Add page).
 * - duplicateMessage  Message shown when the source already redirects.
 * - reservedMessage   Warning shown when the source is a path WordPress
 *                     itself serves. The form still saves.
 * - occupiedMessage   Warning shown when published content already lives at
 *                     the source, so the redirect would stay dormant. The
 *                     form still saves.
 * - hostNotAllowedMessage  Error shown when the destination host is missing
 *                          from allowed_redirect_hosts. Saving would be
 *                          refused with the same message.
 * - suggestionsMessage    Announced when destination suggestions appear.
 * - noSuggestionsMessage  Announced when a search finds nothing.
 */
jQuery( document ).ready( function ( $ ) {
	var settings = window.legacyRedirectorForm || {};
	var originalFrom = $( '#redirect_from' ).val();
	var postId = parseInt( settings.postId, 10 ) || 0;
	var searchTimeout;
	var selectedIndex = -1;
	var entityDecoder = document.createElement( 'textarea' );
	var $display = $( '#redirect_to_display' );
	var $suggestions = $( '#redirect_to_suggestions' );
	var announcements = [];

	// Core's search endpoint returns titles with texturized HTML entities
	// (e.g. &#8217; for an apostrophe), which .text() would show literally.
	// A textarea decodes without executing anything.
	function decodeEntities( text ) {
		entityDecoder.innerHTML = text;
		return entityDecoder.value;
	}

	// Show or clear one of the inline messages under a field. A visible
	// message is announced, since the field has lost focus by the time it
	// appears; an error also marks the field invalid.
	function setMessage( $message, $field, text ) {
		if ( ! text ) {
			$message.hide().text( '' );
			if ( $field ) {
				$field.removeAttr( 'aria-invalid' );
			}
			return;
		}

		$message.text( text ).show();
		if ( $field ) {
			$field.attr( 'aria-invalid', 'true' );
		}
		announce( text );
	}

	// Announce messages shown together, such as a duplicate source that is
	// also a reserved path, as one: each speak() replaces the last.
	function announce( text ) {
		if ( ! announcements.length ) {
			setTimeout( function () {
				wp.a11y.speak( announcements.join( ' ' ) );
				announcements = [];
			} );
		}
		announcements.push( text );
	}

	function showSuggestions() {
		$suggestions.show();
		$display.attr( 'aria-expanded', 'true' );
	}

	function hideSuggestions() {
		$suggestions.hide();
		$display.attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
		selectedIndex = -1;
	}

	// Render search results into the suggestions listbox; the fetch code
	// decides when to call this, this decides what the results look like.
	function renderSuggestions( posts ) {
		if ( ! posts.length ) {
			hideSuggestions();
			wp.a11y.speak( settings.noSuggestionsMessage );
			return;
		}

		// Build via DOM APIs, not string concatenation: titles and
		// type labels are attacker-influenced and must be escaped
		// in both attribute and text positions.
		$suggestions.empty();
		$.each( posts, function ( i, post ) {
			var title = decodeEntities( post.title );
			$( '<div>', {
				'class': 'redirect-suggestion',
				'id': 'redirect_to_suggestion_' + i,
				'role': 'option',
				'aria-selected': 'false',
				'data-id': post.id,
				'data-title': title,
				'style': 'padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;'
			} )
				.append(
					$( '<strong>' ).text( title ),
					'<br>',
					$( '<small>' ).css( 'color', '#646970' ).text( post.subtype + ' (ID: ' + post.id + ')' )
				)
				.appendTo( $suggestions );
		} );
		selectedIndex = -1; // Reset selection when new results appear.
		$display.removeAttr( 'aria-activedescendant' );
		showSuggestions();
		wp.a11y.speak( settings.suggestionsMessage );
	}

	// Check the source on blur: a duplicate blocks the save, a reserved path
	// only warns, so both are known before anything is submitted.
	$( '#redirect_from' ).on( 'blur', function () {
		var $field = $( this );
		var newFrom = $field.val().trim();
		if ( newFrom === originalFrom || newFrom === '' ) {
			setMessage( $( '#redirect_from_error' ), $field, '' );
			setMessage( $( '#redirect_from_warning' ), null, '' );
			return;
		}

		wp.apiFetch( {
			path: '/legacy-redirector/v1/check-source?source=' + encodeURIComponent( newFrom ) + '&exclude_id=' + postId
		} ).then( function ( result ) {
			setMessage( $( '#redirect_from_error' ), $field, result.exists ? settings.duplicateMessage : '' );

			var warning = '';
			if ( result.reserved ) {
				warning = settings.reservedMessage;
			} else if ( result.occupied ) {
				warning = settings.occupiedMessage;
			}
			setMessage( $( '#redirect_from_warning' ), null, warning );
		} ).catch( function () {
			setMessage( $( '#redirect_from_error' ), $field, '' );
			setMessage( $( '#redirect_from_warning' ), null, '' );
		} );
	} );

	// Check the destination on blur: a host missing from allowed_redirect_hosts
	// will be refused at save, so it is flagged before anything is submitted.
	// Leaving the field also closes the suggestions, as Escape does.
	$display.on( 'blur', function () {
		var destination = $( '#redirect_to' ).val().trim();

		hideSuggestions();

		// Only absolute URLs have a host to check.
		if ( ! /^https?:\/\//i.test( destination ) ) {
			setMessage( $( '#redirect_to_error' ), $display, '' );
			return;
		}

		wp.apiFetch( {
			path: '/legacy-redirector/v1/check-destination?destination=' + encodeURIComponent( destination )
		} ).then( function ( result ) {
			setMessage( $( '#redirect_to_error' ), $display, false === result.host_allowed ? settings.hostNotAllowedMessage : '' );
		} ).catch( function () {
			setMessage( $( '#redirect_to_error' ), $display, '' );
		} );
	} );

	// Mark the highlighted suggestion, for sight through aria-selected's
	// styling and for assistive technology through aria-activedescendant.
	function updateHighlight() {
		var $options = $suggestions.find( '.redirect-suggestion' );
		$options.attr( 'aria-selected', 'false' );
		if ( selectedIndex >= 0 && selectedIndex < $options.length ) {
			var $option = $options.eq( selectedIndex ).attr( 'aria-selected', 'true' );
			$display.attr( 'aria-activedescendant', $option.attr( 'id' ) );
			$option[ 0 ].scrollIntoView( { block: 'nearest' } );
		} else {
			$display.removeAttr( 'aria-activedescendant' );
		}
	}

	// Use a suggestion as the destination.
	function selectSuggestion( $option ) {
		var id = $option.data( 'id' );
		var title = $option.data( 'title' );
		$display.val( title + ' (ID: ' + id + ')' );
		$( '#redirect_to' ).val( id );
		hideSuggestions();
	}

	// Handle keyboard navigation.
	$display.on( 'keydown', function ( e ) {
		var $options = $suggestions.find( '.redirect-suggestion' );
		if ( ! $options.length || ! $suggestions.is( ':visible' ) ) {
			return;
		}

		switch ( e.keyCode ) {
			case 40: // Down arrow
				e.preventDefault();
				selectedIndex = Math.min( selectedIndex + 1, $options.length - 1 );
				updateHighlight();
				break;
			case 38: // Up arrow
				e.preventDefault();
				selectedIndex = Math.max( selectedIndex - 1, -1 );
				updateHighlight();
				break;
			case 13: // Enter
				if ( selectedIndex >= 0 ) {
					e.preventDefault();
					selectSuggestion( $options.eq( selectedIndex ) );
				}
				break;
			case 27: // Escape
				e.preventDefault();
				hideSuggestions();
				break;
		}
	} );

	// Sync display field to hidden field when user types directly.
	$display.on( 'input', function () {
		var val = $( this ).val().trim();
		$( '#redirect_to' ).val( val );
		selectedIndex = -1; // Reset selection on new input.

		// Only search if it looks like text (not a URL or path or number).
		clearTimeout( searchTimeout );
		if ( val.length < 2 || /^[\/0-9]/.test( val ) || /^https?:/.test( val ) ) {
			hideSuggestions();
			return;
		}

		searchTimeout = setTimeout( function () {
			wp.apiFetch( {
				path: '/wp/v2/search?search=' + encodeURIComponent( val ) + '&subtype=post,page&per_page=10'
			} ).then( renderSuggestions ).catch( hideSuggestions );
		}, 300 );
	} );

	// Select on mousedown, before the field's blur closes the list, and keep
	// focus in the field so typing can carry on.
	$suggestions.on( 'mousedown', '.redirect-suggestion', function ( e ) {
		e.preventDefault();
		selectSuggestion( $( this ) );
	} );

	// Highlight on hover (also updates selectedIndex for consistency).
	$suggestions.on( 'mouseenter', '.redirect-suggestion', function () {
		selectedIndex = $suggestions.find( '.redirect-suggestion' ).index( this );
		updateHighlight();
	} );
} );
