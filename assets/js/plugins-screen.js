/**
 * Plugins screen: ask what to do with the acceptance evidence before the
 * plugin is deleted. Vanilla JS; strings go through wp.i18n.
 */
( function () {
	'use strict';

	var config = window.mmwpTermsUninstall;
	var i18n = window.wp && window.wp.i18n;

	if ( ! config || ! i18n ) {
		return;
	}

	var __ = i18n.__;
	var DOMAIN = 'mammoth-acceptance-logs';
	var bypass = false;

	/**
	 * Creates an element with optional class, text and attributes.
	 *
	 * @param {string} tag   Tag name.
	 * @param {string} cls   Class name.
	 * @param {string} text  Text content.
	 * @param {Object} attrs Attributes.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, cls, text, attrs ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text ) {
			node.textContent = text;
		}
		Object.keys( attrs || {} ).forEach( function ( key ) {
			node.setAttribute( key, attrs[ key ] );
		} );
		return node;
	}

	/**
	 * Builds a radio option with a label.
	 *
	 * @param {string}  value   Option value.
	 * @param {string}  label   Visible label.
	 * @param {boolean} checked Whether it is selected.
	 * @return {{wrap: HTMLElement, input: HTMLInputElement}} The pieces.
	 */
	function option( value, label, checked ) {
		var wrap = el( 'label', 'mmwp-uninstall-option' );
		var input = el( 'input', '', '', { type: 'radio', name: 'mmwp-uninstall-choice', value: value } );
		input.checked = checked;
		wrap.appendChild( input );
		wrap.appendChild( document.createTextNode( ' ' + label ) );
		return { wrap: wrap, input: input };
	}

	/**
	 * Posts the choice to the server.
	 *
	 * @param {string} choice keep or delete.
	 * @param {string} typed  Typed confirmation.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function save( choice, typed ) {
		var body = new window.URLSearchParams( {
			action: config.action,
			nonce: config.nonce,
			choice: choice,
			typed: typed,
		} );
		return window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Opens the modal for the clicked delete link.
	 *
	 * @param {HTMLElement} link The delete link.
	 */
	function openModal( link ) {
		var previousFocus = document.activeElement;
		var overlay = el( 'div', 'mmwp-uninstall-overlay' );
		var dialog = el( 'div', 'mmwp-uninstall-dialog', '', { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'mmwp-uninstall-title', 'aria-describedby': 'mmwp-uninstall-desc' } );
		var keep = option( 'keep', __( 'Keep acceptance evidence (recommended)', DOMAIN ), config.current !== 'delete' );
		var del = option( 'delete', __( 'Delete all acceptance evidence permanently', DOMAIN ), config.current === 'delete' );
		var confirmWrap = el( 'div', 'mmwp-uninstall-confirm' );
		var confirmId = 'mmwp-uninstall-typed';
		var confirmLabel = el( 'label', '', __( 'Type the plugin name to confirm:', DOMAIN ), { for: confirmId } );
		var typed = el( 'input', '', '', { type: 'text', id: confirmId, autocomplete: 'off' } );
		var error = el( 'p', 'mmwp-uninstall-error', '', { role: 'alert' } );
		var actions = el( 'div', 'mmwp-uninstall-actions' );
		var cancel = el( 'button', 'button', __( 'Cancel', DOMAIN ), { type: 'button' } );
		var proceed = el( 'button', 'button button-primary', __( 'Continue and delete plugin', DOMAIN ), { type: 'button' } );

		error.hidden = true;
		confirmWrap.appendChild( confirmLabel );
		confirmWrap.appendChild( el( 'br' ) );
		confirmWrap.appendChild( el( 'code', '', config.name ) );
		confirmWrap.appendChild( el( 'br' ) );
		confirmWrap.appendChild( typed );
		actions.appendChild( cancel );
		actions.appendChild( proceed );

		dialog.appendChild( el( 'h2', '', __( 'Delete MammothWP Acceptance Logs?', DOMAIN ), { id: 'mmwp-uninstall-title' } ) );
		dialog.appendChild( el( 'p', '', __( 'The acceptance log and the archive of every terms version are evidence of what your users agreed. Choose what happens to them. Settings are always removed.', DOMAIN ), { id: 'mmwp-uninstall-desc' } ) );
		dialog.appendChild( keep.wrap );
		dialog.appendChild( del.wrap );
		dialog.appendChild( confirmWrap );
		dialog.appendChild( error );
		dialog.appendChild( actions );
		overlay.appendChild( dialog );
		document.body.appendChild( overlay );

		/** Shows the typed-confirmation field only when deleting. */
		function sync() {
			confirmWrap.hidden = ! del.input.checked;
		}

		/** Closes the modal and restores focus. */
		function close() {
			document.removeEventListener( 'keydown', onKey, true );
			overlay.remove();
			if ( previousFocus && previousFocus.focus ) {
				previousFocus.focus();
			}
		}

		/**
		 * Escape cancels; Tab stays inside the dialog.
		 *
		 * @param {KeyboardEvent} event Key event.
		 */
		function onKey( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				close();
				return;
			}
			if ( event.key !== 'Tab' ) {
				return;
			}
			var focusable = Array.prototype.filter.call( dialog.querySelectorAll( 'input, button' ), function ( node ) {
				return node.offsetParent !== null;
			} );
			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		keep.input.addEventListener( 'change', sync );
		del.input.addEventListener( 'change', sync );
		cancel.addEventListener( 'click', close );
		proceed.addEventListener( 'click', function () {
			var choice = del.input.checked ? 'delete' : 'keep';
			error.hidden = true;
			proceed.disabled = true;
			save( choice, typed.value ).then( function ( result ) {
				if ( ! result || ! result.success ) {
					error.textContent = result && result.data && result.data.message ? result.data.message : __( 'The choice could not be saved.', DOMAIN );
					error.hidden = false;
					proceed.disabled = false;
					return;
				}
				close();
				bypass = true;
				link.click();
			} ).catch( function () {
				error.textContent = __( 'The choice could not be saved.', DOMAIN );
				error.hidden = false;
				proceed.disabled = false;
			} );
		} );

		document.addEventListener( 'keydown', onKey, true );
		sync();
		keep.input.focus();
	}

	// Capture phase, so this runs before WordPress's own delete handler.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( 'tr[data-plugin="' + config.plugin + '"] .delete a' ) : null;

		if ( ! link ) {
			return;
		}

		if ( bypass ) {
			bypass = false;
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();
		openModal( link );
	}, true );
}() );
