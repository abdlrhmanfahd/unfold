/** Unfold gallery: filter project cards by topic. */
( function () {
	'use strict';
	document.querySelectorAll( '[data-unfold-gallery]' ).forEach( function ( g ) {
		var kw = g.querySelector( '[data-uf-filter="keyword"]' );
		var cards = Array.prototype.slice.call( g.querySelectorAll( '.uf-card' ) );
		var count = g.querySelector( '[data-uf-count]' );
		var none = g.querySelector( '[data-uf-none]' );
		if ( ! kw ) return;

		function apply() {
			var shown = 0;
			cards.forEach( function ( c ) {
				var keys = [];
				try { keys = JSON.parse( c.getAttribute( 'data-keywords' ) || '[]' ); } catch ( e ) {}
				var ok = ! kw.value || keys.indexOf( kw.value ) !== -1;
				c.hidden = ! ok;
				if ( ok ) shown++;
			} );
			count.textContent = shown + ( shown === 1 ? ' project' : ' projects' );
			none.hidden = shown !== 0;
		}
		kw.addEventListener( 'change', apply );

		// Clicking a keyword chip on a card filters by it.
		g.addEventListener( 'click', function ( e ) {
			var chip = e.target.closest( '.uf-card .uf-chip' );
			if ( chip ) {
				kw.value = chip.textContent;
				apply();
			}
		} );
	} );
} )();
