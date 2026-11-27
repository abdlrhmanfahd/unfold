/**
 * Renders inline LaTeX ($...$ or \(...\)) and display LaTeX ($$...$$ or \[...\]) in any
 * Unfold text. Equation blocks (.uf-eq) are rendered separately by project.js.
 */
( function () {
	'use strict';
	function run() {
		if ( ! window.renderMathInElement ) {
			return;
		}
		document.querySelectorAll( '.unfold' ).forEach( function ( el ) {
			window.renderMathInElement( el, {
				delimiters: [
					{ left: '$$', right: '$$', display: true },
					{ left: '\\[', right: '\\]', display: true },
					{ left: '\\(', right: '\\)', display: false },
					{ left: '$', right: '$', display: false },
				],
				ignoredTags: [ 'script', 'noscript', 'style', 'textarea', 'pre', 'code', 'option', 'select', 'svg' ],
				ignoredClasses: [ 'uf-eq', 'uf-diagram' ],
				throwOnError: false,
				strict: 'ignore',
				trust: false,
			} );
		} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
