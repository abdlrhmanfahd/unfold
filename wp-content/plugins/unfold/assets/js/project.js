/**
 * Unfold project page: subsystem tabs, auto-generated block diagram (Mermaid),
 * LaTeX equations (KaTeX), sandboxed simulation sizing, and CSV plots (Plotly).
 * The page is fully readable without this script; it only enhances it.
 */
( function () {
	'use strict';

	function parseCSV( text ) {
		var rows = text.replace( /\r/g, '' ).split( '\n' ).filter( function ( l ) { return l.trim() !== ''; } );
		var head = rows.shift().split( ',' ).map( function ( h ) { return h.trim().replace( /^"|"$/g, '' ); } );
		var raw = head.map( function () { return []; } );
		var num = head.map( function () { return []; } );
		rows.forEach( function ( r ) {
			r.split( ',' ).forEach( function ( v, i ) {
				if ( i >= raw.length ) return;
				var cell = v.trim().replace( /^"|"$/g, '' );
				raw[ i ].push( cell );
				num[ i ].push( cell === '' ? NaN : parseFloat( cell ) );
			} );
		} );
		return { head: head, raw: raw, num: num };
	}

	function isNumericCol( col ) {
		var nonEmpty = 0, finite = 0;
		for ( var i = 0; i < col.raw.length; i++ ) {
			if ( col.raw[ i ] !== '' ) {
				nonEmpty++;
				if ( Number.isFinite( col.num[ i ] ) ) finite++;
			}
		}
		return nonEmpty > 0 && finite * 2 >= nonEmpty;
	}

	function isMonotonic( nums ) {
		var pairs = 0, inc = 0, prev = null;
		for ( var i = 0; i < nums.length; i++ ) {
			if ( ! Number.isFinite( nums[ i ] ) ) continue;
			if ( prev !== null ) {
				pairs++;
				if ( nums[ i ] >= prev ) inc++;
			}
			prev = nums[ i ];
		}
		return pairs === 0 || inc / pairs >= 0.9;
	}

	function buildPlotSpec( txt, declared, units, widthPx ) {
		var d = parseCSV( txt );
		var uMap = units || {};
		var dec = Array.isArray( declared ) ? declared : [];
		var lab = function ( n ) { return uMap[ n ] ? n + ' [' + uMap[ n ] + ']' : n; };
		var allIdx = [];
		for ( var k = 0; k < d.head.length; k++ ) allIdx.push( k );
		var idx = dec.length >= 2
			? dec.map( function ( n ) { return d.head.indexOf( n ); } ).filter( function ( i ) { return i >= 0; } )
			: allIdx;
		if ( idx.length < 2 ) {
			throw new Error( 'declared columns not found in file' );
		}
		var xi = idx[ 0 ];
		var xNumeric = isNumericCol( { raw: d.raw[ xi ], num: d.num[ xi ] } );
		var xVals = xNumeric ? d.num[ xi ] : d.raw[ xi ];
		var yCols = idx.slice( 1 ).filter( function ( colIdx ) {
			return d.num[ colIdx ].some( Number.isFinite );
		} );
		if ( ! yCols.length ) {
			yCols = allIdx.filter( function ( colIdx ) {
				return colIdx !== xi && d.num[ colIdx ].some( Number.isFinite );
			} ).slice( 0, 3 );
		}
		if ( ! yCols.length ) {
			throw new Error( 'no numeric y columns in file' );
		}
		var traceOpts = ! xNumeric
			? { mode: 'lines+markers', marker: { size: 5 }, line: { width: 1.6 } }
			: ( isMonotonic( d.num[ xi ] )
				? { mode: 'lines', line: { width: 1.6 } }
				: { mode: 'markers', marker: { size: 5, opacity: 0.8 } } );
		var traces = yCols.map( function ( i ) {
			var tr = { x: xVals, y: d.num[ i ], name: lab( d.head[ i ] ), type: 'scatter', mode: traceOpts.mode };
			if ( traceOpts.line ) tr.line = traceOpts.line;
			if ( traceOpts.marker ) tr.marker = traceOpts.marker;
			return tr;
		} );
		var maxLegendChars = traces.reduce( function ( m, t ) { return Math.max( m, String( t.name || '' ).length ); }, 0 );
		var narrow = ( widthPx && widthPx < 520 ) || maxLegendChars > 28;
		var topMargin = traces.length <= 1 ? 20 : ( narrow ? ( 28 + traces.length * 22 ) : ( traces.length > 2 ? 78 : 58 ) );
		var layout = {
			margin: { l: 58, r: 18, t: topMargin, b: 56 },
			showlegend: traces.length > 1,
			xaxis: { title: { text: lab( d.head[ xi ] ), standoff: 10 }, automargin: true },
			yaxis: traces.length === 1
				? ( xNumeric && uMap[ d.head[ xi ] ] && uMap[ d.head[ xi ] ] === uMap[ d.head[ yCols[ 0 ] ] ] && uMap[ d.head[ xi ] ] !== 's'
					? { title: { text: traces[ 0 ].name }, scaleanchor: 'x', scaleratio: 1, automargin: true }
					: { title: { text: traces[ 0 ].name }, automargin: true } )
				: { automargin: true },
			legend: { orientation: 'h', x: 0, y: 1.04, yanchor: 'bottom', xanchor: 'left', entrywidth: narrow ? 0.98 : 0.48, entrywidthmode: 'fraction', font: { size: narrow ? 10.5 : 11 } },
			font: { family: 'Onest, system-ui, -apple-system, sans-serif', size: 12 },
			paper_bgcolor: 'rgba(0,0,0,0)',
			plot_bgcolor: 'rgba(0,0,0,0)',
		};
		return { traces: traces, layout: layout };
	}

	window.UnfoldCsvPlot = { parseCSV: parseCSV, isNumericCol: isNumericCol, isMonotonic: isMonotonic, buildPlotSpec: buildPlotSpec };

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

	// Highlight the active section cleanly from scroll position (no observer lag on tall sections).
	var tocLinks = Array.prototype.slice.call( root.querySelectorAll( '.uf-toc a' ) );
	if ( tocLinks.length ) {
		var tocBar = root.querySelector( '.uf-toc' );
		var tocList = tocBar ? tocBar.querySelector( 'ul' ) : null;
		var lastActive = null;
		var tocSections = tocLinks.map( function ( a ) {
			return { a: a, el: document.getElementById( a.getAttribute( 'href' ).slice( 1 ) ) };
		} ).filter( function ( it ) { return !! it.el; } );
		var spyTick = false;
		function updateSpy() {
			spyTick = false;
			if ( ! tocSections.length ) return;
			var offset = ( tocBar ? tocBar.offsetHeight : 48 ) + 40;
			var active = tocSections[ 0 ].el.id;
			var atBottom = ( window.innerHeight + window.scrollY ) >= ( document.documentElement.scrollHeight - 40 );
			if ( atBottom ) {
				active = tocSections[ tocSections.length - 1 ].el.id;
			} else {
				tocSections.forEach( function ( it ) {
					if ( it.el.getBoundingClientRect().top <= offset ) active = it.el.id;
				} );
			}
			tocLinks.forEach( function ( a ) {
				var on = a.getAttribute( 'href' ).slice( 1 ) === active;
				a.classList.toggle( 'is-active', on );
				if ( on && active !== lastActive && tocList && tocList.scrollWidth > tocList.clientWidth ) {
					tocList.scrollTo( { left: Math.max( 0, a.offsetLeft - 16 ), behavior: 'smooth' } );
				}
			} );
			lastActive = active;
		}
		window.addEventListener( 'scroll', function () {
			if ( ! spyTick ) { spyTick = true; requestAnimationFrame( updateSpy ); }
		}, { passive: true } );
		tocLinks.forEach( function ( a ) {
			a.addEventListener( 'click', function () {
				var target = a.getAttribute( 'href' ).slice( 1 );
				tocLinks.forEach( function ( l ) { l.classList.toggle( 'is-active', l.getAttribute( 'href' ).slice( 1 ) === target ); } );
			} );
		} );
		updateSpy();
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

	var hash = '';
	try { hash = decodeURIComponent( location.hash || '' ); } catch { hash = ''; }
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
			} catch {
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
		} catch {
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
		} catch {}
	}
	if ( T ) {
		track( 'view' );
	}
	window.UnfoldTrack = track;

	/* ------------------------------------------------------------------
	 * CSV plots
	 * ---------------------------------------------------------------- */
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
		} catch {}
		fetch( el.getAttribute( 'data-uf-csv' ), { credentials: 'same-origin' } )
			.then( function ( r ) { if ( ! r.ok ) throw new Error( r.status ); return r.text(); } )
			.then( function ( txt ) {
				var spec = buildPlotSpec( txt, declared, units, el.clientWidth || window.innerWidth );
				el.innerHTML = '';
				window.Plotly.newPlot( el, spec.traces, spec.layout, { responsive: true, displaylogo: false } );
			} )
			.catch( function () {
				el.innerHTML = '<p class="uf-empty">This data file could not be loaded.</p>';
			} );
	} );
} )();
