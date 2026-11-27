/**
 * Unfold review form, generated from the project JSON Schema.
 *
 * Members never see JSON. Every field of the schema becomes a form control:
 *   objects → groups, arrays → repeatable cards, enums → selects,
 *   tracked values {value, source_ref, status} → number + "where in the report" +
 *   a three-way status (From the report / Confirmed / Doesn't apply).
 * LABELS / HIDDEN / per-path hints are the only hand-written parts, so the form
 * follows the schema as it evolves.
 *
 * window.UnfoldForm.mount(el, { schema, data, files, onChange }) → { getData, focusPath }
 */
( function () {
	'use strict';

	var LABELS = {
		meta: 'Basics', 'meta.title': 'Project title', 'meta.credit': 'Names on the project', 'meta.question': 'The question', 'meta.headline': 'Headline result', 'meta.headline.before': 'Before', 'meta.headline.after': 'After', plain: 'In one short line', on: 'Car hardware it uses', 'subsystems.sim': 'Simulation', 'subsystems.sim.type': 'Kind', 'subsystems.sim.template': 'Template', 'subsystems.sim.inputs': 'Template inputs', 'subsystems.sim.data': 'Recorded data it uses', 'subsystems.sim.student_approved': 'Simulation checked', 'subsystems.sim.data.set': 'Inputs this run used', role: 'Role', 'meta.short_description': 'One-sentence summary',
		'meta.platform': 'Platform', 'meta.platform.mode': 'Where it ran', 'meta.platform.vehicle_count': 'Number of vehicles',
		'meta.platform.software_stack': 'Software used', 'meta.keywords': 'Keywords',
		goal: 'Goal', 'goal.problem_statement': 'The problem', 'goal.objectives': 'Objectives', 'goal.success_criteria': 'Success criteria (measurable targets)',
		environment: 'Environment', 'environment.map_source': 'Map', 'environment.map_file': 'Map file', 'environment.other_agents': 'Other agents',
		sensors: 'Sensors', subsystems: 'Subsystems (the parts of the project)', connections: 'Connections between subsystems',
		data_files: 'Data files', results: 'Results', 'results.metrics': 'Measured results', 'results.media': 'Figures and videos',
		'results.limitations': 'Limitations', 'results.future_work': 'Future work',
		rate_hz: 'Update rate (Hz)', sample_rate_hz: 'Sample rate (Hz)', used_by: 'Used by', source_ref: 'Where in the report', code_files: 'Code files',
		short_description: 'Summary', problem_statement: 'Problem', id: 'Short id', from: 'From', to: 'To', signal: 'Signal',
	};
	var HINTS = {
		'meta.credit': 'Everyone who worked on it, one per box, or a group name. Leave empty for no names.',
		'meta.short_description': 'Shown on the project card. One or two sentences.',
		'meta.question': 'One short line, shown first on the project page.',
		'meta.headline': 'The one result that answers the question, before and after.',
		'subsystems.plain': 'Under 10 words, e.g. Holds the car at 0.6 m/s.',
		'subsystems.on': 'Lights up these parts of the car on the project page.',
		'subsystems.sim.student_approved': 'Ticked by default: visitors will see this simulation. Try it in the preview, and untick it if it is wrong; you are responsible for what is shown.',
		'subsystems.sim.data': 'Files and column names from your ZIP. The preview shows how well the simulation matches them.',
		'goal.success_criteria': 'Each target is compared with a measured result of the same name.',
		'subsystems.id': 'Lowercase letters, digits and _ — e.g. speed_ctrl. Used to connect parts.',
		'subsystems.equations': 'LaTeX, one equation per line, e.g. u(t) = K_p e(t) + K_i \\int e\\,dt',
		'subsystems.description': 'Plain language, 2–4 sentences. Inline math: $K_p$.',
		'connections': 'These arrows draw the block diagram.',
		'data_files.columns': 'Only the columns worth plotting. The first one is the x-axis.',
	};
	var ENUM_LABELS = {
		qlabs_sim: 'QLabs simulation', physical_qcar2: 'Physical QCar 2', both: 'Both', python: 'Python', matlab_simulink: 'MATLAB / Simulink', ros2: 'ROS 2', other: 'Other',
		qlabs_builtin: 'QLabs built-in map', custom: 'Custom map', physical_track: 'Physical track', static: 'Static', dynamic: 'Dynamic', none: 'None',
		lidar: 'LiDAR', rgb_camera: 'RGB camera', depth_camera: 'Depth camera', imu: 'IMU', wheel_encoder: 'Wheel encoder',
		perception: 'Perception', localization: 'Localization', planning: 'Planning', control: 'Control', task_allocation: 'Task allocation',
		chassis: 'Chassis', wheels: 'Wheels', servo: 'Steering servo', motor: 'Drive motor', encoder: 'Encoder', battery: 'Battery', board: 'Main board', compute: 'Computer', depth: 'Depth camera', csi: '360° cameras', lights: 'Lights', wifi: 'Wi-Fi',
		path_follow: 'Path following', speed_loop: 'Speed control', conceptual: 'Conceptual', data_backed: 'Data-backed', route: 'Route', speed: 'Measured speed', run: 'Recorded run',
		fleet_coordination: 'Fleet coordination', communication: 'Communication', csv: 'CSV', mat: 'MAT', bag: 'ROS bag', json: 'JSON', mp4: 'MP4',
	};
	var HIDDEN = { schema_version: 1, review: 1 };
	var WIDE = { description: 1, problem_statement: 1, scenario: 1, short_description: 1, purpose: 1, conditions: 1, caption: 1 };

	function humanize( k ) { return String( k ).replace( /_/g, ' ' ).replace( /^./, function ( c ) { return c.toUpperCase(); } ); }
	function el( tag, attrs, kids ) {
		var e = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) e.textContent = attrs[ k ];
			else if ( k === 'class' ) e.className = attrs[ k ];
			else if ( k.slice( 0, 2 ) === 'on' ) e.addEventListener( k.slice( 2 ), attrs[ k ] );
			else if ( attrs[ k ] !== null && attrs[ k ] !== undefined && attrs[ k ] !== false ) e.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
		} );
		( kids || [] ).forEach( function ( c ) { if ( c ) e.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } );
		return e;
	}
	var uid = 0;
	function nextId() { return 'uff-' + ( ++uid ); }
	// Render $...$ math in titles and labels (KaTeX auto-render, if loaded).
	function mathify( node ) {
		if ( window.renderMathInElement && /\$[^$]+\$/.test( node.textContent ) ) {
			window.renderMathInElement( node, { delimiters: [ { left: '$', right: '$', display: false } ], throwOnError: false } );
		}
		return node;
	}

	function mount( root, opts ) {
		var schema = opts.schema, data = opts.data, defs = schema.$defs || {};
		var changed = opts.onChange || function () {};

		function resolve( s ) {
			while ( s && s.$ref ) s = defs[ s.$ref.replace( '#/$defs/', '' ) ] || {};
			return s || {};
		}
		function types( s ) { return [].concat( s.type || [] ); }
		// A pure tracked value {value, source_ref, status}; parameter rows (which also have a name) are handled separately.
		function isTracked( s ) { s = resolve( s ); return !! ( s.properties && s.properties.value && s.properties.status && s.properties.source_ref && ! s.properties.name ); }
		function labelFor( path, key ) {
			var generic = path.replace( /\[\d+\]/g, '' );
			return LABELS[ generic ] || LABELS[ key ] || humanize( key );
		}
		function hintFor( path ) { return HINTS[ path.replace( /\[\d+\]/g, '' ) ]; }
		function subsystemIds() { return ( data.subsystems || [] ).map( function ( s ) { return s.id; } ).filter( Boolean ); }

		// Empty value for a schema node (used when adding array items).
		function blank( s ) {
			s = resolve( s );
			if ( isTracked( s ) ) return { value: null, source_ref: null, status: 'extracted' };
			var t = types( s );
			if ( t.indexOf( 'object' ) !== -1 && s.properties ) {
				var o = {};
				Object.keys( s.properties ).forEach( function ( k ) {
					var c = resolve( s.properties[ k ] );
					if ( k === 'sim' ) o[ k ] = null;
					else if ( types( c ).indexOf( 'array' ) !== -1 ) o[ k ] = [];
					else if ( isTracked( c ) || ( types( c ).indexOf( 'object' ) !== -1 && types( c ).indexOf( 'null' ) === -1 ) ) o[ k ] = blank( c );
					else if ( types( c ).indexOf( 'boolean' ) !== -1 ) o[ k ] = false;
					else if ( c.enum && types( c ).indexOf( 'null' ) === -1 ) o[ k ] = c.enum[ 0 ];
					else if ( types( c ).indexOf( 'null' ) !== -1 ) o[ k ] = null;
					else o[ k ] = '';
				} );
				return o;
			}
			if ( t.indexOf( 'array' ) !== -1 ) return [];
			if ( s.enum && t.indexOf( 'null' ) === -1 ) return s.enum[ 0 ];
			return t.indexOf( 'null' ) !== -1 ? null : '';
		}

		// Names on the project: one box per person (or a group name). Kept as one line of text,
		// "Sara Ali, Omar Hassan, Lina Saeed", so the stored project stays the same shape.
		function creditField( path, value, set ) {
			var wrap = el( 'div', { class: 'uff-field uff-wide', 'data-path': path } );
			var first = nextId();
			wrap.appendChild( el( 'label', { for: first, class: 'uff-label', text: labelFor( path, 'credit' ) } ) );
			var box = el( 'div', { class: 'uff-names' } );
			var names = String( value || '' ).split( /\s*[,،]\s*|\s+(?:and|&|و)\s+/ ).map( function ( n ) { return n.trim(); } ).filter( Boolean );
			if ( ! names.length ) names = [ '' ];
			function save() {
				var v = names.map( function ( n ) { return n.trim(); } ).filter( Boolean ).join( ', ' );
				set( v === '' ? null : v ); changed();
			}
			function draw( focusLast ) {
				box.innerHTML = '';
				names.forEach( function ( n, i ) {
					var inp = el( 'input', { type: 'text', id: i === 0 ? first : nextId(), placeholder: i === 0 ? 'Full name, or a group name' : 'Full name', 'aria-label': 'Name ' + ( i + 1 ) } );
					inp.value = n;
					inp.addEventListener( 'input', function () { names[ i ] = inp.value.replace( /,/g, ' ' ); save(); } );
					inp.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); names.push( '' ); draw( true ); } } );
					var row = el( 'div', { class: 'uff-name' }, [ inp ] );
					if ( names.length > 1 ) {
						row.appendChild( el( 'button', { type: 'button', class: 'uff-remove', text: 'Remove', 'aria-label': 'Remove ' + ( n || 'this name' ),
							onclick: function () { names.splice( i, 1 ); draw(); save(); } } ) );
					}
					box.appendChild( row );
					if ( focusLast && i === names.length - 1 ) setTimeout( function () { inp.focus(); } );
				} );
				box.appendChild( el( 'button', { type: 'button', class: 'uff-add', text: '+ Add a person', onclick: function () { names.push( '' ); draw( true ); } } ) );
			}
			draw();
			wrap.appendChild( box );
			var hint = hintFor( path );
			if ( hint ) wrap.appendChild( el( 'small', { class: 'uff-hint', text: hint } ) );
			return wrap;
		}

		function field( path, key, s, value, set ) {
			if ( path === 'meta.credit' ) return creditField( path, value, set );
			s = resolve( s );
			var t = types( s ), id = nextId(), label = labelFor( path, key ), hint = hintFor( path );
			var wrap = el( 'div', { class: 'uff-field' + ( WIDE[ key ] ? ' uff-wide' : '' ), 'data-path': path } );
			var lab = el( 'label', { for: id, class: 'uff-label', text: label } );
			var nullable = t.indexOf( 'null' ) !== -1;
			var input;

			// Cross-references to subsystem ids.
			var ref = /^(connections\[\d+\]\.(from|to)|data_files\[\d+\]\.subsystem)$/.test( path );
			if ( ref || ( s.enum && t.indexOf( 'array' ) === -1 ) ) {
				var opts2 = ref ? subsystemIds() : s.enum.filter( function ( v ) { return v !== null; } );
				input = el( 'select', { id: id } );
				if ( nullable || ref ) input.appendChild( el( 'option', { value: '', text: '—' } ) );
				opts2.forEach( function ( v ) {
					var name = ref ? ( ( data.subsystems || [] ).filter( function ( x ) { return x.id === v; } )[ 0 ] || {} ).name || v : ( ENUM_LABELS[ v ] || humanize( v ) );
					input.appendChild( el( 'option', { value: v, text: name, selected: v === value } ) );
				} );
				input.addEventListener( 'change', function () { set( input.value === '' ? ( nullable ? null : '' ) : input.value ); changed(); } );
			} else if ( t.indexOf( 'boolean' ) !== -1 ) {
				input = el( 'input', { id: id, type: 'checkbox', checked: !! value } );
				input.addEventListener( 'change', function () { set( input.checked ); changed(); } );
			} else if ( t.indexOf( 'number' ) !== -1 || t.indexOf( 'integer' ) !== -1 ) {
				input = el( 'input', { id: id, type: 'number', step: t.indexOf( 'integer' ) !== -1 ? '1' : 'any', value: value === null || value === undefined ? '' : value, inputmode: 'decimal' } );
				input.addEventListener( 'input', function () {
					var v = input.value.trim();
					set( v === '' ? null : ( t.indexOf( 'integer' ) !== -1 && t.indexOf( 'number' ) === -1 ? parseInt( v, 10 ) : parseFloat( v ) ) );
					changed();
				} );
			} else {
				var long = WIDE[ key ];
				var isFile = /(^|\.)(file|map_file)$/.test( key ) || /code_files\[\d+\]$/.test( path );
				input = el( long ? 'textarea' : 'input', { id: id, rows: long ? 3 : null, type: long ? null : 'text', list: isFile ? 'uff-files' : null } );
				input.value = value === null || value === undefined ? '' : value;
				input.addEventListener( 'input', function () { var v = input.value; set( v.trim() === '' && nullable ? null : v ); changed(); } );
			}
			wrap.appendChild( lab );
			wrap.appendChild( input );
			if ( hint ) wrap.appendChild( el( 'small', { class: 'uff-hint', text: hint } ) );
			if ( nullable && ( value === null || value === '' ) && ! s.enum && t.indexOf( 'boolean' ) === -1 ) wrap.classList.add( 'is-empty' );
			return wrap;
		}

		function tracked( path, key, s, obj, unit ) {
			var box = el( 'div', { class: 'uff-tracked uff-status--' + obj.status, 'data-path': path } );
			var id = nextId();
			var sv = resolve( resolve( s ).properties.value );
			var integer = types( sv ).indexOf( 'integer' ) !== -1;
			var num = el( 'input', { id: id, type: 'number', step: integer ? '1' : 'any', value: obj.value === null ? '' : obj.value, inputmode: 'decimal', placeholder: 'not in the report' } );
			var src = el( 'input', { type: 'text', value: obj.source_ref || '', placeholder: 'e.g. p.4, Sec 3.2', 'aria-label': labelFor( path, key ) + ' — where in the report' } );
			function paint() {
				box.className = 'uff-tracked uff-status--' + obj.status + ( obj.value === null && obj.status !== 'missing_confirmed' ? ' is-missing' : '' );
				pills.forEach( function ( p ) { p.setAttribute( 'aria-pressed', p.dataset.s === obj.status ? 'true' : 'false' ); } );
			}
			num.addEventListener( 'input', function () {
				var v = num.value.trim();
				obj.value = v === '' ? null : ( integer ? parseInt( v, 10 ) : parseFloat( v ) );
				if ( obj.status === 'missing_confirmed' && obj.value !== null ) obj.status = 'confirmed';
				paint(); changed();
			} );
			src.addEventListener( 'input', function () { obj.source_ref = src.value.trim() || null; changed(); } );
			var pills = [ [ 'extracted', 'From the report' ], [ 'confirmed', '✓ Confirmed' ], [ 'missing_confirmed', 'Doesn’t apply' ] ].map( function ( p ) {
				return el( 'button', {
					type: 'button', class: 'uff-pill', 'data-s': p[ 0 ], text: p[ 1 ],
					onclick: function () {
						obj.status = p[ 0 ];
						if ( p[ 0 ] === 'missing_confirmed' ) { obj.value = null; num.value = ''; }
						paint(); changed();
					},
				} );
			} );
			var lbl = labelFor( path, key );
			var unitSuffix = ( unit && lbl.toLowerCase().indexOf( '(' + unit.toLowerCase() + ')' ) === -1 ) ? ' (' + unit + ')' : '';
			box.appendChild( el( 'label', { for: id, class: 'uff-label', text: lbl + unitSuffix } ) );
			box.appendChild( el( 'div', { class: 'uff-tracked-row' }, [ num, src ] ) );
			box.appendChild( el( 'div', { class: 'uff-pills', role: 'group', 'aria-label': 'Status' }, pills ) );
			paint();
			return box;
		}

		function stringList( path, key, s, arr ) {
			var box = el( 'div', { class: 'uff-field uff-wide', 'data-path': path } );
			var id = nextId();
			var itemS = resolve( s.items || {} );
			box.appendChild( el( 'label', { for: id, class: 'uff-label', text: labelFor( path, key ) } ) );
			if ( itemS.enum ) {
				var g = el( 'div', { class: 'uff-checks', id: id } );
				itemS.enum.forEach( function ( v ) {
					var cb = el( 'input', { type: 'checkbox', checked: arr.indexOf( v ) !== -1 } );
					cb.addEventListener( 'change', function () {
						var i = arr.indexOf( v );
						if ( cb.checked && i === -1 ) arr.push( v ); else if ( ! cb.checked && i !== -1 ) arr.splice( i, 1 );
						changed();
					} );
					g.appendChild( el( 'label', { class: 'uff-check' }, [ cb, ' ' + ( ENUM_LABELS[ v ] || humanize( v ) ) ] ) );
				} );
				box.appendChild( g );
				return box;
			}
			if ( key === 'used_by' ) {
				var g2 = el( 'div', { class: 'uff-checks', id: id } );
				( data.subsystems || [] ).forEach( function ( sub ) {
					var cb = el( 'input', { type: 'checkbox', checked: arr.indexOf( sub.id ) !== -1 } );
					cb.addEventListener( 'change', function () {
						var i = arr.indexOf( sub.id );
						if ( cb.checked && i === -1 ) arr.push( sub.id ); else if ( ! cb.checked && i !== -1 ) arr.splice( i, 1 );
						changed();
					} );
					g2.appendChild( el( 'label', { class: 'uff-check' }, [ cb, ' ' + ( sub.name || sub.id ) ] ) );
				} );
				box.appendChild( g2 );
				return box;
			}
			// One item per line.
			var ta = el( 'textarea', { id: id, rows: Math.max( 2, Math.min( 8, arr.length + 1 ) ) } );
			ta.value = arr.join( '\n' );
			ta.addEventListener( 'input', function () {
				var lines = ta.value.split( '\n' ).map( function ( l ) { return l.trim(); } ).filter( Boolean );
				arr.length = 0; Array.prototype.push.apply( arr, lines );
				if ( preview ) renderEq();
				changed();
			} );
			box.appendChild( ta );
			box.appendChild( el( 'small', { class: 'uff-hint', text: hintFor( path ) || 'One per line.' } ) );
			var preview = null;
			function renderEq() {
				preview.innerHTML = '';
				arr.forEach( function ( eq ) {
					var d = el( 'div', { class: 'uff-eq' } );
					try { window.katex.render( eq, d, { displayMode: true, throwOnError: false } ); } catch { d.textContent = eq; }
					preview.appendChild( d );
				} );
			}
			if ( key === 'equations' && window.katex ) {
				preview = el( 'div', { class: 'uff-eq-preview', 'aria-label': 'Equation preview' } );
				box.appendChild( preview );
				renderEq();
			}
			return box;
		}

		function itemTitle( item, i, key ) {
			if ( item && typeof item === 'object' ) {
				var t = item.name || item.metric || item.file || ( item.from && item.to ? item.from + ' → ' + item.to : '' ) || item.type || item.id;
				if ( t ) return t;
			}
			return humanize( key ).replace( /s$/, '' ) + ' ' + ( i + 1 );
		}

		function objectArray( path, key, s, arr ) {
			var itemS = resolve( s.items );
			var box = el( 'div', { class: 'uff-array', 'data-path': path } );
			function draw() {
				box.innerHTML = '';
				var head = el( 'div', { class: 'uff-array-head' }, [ el( 'h4', { class: 'uff-array-title', text: labelFor( path, key ) } ), el( 'span', { class: 'uff-count', text: String( arr.length ) } ) ] );
				box.appendChild( head );
				if ( hintFor( path ) ) box.appendChild( el( 'p', { class: 'uff-hint', text: hintFor( path ) } ) );
				arr.forEach( function ( item, i ) {
					var ipath = path + '[' + i + ']';
					var det = el( 'details', { class: 'uff-item', open: arr.length <= 3 || item.__open ? true : null, 'data-path': ipath } );
					var sum = el( 'summary', {}, [ mathify( el( 'span', { class: 'uff-item-title', text: itemTitle( item, i, key ) } ) ) ] );
					var rm = el( 'button', {
						type: 'button', class: 'uff-remove', text: 'Remove', 'aria-label': 'Remove ' + itemTitle( item, i, key ),
						onclick: function ( e ) { e.preventDefault(); arr.splice( i, 1 ); draw(); changed( true ); },
					} );
					sum.appendChild( rm );
					det.appendChild( sum );
					det.appendChild( objectFields( ipath, itemS, item ) );
					box.appendChild( det );
				} );
				box.appendChild( el( 'button', {
					type: 'button', class: 'uff-add', text: '+ Add ' + labelFor( path, key ).replace( /\s*\(.*\)$/, '' ).replace( /s$/, '' ).toLowerCase(),
					onclick: function () { var b = blank( itemS ); b.__open = true; arr.push( b ); draw(); changed( true ); },
				} ) );
			}
			draw();
			box.__redraw = draw;
			return box;
		}

		function objectFields( path, s, obj ) {
			s = resolve( s );
			var grid = el( 'div', { class: 'uff-grid' } );
			Object.keys( s.properties || {} ).forEach( function ( k ) {
				var cs = resolve( s.properties[ k ] ), t = types( cs ), p = path ? path + '.' + k : k;
				if ( HIDDEN[ k ] ) return;
				if ( obj[ k ] === undefined ) obj[ k ] = blank( cs );
				if ( isTracked( cs ) ) {
					if ( ! obj[ k ] || typeof obj[ k ] !== 'object' ) obj[ k ] = blank( cs );
					var unit = k === 'rate_hz' || k === 'sample_rate_hz' ? 'Hz' : ( obj.unit || '' );
					grid.appendChild( tracked( p, k, cs, obj[ k ], unit ) );
				} else if ( cs.properties && cs.properties.value && cs.properties.status && k !== 'value' ) {
					grid.appendChild( tracked( p, k, cs, obj[ k ] ) );
				} else if ( t.indexOf( 'array' ) !== -1 ) {
					if ( ! Array.isArray( obj[ k ] ) ) obj[ k ] = [];
					var its = resolve( cs.items || {} );
					if ( types( its ).indexOf( 'object' ) !== -1 ) grid.appendChild( objectArray( p, k, cs, obj[ k ] ) );
					else grid.appendChild( stringList( p, k, cs, obj[ k ] ) );
				} else if ( t.indexOf( 'object' ) !== -1 && cs.properties ) {
					var group = function () {
						var g = el( 'fieldset', { class: 'uff-group', 'data-path': p } );
						g.appendChild( el( 'legend', { text: labelFor( p, k ) } ) );
						g.appendChild( objectFields( p, cs, obj[ k ] ) );
						return g;
					};
					if ( t.indexOf( 'null' ) !== -1 && ( ! obj[ k ] || typeof obj[ k ] !== 'object' ) ) {
						// Optional section (a simulation, a headline): offered, not shown empty.
						obj[ k ] = null;
						var add = el( 'button', { type: 'button', class: 'uff-add', 'data-path': p, text: '+ Add ' + labelFor( p, k ).toLowerCase() } );
						add.addEventListener( 'click', function () { obj[ k ] = blank( cs ); add.replaceWith( group() ); changed(); } );
						grid.appendChild( add );
					} else {
						if ( ! obj[ k ] || typeof obj[ k ] !== 'object' ) obj[ k ] = blank( cs );
						grid.appendChild( group() );
					}
				} else {
					grid.appendChild( field( p, k, cs, obj[ k ], function ( v ) { obj[ k ] = v; } ) );
				}
			} );
			// Parameters are tracked rows without a nested object: { name, value, unit, source_ref, status }.
			if ( s.properties && s.properties.value && s.properties.status && s.properties.name ) {
				grid.innerHTML = '';
				grid.appendChild( field( path + '.name', 'name', s.properties.name, obj.name, function ( v ) { obj.name = v; } ) );
				grid.appendChild( field( path + '.unit', 'unit', s.properties.unit, obj.unit, function ( v ) { obj.unit = v; } ) );
				grid.appendChild( tracked( path, 'value', s, obj, obj.unit ) );
			}
			return grid;
		}

		// Sections = top-level keys.
		root.innerHTML = '';
		var dl = el( 'datalist', { id: 'uff-files' } );
		( opts.files || [] ).forEach( function ( f ) { dl.appendChild( el( 'option', { value: f } ) ); } );
		root.appendChild( dl );
		var sections = [];
		Object.keys( schema.properties ).forEach( function ( k ) {
			if ( HIDDEN[ k ] ) return;
			var s = resolve( schema.properties[ k ] );
			if ( data[ k ] === undefined || data[ k ] === null ) data[ k ] = blank( s );
			var sec = el( 'section', { class: 'uff-section', id: 'uff-sec-' + k, 'data-path': k } );
			var head = el( 'header', { class: 'uff-section-head' }, [ el( 'h3', { text: labelFor( k, k ) } ) ] );
			var confirmAll = el( 'button', {
				type: 'button', class: 'uff-confirm-all', text: 'Confirm all values here',
				onclick: function () {
					sec.querySelectorAll( '.uff-tracked' ).forEach( function ( b ) {
						var btn = b.querySelector( '.uff-pill[data-s="confirmed"]' );
						var input = b.querySelector( 'input[type=number]' );
						if ( btn && input && input.value !== '' ) btn.click();
					} );
				},
			} );
			head.appendChild( confirmAll );
			sec.appendChild( head );
			var body;
			if ( types( s ).indexOf( 'array' ) !== -1 ) body = objectArray( k, k, s, data[ k ] );
			else body = objectFields( k, s, data[ k ] );
			sec.appendChild( body );
			root.appendChild( sec );
			sections.push( sec );
		} );

		// Redraw reference-dependent parts when subsystems change (names/ids used elsewhere).
		var api = {
			getData: function () {
				return JSON.parse( JSON.stringify( data, function ( key, v ) { return key === '__open' ? undefined : v; } ) );
			},
			focusPath: function ( path ) {
				// Longest matching data-path wins.
				var best = null;
				root.querySelectorAll( '[data-path]' ).forEach( function ( n ) {
					var p = n.getAttribute( 'data-path' );
					if ( ( path === p || path.indexOf( p + '.' ) === 0 || path.indexOf( p + '[' ) === 0 ) && ( ! best || p.length > best.getAttribute( 'data-path' ).length ) ) best = n;
				} );
				if ( ! best ) return false;
				var d = best.closest( 'details' );
				while ( d ) { d.open = true; d = d.parentElement && d.parentElement.closest( 'details' ); }
				best.classList.add( 'uff-flash' );
				best.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				var f = best.querySelector( 'input,select,textarea' );
				if ( f ) setTimeout( function () { f.focus( { preventScroll: true } ); }, 350 );
				setTimeout( function () { best.classList.remove( 'uff-flash' ); }, 2200 );
				return true;
			},
			counts: function () {
				var c = { tracked: 0, confirmed: 0, missing: 0 };
				( function walk( n ) {
					if ( ! n || typeof n !== 'object' ) return;
					if ( 'value' in n && 'status' in n && 'source_ref' in n ) {
						c.tracked++;
						if ( n.status !== 'extracted' ) c.confirmed++; else if ( n.value === null ) c.missing++;
					}
					Object.keys( n ).forEach( function ( k ) { walk( n[ k ] ); } );
				} )( data );
				return c;
			},
		};
		return api;
	}

	window.UnfoldForm = { mount: mount };
} )();
