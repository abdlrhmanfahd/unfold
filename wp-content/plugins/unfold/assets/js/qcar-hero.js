/* Home hero: the QCar 2 explorer with a card for the tapped part, and a halo of the newest
   projects and 3D worlds circling the car. The halo fades away as soon as the car unfolds
   (or a part is picked) and comes back when it is assembled again. */
( function () {
	'use strict';
	document.querySelectorAll( '[data-unfold-qcar-hero]' ).forEach( function ( stage ) {
		var card = stage.querySelector( '.uf-qhero-card' ), name = card.querySelector( 'h2' ), line = card.querySelector( 'p' );
		var car = window.UnfoldQCar.mount( stage, {
			onPick: function ( id ) {
				if ( ! id ) { card.hidden = true; return; }
				name.textContent = window.UnfoldQCar.HW[ id ].name;
				line.textContent = window.UnfoldQCar.HW[ id ].plain;
				card.hidden = false;
			},
		} );
		card.querySelector( '.uf-qhero-x' ).addEventListener( 'click', function () { car.clear(); card.hidden = true; } );
		halo( stage, car );
	} );

	function halo( stage, car ) {
		var items = [];
		try { items = JSON.parse( stage.getAttribute( 'data-halo' ) || '[]' ); } catch ( e ) { items = []; }
		if ( ! items.length || ! car.screen ) return;
		var wrap = document.createElement( 'nav' );
		wrap.className = 'uf-halo';
		wrap.setAttribute( 'aria-label', 'Projects and 3D worlds' );
		var els = items.map( function ( it ) {
			var a = document.createElement( 'a' );
			a.className = 'uf-halo-pill uf-halo-pill--' + ( it.k === 'w' ? 'world' : 'project' );
			a.href = it.u;
			a.textContent = it.n;
			wrap.appendChild( a );
			return a;
		} );
		stage.appendChild( wrap );

		var spin = 0, hover = false, shown = 1, last = performance.now();
		var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		wrap.addEventListener( 'pointerenter', function () { hover = true; } );
		wrap.addEventListener( 'pointerleave', function () { hover = false; } );
		wrap.addEventListener( 'focusin', function () { hover = true; } );
		wrap.addEventListener( 'focusout', function () { hover = false; } );

		function frame( now ) {
			var dt = Math.min( 0.05, ( now - last ) / 1000 ); last = now;
			if ( ! hover && ! still ) spin += dt * 0.12;
			// fade out as the car starts to unfold or a part is picked
			var want = ( car.progress() < 0.02 && ! car.picked() ) ? 1 : 0;
			shown += ( want - shown ) * Math.min( 1, dt * 6 );
			wrap.style.opacity = shown.toFixed( 3 );
			wrap.style.visibility = shown < 0.02 ? 'hidden' : 'visible';
			if ( shown > 0.01 ) {
				var copy = null, cEl = stage.querySelector( '.uf-qhero-copy' );
				if ( cEl ) {
					var sr = stage.getBoundingClientRect(), cr = cEl.getBoundingClientRect();
					copy = { l: cr.left - sr.left, r: cr.right - sr.left, t: cr.top - sr.top, b: cr.bottom - sr.top };
				}
				// up to PER_RING names on one ring; more names add rings, a little wider and higher,
				// turning the other way, so any number of projects stays readable
				var narrow = stage.clientWidth < 700, n = els.length, PER_RING = narrow ? 7 : 10;
				var rings = Math.ceil( n / PER_RING );
				for ( var i = 0; i < n; i++ ) {
					var r = i % rings, k = Math.floor( i / rings ), inRing = Math.ceil( ( n - r ) / rings );
					var R = ( narrow ? 1.9 : 2.5 ) + r * ( narrow ? 0.45 : 0.6 ), Y = ( narrow ? 1.75 : 1.85 ) + r * 0.4;
					var a = ( r % 2 ? -spin : spin ) + k * 2 * Math.PI / inRing + r * 0.5, p = car.screen( Math.cos( a ) * R, Y, Math.sin( a ) * R );
					if ( ! p ) continue;
					var c = car.screen( 0, Y, 0 ), back = p.z > c.z;          // farther than the car's centre
					var el = els[ i ];
					el.style.transform = 'translate(' + p.x.toFixed( 1 ) + 'px,' + p.y.toFixed( 1 ) + 'px) translate(-50%,-50%) scale(' + ( back ? 0.88 : 1 ) + ')';
					// passing under the headline or buttons: step aside (almost invisible, not clickable)
					var w2 = el.offsetWidth / 2 + 8, h2 = el.offsetHeight / 2 + 8;
					var over = copy && p.x + w2 > copy.l && p.x - w2 < copy.r && p.y + h2 > copy.t && p.y - h2 < copy.b;
					el.style.opacity = over ? 0.08 : back ? 0.42 : 1;
					el.style.pointerEvents = over ? 'none' : '';
					el.style.zIndex = back ? 1 : 3;
				}
			}
			requestAnimationFrame( frame );
		}
		requestAnimationFrame( frame );
	}
} )();
