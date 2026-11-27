/**
 * Auth form helpers: show/hide password toggle.
 */
( function () {
	'use strict';

	document.querySelectorAll( '[data-uf-toggle-pw]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var wrap = btn.closest( '.uf-pw-wrap' );
			var input = wrap ? wrap.querySelector( 'input' ) : null;
			if ( ! input ) return;
			var show = input.type === 'password';
			input.type = show ? 'text' : 'password';
			btn.textContent = show ? 'Hide' : 'Show';
			btn.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
			btn.setAttribute( 'aria-label', show ? 'Hide password' : 'Show password' );
		} );
	} );
} )();
