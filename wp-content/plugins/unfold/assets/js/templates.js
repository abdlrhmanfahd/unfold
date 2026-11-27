/**
 * Unfold simulation templates for QCar 2 parts. Tested code, filled with a project's own
 * values and recorded data; never AI-written.
 *
 *   UnfoldTemplates.has('path_follow')
 *   const stop = UnfoldTemplates.mount(el, 'path_follow', { inputs: {k: 1, ...}, streams: [...] })
 *
 * inputs:  template input name -> number (missing names use the template default)
 * streams: [{ role: 'route'|'speed'|'run', label, set: [{name, value}], cols: {colName: values[]} }]
 */
( function () {
	'use strict';

	const esc = s => String( s ).replace( /[&<>"]/g, c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' } )[ c ] );
	const cols = s => Object.values( s.cols || {} );
	const setOf = s => Object.fromEntries( ( s.set || [] ).map( x => [ x.name, x.value ] ) );
	function canvas( c, h ) {
		const dpr = window.devicePixelRatio || 1, w = c.clientWidth;
		c.style.height = h + 'px'; c.width = Math.round( w * dpr ); c.height = Math.round( h * dpr );
		const g = c.getContext( '2d' ); g.setTransform( dpr, 0, 0, dpr, 0, 0 ); return { g, w, h };
	}
	function interp( T, V, t ) {
		if ( ! T.length ) return 0;
		if ( t <= T[ 0 ] ) return V[ 0 ];
		if ( t >= T[ T.length - 1 ] ) return V[ V.length - 1 ];
		let lo = 0, hi = T.length - 1;
		while ( hi - lo > 1 ) { const m = ( lo + hi ) >> 1; if ( T[ m ] <= t ) lo = m; else hi = m; }
		const a = ( t - T[ lo ] ) / ( ( T[ hi ] - T[ lo ] ) || 1 );
		return ( V[ lo ] ?? 0 ) + a * ( ( V[ hi ] ?? 0 ) - ( V[ lo ] ?? 0 ) );
	}
	const toggle = ( el, on ) => el.setAttribute( 'aria-checked', on ? 'true' : 'false' );
	// Break it is shown publicly only when a template's replay matches the recorded data this closely.
	const TOL_PATH = .03, TOL_SPEED = .05;
	const RUN_COLORS = [ '#D9480F', '#5B5BD6', '#B54708', '#7A2E8E' ];

	/* ==================================================================
	   path_follow: Stanley steering with a steering bias and its correction,
	   on a kinematic bicycle driven along the project's route.
	   ================================================================== */
	const pathFollow = {
		label: 'Path following',
		defaults: { k: 1, v_soft: .1, dmax: .5236, b: 0, bhat: 0, ref_off: .2, corridor: .15, duration: 30, wheelbase: .256, lr_frac: .2, start_lateral: 0, start_heading: 0, v_ref: .6 },
		model( ctx ) {
			const C = Object.assign( {}, this.defaults, ctx.inputs ), DT = .01;
			const routeS = ctx.streams.find( s => s.role === 'route' && ! s.error );
			if ( ! routeS ) return null;
			const routeCols = cols( routeS );
			if ( routeCols.length < 2 ) return null;
			const [ RX, RY ] = routeCols, R = RX.map( ( x, i ) => [ x, RY[ i ] ] ).filter( p => p[ 0 ] !== null && p[ 1 ] !== null );
			const speedS = ctx.streams.find( s => s.role === 'speed' && ! s.error );
			const speedCols = speedS ? cols( speedS ) : [];
			const ST = speedCols.length >= 2 ? speedCols[ 0 ] : [], SV = speedCols.length >= 2 ? speedCols[ 1 ] : [];
			const speedAt = t => speedCols.length >= 2 ? interp( ST, SV, t ) : C.v_ref;
			const runs = ctx.streams.filter( s => s.role === 'run' && ! s.error && cols( s ).length >= 2 ).map( ( s, i ) => ( { label: s.label || 'Recorded run', t: cols( s )[ 0 ], e: cols( s )[ 1 ], set: setOf( s ), color: RUN_COLORS[ i % RUN_COLORS.length ] } ) );

			const seg = [];
			for ( let i = 0; i < R.length - 1; i++ ) { const dx = R[ i + 1 ][ 0 ] - R[ i ][ 0 ], dy = R[ i + 1 ][ 1 ] - R[ i ][ 1 ], L = Math.hypot( dx, dy ); if ( L > 1e-9 ) seg.push( { ax: R[ i ][ 0 ], ay: R[ i ][ 1 ], dx, dy, L } ); }
			const NS = seg.length;
			if ( R.length < 2 || ! NS ) return null;
			const proj = ( s, px, py ) => { let u = ( ( px - s.ax ) * s.dx + ( py - s.ay ) * s.dy ) / ( s.L * s.L ); u = Math.max( 0, Math.min( 1, u ) ); const qx = s.ax + u * s.dx, qy = s.ay + u * s.dy; return { qx, qy, d: Math.hypot( px - qx, py - qy ), cross: ( s.dx * ( py - s.ay ) - s.dy * ( px - s.ax ) ) / s.L }; };
			function Matcher( px, py ) { let b = Infinity; this.i = 0; for ( let j = 0; j < NS; j++ ) { const r = proj( seg[ j ], px, py ); if ( r.d < b ) { b = r.d; this.i = j; } } }
			Matcher.prototype.update = function ( px, py ) { let best = null, bi = this.i, acc = 0; for ( let n = 0; n < NS && acc <= 1.5; n++ ) { const j = ( this.i + n ) % NS, r = proj( seg[ j ], px, py ); if ( ! best || r.d < best.d - 1e-12 ) { best = r; bi = j; } acc += seg[ j ].L; } this.i = bi; best.i = bi; return best; };
			const wrap = a => Math.atan2( Math.sin( a ), Math.cos( a ) ), clip = ( v, m ) => Math.max( -m, Math.min( m, v ) );
			function simulate( b, bhat, k ) {
				const th0 = Math.atan2( seg[ 0 ].dy, seg[ 0 ].dx );
				let x = R[ 0 ][ 0 ] - Math.sin( th0 ) * C.start_lateral, y = R[ 0 ][ 1 ] + Math.cos( th0 ) * C.start_lateral, th = wrap( th0 + C.start_heading );
				const p0x = x + C.ref_off * Math.cos( th ), p0y = y + C.ref_off * Math.sin( th );
				const ctrl = new Matcher( p0x, p0y ), score = new Matcher( p0x, p0y );
				const out = { t: [], e: [], px: [], py: [], nx: [], ny: [], th: [] }; const steps = Math.round( C.duration / DT ), every = 5; let failed = false, failT = null;
				for ( let s = 0; s <= steps; s++ ) {
					const tt = s * DT, v = speedAt( tt ), px = x + C.ref_off * Math.cos( th ), py = y + C.ref_off * Math.sin( th );
					const m = ctrl.update( px, py ), sg = seg[ m.i ];
					const raw = wrap( Math.atan2( sg.dy, sg.dx ) - th ) + Math.atan2( k * ( -m.cross ), Math.abs( v ) + C.v_soft );
					const applied = clip( clip( raw - bhat, C.dmax ) + b, C.dmax );
					if ( s % every === 0 ) { const sc = score.update( px, py ), ss = seg[ sc.i ]; const e = failed ? ( sc.cross >= 0 ? C.corridor : -C.corridor ) : sc.cross; if ( ! failed && Math.abs( e ) > C.corridor ) { failed = true; failT = tt; } out.t.push( tt ); out.e.push( e ); out.px.push( sc.qx ); out.py.push( sc.qy ); out.nx.push( -ss.dy / ss.L ); out.ny.push( ss.dx / ss.L ); out.th.push( th ); }
					const beta = Math.atan( C.lr_frac * Math.tan( applied ) );
					x += v * Math.cos( th + beta ) * DT; y += v * Math.sin( th + beta ) * DT; th = wrap( th + v * Math.cos( beta ) / C.wheelbase * Math.tan( applied ) * DT );
				}
				let mean = 0; out.e.forEach( e => { mean += e; } ); out.mean = mean / out.e.length; out.failed = failed; out.failT = failT; return out;
			}

			// Self-check: replay each recorded run with the inputs it used and compare.
			const checks = runs.map( r => { const p = Object.assign( {}, C, r.set ), sim = simulate( p.b, p.bhat, p.k ); let se = 0, n = 0;
				sim.t.forEach( ( t, j ) => { const v = interp( r.t, r.e, t ); if ( Number.isFinite( v ) ) { se += ( sim.e[ j ] - v ) ** 2; n++; } } ); return { label: r.label, rms: n ? Math.sqrt( se / n ) : null }; } );
			return { C, R, runs, simulate, checks };
		},
		/** Replay check without drawing: ok when every recorded run is matched within TOL_PATH. */
		check( ctx ) {
			const M = this.model( ctx );
			if ( ! M ) return { ok: false, text: 'No route found in the project files.' };
			if ( ! M.runs.length ) return { ok: false, text: 'No recorded run to compare with.' };
			const worst = Math.max( ...M.checks.map( c => c.rms ?? Infinity ) );
			return { ok: worst <= TOL_PATH, text: 'Matches the recorded runs within ' + ( worst * 1000 ).toFixed( 0 ) + ' mm (limit ' + TOL_PATH * 1000 + ' mm).' };
		},
		mount( root, ctx ) {
			try {
			const M = this.model( ctx ), EXAG = 20;
			if ( ! M ) { root.innerHTML = '<p class="ufs-note">This simulation needs the route from the project files.</p>'; return () => {}; }
			const { C, R, runs, simulate, checks } = M;

			root.innerHTML = `
			<div class="ufs">
				<div class="ufs-ctrls">
					<div class="ufs-ctl"><label for="pb"><span>Bend the wheels</span><output id="pbv"></output></label><input id="pb" type="range" min="0" max="0.06" step="0.001"></div>
					<div class="ufs-switch"><b>Software fix</b><button class="ufs-tog" id="pfix" type="button" role="switch" aria-label="Software fix"></button></div>
					<div class="ufs-ctl" id="pbhW"><label for="pbh"><span>Size of the fix</span><output id="pbhv"></output></label><input id="pbh" type="range" min="0" max="0.06" step="0.0002"></div>
					<div class="ufs-ctl"><label for="pk"><span>Steering strength</span><output id="pkv"></output></label><input id="pk" type="range" min="0.2" max="3" step="0.1"></div>
					<div class="ufs-presets" id="pp">
						${ runs.map( ( r, i ) => `<button type="button" data-p="run${ i }">${ esc( r.label ) }</button>` ).join( '' ) }
						<button type="button" data-p="perfect">Perfect fix</button><button type="button" data-p="over">Over-correct</button>
						<button type="button" data-p="stiff">Stiffer steering</button><button type="button" data-p="crash">Bend it a lot</button>
					</div>
				</div>
				<div class="ufs-view">
					<div class="ufs-verdict" id="pv" aria-live="polite"><b id="pvn"></b><span id="pv1"></span></div>
					<div class="ufs-cv"><canvas class="ufs-plot" id="pmap" role="img" aria-label="Top-down map of the route with the simulated car"></canvas><span class="ufs-corner">drift ×${ EXAG }</span></div>
					<div class="ufs-cv"><div class="ufs-cvh"><h4>Off the path</h4><div class="ufs-legend"><span><i style="color:#0B8A7A"></i>You</span>
						${ runs.map( ( r, i ) => `<label><input type="checkbox" data-run="${ i }" checked> <i class="d" style="color:${ r.color }"></i>${ esc( r.label ) }</label>` ).join( '' ) }</div></div>
						<canvas class="ufs-plot" id="pchart" role="img" aria-label="Distance from the path over time"></canvas></div>
					<details class="ufs-real"><summary>How real is this?</summary><p id="preal"></p></details>
				</div>
			</div>`;
			const $ = id => root.querySelector( '#' + id );

			$( 'preal' ).textContent = 'Built from the project\'s route, controller, gains, start position and measured speed. Position is perfect and steering instant, so the recorded runs are noisier.'
				+ ( checks.length ? ' Replaying the recorded runs: ' + checks.map( c => c.label + ( c.rms === null ? ': no matching points' : ' within ' + ( c.rms * 1000 ).toFixed( 1 ) + ' mm (rms)' ) ).join( ', ' ) + '.' : '' );

			const bb = [ Infinity, Infinity, -Infinity, -Infinity ]; R.forEach( p => { bb[ 0 ] = Math.min( bb[ 0 ], p[ 0 ] ); bb[ 1 ] = Math.min( bb[ 1 ], p[ 1 ] ); bb[ 2 ] = Math.max( bb[ 2 ], p[ 0 ] ); bb[ 3 ] = Math.max( bb[ 3 ], p[ 1 ] ); } );
			const st = { b: C.b, fix: C.bhat > 0, bhat: C.bhat > 0 ? C.bhat : C.b, k: C.k };
			let sim = null, tp = 0, raf = 0, last = 0, dirty = true, alive = true;
			const deg = r => ( r * 180 / Math.PI ).toFixed( 2 ) + '°';
			function sync() {
				$( 'pb' ).value = st.b; $( 'pbh' ).value = st.bhat; $( 'pk' ).value = st.k; toggle( $( 'pfix' ), st.fix );
				$( 'pbv' ).textContent = deg( st.b ); $( 'pbhv' ).textContent = deg( st.bhat ); $( 'pkv' ).textContent = st.k.toFixed( 1 );
				$( 'pbhW' ).classList.toggle( 'is-off', ! st.fix ); $( 'pbh' ).disabled = ! st.fix; dirty = true;
			}
			function preset( n ) {
				const base = { b: C.b || .02, k: C.k, bhat: C.bhat || C.b || .02 };
				if ( n.startsWith( 'run' ) ) { const r = runs[ +n.slice( 3 ) ], p = Object.assign( {}, C, r.set ); Object.assign( st, { b: p.b, k: p.k, fix: p.bhat > 0, bhat: p.bhat > 0 ? p.bhat : st.bhat } ); }
				else Object.assign( st, { perfect: { b: base.b, fix: true, bhat: base.b, k: C.k }, over: { b: base.b, fix: true, bhat: base.b * 2.2, k: C.k }, stiff: { b: base.b, fix: false, k: 3 }, crash: { b: .06, fix: false, k: .2 } }[ n ] );
				sync(); tp = 0; root.querySelectorAll( '#pp button' ).forEach( b => b.classList.toggle( 'is-on', b.dataset.p === n ) );
			}
			const clr = () => root.querySelectorAll( '#pp button' ).forEach( b => b.classList.remove( 'is-on' ) );
			$( 'pb' ).addEventListener( 'input', e => { st.b = +e.target.value; clr(); sync(); } );
			$( 'pbh' ).addEventListener( 'input', e => { st.bhat = +e.target.value; clr(); sync(); } );
			$( 'pk' ).addEventListener( 'input', e => { st.k = +e.target.value; clr(); sync(); } );
			$( 'pfix' ).addEventListener( 'click', () => { st.fix = ! st.fix; clr(); sync(); tp = 0; } );
			root.querySelectorAll( '#pp button' ).forEach( b => b.addEventListener( 'click', () => preset( b.dataset.p ) ) );
			root.querySelectorAll( '[data-run]' ).forEach( c => c.addEventListener( 'change', draw ) );

			function verdict() {
				const mm = Math.abs( sim.mean * 1000 ), side = sim.mean >= 0 ? 'left' : 'right', el = $( 'pv' );
				el.classList.remove( 'is-bad', 'is-good' );
				if ( sim.failed ) { el.classList.add( 'is-bad' ); $( 'pvn' ).innerHTML = 'Off<small>road</small>'; $( 'pv1' ).textContent = 'Left the lane at ' + sim.failT.toFixed( 1 ) + ' s.'; return; }
				$( 'pvn' ).innerHTML = mm.toFixed( 1 ) + '<small>mm ' + side + '</small>';
				const resid = st.b - ( st.fix ? st.bhat : 0 );
				if ( Math.abs( resid ) < .0015 ) { el.classList.add( 'is-good' ); $( 'pv1' ).textContent = 'Fixed.'; }
				else $( 'pv1' ).textContent = resid > 0 ? 'Leans left to fight the bent wheels.' : 'Too much fix: now it leans right.';
			}
			function drawMap() {
				const c = $( 'pmap' ), w = c.clientWidth, h = Math.max( 220, Math.min( 380, Math.round( w * .52 ) ) );
				const { g } = canvas( c, h ), pad = 18, spanX = Math.max( 1e-6, bb[ 2 ] - bb[ 0 ] ), spanY = Math.max( 1e-6, bb[ 3 ] - bb[ 1 ] );
				const sc = Math.min( ( w - 2 * pad ) / spanX, ( h - 2 * pad ) / spanY );
				const ox = ( w - sc * spanX ) / 2, oy = ( h - sc * spanY ) / 2, X = x => ox + ( x - bb[ 0 ] ) * sc, Y = y => h - ( oy + ( y - bb[ 1 ] ) * sc );
				const line = () => { g.beginPath(); R.forEach( ( p, i ) => i ? g.lineTo( X( p[ 0 ] ), Y( p[ 1 ] ) ) : g.moveTo( X( p[ 0 ] ), Y( p[ 1 ] ) ) ); };
				g.clearRect( 0, 0, w, h ); g.lineJoin = 'round'; g.lineCap = 'round';
				const road = Math.min( 2 * C.corridor * EXAG * sc, 28 );
				g.strokeStyle = '#D3DAE1'; g.lineWidth = road + 2; line(); g.stroke();
				g.strokeStyle = '#EDF1F4'; g.lineWidth = road; line(); g.stroke();
				g.strokeStyle = '#9AA7B4'; g.lineWidth = 1; g.setLineDash( [ 4, 5 ] ); line(); g.stroke(); g.setLineDash( [] );
				if ( ! sim ) return;
				const up = Math.min( sim.t.length - 1, Math.round( tp / .05 ) ), pt = j => [ sim.px[ j ] + sim.nx[ j ] * sim.e[ j ] * EXAG, sim.py[ j ] + sim.ny[ j ] * sim.e[ j ] * EXAG ];
				g.strokeStyle = 'rgba(11,138,122,.22)'; g.lineWidth = 2; g.beginPath(); for ( let j = 0; j < sim.t.length; j++ ) { const q = pt( j ); j ? g.lineTo( X( q[ 0 ] ), Y( q[ 1 ] ) ) : g.moveTo( X( q[ 0 ] ), Y( q[ 1 ] ) ); } g.stroke();
				g.strokeStyle = sim.failed && tp >= sim.failT ? '#B42318' : '#0B8A7A'; g.lineWidth = 3; g.beginPath(); for ( let j = 0; j <= up; j++ ) { const q = pt( j ); j ? g.lineTo( X( q[ 0 ] ), Y( q[ 1 ] ) ) : g.moveTo( X( q[ 0 ] ), Y( q[ 1 ] ) ); } g.stroke();
				const q = pt( up ); g.save(); g.translate( X( q[ 0 ] ), Y( q[ 1 ] ) ); g.rotate( -sim.th[ up ] );
				g.fillStyle = '#0F1720'; g.beginPath(); g.roundRect ? g.roundRect( -8, -5, 17, 10, 3 ) : g.rect( -8, -5, 17, 10 ); g.fill(); g.fillStyle = '#12B39C'; g.fillRect( 5, -4, 3, 8 ); g.restore();
			}
			function drawChart() {
				const c = $( 'pchart' ), w = c.clientWidth, h = Math.max( 190, Math.min( 250, Math.round( w * .3 ) ) );
				const { g } = canvas( c, h ), L = 50, Rr = 10, T = 12, B = 24, W = w - L - Rr, H = h - T - B;
				const ser = [];
				root.querySelectorAll( '[data-run]' ).forEach( cb => { if ( cb.checked ) { const r = runs[ +cb.dataset.run ]; ser.push( { t: r.t, e: r.e, c: r.color, d: [ 5, 4 ], w: 1.4 } ); } } );
				if ( sim ) ser.push( { t: sim.t, e: sim.e, c: '#0B8A7A', d: [], w: 2.6 } );
				let lo = -.06, hi = .06; ser.forEach( s => s.e.forEach( v => { if ( v !== null ) { lo = Math.min( lo, v ); hi = Math.max( hi, v ); } } ) );
				lo = Math.max( lo, -C.corridor * 1.05 ); hi = Math.min( hi, C.corridor * 1.05 );
				const X = x => L + x / C.duration * W, Y = v => T + ( hi - v ) / ( hi - lo ) * H;
				g.clearRect( 0, 0, w, h ); g.font = '11px Onest, sans-serif'; g.fillStyle = '#566270';
				const step = ( hi - lo ) > .18 ? .05 : .02;
				for ( let v = Math.ceil( lo / step ) * step; v <= hi + 1e-9; v += step ) { const yy = Math.round( Y( v ) ) + .5; g.strokeStyle = Math.abs( v ) < 1e-9 ? '#9AA7B4' : '#E6EBEF'; g.lineWidth = 1; g.beginPath(); g.moveTo( L, yy ); g.lineTo( L + W, yy ); g.stroke(); g.textAlign = 'right'; g.textBaseline = 'middle'; g.fillText( Math.round( v * 1000 ) + ' mm', L - 6, yy ); }
				for ( let x = 0; x <= C.duration; x += 5 ) { g.textAlign = 'center'; g.textBaseline = 'top'; g.fillText( x + ' s', X( x ), T + H + 6 ); }
				g.textAlign = 'left'; g.textBaseline = 'top'; g.fillText( 'left ↑', L + 6, T + 2 ); g.textBaseline = 'bottom'; g.fillText( 'right ↓', L + 6, T + H - 2 );
				g.save(); g.beginPath(); g.rect( L, T, W, H ); g.clip();
				ser.forEach( s => { g.strokeStyle = s.c; g.lineWidth = s.w; g.setLineDash( s.d ); g.beginPath(); let on = false; for ( let j = 0; j < s.t.length; j++ ) { if ( s.e[ j ] === null || s.t[ j ] > C.duration ) { on = false; continue; } const px = X( s.t[ j ] ), py = Y( s.e[ j ] ); on ? g.lineTo( px, py ) : g.moveTo( px, py ); on = true; } g.stroke(); } );
				g.setLineDash( [] ); g.strokeStyle = '#0F1720'; g.lineWidth = 1; g.beginPath(); g.moveTo( X( tp ) + .5, T ); g.lineTo( X( tp ) + .5, T + H ); g.stroke(); g.restore();
			}
			function draw() { drawMap(); drawChart(); }
			function frame( ts ) {
				if ( ! alive ) return;
				if ( dirty ) { sim = simulate( st.b, st.fix ? st.bhat : 0, st.k ); verdict(); dirty = false; }
				const dt = last ? Math.min( .05, ( ts - last ) / 1000 ) : 0; last = ts;
				tp += dt * 3; if ( tp > C.duration ) tp = 0;
				draw(); raf = requestAnimationFrame( frame );
			}
			if ( runs.length ) preset( 'run0' ); else sync();
			raf = requestAnimationFrame( frame );
			const ro = new ResizeObserver( () => draw() ); ro.observe( root );
			return () => { alive = false; cancelAnimationFrame( raf ); ro.disconnect(); };
			} catch {
				root.innerHTML = '<p class="ufs-note">Could not render simulation.</p>';
				return () => {};
			}
		},
	};

	/* ==================================================================
	   speed_loop: PI speed control with anti-windup on a first-order motor,
	   the motor fitted to the project's recorded speed.
	   ================================================================== */
	const speedCache = new WeakMap();
	const speedLoop = {
		label: 'Speed control',
		defaults: { v_ref: .6, kp: .1, ki: 1, umax: .3 },
		model( ctx ) {
			if ( ctx && typeof ctx === 'object' && speedCache.has( ctx ) ) return speedCache.get( ctx );
			const P = Object.assign( {}, this.defaults, ctx.inputs ), VREF = P.v_ref, TEND = 12, DT = .01;
			const runs = ctx.streams.filter( s => s.role === 'run' && ! s.error ).map( s => {
				const pair = cols( s );
				if ( pair.length < 2 ) return { label: s.label || 'Recorded', pts: [] };
				const [ T, V ] = pair;
				return { label: s.label || 'Recorded', pts: T.map( ( t, i ) => [ t, V[ i ] ] ).filter( p => p[ 0 ] !== null && p[ 0 ] >= 0 && p[ 0 ] <= TEND && p[ 1 ] !== null ) };
			} );
			const meas = runs.length ? runs[ 0 ].pts : [];
			function run( kp, ki, umax, aw, hill, K, tau ) {
				let v = 0, I = 0; const out = []; let peak = 0, settle = null, post = 0, hillSat = false; const dh = -.4 * VREF / K;
				for ( let s = 0; s <= TEND / DT; s++ ) {
					const tt = s * DT, e = VREF - v; let u = kp * e + ki * I; const sat = Math.abs( u ) > umax; u = Math.max( -umax, Math.min( umax, u ) );
					if ( ! aw || ! sat || Math.sign( e ) !== Math.sign( u ) ) I += e * DT;
					const onHill = hill && tt >= 6 && tt < 8; if ( onHill && sat ) hillSat = true;
					v += ( K * ( u + ( onHill ? dh : 0 ) ) - v ) / tau * DT;
					if ( s % 5 === 0 ) out.push( [ tt, v, u ] );
					if ( tt < 6 ) peak = Math.max( peak, v ); else if ( tt >= 8 ) post = Math.max( post, v );
				}
				const from = hill ? 8 : 0;
				for ( let j = out.length - 1; j >= 0; j-- ) { if ( Math.abs( out[ j ][ 1 ] - VREF ) > .01 ) { settle = j === out.length - 1 ? null : out[ j + 1 ][ 0 ] - from; break; } if ( j === 0 ) settle = 0; }
				return { out, peak, settle, post, hillSat };
			}
			let best = { err: Infinity, K: 3, tau: .3 };
			if ( meas.length ) for ( let K = 1; K <= 20; K += .2 ) for ( let tau = .05; tau <= 1.5; tau += .025 ) {
				const r = run( P.kp, P.ki, P.umax, true, false, K, tau ); let err = 0;
				for ( const [ mt, mv ] of meas ) { const j = Math.min( r.out.length - 1, Math.round( mt / .05 ) ); err += ( r.out[ j ][ 1 ] - mv ) ** 2; }
				err = Math.sqrt( err / meas.length ); if ( err < best.err ) best = { err, K, tau };
			}
			const result = { P, VREF, TEND, runs, meas, run, best };
			if ( ctx && typeof ctx === 'object' ) speedCache.set( ctx, result );
			return result;
		},
		/** Fit check without drawing: ok when the fitted motor follows the recorded speed within TOL_SPEED. */
		check( ctx ) {
			const M = this.model( ctx );
			if ( ! M.meas.length ) return { ok: false, text: 'No recorded speed to compare with.' };
			return { ok: M.best.err <= TOL_SPEED, text: 'Matches the recorded speed within ' + ( M.best.err * 1000 ).toFixed( 0 ) + ' mm/s (limit ' + TOL_SPEED * 1000 + ' mm/s).' };
		},
		mount( root, ctx ) {
			const { P, VREF, TEND, runs, meas, run, best } = this.model( ctx );
			root.innerHTML = `
			<div class="ufs">
				<div class="ufs-ctrls">
					<div class="ufs-ctl"><label for="skp"><span>Push</span><output id="skpv"></output></label><input id="skp" type="range" min="0" max="1" step="0.01"></div>
					<div class="ufs-ctl"><label for="ski"><span>Memory</span><output id="skiv"></output></label><input id="ski" type="range" min="0" max="8" step="0.1"></div>
					<div class="ufs-ctl"><label for="su"><span>Motor strength</span><output id="suv"></output></label><input id="su" type="range" min="0.05" max="0.5" step="0.01"></div>
					<div class="ufs-switch"><b>Anti-windup</b><button class="ufs-tog" id="saw" type="button" role="switch" aria-label="Anti-windup"></button></div>
					<div class="ufs-switch"><b>Hill</b><button class="ufs-tog" id="shill" type="button" role="switch" aria-label="Hill"></button></div>
					<div class="ufs-presets" id="sp"><button type="button" data-p="orig">Original tuning</button><button type="button" data-p="wild">Too much memory</button><button type="button" data-p="sleepy">Sleepy</button><button type="button" data-p="windup">Wind-up</button></div>
				</div>
				<div class="ufs-view">
					<div class="ufs-verdict" id="sv" aria-live="polite"><b id="svn"></b><span id="sv1"></span></div>
					<div class="ufs-cv"><div class="ufs-cvh"><h4>Speed</h4><div class="ufs-legend"><span><i style="color:#0B8A7A"></i>You</span>${ meas.length ? `<label><input type="checkbox" id="sm" checked> <i class="d" style="color:#D9480F"></i>${ esc( runs[ 0 ].label ) }</label>` : '' }<span><i class="d" style="color:#9AA7B4"></i>${ VREF } m/s</span></div></div><canvas class="ufs-plot" id="sch" role="img" aria-label="Speed over time"></canvas></div>
					<div class="ufs-cv"><div class="ufs-cvh"><h4>Throttle</h4></div><canvas class="ufs-plot" id="sth" role="img" aria-label="Throttle over time"></canvas></div>
					<details class="ufs-real"><summary>How real is this?</summary><p>${ meas.length ? 'The project\'s controller and gains. The motor\'s response is fitted to the recorded speed (within ' + ( best.err * 1000 ).toFixed( 0 ) + ' mm/s).' : 'The project\'s controller and gains on a typical motor response. No recorded speed was found to fit it to.' }</p></details>
				</div>
			</div>`;
			const $ = id => root.querySelector( '#' + id );
			const ORIG = { kp: P.kp, ki: P.ki, umax: P.umax, aw: true, hill: false }, st = Object.assign( {}, ORIG );
			let r = null, alive = true;
			function sync() { $( 'skp' ).value = st.kp; $( 'ski' ).value = st.ki; $( 'su' ).value = st.umax; toggle( $( 'saw' ), st.aw ); toggle( $( 'shill' ), st.hill );
				$( 'skpv' ).textContent = st.kp.toFixed( 2 ); $( 'skiv' ).textContent = st.ki.toFixed( 1 ); $( 'suv' ).textContent = st.umax.toFixed( 2 ); compute(); }
			function preset( n ) { const m = { orig: ORIG, wild: { kp: P.kp, ki: P.ki * 7, umax: P.umax, aw: true, hill: false }, sleepy: { kp: P.kp * .1, ki: P.ki * .08, umax: P.umax, aw: true, hill: false }, windup: { kp: P.kp, ki: P.ki * 2.5, umax: Math.ceil( VREF / best.K * 1.25 * 100 ) / 100, aw: false, hill: true } };
				Object.assign( st, m[ n ] ); root.querySelectorAll( '#sp button' ).forEach( b => b.classList.toggle( 'is-on', b.dataset.p === n ) ); sync(); }
			const clr = () => root.querySelectorAll( '#sp button' ).forEach( b => b.classList.remove( 'is-on' ) );
			[ 'skp', 'ski', 'su' ].forEach( id => $( id ).addEventListener( 'input', e => { st[ { skp: 'kp', ski: 'ki', su: 'umax' }[ id ] ] = +e.target.value; clr(); sync(); } ) );
			$( 'saw' ).addEventListener( 'click', () => { st.aw = ! st.aw; clr(); sync(); } ); $( 'shill' ).addEventListener( 'click', () => { st.hill = ! st.hill; clr(); sync(); } );
			root.querySelectorAll( '#sp button' ).forEach( b => b.addEventListener( 'click', () => preset( b.dataset.p ) ) );
			if ( $( 'sm' ) ) $( 'sm' ).addEventListener( 'change', draw );
			function compute() {
				r = run( st.kp, st.ki, st.umax, st.aw, st.hill, best.K, best.tau );
				const over = Math.max( 0, ( r.peak - VREF ) / VREF * 100 ), el = $( 'sv' ), top = Math.max( ...r.out.map( p => p[ 1 ] ) ); el.classList.remove( 'is-bad', 'is-good' );
				if ( top < VREF * .97 ) { el.classList.add( 'is-bad' ); $( 'svn' ).innerHTML = top.toFixed( 2 ) + '<small>m/s top</small>'; $( 'sv1' ).textContent = st.umax * best.K < VREF * 1.05 ? 'Motor too weak.' : 'Too slow to get there.'; }
				else if ( over > 15 ) { el.classList.add( 'is-bad' ); $( 'svn' ).innerHTML = over.toFixed( 0 ) + '<small>% overshoot</small>'; $( 'sv1' ).textContent = st.aw ? 'Too much memory.' : 'Wind-up.'; }
				else if ( st.hill && ( r.post - VREF ) / VREF > .12 ) { el.classList.add( 'is-bad' ); $( 'svn' ).innerHTML = ( ( r.post - VREF ) / VREF * 100 ).toFixed( 0 ) + '<small>% surge</small>'; $( 'sv1' ).textContent = r.hillSat && ! st.aw ? 'Wind-up after the hill.' : 'Surges after the hill.'; }
				else { if ( r.settle !== null && r.settle < 4 ) el.classList.add( 'is-good' ); $( 'svn' ).innerHTML = ( r.settle === null ? '–' : r.settle.toFixed( 1 ) ) + '<small>s to settle</small>'; $( 'sv1' ).textContent = r.settle === null ? 'Never settles.' : ( st.hill ? 'Rides over the hill.' : 'Steady at ' + VREF + ' m/s.' ); }
				draw();
			}
			function plot( c, h, series, lo, hi, refY ) {
				const { g, w } = canvas( c, h ), L = 50, Rr = 10, T = 10, B = 22, W = w - L - Rr, H = h - T - B, X = x => L + x / TEND * W, Y = v => T + ( hi - v ) / ( hi - lo ) * H;
				g.clearRect( 0, 0, w, h ); g.font = '11px Onest, sans-serif'; g.fillStyle = '#566270';
				for ( let k = 0; k <= 4; k++ ) { const v = lo + k * ( hi - lo ) / 4, yy = Math.round( Y( v ) ) + .5; g.strokeStyle = '#E6EBEF'; g.beginPath(); g.moveTo( L, yy ); g.lineTo( L + W, yy ); g.stroke(); g.textAlign = 'right'; g.textBaseline = 'middle'; g.fillText( v.toFixed( 2 ), L - 6, yy ); }
				for ( let x = 0; x <= TEND; x += 2 ) { g.textAlign = 'center'; g.textBaseline = 'top'; g.fillText( x + ' s', X( x ), T + H + 5 ); }
				if ( refY !== undefined ) { g.strokeStyle = '#9AA7B4'; g.setLineDash( [ 4, 4 ] ); g.beginPath(); g.moveTo( L, Y( refY ) ); g.lineTo( L + W, Y( refY ) ); g.stroke(); g.setLineDash( [] ); }
				if ( st.hill ) { g.fillStyle = 'rgba(180,35,24,.07)'; g.fillRect( X( 6 ), T, X( 8 ) - X( 6 ), H ); g.fillStyle = '#B42318'; g.textAlign = 'left'; g.textBaseline = 'top'; g.fillText( 'hill', X( 6 ) + 4, T + 2 ); }
				g.save(); g.beginPath(); g.rect( L, T, W, H ); g.clip();
				series.forEach( s => { g.strokeStyle = s.c; g.lineWidth = s.w; g.setLineDash( s.d || [] ); g.beginPath(); s.p.forEach( ( p, i ) => i ? g.lineTo( X( p[ 0 ] ), Y( p[ 1 ] ) ) : g.moveTo( X( p[ 0 ] ), Y( p[ 1 ] ) ) ); g.stroke(); } );
				g.restore();
			}
			function draw() {
				if ( ! alive || ! r ) return;
				const w = $( 'sch' ).clientWidth, hi = Math.max( VREF * 1.35, ...r.out.map( p => p[ 1 ] ) ) * 1.05;
				const ser = []; if ( $( 'sm' ) && $( 'sm' ).checked ) ser.push( { p: meas, c: '#D9480F', w: 1.4, d: [ 5, 4 ] } ); ser.push( { p: r.out.map( p => [ p[ 0 ], p[ 1 ] ] ), c: '#0B8A7A', w: 2.6 } );
				plot( $( 'sch' ), Math.max( 190, Math.min( 250, Math.round( w * .3 ) ) ), ser, 0, Math.ceil( hi * 10 ) / 10, VREF );
				plot( $( 'sth' ), 120, [ { p: r.out.map( p => [ p[ 0 ], p[ 2 ] ] ), c: '#0F1720', w: 1.8 } ], -.1, Math.max( .5, P.umax * 1.6 ) );
			}
			preset( 'orig' );
			const ro = new ResizeObserver( () => draw() ); ro.observe( root );
			return () => { alive = false; ro.disconnect(); };
		},
	};

	const REG = { path_follow: pathFollow, speed_loop: speedLoop };
	window.UnfoldTemplates = {
		has: id => !! REG[ id ],
		label: id => REG[ id ] ? REG[ id ].label : '',
		mount: ( el, id, ctx ) => REG[ id ].mount( el, ctx ),
		check: ( id, ctx ) => REG[ id ].check( ctx ),
	};
} )();
