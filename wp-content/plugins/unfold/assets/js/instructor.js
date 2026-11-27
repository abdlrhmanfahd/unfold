/** Instructor portal: accept / return decision, and the 30-day visits line chart. */
( function () {
	'use strict';
	var C = window.UNFOLD_PORTAL;

	/* ------------------------------------------------------------------
	 * Decision form
	 * ---------------------------------------------------------------- */
	var form = document.querySelector( '[data-uf-decision]' );
	if ( C && form ) {
		var msg = form.querySelector( '.uf-decision-msg' ), last = null;
		form.querySelectorAll( 'button[name=decision]' ).forEach( function ( b ) { b.addEventListener( 'click', function () { last = b.value; } ); } );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var note = form.note.value.trim();
			if ( last === 'changes' && ! note ) { msg.textContent = 'Write a note so the owner knows what to change.'; form.note.focus(); return; }
			form.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );
			msg.textContent = 'Saving…';
			fetch( C.rest + '/projects/' + form.getAttribute( 'data-uf-decision' ) + '/review', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
				body: JSON.stringify( { decision: last, note: note } ),
			} ).then( function ( r ) { return r.json().then( function ( j ) { if ( ! r.ok ) throw new Error( j.message || 'Failed' ); return j; } ); } )
				.then( function () { msg.textContent = last === 'approve' ? 'Accepted and published.' : 'Returned with notes.'; setTimeout( function () { location.reload(); }, 700 ); } )
				.catch( function ( err ) { msg.textContent = err.message; form.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = false; } ); } );
		} );
	}

	/* ------------------------------------------------------------------
	 * Line chart: views and simulation uses per day
	 * ---------------------------------------------------------------- */
	var host = document.querySelector( '[data-uf-linechart]' );
	if ( ! host ) return;
	var data;
	try { data = JSON.parse( host.getAttribute( 'data-uf-linechart' ) ); } catch ( e ) { return; }
	var SERIES = [
		{ key: 'view', name: 'Page views', color: '#0F9E8A' },
		{ key: 'sim', name: 'Simulation uses', color: '#C27A00' },
	];
	var NS = 'http://www.w3.org/2000/svg';
	function s( tag, attrs ) { var e = document.createElementNS( NS, tag ); Object.keys( attrs ).forEach( function ( k ) { e.setAttribute( k, attrs[ k ] ); } ); return e; }
	function fmtDay( d ) { return new Date( d + 'T00:00:00Z' ).toLocaleDateString( undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' } ); }

	function draw() {
		host.innerHTML = '';
		var W = host.clientWidth, H = Math.max( 220, Math.min( 320, Math.round( W * 0.36 ) ) );
		var L = 40, R = 110, T = 16, B = 28, w = W - L - R, h = H - T - B;
		if ( W < 520 ) R = 16, w = W - L - R;
		var max = Math.max( 4, Math.max.apply( null, data.map( function ( d ) { return Math.max( d.view, d.sim ); } ) ) );
		var step = Math.pow( 10, Math.floor( Math.log10( max / 4 ) ) ) || 1;
		while ( max / step > 5 ) step *= 2;
		var top = Math.ceil( max / step ) * step;
		var X = function ( i ) { return L + ( data.length === 1 ? w / 2 : i * w / ( data.length - 1 ) ); };
		var Y = function ( v ) { return T + h - ( v / top ) * h; };

		var svg = s( 'svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Daily page views and simulation uses over the last 30 days' } );
		// Recessive grid and axis labels.
		for ( var v = 0; v <= top; v += step ) {
			svg.appendChild( s( 'line', { x1: L, x2: L + w, y1: Y( v ), y2: Y( v ), stroke: v === 0 ? '#9ca3af' : '#eef0f3', 'stroke-width': 1 } ) );
			var t = s( 'text', { x: L - 8, y: Y( v ) + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#6b7280' } );
			t.textContent = v; svg.appendChild( t );
		}
		[ 0, Math.floor( ( data.length - 1 ) / 2 ), data.length - 1 ].forEach( function ( i ) {
			var t = s( 'text', { x: X( i ), y: H - 8, 'text-anchor': i === 0 ? 'start' : i === data.length - 1 ? 'end' : 'middle', 'font-size': 11, fill: '#6b7280' } );
			t.textContent = fmtDay( data[ i ].date ); svg.appendChild( t );
		} );
		// Lines + direct labels at the end.
		SERIES.forEach( function ( sr ) {
			var d = data.map( function ( p, i ) { return ( i ? 'L' : 'M' ) + X( i ).toFixed( 1 ) + ' ' + Y( p[ sr.key ] ).toFixed( 1 ); } ).join( ' ' );
			svg.appendChild( s( 'path', { d: d, fill: 'none', stroke: sr.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
			if ( W >= 520 ) {
				var lastV = data[ data.length - 1 ][ sr.key ];
				var lbl = s( 'text', { x: L + w + 8, y: Y( lastV ) + 4, 'font-size': 12, fill: '#374151' } );
				lbl.textContent = sr.name; svg.appendChild( lbl );
			}
		} );
		// Hover layer: crosshair + markers + tooltip.
		var cross = s( 'line', { y1: T, y2: T + h, stroke: '#9ca3af', 'stroke-width': 1, visibility: 'hidden' } );
		svg.appendChild( cross );
		var dots = SERIES.map( function ( sr ) { var c = s( 'circle', { r: 4.5, fill: sr.color, stroke: '#fff', 'stroke-width': 2, visibility: 'hidden' } ); svg.appendChild( c ); return c; } );
		var hit = s( 'rect', { x: L, y: T, width: w, height: h, fill: 'transparent' } );
		svg.appendChild( hit );
		host.appendChild( svg );
		var tip = document.createElement( 'div' );
		tip.className = 'uf-tooltip'; tip.hidden = true;
		host.appendChild( tip );

		function show( clientX ) {
			var r = svg.getBoundingClientRect(), x = clientX - r.left;
			var i = Math.max( 0, Math.min( data.length - 1, Math.round( ( x - L ) / ( w / Math.max( 1, data.length - 1 ) ) ) ) );
			var px = X( i );
			cross.setAttribute( 'x1', px ); cross.setAttribute( 'x2', px ); cross.setAttribute( 'visibility', 'visible' );
			SERIES.forEach( function ( sr, k ) { dots[ k ].setAttribute( 'cx', px ); dots[ k ].setAttribute( 'cy', Y( data[ i ][ sr.key ] ) ); dots[ k ].setAttribute( 'visibility', 'visible' ); } );
			tip.innerHTML = '<strong>' + fmtDay( data[ i ].date ) + '</strong>' + SERIES.map( function ( sr ) {
				return '<span><i style="background:' + sr.color + '"></i>' + sr.name + ': ' + data[ i ][ sr.key ] + '</span>';
			} ).join( '' );
			tip.hidden = false;
			var left = px + 12;
			if ( left + 170 > W ) left = px - 182;
			tip.style.left = left + 'px'; tip.style.top = T + 'px';
		}
		function hide() { cross.setAttribute( 'visibility', 'hidden' ); dots.forEach( function ( d ) { d.setAttribute( 'visibility', 'hidden' ); } ); tip.hidden = true; }
		hit.addEventListener( 'pointermove', function ( e ) { show( e.clientX ); } );
		hit.addEventListener( 'pointerleave', hide );

		// Legend (always present for two series).
		var lg = document.createElement( 'ul' );
		lg.className = 'uf-legend-row';
		lg.innerHTML = SERIES.map( function ( sr ) { return '<li><span class="uf-swatch uf-swatch--line" style="background:' + sr.color + '" aria-hidden="true"></span>' + sr.name + '</li>'; } ).join( '' );
		host.insertBefore( lg, svg );
	}
	draw();
	var rt; window.addEventListener( 'resize', function () { clearTimeout( rt ); rt = setTimeout( draw, 120 ); } );
} )();
