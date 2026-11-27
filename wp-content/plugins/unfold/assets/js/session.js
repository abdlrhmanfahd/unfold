/**
 * Recording replay: camera, LiDAR and IMU of one QCar 2 session, in sync on one clock.
 * Reads the static export of tools/session_export (manifest.json, imu.json, frames/, lidar/).
 * Nothing loads the whole session: frames and scans are fetched around the playhead.
 */
( function () {
	'use strict';

	const PREFETCH = 10;     // frames (and their scans) fetched ahead of the playhead
	const KEEP = 40;         // cached frames / scans kept around the playhead
	const COLORS = { yaw: '#0B8A7A', acc: '#D9480F', grid: '#E6EBEF', dim: '#566270', cursor: '#0F1720' };

	function lowerBound( items, t ) {        // last index with items[i].t <= t
		let lo = 0, hi = items.length - 1, ans = 0;
		while ( lo <= hi ) { const m = ( lo + hi ) >> 1; if ( items[ m ].t <= t ) { ans = m; lo = m + 1; } else hi = m - 1; }
		return ans;
	}
	function canvas2d( c ) {
		const dpr = Math.min( window.devicePixelRatio || 1, 2 ), w = c.clientWidth, h = c.clientHeight;
		if ( c.width !== Math.round( w * dpr ) || c.height !== Math.round( h * dpr ) ) { c.width = Math.round( w * dpr ); c.height = Math.round( h * dpr ); }
		const g = c.getContext( '2d' ); g.setTransform( dpr, 0, 0, dpr, 0, 0 ); return { g, w, h };
	}
	class Cache {                           // small LRU of promises keyed by index
		constructor( max, load ) { this.max = max; this.load = load; this.map = new Map(); }
		get( k ) {
			if ( this.map.has( k ) ) { const v = this.map.get( k ); this.map.delete( k ); this.map.set( k, v ); return v; }
			const v = this.load( k ); this.map.set( k, v );
			while ( this.map.size > this.max ) this.map.delete( this.map.keys().next().value );
			return v;
		}
		peek( k ) { return this.map.get( k ); }
	}
	// Height ramp: teal-blue low -> green -> amber -> red high. Reflectivity ramp: dark -> light.
	function ramp( u, out, o ) {
		u = Math.max( 0, Math.min( 1, u ) );
		const stops = [ [ 0.05, 0.35, 0.65 ], [ 0.05, 0.6, 0.5 ], [ 0.55, 0.75, 0.2 ], [ 0.95, 0.6, 0.15 ], [ 0.85, 0.2, 0.2 ] ];
		const x = u * ( stops.length - 1 ), i = Math.min( stops.length - 2, Math.floor( x ) ), f = x - i;
		for ( let k = 0; k < 3; k++ ) out[ o + k ] = stops[ i ][ k ] + ( stops[ i + 1 ][ k ] - stops[ i ][ k ] ) * f;
	}

	function mount( root ) {
		const base = root.dataset.base;
		const status = root.querySelector( '.ufsr-status' );
		const camCanvas = root.querySelector( '.ufsr-cam canvas' ), imuCanvas = root.querySelector( '.ufsr-imu canvas' );
		const holder = root.querySelector( '.ufsr-3d' );
		const slider = root.querySelector( '.ufsr-slider' ), timeOut = root.querySelector( '.ufsr-time' ), playBtn = root.querySelector( '.ufsr-play' );
		const narrow = () => root.clientWidth < 700;
		status.textContent = 'Loading the recording…';

		const json = r => { if ( ! r.ok ) throw new Error( 'HTTP ' + r.status ); return r.json(); };
		Promise.all( [ fetch( base + 'manifest.json' ).then( json ), fetch( base + 'imu.json' ).then( json ) ] ).then( ( [ M, IMU ] ) => {
			status.textContent = '';
			const frames = M.frames.items, scans = M.lidar.items;
			const duration = Math.max( frames[ frames.length - 1 ].t, IMU.t[ IMU.t.length - 1 ] );
			const R = M.level_frame.R_sensor_to_level;
			slider.max = duration.toFixed( 2 );
			let t = 0, playing = false, speed = 1, colorMode = 'height', shownFrame = -1, shownScan = -1, last = 0;

			/* ---------- data loaders ---------- */
			const images = new Cache( KEEP, i => new Promise( ( ok, fail ) => {
				const im = new Image(); im.decoding = 'async'; im.onload = () => ok( im ); im.onerror = fail; im.src = base + frames[ i ].file;
			} ) );
			const clouds = new Cache( KEEP, k => fetch( base + scans[ k ].file ).then( r => { if ( ! r.ok ) throw new Error( 'HTTP ' + r.status ); return r.arrayBuffer(); } ).then( buf => {
				const n = Math.floor( buf.byteLength / 7 );
				const X = new Int16Array( buf, 0, n ), Y = new Int16Array( buf, 2 * n, n ), Z = new Int16Array( buf, 4 * n, n ), RF = new Uint8Array( buf, 6 * n, n );
				const pos = new Float32Array( n * 3 ), hz = new Float32Array( n ), rf = new Float32Array( n );
				for ( let i = 0; i < n; i++ ) {
					const x = X[ i ] / 100, y = Y[ i ] / 100, z = Z[ i ] / 100;
					const lx = R[ 0 ][ 0 ] * x + R[ 0 ][ 1 ] * y + R[ 0 ][ 2 ] * z;   // level frame: x forward, y left, z up
					const ly = R[ 1 ][ 0 ] * x + R[ 1 ][ 1 ] * y + R[ 1 ][ 2 ] * z;
					const lz = R[ 2 ][ 0 ] * x + R[ 2 ][ 1 ] * y + R[ 2 ][ 2 ] * z;
					pos[ 3 * i ] = lx; pos[ 3 * i + 1 ] = lz; pos[ 3 * i + 2 ] = -ly;   // three.js: y up
					hz[ i ] = lz; rf[ i ] = RF[ i ];
				}
				return { n, pos, hz, rf };
			} ) );
			function prefetch( fi ) {
				for ( let i = fi; i < Math.min( frames.length, fi + PREFETCH ); i++ ) { images.get( i ); clouds.get( frames[ i ].scan ); }
			}

			/* ---------- camera ---------- */
			function drawCamera( fi ) {
				const p = images.get( fi );
				p.then( im => {
					if ( fi !== lowerBound( frames, t ) ) return;
					const { g, w, h } = canvas2d( camCanvas );
					const s = Math.min( w / im.width, h / im.height ), dw = im.width * s, dh = im.height * s;
					g.fillStyle = '#0F1720'; g.fillRect( 0, 0, w, h );
					g.drawImage( im, ( w - dw ) / 2, ( h - dh ) / 2, dw, dh );
					shownFrame = fi;
				} ).catch( () => { status.textContent = 'A camera frame could not be loaded.'; } );
			}

			/* ---------- LiDAR ---------- */
			const renderer = new THREE.WebGLRenderer( { antialias: true, alpha: true } );
			renderer.setPixelRatio( Math.min( window.devicePixelRatio || 1, 2 ) );
			holder.appendChild( renderer.domElement );
			const scene = new THREE.Scene();
			const camera = new THREE.PerspectiveCamera( 50, 1, 0.05, 200 );
			camera.position.set( -5.5, 4.2, 3.2 );
			const controls = new THREE.OrbitControls( camera, renderer.domElement );
			controls.target.set( 1.5, 0, 0 ); controls.enableDamping = true; controls.maxDistance = 60; controls.update();
			const maxN = M.lidar.max_points || 8000;
			const geom = new THREE.BufferGeometry();
			geom.setAttribute( 'position', new THREE.BufferAttribute( new Float32Array( maxN * 3 ), 3 ) );
			geom.setAttribute( 'color', new THREE.BufferAttribute( new Float32Array( maxN * 3 ), 3 ) );
			geom.setDrawRange( 0, 0 );
			const mat = new THREE.PointsMaterial( { size: 0.05, vertexColors: true, sizeAttenuation: true } );
			scene.add( new THREE.Points( geom, mat ) );
			// The car, at the LiDAR origin: QCar 2 footprint (0.39 x 0.21 m) sitting on the ground below the sensor.
			let ground = -0.32;
			const car = new THREE.Group();
			const body = new THREE.Mesh( new THREE.BoxGeometry( 0.39, 0.16, 0.21 ), new THREE.MeshBasicMaterial( { color: 0x0B8A7A, transparent: true, opacity: 0.85 } ) );
			const nose = new THREE.Mesh( new THREE.ConeGeometry( 0.06, 0.14, 16 ), new THREE.MeshBasicMaterial( { color: 0x0F1720 } ) );
			nose.rotation.z = -Math.PI / 2; nose.position.set( 0.27, 0, 0 );
			car.add( body, nose ); scene.add( car );
			const grid = new THREE.GridHelper( 20, 20, 0xC5CED8, 0xE1E6EB ); scene.add( grid );
			function placeGround( g ) { ground = g; car.position.set( -0.05, ground + 0.08, 0 ); grid.position.y = ground; }
			placeGround( ground );

			function paint( c ) {
				const P = geom.attributes.position.array, C = geom.attributes.color.array;
				P.set( c.pos.subarray( 0, Math.min( c.pos.length, P.length ) ) );
				const n = Math.min( c.n, maxN );
				for ( let i = 0; i < n; i++ ) {
					if ( colorMode === 'height' ) ramp( ( c.hz[ i ] - ground ) / 2.6, C, 3 * i );
					else { const r = c.rf[ i ]; ramp( r <= 150 ? Math.sqrt( r / 150 ) * 0.75 : 0.85 + ( r - 150 ) / 105 * 0.15, C, 3 * i ); } // Livox: 0-150 diffuse, 151-255 retro-reflective
				}
				geom.attributes.position.needsUpdate = true; geom.attributes.color.needsUpdate = true;
				geom.setDrawRange( 0, n ); geom.computeBoundingSphere();
			}
			let currentCloud = null;
			function drawLidar( k ) {
				clouds.get( k ).then( c => {
					if ( k !== frames[ lowerBound( frames, t ) ].scan ) return;
					if ( shownScan === -1 ) {   // first scan: find the floor (5th percentile of height 1-4 m away)
						const hs = []; for ( let i = 0; i < c.n; i++ ) { const r = Math.hypot( c.pos[ 3 * i ], c.pos[ 3 * i + 2 ] ); if ( r > 1 && r < 4 ) hs.push( c.hz[ i ] ); }
						if ( hs.length > 50 ) { hs.sort( ( a, b ) => a - b ); placeGround( hs[ Math.floor( hs.length * 0.05 ) ] ); }
					}
					currentCloud = c; paint( c ); shownScan = k;
				} ).catch( () => { status.textContent = 'A LiDAR scan could not be loaded.'; } );
			}
			function sizeLidar() {
				const w = holder.clientWidth, h = holder.clientHeight;
				if ( ! w || ! h ) return;
				renderer.setSize( w, h, false ); camera.aspect = w / h; camera.updateProjectionMatrix();
				mat.size = narrow() ? 0.035 : 0.05;
			}

			/* ---------- IMU plot ---------- */
			// Display only: a centred moving average takes out motor vibration; imu.json keeps the raw samples.
			const smooth = ( a, sec ) => {
				const k = Math.max( 1, Math.round( sec * IMU.rate_hz / 2 ) ), out = new Array( a.length );
				for ( let i = 0; i < a.length; i++ ) { let s = 0, n = 0; for ( let j = Math.max( 0, i - k ); j <= Math.min( a.length - 1, i + k ); j++ ) { s += a[ j ]; n++; } out[ i ] = s / n; }
				return out;
			};
			const yawDeg = smooth( IMU.yaw_rate.map( v => v * 180 / Math.PI ), 0.3 );
			const accRaw = IMU.fwd_accel, accS = smooth( accRaw, 0.6 );
			// Symmetric ranges so both zero lines sit on the middle grid line.
			const range = a => { let m = 0; a.forEach( v => { m = Math.max( m, Math.abs( v ) ); } ); m = m * 1.15 || 1; return [ -m, m ]; };
			const yawR = range( yawDeg ), accR = range( accS );
			function drawImu() {
				const { g, w, h } = canvas2d( imuCanvas ), L = 44, Rr = 44, T = 8, B = 20, W = w - L - Rr, H = h - T - B;
				const X = s => L + s / duration * W, Y = ( v, r ) => T + ( r[ 1 ] - v ) / ( r[ 1 ] - r[ 0 ] ) * H;
				g.clearRect( 0, 0, w, h ); g.font = '11px Onest, system-ui, sans-serif';
				g.strokeStyle = COLORS.grid; g.lineWidth = 1;
				for ( let k = 0; k <= 4; k++ ) { const yy = Math.round( T + k * H / 4 ) + 0.5; g.beginPath(); g.moveTo( L, yy ); g.lineTo( L + W, yy ); g.stroke(); }
				g.fillStyle = COLORS.dim; g.textBaseline = 'top'; g.textAlign = 'center';
				const step = duration / W * 60 > 5 ? 10 : 5; // keep tick labels ~60 px apart
				for ( let s = 0; s <= duration + 1e-6; s += step ) g.fillText( s + ' s', X( s ), T + H + 5 );
				g.textBaseline = 'middle';
				g.textAlign = 'right'; g.fillStyle = COLORS.yaw; [ yawR[ 1 ], ( yawR[ 0 ] + yawR[ 1 ] ) / 2, yawR[ 0 ] ].forEach( ( v, k ) => g.fillText( v.toFixed( 0 ), L - 6, T + k * H / 2 ) );
				g.textAlign = 'left'; g.fillStyle = COLORS.acc; [ accR[ 1 ], ( accR[ 0 ] + accR[ 1 ] ) / 2, accR[ 0 ] ].forEach( ( v, k ) => g.fillText( v.toFixed( 1 ), L + W + 6, T + k * H / 2 ) );
				const line = ( vals, r, color, wd, alpha ) => { g.globalAlpha = alpha; g.strokeStyle = color; g.lineWidth = wd; g.beginPath(); vals.forEach( ( v, i ) => { const x = X( IMU.t[ i ] ), y = Y( v, r ); i ? g.lineTo( x, y ) : g.moveTo( x, y ); } ); g.stroke(); g.globalAlpha = 1; };
				g.save(); g.beginPath(); g.rect( L, T, W, H ); g.clip();
				if ( W > 480 ) line( accRaw, accR, COLORS.acc, 1, 0.14 ); line( accS, accR, COLORS.acc, 2, 1 ); line( yawDeg, yawR, COLORS.yaw, 2, 1 );
				g.restore();
				const cx = Math.round( X( t ) ) + 0.5; g.strokeStyle = COLORS.cursor; g.lineWidth = 1.5; g.beginPath(); g.moveTo( cx, T ); g.lineTo( cx, T + H ); g.stroke();
				const j = Math.min( IMU.t.length - 1, Math.max( 0, Math.round( t * IMU.rate_hz ) ) );
				g.fillStyle = COLORS.cursor; g.textAlign = cx > L + W - 140 ? 'right' : 'left'; g.textBaseline = 'top';
				g.fillText( yawDeg[ j ].toFixed( 0 ) + ' °/s · ' + accS[ j ].toFixed( 2 ) + ' m/s²', cx + ( g.textAlign === 'left' ? 6 : -6 ), T + 2 );
			}
			function seekFromPlot( e ) {
				const r = imuCanvas.getBoundingClientRect(), L = 44, W = r.width - 88;
				setTime( ( e.clientX - r.left - L ) / W * duration );
			}
			let dragging = false;
			imuCanvas.addEventListener( 'pointerdown', e => { dragging = true; imuCanvas.setPointerCapture( e.pointerId ); seekFromPlot( e ); } );
			imuCanvas.addEventListener( 'pointermove', e => { if ( dragging ) seekFromPlot( e ); } );
			imuCanvas.addEventListener( 'pointerup', () => { dragging = false; } );
			imuCanvas.addEventListener( 'pointercancel', () => { dragging = false; } );

			/* ---------- timeline ---------- */
			function update() {
				const fi = lowerBound( frames, t );
				if ( fi !== shownFrame ) drawCamera( fi );
				if ( frames[ fi ].scan !== shownScan ) drawLidar( frames[ fi ].scan );
				prefetch( fi );
				drawImu();
				slider.value = t.toFixed( 2 );
				timeOut.textContent = t.toFixed( 1 ) + ' / ' + duration.toFixed( 1 ) + ' s';
			}
			function setTime( v ) { t = Math.max( 0, Math.min( duration, v ) ); update(); }
			function setPlaying( p ) {
				playing = p; if ( p && t >= duration - 0.01 ) t = 0;
				playBtn.classList.toggle( 'is-playing', p ); playBtn.setAttribute( 'aria-label', p ? 'Pause' : 'Play' );
				last = performance.now();
			}
			slider.addEventListener( 'input', () => setTime( +slider.value ) );
			playBtn.addEventListener( 'click', () => setPlaying( ! playing ) );
			root.querySelectorAll( '[data-speed]' ).forEach( b => b.addEventListener( 'click', () => {
				speed = +b.dataset.speed; root.querySelectorAll( '[data-speed]' ).forEach( x => x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' ) );
			} ) );
			root.querySelectorAll( '[data-color]' ).forEach( b => b.addEventListener( 'click', () => {
				colorMode = b.dataset.color; root.querySelectorAll( '[data-color]' ).forEach( x => x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' ) );
				if ( currentCloud ) paint( currentCloud );
			} ) );
			let visible = true;
			new IntersectionObserver( es => { visible = es[ 0 ].isIntersecting; } ).observe( root );
			new ResizeObserver( () => { sizeLidar(); shownFrame = -1; update(); } ).observe( root );
			function loop( now ) {
				const dt = Math.min( 0.1, ( now - last ) / 1000 ); last = now;
				if ( playing && visible ) {
					t += dt * speed;
					if ( t >= duration ) { t = duration; setPlaying( false ); }
					update();
				}
				if ( visible ) { controls.update(); renderer.render( scene, camera ); }
				requestAnimationFrame( loop );
			}
			sizeLidar(); setTime( 0 ); requestAnimationFrame( loop );
		} ).catch( () => { status.textContent = 'This recording could not be loaded.'; } );
	}

	document.querySelectorAll( '[data-unfold-session]' ).forEach( mount );
} )();
