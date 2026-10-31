( function () {
	'use strict';

	if ( typeof window.mmwpTermsPopup === 'undefined' ) {
		return;
	}

	var config = window.mmwpTermsPopup;

	document.addEventListener( 'DOMContentLoaded', function () {
		var overlay = document.getElementById( 'mmwp-terms-overlay' );

		if ( ! overlay ) {
			return;
		}

		if ( config.showPopup ) {
			overlay.style.display = 'flex';
		}

		// The persistent admin notice's "Review now" link re-opens the
		// overlay even after the user clicked "skip for now".
		document.querySelectorAll( '.mmwp-terms-notice-link' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				overlay.style.display = 'flex';
			} );
		} );

		var acceptButton = document.getElementById( 'mmwp-terms-accept' );
		var skipButton   = document.getElementById( 'mmwp-terms-skip' );
		var errorBox     = document.getElementById( 'mmwp-terms-error' );

		if ( acceptButton ) {
			acceptButton.addEventListener( 'click', function () {
				setBusy( true );
				showError( '' );

				post( config.acceptAction, { versionId: config.versionId } ).then( function () {
					// Reload so the admin notice (server-rendered) clears too.
					window.location.reload();
				}, function ( error ) {
					// The acceptance was NOT recorded. Keep the overlay up and
					// say so — reloading here would leave the user believing
					// they had accepted when no record of it exists.
					showError( ( error && error.message ) || config.errorMessage );
					setBusy( false );
				} );
			} );
		}

		if ( skipButton ) {
			skipButton.addEventListener( 'click', function () {
				setBusy( true );
				post( config.skipAction ).then( function () {
					overlay.style.display = 'none';
					setBusy( false );
				}, function () {
					setBusy( false );
				} );
			} );
		}

		function setBusy( busy ) {
			[ acceptButton, skipButton ].forEach( function ( button ) {
				if ( button ) {
					button.disabled = busy;
				}
			} );
		}

		function showError( message ) {
			if ( ! errorBox ) {
				return;
			}

			errorBox.textContent = message;
			errorBox.style.display = message ? 'block' : 'none';
		}

		function post( action, extra ) {
			var body = new URLSearchParams();
			body.set( 'action', action );
			body.set( 'nonce', config.nonce );

			Object.keys( extra || {} ).forEach( function ( key ) {
				body.set( key, extra[ key ] );
			} );

			return fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( payload ) {
					// A non-2xx response, or success:false, both mean the
					// server did not record anything. Surface the server's own
					// message where it gave one.
					if ( ! response.ok || ! payload || ! payload.success ) {
						throw new Error(
							( payload && payload.data && payload.data.message ) || 'Request failed'
						);
					}

					return payload;
				} );
			} );
		}
	} );
} )();
