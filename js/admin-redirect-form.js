/**
 * Redirect form behavior: duplicate and reserved-source checking, and
 * destination autocomplete for the Add/Edit Redirect admin pages.
 *
 * Requests go through wp.apiFetch, which handles the REST nonce: the source
 * and destination checks call the plugin's legacy-redirector/v1 routes, and
 * the autocomplete searches with core's /wp/v2/search endpoint.
 *
 * Configuration is provided by wp_localize_script() as
 * `legacyRedirectorForm`:
 * - postId            Redirect post ID being edited (0 on the Add page).
 * - duplicateMessage  Message shown when the source already redirects.
 * - reservedMessage   Warning shown when the source is a path WordPress
 *                     itself serves. The form still saves.
 * - hostNotAllowedMessage  Error shown when the destination host is missing
 *                          from allowed_redirect_hosts. Saving would be
 *                          refused with the same message.
 */
jQuery( document ).ready( function ( $ ) {
	var settings = window.legacyRedirectorForm || {};
	var originalFrom = $( '#redirect_from' ).val();
	var postId = parseInt( settings.postId, 10 ) || 0;
	var searchTimeout;
	var selectedIndex = -1;
	var entityDecoder = document.createElement( 'textarea' );

	// Core's search endpoint returns titles with texturized HTML entities
	// (e.g. &#8217; for an apostrophe), which .text() would show literally.
	// A textarea decodes without executing anything.
	function decodeEntities( text ) {
		entityDecoder.innerHTML = text;
		return entityDecoder.value;
	}

	// Render search results into the suggestions dropdown; the fetch code
	// decides when to call this, this decides what the results look like.
	function renderSuggestions( posts ) {
		if ( ! posts.length ) {
			$( '#redirect_to_suggestions' ).hide();
			return;
		}

		// Build via DOM APIs, not string concatenation: titles and
		// type labels are attacker-influenced and must be escaped
		// in both attribute and text positions.
		var $container = $( '#redirect_to_suggestions' ).empty();
		$.each( posts, function ( i, post ) {
			var title = decodeEntities( post.title );
			$( '<div>', {
				'class': 'redirect-suggestion',
				'data-id': post.id,
				'data-title': title,
				'style': 'padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee;'
			} )
				.append(
					$( '<strong>' ).text( title ),
					'<br>',
					$( '<small>' ).css( 'color', '#666' ).text( post.subtype + ' (ID: ' + post.id + ')' )
				)
				.appendTo( $container );
		} );
		$container.show();
		selectedIndex = -1; // Reset selection when new results appear.
	}

	// Check the source on blur: a duplicate blocks the save, a reserved path
	// only warns, so both are known before anything is submitted.
	$( '#redirect_from' ).on( 'blur', function () {
		var newFrom = $( this ).val().trim();
		if ( newFrom === originalFrom || newFrom === '' ) {
			$( '#redirect_from_error' ).hide();
			$( '#redirect_from_warning' ).hide();
			return;
		}

		wp.apiFetch( {
			path: '/legacy-redirector/v1/check-source?source=' + encodeURIComponent( newFrom ) + '&exclude_id=' + postId
		} ).then( function ( result ) {
			if ( result.exists ) {
				$( '#redirect_from_error' )
					.text( settings.duplicateMessage )
					.show();
			} else {
				$( '#redirect_from_error' ).hide();
			}

			if ( result.reserved ) {
				$( '#redirect_from_warning' )
					.text( settings.reservedMessage )
					.show();
			} else {
				$( '#redirect_from_warning' ).hide();
			}
		} ).catch( function () {
			$( '#redirect_from_error' ).hide();
			$( '#redirect_from_warning' ).hide();
		} );
	} );

	// Check the destination on blur: a host missing from allowed_redirect_hosts
	// will be refused at save, so it is flagged before anything is submitted.
	$( '#redirect_to_display' ).on( 'blur', function () {
		var destination = $( '#redirect_to' ).val().trim();

		// Only absolute URLs have a host to check.
		if ( ! /^https?:\/\//i.test( destination ) ) {
			$( '#redirect_to_error' ).hide();
			return;
		}

		wp.apiFetch( {
			path: '/legacy-redirector/v1/check-destination?destination=' + encodeURIComponent( destination )
		} ).then( function ( result ) {
			if ( false === result.host_allowed ) {
				$( '#redirect_to_error' )
					.text( settings.hostNotAllowedMessage )
					.show();
			} else {
				$( '#redirect_to_error' ).hide();
			}
		} ).catch( function () {
			$( '#redirect_to_error' ).hide();
		} );
	} );

	// Update visual highlight for selected suggestion.
	function updateHighlight() {
		var $suggestions = $( '#redirect_to_suggestions .redirect-suggestion' );
		$suggestions.css( 'background-color', '#fff' );
		if ( selectedIndex >= 0 && selectedIndex < $suggestions.length ) {
			$suggestions.eq( selectedIndex ).css( 'background-color', '#f0f0f1' );
		}
	}

	// Select the currently highlighted suggestion.
	function selectCurrentSuggestion() {
		var $suggestions = $( '#redirect_to_suggestions .redirect-suggestion' );
		if ( selectedIndex >= 0 && selectedIndex < $suggestions.length ) {
			var $selected = $suggestions.eq( selectedIndex );
			var id = $selected.data( 'id' );
			var title = $selected.data( 'title' );
			$( '#redirect_to_display' ).val( title + ' (ID: ' + id + ')' );
			$( '#redirect_to' ).val( id );
			$( '#redirect_to_suggestions' ).hide();
			selectedIndex = -1;
		}
	}

	// Handle keyboard navigation.
	$( '#redirect_to_display' ).on( 'keydown', function ( e ) {
		var $suggestions = $( '#redirect_to_suggestions .redirect-suggestion' );
		if ( ! $suggestions.length || ! $( '#redirect_to_suggestions' ).is( ':visible' ) ) {
			return;
		}

		switch ( e.keyCode ) {
			case 40: // Down arrow
				e.preventDefault();
				selectedIndex = Math.min( selectedIndex + 1, $suggestions.length - 1 );
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
					selectCurrentSuggestion();
				}
				break;
			case 27: // Escape
				$( '#redirect_to_suggestions' ).hide();
				selectedIndex = -1;
				break;
		}
	} );

	// Sync display field to hidden field when user types directly.
	$( '#redirect_to_display' ).on( 'input', function () {
		var val = $( this ).val().trim();
		$( '#redirect_to' ).val( val );
		selectedIndex = -1; // Reset selection on new input.

		// Only search if it looks like text (not a URL or path or number).
		clearTimeout( searchTimeout );
		if ( val.length < 2 || /^[\/0-9]/.test( val ) || /^https?:/.test( val ) ) {
			$( '#redirect_to_suggestions' ).hide();
			return;
		}

		searchTimeout = setTimeout( function () {
			wp.apiFetch( {
				path: '/wp/v2/search?search=' + encodeURIComponent( val ) + '&subtype=post,page&per_page=10'
			} ).then( renderSuggestions ).catch( function () {
				$( '#redirect_to_suggestions' ).hide();
			} );
		}, 300 );
	} );

	// Handle suggestion click.
	$( document ).on( 'click', '.redirect-suggestion', function () {
		var id = $( this ).data( 'id' );
		var title = $( this ).data( 'title' );
		$( '#redirect_to_display' ).val( title + ' (ID: ' + id + ')' );
		$( '#redirect_to' ).val( id );
		$( '#redirect_to_suggestions' ).hide();
		selectedIndex = -1;
	} );

	// Highlight on hover (also updates selectedIndex for consistency).
	$( document ).on( 'mouseenter', '.redirect-suggestion', function () {
		var $suggestions = $( '#redirect_to_suggestions .redirect-suggestion' );
		selectedIndex = $suggestions.index( this );
		updateHighlight();
	} ).on( 'mouseleave', '.redirect-suggestion', function () {
		// Keep highlight if using keyboard, otherwise clear.
		$( this ).css( 'background-color', '#fff' );
	} );

	// Hide suggestions on click outside.
	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '#redirect_to_display, #redirect_to_suggestions' ).length ) {
			$( '#redirect_to_suggestions' ).hide();
			selectedIndex = -1;
		}
	} );
} );
