/** Unfold gallery: filter project cards by search, topic, and sort order. */
( function () {
	'use strict';
	document.querySelectorAll( '[data-unfold-gallery]' ).forEach( function ( g ) {
		var list = g.querySelector( '.uf-cards' );
		var kw = g.querySelector( '[data-uf-filter="keyword"]' );
		var search = g.querySelector( '[data-uf-search]' );
		var sort = g.querySelector( '[data-uf-sort]' );
		var cards = Array.prototype.slice.call( g.querySelectorAll( '.uf-card' ) );
		var count = g.querySelector( '[data-uf-count]' );
		var none = g.querySelector( '[data-uf-none]' );
		if ( ! cards.length || ( ! kw && ! search && ! sort ) ) return;

		function apply() {
			var q = search ? search.value.trim().toLowerCase() : '';
			var topic = kw ? kw.value : '';
			var shown = 0;
			cards.forEach( function ( c ) {
				var keys = [];
				try { keys = JSON.parse( c.getAttribute( 'data-keywords' ) || '[]' ); } catch {}
				var hay = c.getAttribute( 'data-search' ) || '';
				var ok = ( ! topic || keys.indexOf( topic ) !== -1 ) && ( ! q || hay.indexOf( q ) !== -1 );
				c.hidden = ! ok;
				if ( ok ) shown++;
			} );
			if ( list && sort ) {
				var mode = sort.value;
				var ordered = cards.slice().sort( function ( a, b ) {
					if ( mode === 'newest' ) return ( +b.getAttribute( 'data-date' ) || 0 ) - ( +a.getAttribute( 'data-date' ) || 0 );
					if ( mode === 'az' ) return ( a.getAttribute( 'data-title' ) || '' ).localeCompare( b.getAttribute( 'data-title' ) || '' );
					return ( +a.getAttribute( 'data-order' ) || 0 ) - ( +b.getAttribute( 'data-order' ) || 0 );
				} );
				ordered.forEach( function ( c ) { list.appendChild( c ); } );
			}
			if ( count ) count.textContent = shown + ( shown === 1 ? ' project' : ' projects' );
			if ( none ) none.hidden = shown !== 0;
		}
		if ( kw ) kw.addEventListener( 'change', apply );
		if ( search ) search.addEventListener( 'input', apply );
		if ( sort ) sort.addEventListener( 'change', apply );

		// Clicking a keyword chip on a card filters by it.
		g.addEventListener( 'click', function ( e ) {
			var chip = e.target.closest( '.uf-card .uf-chip' );
			if ( chip && kw ) {
				kw.value = chip.textContent;
				apply();
			}
		} );
	} );
} )();
