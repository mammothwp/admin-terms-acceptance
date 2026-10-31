/**
 * Front-end behaviour for the mmwp/terms-display block.
 *
 * Loaded only on pages containing the block (declared as viewScript in
 * block.json). No dependencies, no bundler — same shape as popup.js.
 *
 * The full text of a version is fetched on first open and kept, so reopening
 * the same version costs nothing. Markup arrives already filtered through
 * wp_kses_post() on the server, which is why it can be assigned as HTML.
 */
( function () {
	'use strict';

	function setup( root ) {
		var modal = root.querySelector( '.mmwp-terms-block__modal' );
		var dialog = modal ? modal.querySelector( '.mmwp-terms-block__dialog' ) : null;
		var title = modal ? modal.querySelector( '.mmwp-terms-block__title' ) : null;
		var body = modal ? modal.querySelector( '.mmwp-terms-block__body' ) : null;
		var closeButton = modal ? modal.querySelector( '.mmwp-terms-block__close' ) : null;
		var endpoint = root.getAttribute( 'data-rest' );

		if ( ! modal || ! dialog || ! title || ! body || ! endpoint ) {
			return;
		}

		var cache = {};
		var lastTrigger = null;

		function close() {
			modal.hidden = true;
			root.classList.remove( 'is-open' );

			if ( lastTrigger ) {
				lastTrigger.focus();
				lastTrigger = null;
			}
		}

		function open( trigger, versionId ) {
			lastTrigger = trigger;
			modal.hidden = false;
			root.classList.add( 'is-open' );
			dialog.scrollTop = 0;
			dialog.focus();

			if ( cache[ versionId ] ) {
				render( cache[ versionId ] );
				return;
			}

			title.textContent = '';
			body.textContent = modal.getAttribute( 'data-loading' ) || '';
			dialog.setAttribute( 'aria-busy', 'true' );

			fetch( endpoint + encodeURIComponent( versionId ), {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}

					return response.json();
				} )
				.then( function ( version ) {
					cache[ versionId ] = version;
					render( version );
				} )
				.catch( function () {
					dialog.removeAttribute( 'aria-busy' );
					title.textContent = '';
					body.textContent = modal.getAttribute( 'data-error' ) || '';
				} );
		}

		function render( version ) {
			dialog.removeAttribute( 'aria-busy' );

			// The label is authored by an administrator and the body is
			// already kses-filtered server-side; the label still goes in as
			// text because it has no reason to carry markup.
			var heading = version.label || '';

			if ( version.effective_date ) {
				heading += ' — ' + version.effective_date;
			}

			title.textContent = heading;
			body.innerHTML = version.content || '';
		}

		root.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '.mmwp-terms-block__open' );

			if ( trigger ) {
				event.preventDefault();
				open( trigger, trigger.getAttribute( 'data-version-id' ) );
				return;
			}

			// The backdrop is the modal element itself; a click inside the
			// dialog bubbles up to it and must not close anything.
			if ( event.target === modal || event.target.closest( '.mmwp-terms-block__close' ) ) {
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				close();
			}
		} );

		if ( closeButton ) {
			closeButton.setAttribute( 'type', 'button' );
		}
	}

	function init() {
		var blocks = document.querySelectorAll( '.mmwp-terms-block' );

		Array.prototype.forEach.call( blocks, setup );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
