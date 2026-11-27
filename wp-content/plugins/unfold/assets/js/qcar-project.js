/**
 * Project page: the project's parts and their Break-it simulations first; the QCar 2 opens
 * on demand ("See it on the car") with the chosen part lit up.
 * Everything comes from the project file the server prints into <script type="application/json">.
 */
( function () {
	'use strict';
	const esc = s => String( s ?? '' ).replace( /[&<>"]/g, c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' } )[ c ] );

	document.querySelectorAll( '[data-unfold-p2]' ).forEach( root => {
		const P = JSON.parse( root.querySelector( 'script[type="application/json"]' ).textContent );
		const HW = window.UnfoldQCar.HW;
		const head = root.querySelector( '.ufp-head' ), grid = root.querySelector( '.ufp-parts' );
		const carSheet = root.querySelector( '.ufp-car' ), stage = carSheet.querySelector( '.ufp-stage' ), flow = carSheet.querySelector( '.ufp-flow' ), info = carSheet.querySelector( '.ufp-info' );
		const simSheet = root.querySelector( '.ufp-sheet' ), simBody = simSheet.querySelector( '.ufp-sheet-body' ), simTitle = simSheet.querySelector( 'h2' );
		const usedHw = new Set( P.parts.flatMap( p => p.on ) );
		const playable = p => p.template && window.UnfoldTemplates.has( p.template );
		// Gate: publicly, Break it needs the owner's approval and a replay that matches the recorded data.
		// In the owner preview and instructor review it always shows, with the check result on the card.
		const checks = {};
		P.parts.forEach( p => { if ( playable( p ) ) { try { checks[ p.id ] = window.UnfoldTemplates.check( p.template, { inputs: p.inputs, streams: p.streams } ); } catch { checks[ p.id ] = { ok: false, text: 'The simulation could not run on this data.' }; } } } );
		const canPlay = p => playable( p ) && ( P.review || ( p.approved && checks[ p.id ].ok ) );
		const reviewNote = p => ! P.review || ! playable( p ) ? '' : `<p class="ufp-check ${ checks[ p.id ].ok ? 'is-ok' : 'is-bad' }">${ esc( checks[ p.id ].text ) }</p><p class="ufp-check ${ p.approved ? 'is-ok' : 'is-bad' }">${ p.approved ? 'Approved by the owner.' : 'Not approved yet: tick “Simulation checked” in the form.' }${ checks[ p.id ].ok ? '' : ' Visitors won’t see it until it matches.' }</p>`;
		const hwNames = p => p.on.map( id => HW[ id ] ? HW[ id ].name : id ).join( ' · ' );
		const figLabel = p => { const m = p.media || []; if ( ! m.length ) return ''; const v = m.every( x => x.type === 'video' ); return ( v ? 'Video' : 'Figure' ) + ( m.length > 1 ? 's (' + m.length + ')' : '' ); };
		let car = null, selected = null, stopSim = null, lastFocus = null;

		// AI check of the project against the instructor's brief: the number, and what it is made of.
		const VERDICT = { met: 'Done', partial: 'Partly', not_met: 'Not found' };
		const briefCard = b => `<details class="ufp-brief"><summary><b>${ b.score }<small>/100</small></b><span>Match to the brief · checked by AI</span></summary>`
			+ '<ol class="ufp-brief-list">' + b.items.map( i => `<li class="is-${ esc( i.verdict ) }"><span class="ufp-verdict">${ VERDICT[ i.verdict ] || '' }</span><p>${ esc( i.text ) }</p>`
				+ ( i.evidence ? `<p class="ufp-ev">${ esc( i.evidence ) }${ i.where ? ' <i>(' + esc( i.where ) + ')</i>' : '' }</p>` : '' ) + '</li>' ).join( '' ) + '</ol>'
			+ ( b.briefs.length ? `<p class="ufp-brief-src">Checked against ${ b.briefs.map( esc ).join( ', ' ) }. Done = 1, partly = ½, not found = 0, by the weight the brief gives each item.</p>` : '' )
			+ '</details>';

		/*
		 * Instructors: papers on the same topic (OpenAlex, searched from the browser; nothing is stored),
		 * and "Develop it with Claude", which opens a chat in Claude with the project and the papers.
		 */
		function research( btn, box ) {
			let papers = [], loaded = false;
			const venue = w => ( w.primary_location && w.primary_location.source && w.primary_location.source.display_name ) || '';
			const link = w => w.doi || ( w.primary_location && w.primary_location.landing_page_url ) || w.id;
			const safeUrl = u => ( typeof u === 'string' && /^https?:\/\//i.test( u.trim() ) ) ? u.trim() : '#';
			function draw( q, note ) {
				box.innerHTML = `<form class="ufp-res-q"><input type="search" value="${ esc( q ) }" aria-label="Search topic"><button type="submit">Search</button></form>`
					+ ( note ? `<p class="ufp-res-note">${ esc( note ) }</p>` : '' )
					+ ( papers.length ? '<ol class="ufp-res-list">' + papers.map( w => `<li><a href="${ esc( safeUrl( link( w ) ) ) }" target="_blank" rel="noopener">${ esc( w.display_name || '' ) }</a>`
						+ `<span>${ [ w.publication_year, venue( w ), ( w.cited_by_count || 0 ) + ' citations' ].filter( Boolean ).map( esc ).join( ' · ' ) }`
						+ ( w.open_access && w.open_access.oa_url && safeUrl( w.open_access.oa_url ) !== '#' ? ` · <a href="${ esc( safeUrl( w.open_access.oa_url ) ) }" target="_blank" rel="noopener">Free PDF</a>` : '' ) + '</span></li>' ).join( '' ) + '</ol>' : '' )
					+ ( papers.length ? '<p class="ufp-res-go"><button type="button" class="ufp-claude">Develop it with Claude</button><small>Opens a chat in Claude with this project and these papers.</small></p>' : '' );
				box.querySelector( 'form' ).addEventListener( 'submit', e => { e.preventDefault(); search( box.querySelector( 'input' ).value.trim() ); } );
				const go = box.querySelector( '.ufp-claude' );
				if ( go ) go.addEventListener( 'click', () => window.open( 'https://claude.ai/new?q=' + encodeURIComponent( prompt() ), '_blank', 'noopener' ) );
			}
			function search( q, retried ) {
				if ( ! q ) return;
				draw( q, 'Searching…' );
				fetch( 'https://api.openalex.org/works?per-page=10&search=' + encodeURIComponent( q ) + '&select=id,doi,display_name,publication_year,cited_by_count,primary_location,open_access' )
					.then( r => { if ( ! r.ok ) { const e = new Error( 'http' ); e.status = r.status; throw e; } return r.json(); } )
					.then( j => { papers = j.results || []; draw( q, papers.length ? '' : 'Nothing found. Try other words.' ); } )
					.catch( e => {
						// a dropped connection or a busy moment usually clears in a second or two: try once more by itself
						if ( ! retried ) { setTimeout( () => search( q, true ), 2000 ); return; }
						draw( q, e.status === 429 ? 'The research database is busy (too many searches from this network today). Try again later.'
							: e.status ? 'The research database answered with an error (' + e.status + '). Try again in a minute.'
							: 'Could not reach the research database. Check the internet connection, then press Search.' );
					} );
			}
			function prompt() {
				return 'I am an instructor. One of my students did the engineering project below, and I want to develop the idea further: '
					+ 'what is new or missing compared with the published work, which papers I should read first and why, and two or three '
					+ 'stronger follow-up projects or research questions with the experiment for each.\n\n'
					+ P.research.summary + '\n\nRelated papers:\n'
					+ papers.map( ( w, i ) => ( i + 1 ) + '. ' + w.display_name + ' (' + ( w.publication_year || 'n.d.' ) + ') ' + link( w ) ).join( '\n' );
			}
			btn.addEventListener( 'click', () => {
				const open = box.hidden;
				box.hidden = ! open; btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				if ( open && ! loaded ) { loaded = true; search( P.research.query ); }
			} );
		}

		/* ---------- head + part cards ---------- */
		const h = P.headline;
		head.innerHTML = `<h1 class="ufp-q">${ esc( P.question || P.title ) }</h1>`
			+ ( P.question ? `<p class="ufp-title">${ esc( P.title ) }</p>` : '' )
			+ ( P.summary ? `<p class="ufp-desc">${ esc( P.summary ) }</p>` : '' )
			+ ( h || P.brief ? '<div class="ufp-scores">' : '' )
			+ ( h ? `<div class="ufp-result"><b>${ h.before !== null ? esc( h.before ) + ' <span class="ufp-arr">→</span> ' : '' }<em>${ esc( h.after ) }</em> <small>${ esc( h.unit ) }</small></b><span>${ esc( h.label ) }</span></div>` : '' )
			+ ( P.brief ? briefCard( P.brief ) : '' )
			+ ( h || P.brief ? '</div>' : '' )
			+ ( P.credit ? `<p class="ufp-credit">${ esc( P.credit ) }</p>` : '' )
			+ '<div class="ufp-headbtns"><button type="button" class="ufp-carbtn" data-car>See it on the car</button>'
			+ ( P.research ? '<button type="button" class="ufp-resbtn" data-research aria-expanded="false">Related research</button>' : '' ) + '</div>'
			+ ( P.research ? '<section class="ufp-research" hidden aria-label="Related research"></section>' : '' );
		head.querySelector( '[data-car]' ).addEventListener( 'click', () => openCar( null ) );
		if ( P.research ) research( head.querySelector( '[data-research]' ), head.querySelector( '.ufp-research' ) );
		const desc = head.querySelector( '.ufp-desc' );
		// inline symbols ($\hat{b}$); the math library may still be loading, so wait for it a little
		( function typeset( tries ) {
			if ( ! desc || ! desc.textContent.includes( '$' ) ) return;
			if ( window.renderMathInElement ) window.renderMathInElement( desc, { delimiters: [ { left: '$', right: '$', display: false } ], throwOnError: false } );
			else if ( tries < 40 ) setTimeout( () => typeset( tries + 1 ), 150 );
		}( 0 ) );

		grid.innerHTML = P.parts.map( p => `
			<article class="ufp-card${ canPlay( p ) ? ' is-play' : '' }">
				<h3>${ esc( p.name ) }</h3>
				<p>${ esc( p.plain ) }</p>
				${ p.on.length ? `<p class="ufp-hw">${ esc( hwNames( p ) ) }</p>` : '' }
				${ reviewNote( p ) }
				<div class="ufp-actions">
					${ canPlay( p ) ? `<button type="button" class="ufp-break" data-sim="${ esc( p.id ) }"><span class="ufp-ic" aria-hidden="true"></span>Break it</button>` : '' }
					${ p.on.length ? `<button type="button" class="ufp-oncar" data-on="${ esc( p.id ) }">On the car</button>` : '' }
					${ figLabel( p ) ? `<button type="button" class="ufp-figbtn" data-fig="${ esc( p.id ) }" aria-label="${ esc( figLabel( p ) ) }" title="${ esc( figLabel( p ) ) }"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m4 17 5-5 4 4 3-3 4 4"/></svg>${ ( p.media || [] ).length > 1 ? `<span>${ p.media.length }</span>` : '' }</button>` : '' }
				</div>
			</article>` ).join( '' );
		grid.querySelectorAll( '[data-sim]' ).forEach( b => b.addEventListener( 'click', () => openSim( part( b.dataset.sim ) ) ) );
		grid.querySelectorAll( '[data-on]' ).forEach( b => b.addEventListener( 'click', () => openCar( b.dataset.on ) ) );
		grid.querySelectorAll( '[data-fig]' ).forEach( b => b.addEventListener( 'click', () => openFigures( part( b.dataset.fig ) ) ) );

		/* ---------- figures and videos, full size ---------- */
		const box = document.createElement( 'div' );
		box.className = 'ufp-lightbox'; box.hidden = true; box.setAttribute( 'role', 'dialog' ); box.setAttribute( 'aria-modal', 'true' );
		box.innerHTML = '<div class="ufp-lb-in"><div class="ufp-lb-head"><h2></h2><button type="button" class="ufp-close">Close</button></div><div class="ufp-lb-body"></div><div class="ufp-lb-nav"><button type="button" class="ufp-oncar" data-step="-1">← Previous</button><span></span><button type="button" class="ufp-oncar" data-step="1">Next →</button></div></div>';
		root.appendChild( box );
		let figs = [], figAt = 0, figFocus = null;
		function showFig() {
			const m = figs[ figAt ];
			box.querySelector( '.ufp-lb-body' ).innerHTML = ( m.type === 'video'
				? `<video controls autoplay playsinline src="${ esc( m.full ) }"></video>`
				: `<img src="${ esc( m.full ) }" alt="${ esc( m.caption ) }">` ) + ( m.caption ? `<p>${ esc( m.caption ) }</p>` : '' );
			const nav = box.querySelector( '.ufp-lb-nav' ); nav.hidden = figs.length < 2;
			nav.querySelector( 'span' ).textContent = ( figAt + 1 ) + ' / ' + figs.length;
		}
		function openFigures( p ) {
			if ( ! p || ! ( p.media || [] ).length ) return;
			figFocus = document.activeElement; figs = p.media; figAt = 0;
			box.querySelector( 'h2' ).textContent = p.name; box.hidden = false; document.documentElement.classList.add( 'ufp-locked' );
			showFig(); box.querySelector( '.ufp-close' ).focus();
		}
		function closeFigures() { box.hidden = true; box.querySelector( '.ufp-lb-body' ).innerHTML = ''; document.documentElement.classList.remove( 'ufp-locked' ); figFocus?.focus?.(); }
		box.querySelector( '.ufp-close' ).addEventListener( 'click', closeFigures );
		box.addEventListener( 'click', e => { if ( e.target === box ) closeFigures(); } );
		box.querySelectorAll( '[data-step]' ).forEach( b => b.addEventListener( 'click', () => { figAt = ( figAt + +b.dataset.step + figs.length ) % figs.length; showFig(); } ) );
		const part = id => P.parts.find( x => x.id === id );

		/* ---------- the car (mounted the first time it opens) ---------- */
		const feeds = new Set( P.flows.map( f => f.from + '>' + f.to ) );
		P.parts.forEach( ( p, i ) => {
			if ( i && feeds.has( P.parts[ i - 1 ].id + '>' + p.id ) ) { const a = document.createElement( 'span' ); a.className = 'ufp-arrow'; a.textContent = '→'; a.setAttribute( 'aria-hidden', 'true' ); flow.appendChild( a ); }
			const b = document.createElement( 'button' );
			b.type = 'button'; b.className = 'ufp-chip'; b.dataset.id = p.id; b.textContent = p.name;
			b.addEventListener( 'click', () => toggle( p.id ) );
			flow.appendChild( b );
		} );
		function mark() { flow.querySelectorAll( '.ufp-chip' ).forEach( c => { const on = !! selected && c.dataset.id === selected.id; c.classList.toggle( 'is-on', on ); c.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); } ); }
		function showInfo( html ) { info.innerHTML = html; info.hidden = ! html; const b = info.querySelector( '[data-sim]' ); if ( b ) b.addEventListener( 'click', () => openSim( part( b.dataset.sim ) ) ); }
		function toggle( id ) {
			if ( selected && selected.id === id ) { selected = null; car.clear(); mark(); showInfo( '' ); return; }
			const p = part( id ); selected = p; mark();
			car.focus( p.on, flow.querySelector( `.ufp-chip[data-id="${ id }"]` ) );
			showInfo( `<h3>${ esc( p.name ) }</h3><p>${ esc( p.plain ) }</p>` + ( canPlay( p ) ? `<button type="button" class="ufp-break" data-sim="${ esc( p.id ) }"><span class="ufp-ic" aria-hidden="true"></span>Break it</button>` : '' ) );
		}
		function onPick( id ) {
			selected = null; mark();
			if ( ! id ) { showInfo( '' ); return; }
			const users = P.parts.filter( p => p.on.includes( id ) );
			showInfo( `<h3>${ esc( HW[ id ].name ) }</h3><p>${ esc( HW[ id ].plain ) }</p>` + ( usedHw.has( id ) ? `<p class="ufp-hw">${ esc( users.map( u => u.name ).join( ' · ' ) ) }</p>` : '<p class="ufp-hw">Not used in this project.</p>' ) );
		}
		function openCar( id ) {
			lastFocus = document.activeElement;
			carSheet.hidden = false; document.documentElement.classList.add( 'ufp-locked' );
			if ( ! car ) car = window.UnfoldQCar.mount( stage, { used: [ ...usedHw ], onPick } );
			selected = null; car.clear(); mark(); showInfo( '' );
			if ( id ) requestAnimationFrame( () => toggle( id ) );
			carSheet.querySelector( '.ufp-close' ).focus();
		}
		function closeCar() { carSheet.hidden = true; document.documentElement.classList.remove( 'ufp-locked' ); lastFocus?.focus?.(); }
		carSheet.querySelector( '.ufp-close' ).addEventListener( 'click', closeCar );

		/* ---------- Break it ---------- */
		function openSim( p ) {
			if ( ! p ) return;
			if ( simSheet.hidden ) lastFocus = document.activeElement;
			simTitle.textContent = p.name; simSheet.hidden = false; simBody.innerHTML = '';
			document.documentElement.classList.add( 'ufp-locked' );
			stopSim = window.UnfoldTemplates.mount( simBody, p.template, { inputs: p.inputs, streams: p.streams } );
			simSheet.querySelector( '.ufp-close' ).focus();
			if ( window.UnfoldTrack ) window.UnfoldTrack( 'sim' );
		}
		function closeSim() {
			simSheet.hidden = true; if ( stopSim ) stopSim(); stopSim = null; simBody.innerHTML = '';
			if ( carSheet.hidden ) document.documentElement.classList.remove( 'ufp-locked' );
			lastFocus?.focus?.();
		}
		simSheet.querySelector( '.ufp-close' ).addEventListener( 'click', closeSim );
		simSheet.addEventListener( 'click', e => { if ( e.target === simSheet ) closeSim(); } );
		document.addEventListener( 'keydown', e => {
			if ( e.key !== 'Escape' ) return;
			if ( ! box.hidden ) closeFigures(); else if ( ! simSheet.hidden ) closeSim(); else if ( ! carSheet.hidden ) closeCar();
		} );
	} );

	// "All details": kept laid out while folded so diagrams and plots inside draw at full size.
	document.querySelectorAll( '.uf-p2-details' ).forEach( box => {
		const btn = box.querySelector( '.ufp-details-btn' ), body = box.querySelector( '.uf-p2-inner' );
		const set = open => { box.classList.toggle( 'is-folded', ! open ); btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' ); body.inert = ! open; };
		btn.addEventListener( 'click', () => set( box.classList.contains( 'is-folded' ) ) );
		if ( location.hash && location.hash !== '#uf-details' ) {
			try {
				const id = decodeURIComponent( location.hash.slice( 1 ) );
				const target = id ? document.getElementById( id ) : null;
				if ( target && body.contains( target ) ) set( true );
			} catch {}
		}
	} );
} )();
