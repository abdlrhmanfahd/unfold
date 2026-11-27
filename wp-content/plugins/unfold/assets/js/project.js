/**
 * Unfold project page: subsystem tabs, auto-generated block diagram (Mermaid),
 * LaTeX equations (KaTeX), sandboxed simulation sizing, and CSV plots (Plotly).
 * The page is fully readable without this script; it only enhances it.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-unfold-project]' );
	if ( ! root ) {
		return;
	}

	/* ------------------------------------------------------------------
	 * Subsystem tabs
	 * ---------------------------------------------------------------- */
	var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-uf-tab]' ) );
	root.classList.add( 'uf-js' );

	function activate( id, focus ) {
		var found = false;
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-uf-tab' ) === id;
			found = found || on;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
			var panel = document.getElementById( 'sub-' + t.getAttribute( 'data-uf-tab' ) );
			if ( panel ) {
				panel.classList.toggle( 'is-active', on );
				panel.hidden = ! on;
			}
			if ( on && focus ) {
				t.focus();
			}
		} );
		if ( found ) {
			currentId = id;
			markNode();
		}
		return found;
	}

	var currentId = null;
	function markNode() {
		root.querySelectorAll( '.uf-diagram .uf-node' ).forEach( function ( g ) {
			g.classList.toggle( 'is-current', g.getAttribute( 'data-sid' ) === currentId );
		} );
	}

	// Highlight the section in view in the sticky nav.
	var tocLinks = Array.prototype.slice.call( root.querySelectorAll( '.uf-toc a' ) );
	if ( 'IntersectionObserver' in window && tocLinks.length ) {
		var seen = {};
		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( en ) { seen[ en.target.id ] = en.isIntersecting; } );
			var firstVisible = null;
			tocLinks.forEach( function ( a ) {
				var id = a.getAttribute( 'href' ).slice( 1 );
				if ( ! firstVisible && seen[ id ] ) firstVisible = id;
			} );
			tocLinks.forEach( function ( a ) { a.classList.toggle( 'is-active', a.getAttribute( 'href' ).slice( 1 ) === firstVisible ); } );
		}, { rootMargin: '-80px 0px -55% 0px' } );
		tocLinks.forEach( function ( a ) {
			var s = document.getElementById( a.getAttribute( 'href' ).slice( 1 ) );
			if ( s ) io.observe( s );
		} );
	}

	tabs.forEach( function ( t, i ) {
		t.tabIndex = i === 0 ? 0 : -1;
		t.addEventListener( 'click', function () {
			activate( t.getAttribute( 'data-uf-tab' ) );
			history.replaceState( null, '', '#sub-' + t.getAttribute( 'data-uf-tab' ) );
		} );
		t.addEventListener( 'keydown', function ( e ) {
			var k = e.key, j = i;
			if ( k === 'ArrowRight' ) j = ( i + 1 ) % tabs.length;
			else if ( k === 'ArrowLeft' ) j = ( i - 1 + tabs.length ) % tabs.length;
			else if ( k === 'Home' ) j = 0;
			else if ( k === 'End' ) j = tabs.length - 1;
			else return;
			e.preventDefault();
			activate( tabs[ j ].getAttribute( 'data-uf-tab' ), true );
		} );
	} );
	if ( tabs.length ) {
		activate( tabs[ 0 ].getAttribute( 'data-uf-tab' ) );
	}

	function openSubsystem( id ) {
		if ( activate( id ) ) {
			var section = document.getElementById( 'uf-subsystems' );
			section.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			history.replaceState( null, '', '#sub-' + id );
		}
	}

	root.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( '[data-uf-open]' );
		if ( a ) {
			e.preventDefault();
			openSubsystem( a.getAttribute( 'data-uf-open' ) );
		}
	} );

	var hash = decodeURIComponent( location.hash || '' );
	if ( hash.indexOf( '#sub-' ) === 0 ) {
		var hid = hash.slice( 5 );
		if ( activate( hid ) ) {
			setTimeout( function () {
				document.getElementById( 'sub-' + hid ).scrollIntoView( { block: 'start' } );
			}, 50 );
		}
	}

	/* ------------------------------------------------------------------
	 * Equations
	 * ---------------------------------------------------------------- */
	if ( window.katex ) {
		root.querySelectorAll( '.uf-eq[data-latex]' ).forEach( function ( el ) {
			try {
				window.katex.render( el.getAttribute( 'data-latex' ), el, {
					displayMode: true,
					throwOnError: false,
					trust: false,
					strict: 'ignore',
					output: 'htmlAndMathml',
				} );
				el.classList.add( 'is-rendered' );
			} catch ( err ) {
				/* Leave the raw LaTeX visible. */
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Block diagram (generated from subsystems + connections)
	 * ---------------------------------------------------------------- */
	var CATEGORY_STYLE = {
		perception: [ '#e0f2fe', '#0369a1' ],
		localization: [ '#ecfeff', '#0e7490' ],
		planning: [ '#ede9fe', '#6d28d9' ],
		control: [ '#dcfce7', '#15803d' ],
		task_allocation: [ '#fef3c7', '#b45309' ],
		fleet_coordination: [ '#ffe4e6', '#be123c' ],
		communication: [ '#f1f5f9', '#475569' ],
		other: [ '#f4f4f5', '#52525b' ],
	};

	function mmLabel( s ) {
		// Inside a quoted Mermaid label: escape quotes and angle brackets as Mermaid entities.
		return String( s == null ? '' : s )
			.replace( /"/g, '#quot;' )
			.replace( /</g, '#lt;' )
			.replace( />/g, '#gt;' );
	}

	function buildMermaid( spec, dir ) {
		var lines = [ 'flowchart ' + dir ];
		var used = {};
		spec.nodes.forEach( function ( n ) {
			used[ n.category ] = true;
			lines.push( '  n_' + n.id + '["' + mmLabel( n.name ) + '"]:::cat_' + n.category );
		} );
		spec.edges.forEach( function ( e ) {
			lines.push( '  n_' + e.from + ' -->' + ( e.signal ? '|"' + mmLabel( e.signal ) + '"|' : '' ) + ' n_' + e.to );
		} );
		Object.keys( used ).forEach( function ( c ) {
			var st = CATEGORY_STYLE[ c ] || CATEGORY_STYLE.other;
			lines.push( '  classDef cat_' + c + ' fill:' + st[ 0 ] + ',stroke:' + st[ 1 ] + ',stroke-width:2px,color:#111827' );
		} );
		return lines.join( '\n' );
	}

	var diagram = root.querySelector( '[data-uf-diagram]' );
	var diagramDir = null;
	var diagramSeq = 0;

	function drawDiagram() {
		if ( ! diagram || ! window.mermaid ) {
			return;
		}
		var spec;
		try {
			spec = JSON.parse( diagram.getAttribute( 'data-uf-diagram' ) );
		} catch ( e ) {
			return;
		}
		if ( ! spec.nodes.length ) {
			diagram.innerHTML = '';
			return;
		}
		var dir = diagram.clientWidth < 640 ? 'TD' : 'LR';
		if ( dir === diagramDir ) {
			return;
		}
		diagramDir = dir;
		var id = 'uf-mermaid-' + ++diagramSeq;
		window.mermaid
			.render( id, buildMermaid( spec, dir ) )
			.then( function ( out ) {
				diagram.innerHTML = out.svg;
				diagram.classList.add( 'is-rendered' );
				var svg = diagram.querySelector( 'svg' );
				if ( svg ) {
					svg.removeAttribute( 'height' );
					svg.style.maxWidth = '100%';
				}
				// Make each block open its subsystem.
				diagram.querySelectorAll( 'g.node' ).forEach( function ( g ) {
					var m = /flowchart-n_([a-z][a-z0-9_]*)-\d+$/.exec( g.id || '' );
					if ( ! m ) return;
					var sid = m[ 1 ];
					var name = spec.nodes.filter( function ( n ) { return n.id === sid; } )[ 0 ];
					g.classList.add( 'uf-node' );
					g.setAttribute( 'data-sid', sid );
					g.setAttribute( 'tabindex', '0' );
					g.setAttribute( 'role', 'button' );
					g.setAttribute( 'aria-label', 'Open subsystem: ' + ( name ? name.name : sid ) );
					g.addEventListener( 'click', function () { openSubsystem( sid ); } );
					if ( sid === currentId ) g.classList.add( 'is-current' );
					g.addEventListener( 'keydown', function ( e ) {
						if ( e.key === 'Enter' || e.key === ' ' ) {
							e.preventDefault();
							openSubsystem( sid );
						}
					} );
				} );
			} )
			.catch( function () {
				diagram.innerHTML = '<p class="uf-empty">The diagram could not be drawn. See “Connections as text” below.</p>';
			} );
	}

	if ( diagram && window.mermaid ) {
		window.mermaid.initialize( {
			startOnLoad: false,
			securityLevel: 'strict',
			theme: 'base',
			fontFamily: 'inherit',
			themeVariables: {
				fontSize: '15px',
				lineColor: '#64748b',
				primaryTextColor: '#111827',
				edgeLabelBackground: '#ffffff',
			},
			flowchart: { curve: 'basis', padding: 14, nodeSpacing: 40, rankSpacing: 56, htmlLabels: true },
		} );
		drawDiagram();
		var rt;
		window.addEventListener( 'resize', function () {
			clearTimeout( rt );
			rt = setTimeout( drawDiagram, 150 );
		} );
	}

	/* ------------------------------------------------------------------
	 * Engagement counters: one view per page load, one "simulation use" each time
	 * a visitor opens Break it (qcar-project.js). No cookies, no IDs.
	 * ---------------------------------------------------------------- */
	var T = window.UNFOLD_TRACK;
	function track( kind ) {
		if ( ! T ) return;
		var headers = { 'Content-Type': 'application/json' };
		if ( T.nonce ) headers[ 'X-WP-Nonce' ] = T.nonce;
		try {
			fetch( T.url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: headers, body: JSON.stringify( { project: T.project, kind: kind } ) } ).catch( function () {} );
		} catch ( e ) {}
	}
	if ( T ) {
		track( 'view' );
	}
	window.UnfoldTrack = track;

	/* ------------------------------------------------------------------
	 * CSV plots
	 * ---------------------------------------------------------------- */
	function parseCSV( text ) {
		var rows = text.replace( /\r/g, '' ).split( '\n' ).filter( function ( l ) { return l.trim() !== ''; } );
		var head = rows.shift().split( ',' ).map( function ( h ) { return h.trim().replace( /^"|"$/g, '' ); } );
		var cols = head.map( function () { return []; } );
		rows.forEach( function ( r ) {
			r.split( ',' ).forEach( function ( v, i ) {
				if ( i < cols.length ) cols[ i ].push( parseFloat( v ) );
			} );
		} );
		return { head: head, cols: cols };
	}

	root.querySelectorAll( '[data-uf-csv]' ).forEach( function ( el ) {
		if ( ! window.Plotly ) {
			el.innerHTML = '';
			return;
		}
		// Declared columns (data_files[].columns) choose what to plot: first = x axis, the rest = y.
		// With none declared, column 1 is x and every other column is plotted.
		var units = {}, declared = [];
		try {
			JSON.parse( el.getAttribute( 'data-uf-columns' ) || '[]' ).forEach( function ( c ) {
				units[ c.name ] = c.unit;
				declared.push( c.name );
			} );
		} catch ( e ) {}
		fetch( el.getAttribute( 'data-uf-csv' ), { credentials: 'same-origin' } )
			.then( function ( r ) { if ( ! r.ok ) throw new Error( r.status ); return r.text(); } )
			.then( function ( txt ) {
				var d = parseCSV( txt );
				var lab = function ( n ) { return units[ n ] ? n + ' [' + units[ n ] + ']' : n; };
				var idx = declared.length >= 2
					? declared.map( function ( n ) { return d.head.indexOf( n ); } ).filter( function ( i ) { return i >= 0; } )
					: d.head.map( function ( _, i ) { return i; } );
				if ( idx.length < 2 ) {
					throw new Error( 'declared columns not found in file' );
				}
				var xi = idx[ 0 ];
				var traces = idx.slice( 1 ).map( function ( i ) {
					return { x: d.cols[ xi ], y: d.cols[ i ], name: lab( d.head[ i ] ), mode: 'lines', type: 'scatter', line: { width: 1.6 } };
				} );
				el.innerHTML = '';
				window.Plotly.newPlot( el, traces, {
					margin: { l: 56, r: 16, t: 16, b: 48 },
					showlegend: traces.length > 1,
					xaxis: { title: { text: lab( d.head[ xi ] ) } },
					// Same unit on both axes (e.g. an x–y route in metres): keep true proportions.
					yaxis: traces.length === 1
						? ( units[ d.head[ xi ] ] && units[ d.head[ xi ] ] === units[ d.head[ idx[ 1 ] ] ] && units[ d.head[ xi ] ] !== 's'
							? { title: { text: traces[ 0 ].name }, scaleanchor: 'x', scaleratio: 1 }
							: { title: { text: traces[ 0 ].name } } )
						: {},
					legend: { orientation: 'h', y: -0.2 },
					font: { family: 'inherit' },
					paper_bgcolor: 'rgba(0,0,0,0)',
					plot_bgcolor: 'rgba(0,0,0,0)',
				}, { responsive: true, displaylogo: false } );
			} )
			.catch( function () {
				el.innerHTML = '<p class="uf-empty">This data file could not be loaded.</p>';
			} );
	} );
} )();
