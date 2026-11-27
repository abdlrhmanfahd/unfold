/**
 * The QCar 2 explorer: a 3D model of the car that unfolds into its parts.
 * Shared by the home page hero and every project page, so the car always looks and behaves the same.
 *
 *   const car = UnfoldQCar.mount(stageEl, { used: ['servo', ...], onPick: id => {} });
 *   car.focus(['servo','wheels'], chipEl)   highlight parts (lines drawn from chipEl if given)
 *   car.clear()                             remove the highlight
 *   car.unfold(true|false)
 *
 * Tapping a part calls onPick(id); tapping the highlighted part again, or empty space, calls onPick(null).
 * Requires THREE (r128) and THREE.OrbitControls.
 */
( function () {
	'use strict';

	const HW = {
		chassis: { name: 'Chassis', plain: 'Holds everything together.' },
		wheels: { name: 'Wheels', plain: 'The front pair steers.' },
		servo: { name: 'Steering servo', plain: 'Turns the front wheels.' },
		motor: { name: 'Drive motor', plain: 'Drives the car.' },
		encoder: { name: 'Encoder', plain: 'Measures speed.' },
		battery: { name: 'Battery', plain: 'Powers the car.' },
		board: { name: 'Main board', plain: 'Links sensors and motors to the computer.' },
		imu: { name: 'IMU', plain: 'Measures turning.' },
		compute: { name: 'Computer', plain: 'NVIDIA Jetson AGX Orin. Runs all the software.' },
		lidar: { name: 'Lidar', plain: 'Laser scan all around the car.' },
		depth: { name: 'Depth camera', plain: 'Sees colour and distance ahead.' },
		csi: { name: '360° cameras', plain: 'Four wide cameras, one per side.' },
		lights: { name: 'Lights', plain: 'Headlights, brake lights and RGB strips.' },
		wifi: { name: 'Wi-Fi', plain: 'Talks to the lab computer.' },
	};
	const ANCHOR = { wheels: [ 0, .45, 0 ], servo: [ 0, .25, 0 ], motor: [ 0, .25, 0 ], encoder: [ 0, .22, 0 ], battery: [ 0, .2, 0 ], board: [ -.7, .1, 0 ], imu: [ 0, .12, 0 ],
		compute: [ 0, .55, 0 ], lidar: [ 0, .55, 0 ], depth: [ 0, .28, 0 ], csi: [ 0, .17, 0 ], lights: [ 1.78, .1, .45 ], wifi: [ .85, 1.42, 0 ] };

	/* ---------- building blocks ---------- */
	const mat = ( color, o ) => new THREE.MeshStandardMaterial( Object.assign( { color, roughness: .55, metalness: .15, transparent: true, opacity: 1 }, o || {} ) );
	const box = ( w, h, d, m ) => new THREE.Mesh( new THREE.BoxGeometry( w, h, d ), m );
	const cyl = ( rt, rb, h, m, seg ) => new THREE.Mesh( new THREE.CylinderGeometry( rt, rb, h, seg || 32 ), m );
	const at = ( o, x, y, z ) => { o.position.set( x, y, z ); return o; };
	const group = ( ...kids ) => { const g = new THREE.Group(); kids.forEach( k => g.add( k ) ); return g; };
	function rrect( p, x, y, w, h, r ) { p.moveTo( x + r, y ); p.lineTo( x + w - r, y ); p.quadraticCurveTo( x + w, y, x + w, y + r ); p.lineTo( x + w, y + h - r ); p.quadraticCurveTo( x + w, y + h, x + w - r, y + h ); p.lineTo( x + r, y + h ); p.quadraticCurveTo( x, y + h, x, y + h - r ); p.lineTo( x, y + r ); p.quadraticCurveTo( x, y, x + r, y ); }
	function plate( len, wid, th, r, holes, m ) {
		const s = new THREE.Shape(); rrect( s, -len / 2, -wid / 2, len, wid, r );
		( holes || [] ).forEach( ( [ cx, cz, hl, hw ] ) => { const p = new THREE.Path(); rrect( p, cx - hl, cz - hw, hl * 2, hw * 2, Math.min( hl, hw ) * .6 ); s.holes.push( p ); } );
		const geo = new THREE.ExtrudeGeometry( s, { depth: th, bevelEnabled: false, curveSegments: 8 } ); geo.rotateX( Math.PI / 2 ); geo.translate( 0, th / 2, 0 );
		return new THREE.Mesh( geo, m );
	}
	function rbox( lx, ly, lz, r, m ) {
		const s = new THREE.Shape(); rrect( s, -lx / 2, -ly / 2, lx, ly, r );
		const geo = new THREE.ExtrudeGeometry( s, { depth: lz, bevelEnabled: false, curveSegments: 8 } ); geo.translate( 0, 0, -lz / 2 );
		return new THREE.Mesh( geo, m );
	}

	/* ---------- the QCar 2 (x forward, y up, z right; 1 unit = 10 cm), modelled on Quanser's product photos ---------- */
	function buildCar( scene, piece ) {
		const alu = mat( 0xB9C1C9, { metalness: .7, roughness: .3 } );
		const blk = () => mat( 0x15181B, { roughness: .55, metalness: .2 } );
		const lens = () => mat( 0x1E2A36, { roughness: .1, metalness: .6 } );

		{ const g = group( at( plate( 3.0, .95, .06, .2, [ [ .2, 0, .45, .22 ] ], mat( 0x1D2125, { roughness: .6, metalness: .3 } ) ), 0, .3, 0 ),
			at( box( .3, .34, .5, blk() ), 1.55, .5, 0 ), at( box( .3, .3, .5, blk() ), -1.55, .5, 0 ) );
		[ [ 1.45, .42 ], [ 1.45, -.42 ], [ -1.45, .42 ], [ -1.45, -.42 ], [ .3, .5 ], [ .3, -.5 ] ].forEach( ( [ x, z ] ) => g.add( at( cyl( .035, .035, .46, alu, 10 ), x, .55, z ) ) );
		piece( scene, 'chassis', g ); }
		{ const g = new THREE.Group(), red = mat( 0xC62828, { roughness: .35, metalness: .3 } );
			[ [ 1.12, 1 ], [ 1.12, -1 ], [ -1.12, 1 ], [ -1.12, -1 ] ].forEach( ( [ x, s ] ) => {
				const sh = group( at( cyl( .03, .03, .42, alu, 10 ), 0, 0, 0 ) );
				for ( let k = 0; k < 7; k++ ) { const r = new THREE.Mesh( new THREE.TorusGeometry( .055, .014, 6, 14 ), red ); r.rotation.x = Math.PI / 2; r.position.y = -.15 + k * .05; sh.add( r ); }
				sh.position.set( x, .52, s * .6 ); sh.rotation.x = s * .35; g.add( sh );
			} );
			piece( scene, 'chassis', g ); }
		{ const fm = mat( 0x1C1D1F, { roughness: 1 } );
			piece( scene, 'chassis', at( group( rbox( .36, .34, 1.45, .12, fm ) ), 2.0, .36, 0 ), [ .8, 0, 0 ], .05 );
			piece( scene, 'chassis', at( group( rbox( .26, .3, 1.3, .09, fm ) ), -1.95, .4, 0 ), [ -.8, 0, 0 ], .05 ); }
		const side = s => {
			const sh = new THREE.Shape();
			sh.moveTo( -1.85, .82 ); sh.lineTo( 1.85, .82 ); sh.quadraticCurveTo( 2.02, .84, 1.96, 1.0 );
			sh.lineTo( 1.75, 1.13 ); sh.lineTo( 1.12, 1.2 ); sh.lineTo( .64, 1.77 ); sh.lineTo( -.62, 1.77 );
			sh.lineTo( -1.06, 1.24 ); sh.lineTo( -1.7, 1.21 ); sh.quadraticCurveTo( -1.96, 1.18, -1.92, .98 ); sh.lineTo( -1.85, .82 );
			const hole = new THREE.Path(); hole.moveTo( -.8, 1.07 ); hole.lineTo( .95, 1.07 ); hole.lineTo( .5, 1.6 ); hole.lineTo( -.44, 1.6 ); hole.lineTo( -.8, 1.07 );
			sh.holes.push( hole );
			const geo = new THREE.ExtrudeGeometry( sh, { depth: .04, bevelEnabled: false, curveSegments: 10 } ); geo.translate( 0, 0, -.02 );
			const m = new THREE.Mesh( geo, mat( 0x101316, { roughness: .5, metalness: .25 } ) ); m.position.z = s * .68; return m;
		};
		piece( scene, 'chassis', group( side( 1 ) ), [ 0, 0, 1.35 ], .18 );
		piece( scene, 'chassis', group( side( -1 ) ), [ 0, 0, -1.35 ], .18 );
		{ const tp = group( at( plate( 1.75, 1.5, .045, .12, [], mat( 0x15181B, { roughness: .5, metalness: .3 } ) ), 0, 0, 0 ) );
			[ [ .75, .62 ], [ .75, -.62 ], [ -.75, .62 ], [ -.75, -.62 ] ].forEach( ( [ x, z ] ) => { const n = cyl( .055, .055, .1, alu, 12 ); n.position.set( x, .07, z ); tp.add( n ); } );
			piece( scene, 'chassis', at( tp, .2, 1.795, 0 ), [ 0, 1.2, 0 ], .26 ); }

		[ [ 1.28, 1 ], [ 1.28, -1 ], [ -1.28, 1 ], [ -1.28, -1 ] ].forEach( ( [ x, s ], i ) => {
			const tm = mat( 0x141618, { roughness: .85 } ), chrome = mat( 0xD5D9DE, { metalness: .75, roughness: .2 } ), dark = mat( 0x2A2F35, { metalness: .5, roughness: .35 } );
			const pts = [ [ .215, -.13 ], [ .31, -.13 ], [ .332, -.105 ], [ .332, .105 ], [ .31, .13 ], [ .215, .13 ] ].map( ( [ r, y ] ) => new THREE.Vector2( r, y ) );
			const tg = new THREE.LatheGeometry( pts, 56 ); tg.rotateX( Math.PI / 2 );
			const w = group( new THREE.Mesh( tg, tm ) );
			[ -.06, .06 ].forEach( z => { const gr = new THREE.Mesh( new THREE.TorusGeometry( .333, .007, 4, 56 ), mat( 0x08090A, { roughness: 1 } ) ); gr.position.z = z; w.add( gr ); } );
			const face = s * .11;
			const back = cyl( .215, .215, .02, dark, 40 ); back.rotation.x = Math.PI / 2; back.position.z = -s * .02; w.add( back );
			const lip = new THREE.Mesh( new THREE.TorusGeometry( .212, .022, 8, 48 ), chrome ); lip.position.z = face; w.add( lip );
			for ( let k = 0; k < 5; k++ ) for ( const d of [ -.12, .12 ] ) { const a = k / 5 * Math.PI * 2 + d, sp = box( .19, .028, .025, chrome ); sp.position.set( Math.cos( a ) * .11, Math.sin( a ) * .11, face ); sp.rotation.z = a; w.add( sp ); }
			const nut = cyl( .045, .045, .06, chrome, 6 ); nut.rotation.x = Math.PI / 2; nut.position.z = face; w.add( nut );
			const cal = box( .08, .12, .05, mat( 0xD32F2F, { roughness: .4 } ) ); cal.position.set( .12, .08, face - s * .05 ); w.add( cal );
			piece( scene, 'wheels', at( w, x, .33, s * .92 ), [ 0, 0, s * 1.0 ], .05 * i );
		} );

		piece( scene, 'battery', at( group( rbox( 2.1, .17, .5, .05, mat( 0x6E757C, { roughness: .55, metalness: .2 } ) ) ), -.1, .17, -.1 ), [ 0, 0, 1.95 ], .08 );
		{ const can = cyl( .15, .15, .45, mat( 0xB8BEC4, { metalness: .7, roughness: .28 } ), 32 ); can.rotation.z = Math.PI / 2;
			const end = cyl( .155, .155, .06, mat( 0x2B3036 ), 32 ); end.rotation.z = Math.PI / 2; end.position.x = .25;
			piece( scene, 'motor', at( group( can, end ), -.45, .5, -.2 ), [ -.2, -.15, -1.75 ], .12 ); }
		{ const e = cyl( .12, .12, .08, mat( 0x0B8A7A, { metalness: .3, roughness: .4 } ), 28 ); e.rotation.z = Math.PI / 2;
			const pcb = box( .03, .22, .22, mat( 0x1C3D57 ) ); pcb.position.x = -.05;
			piece( scene, 'encoder', at( group( e, pcb ), -.72, .5, -.2 ), [ -1.15, .3, -2.05 ], .16 ); }
		piece( scene, 'servo', at( group( at( box( .36, .22, .42, blk() ), 0, 0, 0 ), at( cyl( .06, .06, .1, alu, 16 ), .08, .15, 0 ), at( box( .05, .04, 1.3, alu ), .25, -.05, 0 ) ), .95, .48, .15 ), [ .9, -.05, 1.75 ], .1 );

		{ const g = group( at( box( 3.55, .05, 1.3, mat( 0x0E1114, { roughness: .45 } ) ), 0, 0, 0 ) );
			[ [ .4, .3, .3, .2 ], [ -.3, -.35, .22, .18 ], [ .65, -.42, .2, .2 ], [ -.1, .38, .18, .14 ] ].forEach( ( [ x, z, w, d ] ) => g.add( at( box( w, .07, d, mat( 0x23292E ) ), x, .05, z ) ) );
			g.add( at( box( .22, .16, .2, alu ), .15, .1, .45 ) );
			g.add( at( box( .12, .1, .16, mat( 0xF0A020, { roughness: .4 } ) ), .9, .07, .5 ) );
			const lcd = group( at( box( .5, .04, .42, mat( 0x0B0D0F ) ), 0, 0, 0 ), at( box( .44, .012, .34, mat( 0x0D2233, { emissive: 0x2C7DA0, emissiveIntensity: .5, roughness: .2 } ) ), 0, .026, 0 ) );
			lcd.position.set( 1.12, .05, -.05 ); g.add( lcd );
			piece( scene, 'board', at( g, 0, .8, 0 ), [ 0, .6, 0 ], .14 ); }
		piece( scene, 'imu', at( group( at( box( .16, .05, .16, mat( 0xE0A526, { roughness: .4 } ) ), 0, 0, 0 ) ), .55, .85, .05 ), [ 0, 1.0, 0 ], .2 );
		{ const g = new THREE.Group(), N = 16;
			for ( const s of [ 1, -1 ] ) for ( let k = 0; k < N; k++ ) {
				const c = new THREE.Color().setHSL( .74 - k / N * .32, .8, .62 ).getHex();
				g.add( at( box( 3.5 / N, .035, .02, mat( c, { emissive: c, emissiveIntensity: .9 } ) ), -1.75 + 3.5 / N * ( k + .5 ), -.05, s * .66 ) );
			}
			[ -.52, -.4, -.28, .28, .4, .52 ].forEach( z => {
				g.add( at( box( .04, .05, .07, mat( 0xFFFFFF, { emissive: 0xFFF6E0, emissiveIntensity: 1 } ) ), 1.78, .03, z ) );
				g.add( at( box( .04, .05, .07, mat( 0xFF3B30, { emissive: 0xFF2D20, emissiveIntensity: 1 } ) ), -1.78, .03, z ) );
			} );
			piece( scene, 'lights', at( g, 0, .8, 0 ), [ .3, .42, 0 ], .14 ); }
		{ const am = mat( 0x111316, { roughness: .6 } ), g = new THREE.Group();
			[ .26, -.26 ].forEach( z => { const a = cyl( .045, .038, .75, am, 14 ); a.rotation.z = Math.PI / 2; a.position.set( .75, 1.3, z ); g.add( a );
				const k = cyl( .06, .06, .1, am, 14 ); k.rotation.z = Math.PI / 2; k.position.set( .33, 1.3, z ); g.add( k ); } );
			piece( scene, 'wifi', g, [ .55, .9, 0 ], .22 ); }
		{ const g = group( at( box( .95, .12, .95, mat( 0x1B1F23 ) ), 0, 0, 0 ) );
			for ( let k = 0; k < 16; k++ ) g.add( at( box( .9, .2, .025, mat( 0x2B3036, { metalness: .5, roughness: .4 } ) ), 0, .16, -.44 + k * .058 ) );
			g.add( at( box( .95, .1, .95, mat( 0x121417 ) ), 0, .31, 0 ) );
			[ .12, .22, .32 ].forEach( r => { const tr = new THREE.Mesh( new THREE.TorusGeometry( r, .012, 6, 32 ), alu ); tr.rotation.x = Math.PI / 2; tr.position.y = .37; g.add( tr ); } );
			const hub = cyl( .08, .08, .03, mat( 0x0B8A7A ), 20 ); hub.position.y = .37; g.add( hub );
			piece( scene, 'compute', at( g, -1.05, .98, 0 ), [ -.55, 1.3, 0 ], .28 ); }
		{ const k = mat( 0x17191C, { roughness: .45 } );
			const b = cyl( .33, .33, .18, k, 40 ); b.position.y = .09;
			const ring = cyl( .336, .336, .06, mat( 0x6C4CE0, { emissive: 0x6C4CE0, emissiveIntensity: .55, roughness: .3 } ), 40 ); ring.position.y = .21;
			const top = cyl( .3, .33, .1, k, 40 ); top.position.y = .29;
			const cap = new THREE.Mesh( new THREE.SphereGeometry( .3, 32, 12, 0, Math.PI * 2, 0, Math.PI / 2 ), k ); cap.scale.y = .22; cap.position.y = .34;
			piece( scene, 'lidar', at( group( b, ring, top, cap ), -.05, 1.84, 0 ), [ 0, 2.05, 0 ], .34 ); }
		{ const sm = mat( 0xC9CED3, { metalness: .65, roughness: .3 } );
			const e1 = cyl( .12, .12, .22, sm, 24 ); e1.rotation.z = Math.PI / 2; e1.position.z = .35;
			const e2 = cyl( .12, .12, .22, sm, 24 ); e2.rotation.z = Math.PI / 2; e2.position.z = -.35;
			const face = box( .01, .19, .84, mat( 0x0B0D0F, { roughness: .2 } ) ); face.position.x = .112;
			const g = group( box( .22, .24, .7, sm ), e1, e2, face );
			[ [ -.3, .045 ], [ -.1, .065 ], [ .12, .045 ], [ .32, .035 ] ].forEach( ( [ z, r ] ) => { const l = cyl( r, r, .03, lens(), 18 ); l.rotation.z = Math.PI / 2; l.position.set( .12, 0, z ); g.add( l ); } );
			piece( scene, 'depth', at( g, .85, 1.96, 0 ), [ 1.45, 1.35, 0 ], .3 ); }
		const csi = ( x, y, z, r ) => { const g = group( box( .15, .15, .15, mat( 0x111316 ) ) );
			const l = cyl( .05, .05, .05, lens(), 18 ); l.rotation.z = Math.PI / 2; l.position.x = .09; g.add( l );
			g.rotation.y = -r; g.position.set( x, y, z ); return g; };
		piece( scene, 'csi', csi( 1.6, .94, 0, 0 ), [ .95, .5, 0 ], .3 );
		piece( scene, 'csi', csi( -1.62, .94, 0, Math.PI ), [ -.95, .5, 0 ], .31 );
		piece( scene, 'csi', csi( .15, .95, .78, Math.PI / 2 ), [ 0, .25, 2.05 ], .32 );
		piece( scene, 'csi', csi( .15, .95, -.78, -Math.PI / 2 ), [ 0, .25, -2.05 ], .33 );
	}

	/** The QCar 2 as one THREE.Group in metres (x forward, y up, z right; origin under the car's centre, on the ground). */
	function model() {
		const g = new THREE.Group(), inner = new THREE.Group();
		buildCar( inner, ( scene, id, obj ) => { obj.userData.hw = id; scene.add( obj ); } );
		inner.scale.setScalar( 0.1 ); g.add( inner );
		return g;
	}

	function mount( stage, opts ) {
		opts = opts || {};
		const used = opts.used ? new Set( opts.used ) : null;
		stage.classList.add( 'ufq' );
		const cvs = document.createElement( 'canvas' ); cvs.className = 'ufq-gl'; cvs.setAttribute( 'aria-label', 'QCar 2. Drag to turn it, tap a part.' );
		const leaders = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' ); leaders.setAttribute( 'class', 'ufq-leaders' ); leaders.setAttribute( 'aria-hidden', 'true' );
		const labelsEl = document.createElement( 'div' ); labelsEl.className = 'ufq-labels';
		const dock = document.createElement( 'div' ); dock.className = 'ufq-dock';
		dock.innerHTML = '<span>Assembled</span><input type="range" class="ufq-slider" min="0" max="1" step="0.001" value="0" aria-label="Unfold the car"><span>Unfolded</span><button class="ufq-btn" type="button">Unfold</button>';
		stage.prepend( cvs, leaders, labelsEl );
		stage.append( dock );
		const slider = dock.querySelector( '.ufq-slider' ), btn = dock.querySelector( '.ufq-btn' );

		let t = 0, target = 0, autoSpin = true, userZoomed = false, focusSet = null, focusFrom = null, picked = null, pulse = 0;
		const parts = {};
		let three = null;

		function piece( scene, id, obj, ex, delay ) {
			const p = parts[ id ] || ( parts[ id ] = { pieces: [], mats: [] } );
			obj.traverse( o => { if ( o.isMesh ) { o.castShadow = true; o.receiveShadow = true; o.userData.hw = id; p.mats.push( o.material ); } } );
			p.pieces.push( { obj, base: obj.position.clone(), ex: new THREE.Vector3( ...( ex || [ 0, 0, 0 ] ) ), delay: delay || 0 } );
			scene.add( obj );
		}

		/* ---------- scene ---------- */
		function init() {
			const renderer = new THREE.WebGLRenderer( { canvas: cvs, antialias: true, alpha: true } );
			renderer.setPixelRatio( Math.min( window.devicePixelRatio || 1, 2 ) );
			renderer.shadowMap.enabled = true; renderer.shadowMap.type = THREE.PCFSoftShadowMap; renderer.outputEncoding = THREE.sRGBEncoding;
			const scene = new THREE.Scene();
			const camera = new THREE.PerspectiveCamera( 32, 1, 0.1, 100 );
			camera.position.set( 7.2, 4.6, 7.6 );
			const controls = new THREE.OrbitControls( camera, cvs );
			controls.enableDamping = true; controls.enablePan = false; controls.enableZoom = !! opts.zoom; controls.minDistance = 4.5; controls.maxDistance = 20;
			controls.maxPolarAngle = 1.48; controls.target.set( 0, 1.0, 0 );
			controls.addEventListener( 'start', () => { autoSpin = false; } );
			scene.add( new THREE.HemisphereLight( 0xffffff, 0xb9c4ce, 0.95 ) );
			const sun = new THREE.DirectionalLight( 0xffffff, 1.05 ); sun.position.set( 4, 9, 5 ); sun.castShadow = true; sun.shadow.mapSize.set( 1024, 1024 );
			Object.assign( sun.shadow.camera, { left: -6, right: 6, top: 6, bottom: -6, near: 1, far: 25 } ); scene.add( sun );
			const fill = new THREE.DirectionalLight( 0xdff3ef, 0.35 ); fill.position.set( -6, 3, -4 ); scene.add( fill );
			const ground = new THREE.Mesh( new THREE.PlaneGeometry( 60, 60 ), new THREE.ShadowMaterial( { opacity: 0.13 } ) );
			ground.rotation.x = -Math.PI / 2; ground.receiveShadow = true; scene.add( ground );
			const grid = new THREE.GridHelper( 24, 48, 0xc5ced8, 0xd6dde3 ); grid.material.transparent = true; grid.material.opacity = .55; grid.position.y = .001; scene.add( grid );
			buildCar( scene, piece );
			three = { renderer, scene, camera, controls, ray: new THREE.Raycaster(), baseDist: camera.position.distanceTo( controls.target ), v: new THREE.Vector3() };
			resize(); new ResizeObserver( resize ).observe( stage );
		}
		function resize() {
			if ( ! three ) return;
			const w = stage.clientWidth, h = stage.clientHeight;
			if ( ! w || ! h ) return; // Hidden (e.g. a closed overlay): keep the last size.
			three.renderer.setSize( w, h, false ); three.camera.aspect = w / h; three.camera.fov = w < 600 ? 42 : 32; three.camera.updateProjectionMatrix();
			leaders.setAttribute( 'viewBox', '0 0 ' + w + ' ' + h );
		}
		function buildLabels() {
			Object.keys( HW ).forEach( id => {
				const b = document.createElement( 'button' );
				b.type = 'button'; b.className = 'ufq-hw' + ( used ? ( used.has( id ) ? ' is-used' : ' is-unused' ) : '' ); b.textContent = HW[ id ].name;
				b.addEventListener( 'click', e => { e.stopPropagation(); pick( id ); } );
				labelsEl.appendChild( b ); parts[ id ].label = b;
			} );
		}

		/* ---------- behaviour ---------- */
		const ease = x => x < .5 ? 4 * x * x * x : 1 - Math.pow( -2 * x + 2, 3 ) / 2;
		function applyExplode() {
			for ( const id in parts ) for ( const pc of parts[ id ].pieces ) {
				const k = ease( Math.min( 1, Math.max( 0, ( t - pc.delay ) / ( 1 - .36 ) ) ) );
				pc.obj.position.copy( pc.base ).addScaledVector( pc.ex, k );
			}
		}
		function placeLabels() {
			const w = stage.clientWidth, h = stage.clientHeight, show = Math.max( 0, Math.min( 1, ( t - .55 ) / .35 ) ), v = three.v;
			for ( const id in parts ) {
				const p = parts[ id ]; if ( ! p.label ) continue;
				if ( id === 'chassis' ) v.set( -.9, .42, 1.05 ); else { const a = ANCHOR[ id ] || [ 0, .3, 0 ]; v.set( a[ 0 ], a[ 1 ], a[ 2 ] ).add( p.pieces[ 0 ].obj.position ); }
				v.project( three.camera );
				p.sx = ( v.x * .5 + .5 ) * w; p.sy = ( -v.y * .5 + .5 ) * h;
				const vis = ( focusSet && ! focusSet.has( id ) ? .35 : 1 ) * show;
				p.label.style.transform = 'translate(' + p.sx + 'px,' + p.sy + 'px) translate(-50%,-50%)';
				p.label.style.opacity = vis.toFixed( 2 );
				p.label.style.pointerEvents = show > .5 ? 'auto' : 'none';
				p.label.tabIndex = show > .5 ? 0 : -1;
				p.label.classList.toggle( 'is-on', !! ( focusSet && focusSet.has( id ) ) );
			}
		}
		function applyFocus() {
			pulse += .06;
			for ( const id in parts ) {
				const on = focusSet && focusSet.has( id ), dim = focusSet && ! on;
				for ( const m of parts[ id ].mats ) {
					if ( m.userData.e0 === undefined ) { m.userData.e0 = m.emissive.getHex(); m.userData.ei0 = m.emissiveIntensity; }
					if ( on ) { m.emissive.setHex( 0x0B8A7A ); m.emissiveIntensity = .35 + .2 * Math.sin( pulse ); } else { m.emissive.setHex( m.userData.e0 ); m.emissiveIntensity = m.userData.ei0; }
					m.opacity = dim ? .22 : 1; m.depthWrite = ! dim;
				}
			}
		}
		function drawLeaders() {
			leaders.innerHTML = '';
			if ( ! focusSet || ! focusFrom || t < .6 ) return;
			const sr = stage.getBoundingClientRect(), cr = focusFrom.getBoundingClientRect();
			const x0 = cr.left - sr.left + cr.width / 2, y0 = cr.bottom - sr.top;
			focusSet.forEach( id => {
				const p = parts[ id ]; if ( ! p || p.sx === undefined ) return;
				const path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' ), my = ( y0 + p.sy ) / 2;
				path.setAttribute( 'd', 'M' + x0 + ',' + y0 + ' C' + x0 + ',' + my + ' ' + p.sx + ',' + my + ' ' + p.sx + ',' + ( p.sy - 12 ) );
				path.setAttribute( 'opacity', Math.min( 1, ( t - .6 ) / .3 ).toFixed( 2 ) );
				leaders.appendChild( path );
			} );
		}
		function pick( id ) {
			if ( id && picked === id ) id = null;     // tap again: let go
			picked = id;
			if ( id ) { focusSet = new Set( [ id ] ); focusFrom = null; } else { focusSet = null; focusFrom = null; }
			if ( opts.onPick ) opts.onPick( id );
		}
		function bindPicking() {
			let down = null;
			cvs.addEventListener( 'pointerdown', e => { down = [ e.clientX, e.clientY ]; } );
			cvs.addEventListener( 'pointerup', e => {
				if ( ! down || Math.hypot( e.clientX - down[ 0 ], e.clientY - down[ 1 ] ) > 6 ) return;
				const r = cvs.getBoundingClientRect();
				three.ray.setFromCamera( { x: ( e.clientX - r.left ) / r.width * 2 - 1, y: -( e.clientY - r.top ) / r.height * 2 + 1 }, three.camera );
				const hit = three.ray.intersectObjects( three.scene.children, true ).find( h => h.object.userData.hw && h.object.material.opacity > .5 );
				if ( hit ) { if ( t < .5 ) setTarget( 1 ); pick( hit.object.userData.hw ); } else if ( focusSet ) { pick( null ); }
			} );
			cvs.addEventListener( 'wheel', () => { userZoomed = true; }, { passive: true } );
		}
		function setTarget( v ) { target = v; btn.textContent = v > .5 ? 'Fold' : 'Unfold'; }
		btn.addEventListener( 'click', () => setTarget( target > .5 ? 0 : 1 ) );
		slider.addEventListener( 'input', () => { target = t = +slider.value; btn.textContent = t > .5 ? 'Fold' : 'Unfold'; } );

		let last = performance.now();
		function loop( now ) {
			const dt = Math.min( .05, ( now - last ) / 1000 ); last = now;
			if ( ! stage.clientWidth || ! stage.clientHeight ) { leaders.innerHTML = ''; requestAnimationFrame( loop ); return; } // Not visible: draw nothing.
			const prev = t;
			t += ( target - t ) * Math.min( 1, dt * 3.2 ); if ( Math.abs( target - t ) < .0005 ) t = target;
			if ( prev !== t ) slider.value = t;
			applyExplode();
			const c = three.controls;
			if ( autoSpin ) { const p = three.camera.position.clone().sub( c.target ); p.applyAxisAngle( new THREE.Vector3( 0, 1, 0 ), dt * .18 ); three.camera.position.copy( c.target ).add( p ); }
			c.target.y = 1.0 + t * .95;
			if ( ! userZoomed ) {
				const dir = three.camera.position.clone().sub( c.target ), want = three.baseDist * ( ( opts.near || .68 ) + t * .8 ) * ( stage.clientWidth < 600 ? 1.3 : 1 );
				dir.setLength( dir.length() + ( want - dir.length() ) * Math.min( 1, dt * 3 ) ); three.camera.position.copy( c.target ).add( dir );
			}
			c.update(); applyFocus(); three.renderer.render( three.scene, three.camera ); placeLabels(); drawLeaders();
			requestAnimationFrame( loop );
		}

		try { init(); buildLabels(); bindPicking(); requestAnimationFrame( loop ); }
		catch ( e ) { stage.classList.add( 'ufq--nogl' ); dock.hidden = true; }

		return {
			focus( ids, fromEl ) { picked = null; focusSet = ids && ids.length ? new Set( ids ) : null; focusFrom = fromEl || null; if ( focusSet && t < .5 ) setTarget( 1 ); },
			clear() { picked = null; focusSet = null; focusFrom = null; },
			unfold( v ) { setTarget( v ? 1 : 0 ); },
			/** 0 = assembled … 1 = fully unfolded (as shown now). */
			progress() { return t; },
			/** Whether a part is picked (its card is open). */
			picked() { return !! picked; },
			/** Screen position (px in the stage) of a point in the car's space; z < 1 when in front of the camera. */
			screen( x, y, z ) {
				if ( ! three ) return null;
				const v = new THREE.Vector3( x, y, z ).project( three.camera );
				return { x: ( v.x * .5 + .5 ) * stage.clientWidth, y: ( -v.y * .5 + .5 ) * stage.clientHeight, z: v.z };
			},
			/** Camera height of the orbit centre, which rises while the car unfolds. */
			cameraPos() { return three ? three.camera.position.clone() : null; },
		};
	}

	window.UnfoldQCar = { mount, model, HW };
} )();
