/**
 * 3D simulation of a world: the LiDAR map of every ready recording, the QCar 2 driving its real
 * path, the live LiDAR scan around it and the front camera, on one timeline.
 *
 * Files per recording (made by tools/session_export/world_worker.py, served to instructors only):
 *   map.bin    planar int16 x[n], y[n], z[n] (cm), uint8 reflectivity[n], uint8 hits[n]; n = size / 8
 *   map.json   summary (floor_z, duration, ...)
 *   path.json  {t[], x[], y[], z[], yaw[]}
 *   frames.json + frames-NNN.bin   JPEGs back to back, located by {t[], c[], o[], s[]}
 *   scans.json  + scans-NNN.bin    planar int16 x[n], y[n], z[n] (cm) per scan, located by {t[], c[], o[], n[]}
 * World frame: x forward (at the first scan of the first recording), y left, z up, metres.
 * Three.js frame: x = x, y = z, z = -y.
 */
( function () {
	'use strict';
	const C = window.UNFOLD_WORLDS;
	const root = document.querySelector( '[data-ufw-sim]' );
	if ( ! C || ! root || ! window.THREE || ! window.UnfoldQCar ) return;

	const $ = s => root.querySelector( s );
	const status = $( '.ufw-status' ), holder = $( '.ufw-gl' ), inset = $( '.ufw-inset' ), insetCvs = inset.querySelector( 'canvas' );
	const slider = $( '.ufw-slider' ), timeOut = $( '.ufw-time' ), playBtn = $( '.ufw-play' ), drivesEl = $( '.ufw-drives' );
	const hudSpeed = root.querySelector( '[data-hud="speed"]' ), hudTurn = root.querySelector( '[data-hud="turn"]' );
	const narrow = () => window.matchMedia( '(max-width: 640px)' ).matches;
	const say = m => { status.textContent = m; };

	function fileUrl( d, name ) { const u = new URL( d.files, location.href ); u.searchParams.set( 'name', name ); return u.toString(); }
	function get( d, name, type ) {
		return fetch( fileUrl( d, name ), { credentials: 'same-origin', cache: 'no-cache', headers: { 'X-WP-Nonce': C.nonce } } ).then( r => {
			if ( ! r.ok ) throw new Error( name + ' (' + r.status + ')' );
			return type === 'json' ? r.json() : r.arrayBuffer();
		} );
	}
	const fmt = s => { s = Math.max( 0, s ); return Math.floor( s / 60 ) + ':' + String( Math.floor( s % 60 ) ).padStart( 2, '0' ); };
	function lowerBound( arr, v ) { let lo = 0, hi = arr.length - 1; if ( v <= arr[ 0 ] ) return 0; while ( lo < hi ) { const m = ( lo + hi + 1 ) >> 1; if ( arr[ m ] <= v ) lo = m; else hi = m - 1; } return lo; }
	// Height ramp, same family as the recording viewer: teal-blue -> green -> amber -> coral.
	function ramp( u, out, o ) {
		u = Math.max( 0, Math.min( 1, u ) );
		const st = [ [ 0, .16, .42, .62 ], [ .3, .13, .62, .55 ], [ .55, .55, .75, .3 ], [ .78, .95, .66, .2 ], [ 1, .92, .36, .25 ] ];
		let k = 1; while ( k < st.length - 1 && u > st[ k ][ 0 ] ) k++;
		const a = st[ k - 1 ], b = st[ k ], f = ( u - a[ 0 ] ) / ( b[ 0 ] - a[ 0 ] );
		out[ o ] = a[ 1 ] + ( b[ 1 ] - a[ 1 ] ) * f; out[ o + 1 ] = a[ 2 ] + ( b[ 2 ] - a[ 2 ] ) * f; out[ o + 2 ] = a[ 3 ] + ( b[ 3 ] - a[ 3 ] ) * f;
	}

	/* ---------- scene ---------- */
	const renderer = new THREE.WebGLRenderer( { antialias: true, alpha: true } );
	renderer.setPixelRatio( Math.min( window.devicePixelRatio || 1, 2 ) );
	renderer.outputEncoding = THREE.sRGBEncoding;
	renderer.localClippingEnabled = true;
	holder.appendChild( renderer.domElement );
	const scene = new THREE.Scene();
	const camera = new THREE.PerspectiveCamera( 60, 16 / 9, 0.05, 500 );
	const hemi = new THREE.HemisphereLight( 0xffffff, 0x334455, 0.9 ); scene.add( hemi );
	const sun = new THREE.DirectionalLight( 0xffffff, 0.9 ); sun.position.set( 3, 8, 4 ); scene.add( sun );
	// Clean look lighting: cool sky light from above, almost none from below, a soft key light,
	// and a dark vertical gradient behind everything.
	const LIGHT = {
		normal: { sky: 0xffffff, ground: 0x334455, hemi: 0.9, sun: 0.9 },
		clean: { sky: 0xC8D6E8, ground: 0x0A0C10, hemi: 1.15, sun: 0.35 },
	};
	const CLEAN_SKY = ( () => {
		const c = document.createElement( 'canvas' ); c.width = 2; c.height = 256;
		const x = c.getContext( '2d' ), gr = x.createLinearGradient( 0, 0, 0, 256 );
		gr.addColorStop( 0, '#07080B' ); gr.addColorStop( 0.55, '#14171D' ); gr.addColorStop( 1, '#232830' );
		x.fillStyle = gr; x.fillRect( 0, 0, 2, 256 );
		const t = new THREE.CanvasTexture( c ); t.encoding = THREE.sRGBEncoding; return t;
	} )();
	const controls = new THREE.OrbitControls( camera, renderer.domElement );
	controls.enableDamping = true; controls.dampingFactor = 0.1; controls.enabled = false;

	// World (x fwd, y left, z up) -> three (x, y up, z = -y)
	const B = new THREE.Matrix4().set( 1, 0, 0, 0, 0, 0, 1, 0, 0, -1, 0, 0, 0, 0, 0, 1 );
	const car = UnfoldQCar.model(); scene.add( car );
	const carGlow = new THREE.Mesh( new THREE.RingGeometry( 0.32, 0.42, 48 ), new THREE.MeshBasicMaterial( { color: 0x12B39C, transparent: true, opacity: 0.55, side: THREE.DoubleSide } ) );
	carGlow.rotation.x = -Math.PI / 2; car.add( carGlow ); carGlow.position.y = 0.01;

	// merged: all recordings on one timeline, in recording order ("All"); otherwise one recording alone.
	let merged = true;
	const durOf = d => d.path.t[ d.path.t.length - 1 ];
	const offsetOf = d => drives.slice( 0, drives.indexOf( d ) ).reduce( ( s, x ) => s + durOf( x ), 0 );
	const totalDur = () => drives.reduce( ( s, x ) => s + durOf( x ), 0 );
	let drives = [], active = null, t = 0, duration = 1, playing = false, speed = 1, view = 'chase', last = 0, mode = 'points';
	const CLEAN_BG = new THREE.Color( 0x15171B );
	const CLEAN_FOG = new THREE.Fog( 0x1A1E24, 10, 40 );
	const layers = { ceiling: false, scan: true, camera: true };
	const camPos = new THREE.Vector3( -3, 2, 0 ), camLook = new THREE.Vector3();

	function matrixFor( d ) {
		const T = d.T, M = new THREE.Matrix4().set( T[ 0 ], T[ 1 ], T[ 2 ], T[ 3 ], T[ 4 ], T[ 5 ], T[ 6 ], T[ 7 ], T[ 8 ], T[ 9 ], T[ 10 ], T[ 11 ], T[ 12 ], T[ 13 ], T[ 14 ], T[ 15 ] );
		return new THREE.Matrix4().multiplyMatrices( B, M );
	}
	function pointsObject( pos, col, size, M ) {
		const g = new THREE.BufferGeometry();
		g.setAttribute( 'position', new THREE.BufferAttribute( pos, 3 ) );
		if ( col ) g.setAttribute( 'color', new THREE.BufferAttribute( col, 3 ) );
		const m = new THREE.PointsMaterial( { size, sizeAttenuation: false, vertexColors: !! col, color: col ? 0xffffff : 0x5EEAD4, transparent: ! col, opacity: col ? 1 : 0.95, depthWrite: !! col } );
		const p = new THREE.Points( g, m ); p.matrixAutoUpdate = false; p.matrix.copy( M ); p.matrixWorldNeedsUpdate = true; p.frustumCulled = false;
		return p;
	}

	function buildMap( d, buf ) {
		const n = buf.byteLength / 8, x = new Int16Array( buf, 0, n ), y = new Int16Array( buf, 2 * n, n ), z = new Int16Array( buf, 4 * n, n );
		const floor = d.meta.floor_z != null ? d.meta.floor_z : -0.3, cut = floor + ( d.meta.ceiling_cut_m || 1.9 );
		let nc = 0; for ( let i = 0; i < n; i++ ) if ( z[ i ] / 100 > cut ) nc++;
		const rgb = d.rgbBuf && d.rgbBuf.byteLength === 4 * n ? new Uint8Array( d.rgbBuf ) : null;
		const part = m => ( { p: new Float32Array( 3 * m ), c: new Float32Array( 3 * m ), r: rgb ? new Float32Array( 3 * m ) : null, k: 0 } );
		const lo = part( n - nc ), hi = part( nc );
		for ( let i = 0; i < n; i++ ) {
			const zz = z[ i ] / 100, s = zz > cut ? hi : lo, o = 3 * s.k++;
			s.p[ o ] = x[ i ] / 100; s.p[ o + 1 ] = y[ i ] / 100; s.p[ o + 2 ] = zz;
			ramp( ( zz - floor ) / 2.6, s.c, o );
			if ( rgb ) {
				// camera colour (map_rgb.bin: r[n], g[n], b[n], seen[n]); points the camera never saw stay grey
				if ( rgb[ 3 * n + i ] ) { s.r[ o ] = rgb[ i ] / 255; s.r[ o + 1 ] = rgb[ n + i ] / 255; s.r[ o + 2 ] = rgb[ 2 * n + i ] / 255; }
				else { s.r[ o ] = s.r[ o + 1 ] = s.r[ o + 2 ] = 0.42; }
			}
		}
		const M = matrixFor( d ), size = ( narrow() ? 1.6 : 2 ) * Math.min( window.devicePixelRatio || 1, 2 ); // pixels
		d.mapLo = pointsObject( lo.p, lo.c, size, M ); d.mapHi = pointsObject( hi.p, hi.c, size, M );
		d.mapHi.visible = layers.ceiling;
		scene.add( d.mapLo, d.mapHi );
		if ( rgb ) {
			// same positions, camera colours
			const twin = ( src, col ) => { const g = new THREE.BufferGeometry(); g.setAttribute( 'position', src.geometry.attributes.position ); g.setAttribute( 'color', new THREE.BufferAttribute( col, 3 ) ); const o = new THREE.Points( g, src.material.clone() ); o.matrixAutoUpdate = false; o.matrix.copy( M ); o.matrixWorldNeedsUpdate = true; o.frustumCulled = false; o.visible = false; return o; };
			d.camLo = twin( d.mapLo, lo.r ); d.camHi = twin( d.mapHi, hi.r );
			scene.add( d.camLo, d.camHi );
		}
		d.clean = d.meshBuf ? buildSolid( d, M ) : d.planBuf ? buildClean( d.plan, d.planBuf, M ) : new THREE.Group();
		d.clean.visible = false;
		scene.add( d.clean );
	}

	/**
	 * Clean model from plan.bin (made by plan_session.py by tracing every laser ray): wall cells
	 * become blocks WALL_H tall (cut low so the inside stays visible), floor cells become tiles, both
	 * at the local floor height. Neighbouring cells in a row are merged into one block.
	 */
	/**
	 * Solid model from mesh.bin (made by mesh_session.py): a skin of triangles wrapped around the
	 * map points, coloured from the camera. Above the ceiling cut it is clipped away (unless the
	 * Ceiling layer is on) so the inside stays visible from above.
	 */
	function buildSolid( d, M ) {
		const { nv, nt } = d.mesh, buf = d.meshBuf;
		const p16 = new Int16Array( buf, 0, 3 * nv ), c8 = new Uint8Array( buf, 6 * nv, 3 * nv );
		const hasAo = !! d.mesh.ao, ao8 = hasAo ? new Uint8Array( buf, 9 * nv, nv ) : null;
		const idx = new Uint32Array( buf, Math.ceil( ( hasAo ? 10 : 9 ) * nv / 4 ) * 4, 3 * nt );
		const pos = new Float32Array( 3 * nv ), col = new Float32Array( 3 * nv );
		for ( let i = 0; i < 3 * nv; i++ ) pos[ i ] = p16[ i ] / 100;
		const g = new THREE.BufferGeometry();
		g.setAttribute( 'position', new THREE.BufferAttribute( pos, 3 ) );
		g.setIndex( new THREE.BufferAttribute( idx, 1 ) );
		g.computeVertexNormals();
		// Light the surface as if it were smoother than it is: average each corner's normal with its
		// neighbours a few times. The shape stays the same, small bumps stop catching the light.
		{
			const N = g.attributes.normal.array, T = new Float32Array( 3 * nv );
			for ( let it = 0; it < 6; it++ ) {
				T.set( N );
				for ( let f = 0; f < 3 * nt; f += 3 ) {
					const a = 3 * idx[ f ], b = 3 * idx[ f + 1 ], c = 3 * idx[ f + 2 ];
					for ( let k = 0; k < 3; k++ ) { const s = N[ a + k ] + N[ b + k ] + N[ c + k ]; T[ a + k ] += s; T[ b + k ] += s; T[ c + k ] += s; }
				}
				for ( let i = 0; i < 3 * nv; i += 3 ) { const l = Math.hypot( T[ i ], T[ i + 1 ], T[ i + 2 ] ) || 1; N[ i ] = T[ i ] / l; N[ i + 1 ] = T[ i + 1 ] / l; N[ i + 2 ] = T[ i + 2 ] / l; }
			}
		}
		const floor = d.meta.floor_z != null ? d.meta.floor_z : -0.3, cut = floor + ( d.meta.ceiling_cut_m || 1.9 );
		// One quiet palette instead of camera colours: ground dark, everything standing up light grey,
		// darker in corners (baked ambient occlusion) and near the ground.
		const nrm = g.attributes.normal.array;
		for ( let i = 0; i < nv; i++ ) {
			const up = Math.abs( nrm[ 3 * i + 2 ] ), h = pos[ 3 * i + 2 ] - floor, a = ao8 ? 0.35 + 0.65 * ao8[ i ] / 255 : 1;
			const ground = up > 0.8 && h < 0.3;
			const v = ( ground ? 0.022 : 0.2 * ( 0.65 + 0.35 * Math.min( 1, Math.max( 0, h / 1.4 ) ) ) ) * a;
			col[ 3 * i ] = v * 0.9; col[ 3 * i + 1 ] = v * 0.95; col[ 3 * i + 2 ] = v;
		}
		g.setAttribute( 'color', new THREE.BufferAttribute( col, 3 ) );
		d.cutY = new THREE.Vector3( 0, 0, cut ).applyMatrix4( M ).y;
		d.cutPlane = new THREE.Plane( new THREE.Vector3( 0, -1, 0 ), d.cutY );
		const mat = new THREE.MeshStandardMaterial( { vertexColors: true, roughness: 0.85, metalness: 0, side: THREE.DoubleSide, clippingPlanes: [ d.cutPlane ] } );
		// rim light: edges that turn away from the viewer glow faintly, so shapes read against the dark
		mat.onBeforeCompile = sh => {
			sh.fragmentShader = sh.fragmentShader.replace( '#include <dithering_fragment>',
				'float rim = 1.0 - abs( dot( normal, normalize( vViewPosition ) ) );\n' +
				'gl_FragColor.rgb += vec3( 0.55, 0.68, 0.85 ) * pow( rim, 4.0 ) * 0.12;\n#include <dithering_fragment>' );
		};
		const m = new THREE.Mesh( g, mat ); m.matrixAutoUpdate = false; m.matrix.copy( M ); m.matrixWorldNeedsUpdate = true; m.frustumCulled = false;
		const grp = new THREE.Group(); grp.add( m ); grp.visible = false;
		return grp;
	}

	const WALL_H = 1.1;
	function buildClean( plan, buf, M ) {
		const { W, H, cell, x0, y0 } = plan, st = new Uint8Array( buf, 0, W * H ), fz = new Int16Array( buf.slice( W * H, W * H + 2 * W * H ) );
		const P = [], N = [], WC = [], F = [], FN = [];
		const quad = ( A, Nn, a, b, c2, d2, nx, ny, nz ) => { A.push( ...a, ...b, ...c2, ...a, ...c2, ...d2 ); for ( let k = 0; k < 6; k++ ) Nn.push( nx, ny, nz ); if ( A === P ) for ( let k = 0; k < 6; k++ ) WC.push( ...( nz > 0 ? [ .16, .2, .25 ] : [ .66, .71, .76 ] ) ); };
		function box( xa, xb, ya, yb, za, zb ) {
			quad( P, N, [ xa, ya, zb ], [ xb, ya, zb ], [ xb, yb, zb ], [ xa, yb, zb ], 0, 0, 1 );
			quad( P, N, [ xa, ya, za ], [ xa, ya, zb ], [ xa, yb, zb ], [ xa, yb, za ], -1, 0, 0 );
			quad( P, N, [ xb, ya, za ], [ xb, yb, za ], [ xb, yb, zb ], [ xb, ya, zb ], 1, 0, 0 );
			quad( P, N, [ xa, ya, za ], [ xb, ya, za ], [ xb, ya, zb ], [ xa, ya, zb ], 0, -1, 0 );
			quad( P, N, [ xa, yb, za ], [ xa, yb, zb ], [ xb, yb, zb ], [ xb, yb, za ], 0, 1, 0 );
		}
		const cx = i => x0 + i * cell, cy = j => y0 + j * cell;
		for ( let j = 0; j < H; j++ ) {
			let i = 0;
			while ( i < W ) {
				const kind = st[ j * W + i ];
				if ( ! kind ) { i++; continue; }
				let e = i, sum = fz[ j * W + i ], lo = sum;
				while ( e + 1 < W && st[ j * W + e + 1 ] === kind && Math.abs( fz[ j * W + e + 1 ] - fz[ j * W + i ] ) < 4 ) { e++; sum += fz[ j * W + e ]; lo = Math.min( lo, fz[ j * W + e ] ); }
				const f = sum / ( e - i + 1 ) / 100;
				if ( kind === 2 ) box( cx( i ), cx( e + 1 ), cy( j ), cy( j + 1 ), lo / 100, f + WALL_H );
				else quad( F, FN, [ cx( i ), cy( j ), f ], [ cx( e + 1 ), cy( j ), f ], [ cx( e + 1 ), cy( j + 1 ), f ], [ cx( i ), cy( j + 1 ), f ], 0, 0, 1 );
				i = e + 1;
			}
		}
		const mesh = ( pos, nor, mat, col ) => {
			const g = new THREE.BufferGeometry();
			g.setAttribute( 'position', new THREE.BufferAttribute( new Float32Array( pos ), 3 ) );
			g.setAttribute( 'normal', new THREE.BufferAttribute( new Float32Array( nor ), 3 ) );
			if ( col ) g.setAttribute( 'color', new THREE.BufferAttribute( new Float32Array( col ), 3 ) );
			const m = new THREE.Mesh( g, mat ); m.matrixAutoUpdate = false; m.matrix.copy( M ); m.matrixWorldNeedsUpdate = true; m.frustumCulled = false;
			return m;
		};
		const grp = new THREE.Group();
		grp.add( mesh( F, FN, new THREE.MeshStandardMaterial( { color: 0xFFFFFF, roughness: 1, metalness: 0, side: THREE.DoubleSide, polygonOffset: true, polygonOffsetFactor: 1, polygonOffsetUnits: 1 } ) ) );
		grp.add( mesh( P, N, new THREE.MeshStandardMaterial( { color: 0xFFFFFF, vertexColors: true, roughness: .85, metalness: 0, side: THREE.DoubleSide } ), WC ) );
		grp.visible = false;
		return grp;
	}
	function buildTrail( d ) {
		const P = d.path, n = P.t.length, pos = new Float32Array( 3 * n );
		for ( let i = 0; i < n; i++ ) { pos[ 3 * i ] = P.x[ i ]; pos[ 3 * i + 1 ] = P.y[ i ]; pos[ 3 * i + 2 ] = groundAt( d, i ) + 0.02; }
		const g = new THREE.BufferGeometry(); g.setAttribute( 'position', new THREE.BufferAttribute( pos, 3 ) );
		const all = new THREE.Line( g, new THREE.LineBasicMaterial( { color: 0x5EEAD4, transparent: true, opacity: 0.25 } ) );
		const done = new THREE.Line( g.clone(), new THREE.LineBasicMaterial( { color: 0x12B39C } ) );
		[ all, done ].forEach( l => { l.matrixAutoUpdate = false; l.matrix.copy( matrixFor( d ) ); l.matrixWorldNeedsUpdate = true; l.frustumCulled = false; scene.add( l ); } );
		d.trailDone = done; d.trailAll = all;
	}

	/* ---------- chunks (frames, scans) ---------- */
	function chunkLoader( d, prefix, list ) {
		const cache = new Map();
		return function ( c ) {
			if ( c < 0 || c >= list.length ) return null;
			if ( ! cache.has( c ) ) {
				cache.set( c, get( d, list[ c ] ).catch( () => { cache.delete( c ); return null; } ) );
				if ( cache.size > 6 ) cache.delete( cache.keys().next().value );
			}
			return cache.get( c );
		};
	}
	const images = new Map();
	let shownFrame = -1, shownScan = -1, scanObj = null;
	function showFrame( d, i ) {
		if ( ! d.frames || i === shownFrame || ! layers.camera ) return;
		const F = d.frames, c = F.c[ i ], p = d.frameChunk( c );
		d.frameChunk( c + 1 ); // prefetch
		if ( ! p ) return;
		const want = i; shownFrame = i;
		const key = d.id + ':' + i;
		const draw = bmp => { if ( shownFrame !== want ) return; const g = insetCvs.getContext( '2d' ); if ( insetCvs.width !== bmp.width ) { insetCvs.width = bmp.width; insetCvs.height = bmp.height; } g.drawImage( bmp, 0, 0 ); };
		if ( images.has( key ) ) { draw( images.get( key ) ); return; }
		p.then( buf => {
			if ( ! buf ) return;
			return createImageBitmap( new Blob( [ new Uint8Array( buf, F.o[ i ], F.s[ i ] ) ], { type: 'image/jpeg' } ) ).then( bmp => {
				images.set( key, bmp ); if ( images.size > 60 ) { const k = images.keys().next().value; images.get( k ).close && images.get( k ).close(); images.delete( k ); }
				draw( bmp );
			} );
		} );
	}
	function showScan( d, i ) {
		if ( ! d.scans || i === shownScan ) return;
		const S = d.scans, c = S.c[ i ], p = d.scanChunk( c );
		d.scanChunk( c + 1 );
		if ( ! p ) return;
		const want = i; shownScan = i;
		p.then( buf => {
			if ( ! buf || shownScan !== want ) return;
			const n = S.n[ i ], o = S.o[ i ], x = new Int16Array( buf, o, n ), y = new Int16Array( buf, o + 2 * n, n ), z = new Int16Array( buf, o + 4 * n, n );
			const geo = scanObj.geometry, P = geo.attributes.position.array, m = Math.min( n, P.length / 3 );
			for ( let k = 0; k < m; k++ ) { P[ 3 * k ] = x[ k ] / 100; P[ 3 * k + 1 ] = y[ k ] / 100; P[ 3 * k + 2 ] = z[ k ] / 100; }
			geo.attributes.position.needsUpdate = true; geo.setDrawRange( 0, m );
			scanObj.matrix.copy( matrixFor( d ) ); scanObj.matrixWorldNeedsUpdate = true;
		} );
	}

	/* Floor under the car: the LiDAR sits a fixed height above it (measured by plan_session.py). */
	function groundAt( d, i ) {
		const h = d.meta.lidar_height_m;
		return h ? d.path.z[ i ] - h : ( d.meta.floor_z != null ? d.meta.floor_z : -0.3 );
	}

	/* ---------- pose at time ---------- */
	const tmp = new THREE.Vector3();
	function pose( d, s ) {
		const P = d.path, i = Math.min( P.t.length - 2, lowerBound( P.t, s ) ), f = Math.max( 0, Math.min( 1, ( s - P.t[ i ] ) / ( ( P.t[ i + 1 ] - P.t[ i ] ) || 1 ) ) );
		let dy = P.yaw[ i + 1 ] - P.yaw[ i ]; if ( dy > Math.PI ) dy -= 2 * Math.PI; if ( dy < -Math.PI ) dy += 2 * Math.PI;
		const j = Math.max( 0, i - 5 ), k = Math.min( P.t.length - 1, i + 5 ), dt = ( P.t[ k ] - P.t[ j ] ) || 1;
		let dyw = P.yaw[ k ] - P.yaw[ j ]; while ( dyw > Math.PI ) dyw -= 2 * Math.PI; while ( dyw < -Math.PI ) dyw += 2 * Math.PI;
		return { x: P.x[ i ] + ( P.x[ i + 1 ] - P.x[ i ] ) * f, y: P.y[ i ] + ( P.y[ i + 1 ] - P.y[ i ] ) * f, yaw: P.yaw[ i ] + dy * f, i,
			v: Math.hypot( P.x[ k ] - P.x[ j ], P.y[ k ] - P.y[ j ] ) / dt, w: dyw / dt };
	}

	function update() {
		const d = active; if ( ! d ) return;
		const p = pose( d, t );
		tmp.set( p.x, p.y, groundAt( d, p.i ) ).applyMatrix4( matrixFor( d ) );
		car.position.copy( tmp );
		car.rotation.set( 0, p.yaw + Math.atan2( d.T[ 4 ], d.T[ 0 ] ), 0 );
		d.trailDone.geometry.setDrawRange( 0, p.i + 1 );
		hudSpeed.textContent = p.v.toFixed( 2 ) + ' m/s';
		hudTurn.textContent = ( p.w * 180 / Math.PI ).toFixed( 0 ) + ' °/s turn';
		if ( d.frames && layers.camera ) showFrame( d, lowerBound( d.frames.t, t ) );
		if ( d.scans && layers.scan ) showScan( d, lowerBound( d.scans.t, t ) );
		const g = merged ? offsetOf( d ) + t : t, all = merged ? totalDur() : duration;
		slider.value = g.toFixed( 2 ); timeOut.textContent = fmt( g ) + ' / ' + fmt( all );
	}

	/* ---------- views ---------- */
	const fwd = new THREE.Vector3(), up = new THREE.Vector3( 0, 1, 0 );
	function placeCamera( snap ) {
		fwd.set( Math.cos( car.rotation.y ), 0, -Math.sin( car.rotation.y ) );
		const c = car.position, want = new THREE.Vector3(), look = new THREE.Vector3();
		if ( view === 'chase' ) { want.copy( c ).addScaledVector( fwd, -2.4 ).addScaledVector( up, 1.25 ); look.copy( c ).addScaledVector( fwd, 1.6 ).addScaledVector( up, 0.25 ); camera.fov = 60; }
		else if ( view === 'top' ) { want.copy( c ).addScaledVector( up, 16 ).addScaledVector( fwd, -0.01 ); look.copy( c ); camera.fov = 50; }
		else if ( view === 'driver' ) { want.copy( c ).addScaledVector( up, 0.22 ).addScaledVector( fwd, 0.22 ); look.copy( want ).addScaledVector( fwd, 5 ); camera.fov = 75; }
		else return;
		const k = snap ? 1 : view === 'driver' ? 0.5 : 0.12;
		camPos.lerp( want, k ); camLook.lerp( look, k );
		camera.position.copy( camPos ); camera.lookAt( camLook ); camera.updateProjectionMatrix();
		car.visible = view !== 'driver';
	}
	function setView( v ) {
		view = v;
		root.querySelectorAll( '[data-view]' ).forEach( b => b.setAttribute( 'aria-pressed', b.dataset.view === v ? 'true' : 'false' ) );
		controls.enabled = v === 'free';
		car.visible = v !== 'driver';
		if ( v === 'free' ) { controls.target.copy( car.position ); camera.fov = 60; camera.updateProjectionMatrix(); controls.update(); }
		else placeCamera( true );
	}

	function size() {
		const w = holder.clientWidth, h = holder.clientHeight; if ( ! w || ! h ) return;
		renderer.setSize( w, h, false ); camera.aspect = w / h; camera.updateProjectionMatrix();
	}

	function setPlaying( p ) {
		playing = p;
		if ( p && t >= duration - 0.05 ) {
			if ( merged && drives.length > 1 && drives.indexOf( active ) === drives.length - 1 ) { t = 0; activate( drives[ 0 ] ); }
			else if ( ! merged || drives.length === 1 ) t = 0;
		}
		playBtn.classList.toggle( 'is-playing', p ); playBtn.setAttribute( 'aria-label', p ? 'Pause' : 'Play' );
		last = performance.now();
	}

	function loop( now ) {
		requestAnimationFrame( loop );
		if ( ! active ) return;
		if ( playing ) {
			t += Math.min( 0.1, ( now - last ) / 1000 ) * speed;
			if ( t >= duration ) {
				// "All": carry on with the next recording (in recording order) as if it were one drive
				const next = merged ? drives[ drives.indexOf( active ) + 1 ] : null;
				if ( next ) { t = 0; activate( next ); } else { t = duration; setPlaying( false ); }
			}
			update();
		}
		last = now;
		if ( view === 'free' ) controls.update(); else placeCamera( false );
		draw();
	}

	/* Points, Clean, or both side by side (same camera, two viewports). */
	function show( m ) {
		// A recording not placed in the world yet is shown alone (it would sit on top of the others).
		const alone = active && active.placement === 'unaligned';
		drives.forEach( d => {
			if ( ! d.mapLo ) return;
			const on = alone ? d === active : d.placement !== 'unaligned';
			const pts = m === 'points' || ( m === 'camera' && ! d.camLo );
			d.mapLo.visible = on && pts; d.mapHi.visible = on && pts && layers.ceiling; d.clean.visible = on && m === 'clean';
			if ( d.cutPlane ) d.cutPlane.constant = layers.ceiling ? 1e6 : d.cutY;
			if ( d.camLo ) { d.camLo.visible = on && m === 'camera'; d.camHi.visible = on && m === 'camera' && layers.ceiling; }
			d.trailAll.visible = d.trailDone.visible = d === active;
		} );
		const solid = m === 'clean' && drives.some( d => d.meshBuf ), L = solid ? LIGHT.clean : LIGHT.normal;
		scene.background = solid ? CLEAN_SKY : m === 'clean' ? CLEAN_BG : null;
		scene.fog = solid ? CLEAN_FOG : null;
		hemi.color.set( L.sky ); hemi.groundColor.set( L.ground ); hemi.intensity = L.hemi; sun.intensity = L.sun;
		if ( scanObj ) { scanObj.material.color.set( 0x5EEAD4 ); scanObj.material.opacity = m === 'clean' ? 0.35 : 0.95; }
	}
	function draw() {
		const w = holder.clientWidth, h = holder.clientHeight;
		if ( mode !== 'split' ) { show( mode ); renderer.render( scene, camera ); return; }
		const half = Math.floor( w / 2 );
		camera.aspect = half / h; camera.updateProjectionMatrix();
		renderer.setScissorTest( true );
		[ [ 'points', 0 ], [ 'clean', half ] ].forEach( ( [ m, x0 ] ) => {
			show( m ); renderer.setViewport( x0, 0, w - half, h ); renderer.setScissor( x0, 0, w - half, h ); renderer.render( scene, camera );
		} );
		renderer.setScissorTest( false ); renderer.setViewport( 0, 0, w, h );
		camera.aspect = w / h; camera.updateProjectionMatrix();
	}
	function setMode( m ) {
		mode = m;
		root.querySelectorAll( '[data-mode]' ).forEach( b => b.setAttribute( 'aria-pressed', b.dataset.mode === m ? 'true' : 'false' ) );
		root.querySelector( '.ufw-stage' ).classList.toggle( 'is-clean', m === 'clean' );
		root.querySelector( '.ufw-stage' ).classList.toggle( 'is-split', m === 'split' );
		draw();
	}

	function activate( d ) {
		active = d; duration = durOf( d ); t = Math.min( t, duration );
		slider.max = ( merged ? totalDur() : duration ).toFixed( 2 ); shownFrame = shownScan = -1;
		drivesEl.querySelectorAll( 'button' ).forEach( b => b.setAttribute( 'aria-pressed', ( merged ? b.dataset.drive === 'all' : +b.dataset.drive === d.id ) ? 'true' : 'false' ) );
		inset.hidden = ! d.frames || ! layers.camera;
		say( d.placement === 'unaligned' ? 'This recording is not placed in the world yet, so it is shown on its own.' : '' );
		update(); placeCamera( true ); draw();
	}

	/* ---------- load ---------- */
	function loadDrive( d ) {
		return Promise.all( [ get( d, 'map.json', 'json' ), get( d, 'path.json', 'json' ), get( d, 'map.bin' ),
			get( d, 'frames.json', 'json' ).catch( () => null ), get( d, 'scans.json', 'json' ).catch( () => null ),
			get( d, 'plan.json', 'json' ).catch( () => null ), get( d, 'plan.bin' ).catch( () => null ), get( d, 'map_rgb.bin' ).catch( () => null ),
			get( d, 'mesh.json', 'json' ).catch( () => null ), get( d, 'mesh.bin' ).catch( () => null ) ] )
			.then( ( [ meta, path, map, frames, scans, plan, planBuf, rgbBuf, mesh, meshBuf ] ) => {
				Object.assign( d, { meta, path, frames, scans, plan, planBuf: plan ? planBuf : null, rgbBuf, mesh, meshBuf: mesh ? meshBuf : null } );
				buildMap( d, map ); buildTrail( d );
				if ( frames ) d.frameChunk = chunkLoader( d, 'frames', frames.chunks );
				if ( scans ) d.scanChunk = chunkLoader( d, 'scans', scans.chunks );
				return d;
			} );
	}

	fetch( C.rest + '/worlds/' + C.world + '/view', { credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } } ).then( r => {
		if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
		return r.json();
	} ).then( w => {
		drives = ( w.drives || [] ).filter( d => d.state === 'ready' );
		if ( ! drives.length ) { say( 'No recording of this world is ready yet.' ); return; }
		say( 'Loading the 3D map…' );
		return Promise.all( drives.map( loadDrive ) ).then( () => {
			// Recordings play in the order they were recorded on the car, not the order they were uploaded.
			drives.sort( ( a, b ) => ( a.meta.t0_epoch || 0 ) - ( b.meta.t0_epoch || 0 ) );
			const maxN = Math.max( 1, ...drives.map( d => ( d.scans && Array.isArray( d.scans.n ) ) ? d.scans.n.reduce( ( m, v ) => ( v > m ? v : m ), 1 ) : 1 ) );
			const g = new THREE.BufferGeometry(); g.setAttribute( 'position', new THREE.BufferAttribute( new Float32Array( 3 * maxN ), 3 ) ); g.setDrawRange( 0, 0 );
			scanObj = new THREE.Points( g, new THREE.PointsMaterial( { color: 0x5EEAD4, size: ( narrow() ? 2.4 : 3 ) * Math.min( window.devicePixelRatio || 1, 2 ), sizeAttenuation: false, transparent: true, opacity: 0.95, depthWrite: false } ) );
			scanObj.matrixAutoUpdate = false; scanObj.frustumCulled = false; scene.add( scanObj );
			drivesEl.innerHTML = drives.length > 1 ? '<button type="button" data-drive="all" aria-pressed="true">All</button>' + drives.map( d => '<button type="button" data-drive="' + d.id + '" aria-pressed="false">' + String( d.name ).replace( /[&<>"']/g, '' ) + '</button>' ).join( '' ) : '';
			say( '' ); size(); activate( drives[ 0 ] ); requestAnimationFrame( loop );
		} );
	} ).catch( e => say( 'The world could not be loaded: ' + e.message ) );

	/* ---------- controls ---------- */
	playBtn.addEventListener( 'click', () => setPlaying( ! playing ) );
	slider.addEventListener( 'input', () => {
		let g = +slider.value;
		if ( merged && drives.length > 1 ) {               // which recording this moment of "All" falls in
			let d = drives[ drives.length - 1 ];
			for ( const x of drives ) { if ( g < offsetOf( x ) + durOf( x ) ) { d = x; break; } }
			g -= offsetOf( d );
			if ( d !== active ) { t = g; activate( d ); return; }
		}
		t = g; update();
	} );
	root.querySelectorAll( '[data-speed]' ).forEach( b => b.addEventListener( 'click', () => {
		speed = +b.dataset.speed; root.querySelectorAll( '[data-speed]' ).forEach( x => x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' ) );
	} ) );
	root.querySelectorAll( '[data-view]' ).forEach( b => b.addEventListener( 'click', () => setView( b.dataset.view ) ) );
	root.querySelectorAll( '[data-mode]' ).forEach( b => b.addEventListener( 'click', () => setMode( b.dataset.mode ) ) );
	root.querySelectorAll( '[data-layer]' ).forEach( b => b.addEventListener( 'click', () => {
		const k = b.dataset.layer; layers[ k ] = ! layers[ k ]; b.setAttribute( 'aria-pressed', layers[ k ] ? 'true' : 'false' );
		if ( k === 'ceiling' ) show( mode === 'split' ? 'points' : mode );
		if ( k === 'scan' && scanObj ) { scanObj.visible = layers.scan; shownScan = -1; }
		if ( k === 'camera' ) { inset.hidden = ! layers.camera || ! ( active && active.frames ); shownFrame = -1; }
		update();
	} ) );
	drivesEl.addEventListener( 'click', e => {
		const b = e.target.closest( '[data-drive]' ); if ( ! b ) return;
		if ( b.dataset.drive === 'all' ) { merged = true; activate( active || drives[ 0 ] ); }
		else { merged = false; t = 0; activate( drives.find( d => d.id === +b.dataset.drive ) ); }
	} );
	renderer.domElement.addEventListener( 'pointerdown', () => { if ( view !== 'free' ) setView( 'free' ); } );
	new ResizeObserver( size ).observe( holder );
	// "3D simulation | Sensor replay" switch (worlds with a replay clip)
	const page = root.closest( '[data-ufw-page]' );
	if ( page ) page.querySelectorAll( '[data-panel]' ).forEach( b => b.addEventListener( 'click', () => {
		const want = b.dataset.panel;
		page.querySelectorAll( '[data-panel]' ).forEach( x => x.setAttribute( 'aria-selected', x === b ? 'true' : 'false' ) );
		page.querySelectorAll( '[data-panel-body]' ).forEach( x => { x.hidden = x.dataset.panelBody !== want; } );
		if ( want !== 'sim' ) setPlaying( false ); else size();
	} ) );
	document.addEventListener( 'keydown', e => {
		if ( e.target.closest( 'input, textarea, select' ) ) return;
		if ( e.code === 'Space' ) { e.preventDefault(); setPlaying( ! playing ); }
	} );
}() );
