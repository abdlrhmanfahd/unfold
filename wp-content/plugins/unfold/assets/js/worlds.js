/**
 * Instructor portal, "3D worlds" tab: create a world, upload raw QCar 2 recordings (chunked),
 * follow the worker's progress, open the 3D simulation.
 */
( function () {
	'use strict';
	const C = window.UNFOLD_WORLDS;
	const root = document.querySelector( '[data-ufw-list]' );
	if ( ! C || ! root ) return;

	function api( method, path, body, raw ) {
		const opts = { method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if ( raw ) { opts.body = body; opts.headers[ 'Content-Type' ] = 'application/octet-stream'; }
		else if ( body !== undefined ) { opts.body = JSON.stringify( body ); opts.headers[ 'Content-Type' ] = 'application/json'; }
		return fetch( C.rest + path, opts ).then( r => r.text().then( t => {
			let j = null; try { j = t ? JSON.parse( t ) : null; } catch ( e ) {}
			if ( ! r.ok ) throw new Error( ( j && j.message ) || 'Request failed (' + r.status + ')' );
			return j;
		} ) );
	}
	const esc = s => String( s == null ? '' : s ).replace( /[&<>"']/g, c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	const gb = n => n >= 1073741824 ? ( n / 1073741824 ).toFixed( 2 ) + ' GB' : ( n / 1048576 ).toFixed( 0 ) + ' MB';
	const STATE = { queued: 'Waiting', processing: 'Building', ready: 'Ready', failed: 'Failed' };
	const STAGE = { queued: 'Waiting for the worker', unpacking: 'Unpacking the recording', mapping: 'Building the 3D map', frames: 'Preparing camera frames', scans: 'Preparing LiDAR scans', plan: 'Drawing the floor plan', colour: 'Colouring the map from the camera', '3D model': 'Building the clean 3D model', linking: 'Matching it with the other recordings', placing: 'Placing it in the world', ready: 'Ready' };

	let worlds = [], uploads = {}, workerSeen = C.workerSeen, clockSkew = Date.now() / 1000 - C.now;

	// Destructive actions live behind a "⋯" menu so they cannot be hit by accident.
	// "⋯" menus: put destructive actions (class ufw-x without --plain, e.g. Delete) last, after a divider.
	const more = ( label, items ) => '<details class="ufw-more"><summary aria-label="' + label + '" title="' + label + '">⋯</summary><div class="ufw-menu">' + items + '</div></details>';

	// "62% · 4:10 so far · about 3 min left" (the estimate assumes the rest goes at the pace so far)
	function timing( started, pct ) {
		const el = Math.max( 0, Date.now() / 1000 - clockSkew - started ), mmss = s => Math.floor( s / 60 ) + ':' + String( Math.floor( s % 60 ) ).padStart( 2, '0' );
		let t = pct + '% · ' + mmss( el ) + ' so far';
		if ( pct >= 5 && pct < 100 && el > 20 ) t += ' · about ' + Math.max( 1, Math.round( el * ( 100 - pct ) / pct / 60 ) ) + ' min left';
		return t;
	}
	setInterval( () => root.querySelectorAll( '[data-started]' ).forEach( s => { s.textContent = timing( +s.dataset.started, +s.dataset.pct ); } ), 1000 );

	function driveRow( d ) {
		const s = d.summary || {};
		let meta = '';
		if ( d.state === 'ready' ) {
			meta = [ s.duration_s ? Math.round( s.duration_s ) + ' s drive' : '', s.path_length_m ? s.path_length_m.toFixed( 0 ) + ' m driven' : '', s.map_points ? ( s.map_points / 1e6 ).toFixed( 1 ) + 'M map points' : '' ].filter( Boolean ).join( ' · ' );
			if ( d.placement === 'matched' && s.link ) meta += ' · <span class="ufw-ok">Placed: ' + Math.round( s.link.shared_wall_m ) + ' m of wall match the recordings before it</span>';
			else if ( d.placement === 'unaligned' ) meta += ' · <span class="ufw-warn">Not placed: no area shared with the other recordings was found</span>';
		}
		else if ( d.state === 'failed' ) meta = '<span class="ufw-err">' + esc( d.error || 'Something went wrong.' ) + '</span>';
		else {
			meta = esc( STAGE[ ( d.progress && d.progress.stage ) || 'queued' ] || d.progress.stage );
			if ( d.state === 'processing' && d.started ) meta += ' · <span data-started="' + d.started + '" data-pct="' + ( d.progress ? d.progress.pct : 0 ) + '">' + timing( d.started, d.progress ? d.progress.pct : 0 ) + '</span>';
		}
		const pct = d.progress ? d.progress.pct : 0;
		return '<div class="ufw-drive"><span class="ufw-drive-name">' + esc( d.name ) + '</span>'
			+ '<span>' + ( d.state === 'failed' ? '<button type="button" class="ufw-btn ufw-btn--ghost" data-retry="' + d.id + '">Try again</button> ' : '' )
			+ '<span class="ufw-pill ufw-pill--' + d.state + '">' + STATE[ d.state ] + '</span>'
			+ ( d.state !== 'processing' ? more( 'Recording options', '<button type="button" class="ufw-x ufw-x--plain" data-rename="drives/' + d.id + '" data-name="' + esc( d.name ) + '">Rename recording</button><button type="button" class="ufw-x" data-del-drive="' + d.id + '" data-name="' + esc( d.name ) + '">Delete recording</button>' ) : '' ) + '</span>'
			+ '<span class="ufw-drive-meta">' + meta + '</span>'
			+ ( d.state === 'processing' || d.state === 'queued' ? '<span class="ufw-bar-prog"><i style="width:' + pct + '%"></i></span>' : '' )
			+ '</div>';
	}

	function card( w ) {
		const ready = w.drives.filter( d => d.state === 'ready' ).length;
		const up = uploads[ w.id ];
		const busy = w.drives.some( d => d.state === 'queued' || d.state === 'processing' );
		const seen = Math.max( workerSeen, ...w.drives.map( d => ( d.progress && d.progress.at ) || 0 ) );
		const idle = Date.now() / 1000 - clockSkew - seen > 60;
		return '<article class="ufw-card" data-world="' + w.id + '"><div class="ufw-card-head"><h3>' + esc( w.name ) + '</h3><div class="ufw-card-actions">'
			+ '<label class="ufw-btn ufw-btn--ghost"' + ( up ? ' aria-disabled="true"' : '' ) + '>Add a recording<input type="file" accept=".zip" hidden data-upload="' + w.id + '"' + ( up ? ' disabled' : '' ) + '></label>'
			+ ( w.published ? '<a class="ufw-live" href="' + esc( w.url ) + '">On the site ↗</a>' : ( ready ? '<button type="button" class="ufw-btn ufw-btn--pub" data-publish="' + w.id + '">Publish</button>' : '' ) )
			+ ( ready ? '<a class="ufw-btn ufw-btn--go" href="' + esc( C.worldUrl.replace( '__ID__', w.id ) ) + '">Open 3D</a>' : '<button class="ufw-btn ufw-btn--go" type="button" disabled>Open 3D</button>' )
			+ more( 'World options', ( w.published ? '<button type="button" class="ufw-x ufw-x--plain" data-unpublish="' + w.id + '">Remove from the site</button>' : '' ) + '<button type="button" class="ufw-x ufw-x--plain" data-rename="worlds/' + w.id + '" data-name="' + esc( w.name ) + '">Rename world</button><button type="button" class="ufw-x" data-del-world="' + w.id + '" data-name="' + esc( w.name ) + '"' + ( w.drives.some( d => d.state === 'processing' ) || up ? ' disabled title="A recording is being built or uploaded"' : '' ) + '>Delete world</button>' )
			+ '</div></div>'
			+ ( w.drives.length ? w.drives.map( driveRow ).join( '' ) : '<p class="ufw-note">Upload the ZIP of a session folder exactly as it comes off the car (with bag/bag_0.db3 inside).</p>' )
			+ ( up ? '<p class="ufw-note">' + esc( up ) + '</p>' : '' )
			+ ( busy && idle ? '<p class="ufw-note ufw-note--warn">The 3D worker is not running on the lab computer, so recordings wait in line.</p>' : '' )
			+ ( w.drives.length > 1 ? '<p class="ufw-note">Recordings after the first are placed next to it by matching what both of them saw.</p>' : '' )
			+ '</article>';
	}

	function render() {
		const focus = document.activeElement && document.activeElement.name === 'ufw-name' ? document.activeElement.value : null;
		root.innerHTML = '<form class="ufw-new"><input name="ufw-name" maxlength="60" placeholder="Name a new world, like Lab building" aria-label="World name" required><button class="ufw-btn" type="submit">Create world</button></form>'
			+ ( worlds.length ? worlds.map( card ).join( '' ) : '<p class="ufw-empty">No worlds yet. A world is one place; add the car’s recordings of it and Unfold builds it in 3D.</p>' );
		if ( focus !== null ) { const i = root.querySelector( '[name="ufw-name"]' ); i.value = focus; i.focus(); }
	}

	function load() {
		return api( 'GET', '/worlds' ).then( w => { worlds = w || []; render(); } ).catch( e => { root.innerHTML = '<p class="ufw-err">' + esc( e.message ) + '</p>'; } );
	}

	function upload( worldId, file ) {
		if ( ! /\.zip$/i.test( file.name ) ) { alert( 'Choose a .zip file.' ); return; }
		uploads[ worldId ] = 'Starting upload…'; render();
		api( 'POST', '/worlds/' + worldId + '/uploads', { name: file.name, size: file.size } ).then( s => {
			let offset = 0; const t0 = Date.now();
			const next = () => {
				if ( offset >= file.size ) {
					uploads[ worldId ] = 'Checking the recording…'; render();
					return api( 'POST', '/worlds/' + worldId + '/uploads/' + s.upload_id + '/complete', {} );
				}
				return api( 'PUT', '/uploads/' + s.upload_id + '?offset=' + offset, file.slice( offset, Math.min( file.size, offset + s.chunk_bytes ) ), true ).then( r => {
					offset = r.received;
					const rate = offset / Math.max( 0.1, ( Date.now() - t0 ) / 1000 );
					uploads[ worldId ] = 'Uploading ' + gb( offset ) + ' of ' + gb( file.size ) + ' · about ' + Math.ceil( ( file.size - offset ) / rate / 60 ) + ' min left';
					const el = root.querySelector( '[data-world="' + worldId + '"] .ufw-note' );
					if ( el ) el.textContent = uploads[ worldId ];
					return next();
				} );
			};
			return next();
		} ).then( () => { delete uploads[ worldId ]; return load(); } )
			.catch( e => { delete uploads[ worldId ]; render(); alert( e.message ); } );
	}

	root.addEventListener( 'submit', e => {
		e.preventDefault();
		const name = root.querySelector( '[name="ufw-name"]' ).value.trim();
		if ( ! name ) return;
		api( 'POST', '/worlds', { name } ).then( load ).catch( err => alert( err.message ) );
	} );
	root.addEventListener( 'change', e => {
		const id = e.target.dataset && e.target.dataset.upload;
		if ( id && e.target.files[ 0 ] ) upload( +id, e.target.files[ 0 ] );
	} );
	document.addEventListener( 'click', e => {
		root.querySelectorAll( '.ufw-more[open]' ).forEach( m => { if ( ! m.contains( e.target ) ) m.removeAttribute( 'open' ); } );
	} );
	root.addEventListener( 'click', e => {
		const rn = e.target.closest( '[data-rename]' );
		if ( rn ) {
			const name = prompt( 'New name', rn.dataset.name );
			if ( name && name.trim() && name.trim() !== rn.dataset.name ) api( 'POST', '/' + rn.dataset.rename + '/rename', { name: name.trim() } ).then( load ).catch( err => alert( err.message ) );
			return;
		}
		const pb = e.target.closest( '[data-publish]' ), up = e.target.closest( '[data-unpublish]' );
		if ( pb || up ) {
			const id = ( pb || up ).dataset[ pb ? 'publish' : 'unpublish' ];
			if ( pb && ! confirm( 'Publish this world? Everyone visiting the site will be able to open it.' ) ) return;
			api( 'POST', '/worlds/' + id + '/publish', { on: !! pb } ).then( load ).catch( err => alert( err.message ) );
			return;
		}
		const dw = e.target.closest( '[data-del-world]' ), dd = e.target.closest( '[data-del-drive]' );
		if ( dw && ! dw.disabled ) {
			const w = worlds.find( x => x.id === +dw.dataset.delWorld ), n = w ? w.drives.length : 0;
			if ( confirm( 'Delete the world “' + dw.dataset.name + '”' + ( n ? ' and its ' + n + ' recording' + ( n > 1 ? 's' : '' ) : '' ) + '? The uploaded files and the 3D are removed for good.' ) ) {
				api( 'DELETE', '/worlds/' + dw.dataset.delWorld ).then( load ).catch( err => alert( err.message ) );
			}
			return;
		}
		if ( dd ) {
			if ( confirm( 'Delete the recording “' + dd.dataset.name + '”? Its uploaded file and its 3D are removed for good.' ) ) {
				api( 'DELETE', '/drives/' + dd.dataset.delDrive ).then( load ).catch( err => alert( err.message ) );
			}
			return;
		}
		const b = e.target.closest( '[data-retry]' );
		if ( b ) api( 'POST', '/drives/' + b.dataset.retry + '/retry', {} ).then( load ).catch( err => alert( err.message ) );
	} );

	load();
	// Follow the worker while something is building (and the tab is visible).
	setInterval( () => {
		if ( document.hidden || Object.keys( uploads ).length || root.querySelector( '.ufw-more[open]' ) ) return;
		if ( worlds.some( w => w.drives.some( d => d.state === 'queued' || d.state === 'processing' ) ) ) {
			api( 'GET', '/worlds' ).then( w => { worlds = w || []; render(); } ).catch( () => {} );
		}
	}, 5000 );
}() );
