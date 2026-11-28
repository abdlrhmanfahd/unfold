import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function loadBrowserScript( relPath ) {
	const code = fs.readFileSync( new URL( '../../' + relPath, import.meta.url ), 'utf8' );
	const sandbox = {
		window: {},
		document: {
			querySelector: () => null,
			querySelectorAll: () => [],
		},
		location: { hash: '' },
		URL,
		encodeURIComponent,
		decodeURIComponent,
		setTimeout,
		clearTimeout,
	};
	vm.runInNewContext( code, sandbox );
	return sandbox.window;
}

test( 'Related research exposes redirected AI providers and filters checked papers in prompt', () => {
	const win = loadBrowserScript( 'wp-content/plugins/unfold/assets/js/qcar-project.js' );
	const api = win.UnfoldResearch;
	assert.ok( api, 'UnfoldResearch API should be registered' );

	const ids = Array.from( api.AI_PROVIDERS, p => p.id );
	assert.deepEqual( ids, [ 'claude', 'chatgpt', 'perplexity', 'grok', 'copilot' ] );
	for ( const p of api.AI_PROVIDERS ) {
		assert.match( p.url, /^https:\/\/.+\?q=$/ );
	}

	const papers = [
		{ id: 'https://openalex.org/W1', doi: 'https://doi.org/10.1000/a', display_name: 'Paper One', publication_year: 2025 },
		{ id: 'https://openalex.org/W2', doi: 'https://doi.org/10.1000/b', display_name: 'Paper Two', publication_year: 2026 },
	];

	const onlySecond = api.buildResearchPrompt( 'QCar LiDAR project', papers, new Set( [ 1 ] ) );
	assert.doesNotMatch( onlySecond, /Paper One/ );
	assert.match( onlySecond, /1\. Paper Two \(2026\) https:\/\/doi\.org\/10\.1000\/b/ );

	const none = api.buildResearchPrompt( 'QCar LiDAR project', papers, new Set() );
	assert.doesNotMatch( none, /Related papers:/ );
} );

test( 'Related research falls back cleanly to Crossref when OpenAlex returns 503', async () => {
	const win = loadBrowserScript( 'wp-content/plugins/unfold/assets/js/qcar-project.js' );
	const api = win.UnfoldResearch;

	const openAlexCalls = [];
	const crossrefCalls = [];
	const mockFetch = async url => {
		if ( url.startsWith( 'https://api.openalex.org/' ) ) {
			openAlexCalls.push( url );
			return { ok: false, status: 503, json: async () => ( {} ) };
		}
		if ( url.startsWith( 'https://api.crossref.org/' ) ) {
			crossrefCalls.push( url );
			return {
				ok: true,
				status: 200,
				json: async () => ( {
					message: {
						items: [
							{
								DOI: '10.1109/IV.2025.001',
								title: [ 'LiDAR Safety Envelope for Autonomous Cars' ],
								published: { 'date-parts': [ [ 2025 ] ] },
								'is-referenced-by-count': 12,
								'container-title': [ 'IEEE IV' ],
								URL: 'https://ieeexplore.ieee.org/document/123',
							},
						],
					},
				} ),
			};
		}
		throw new Error( 'Unexpected URL: ' + url );
	};

	const res = await api.fetchPapers( 'QCar LiDAR obstacle detection', mockFetch );
	assert.equal( openAlexCalls.length, 1 );
	assert.equal( crossrefCalls.length, 1 );
	assert.equal( res.source, 'crossref' );
	assert.equal( res.papers.length, 1 );
	assert.equal( res.papers[ 0 ].display_name, 'LiDAR Safety Envelope for Autonomous Cars' );
	assert.equal( res.papers[ 0 ].publication_year, 2025 );
	assert.equal( res.papers[ 0 ].cited_by_count, 12 );
	assert.equal( res.papers[ 0 ].doi, 'https://doi.org/10.1109/IV.2025.001' );
} );

test( 'Recorded data CSV plotter handles categorical x, unordered scatter x, quoted numbers, and legend placement', () => {
	const win = loadBrowserScript( 'wp-content/plugins/unfold/assets/js/project.js' );
	const csv = win.UnfoldCsvPlot;
	assert.ok( csv, 'UnfoldCsvPlot API should be registered' );

	// 1. Categorical x column (e.g. block_id = B01, B02, B03) stays string category with lines+markers.
	const catCsv = 'block_id,block_loss,cmin_m\n"B01","0.25","1.2"\n"B02","0.10","1.5"\n"B03","0.05","1.8"\n';
	const catSpec = csv.buildPlotSpec( catCsv, [ 'block_id', 'block_loss', 'cmin_m' ], { block_loss: 'loss units', cmin_m: 'm' } );
	assert.deepEqual( Array.from( catSpec.traces[ 0 ].x ), [ 'B01', 'B02', 'B03' ] );
	assert.deepEqual( Array.from( catSpec.traces[ 0 ].y ), [ 0.25, 0.1, 0.05 ] );
	assert.equal( catSpec.traces[ 0 ].mode, 'lines+markers' );
	assert.ok( catSpec.layout.legend.y > 1, 'horizontal legend sits above plot to avoid overlapping x title' );
	assert.equal( catSpec.layout.xaxis.automargin, true );

	// 2. Non-monotonic numeric x (e.g. per-run t_cmin jumping up/down) uses markers instead of zig-zag lines.
	const scatterCsv = 't_cmin,cmin_m,loss\n5.2,0.4,0.1\n3.8,0.9,0.2\n6.7,0.3,0.4\n4.1,0.7,0.15\n';
	const scatterSpec = csv.buildPlotSpec( scatterCsv, [ 't_cmin', 'cmin_m', 'loss' ], { t_cmin: 's' } );
	assert.equal( scatterSpec.traces[ 0 ].mode, 'markers' );

	// 3. Monotonic time-series x uses clean lines.
	const tsCsv = 't,v\n0.0,0.2\n0.1,0.5\n0.2,0.6\n';
	const tsSpec = csv.buildPlotSpec( tsCsv, [ 't', 'v' ], { t: 's', v: 'm/s' } );
	assert.equal( tsSpec.traces[ 0 ].mode, 'lines' );
} );

test( 'Portal live extraction progress helper reflects real backend stages and streamed chars', () => {
	const win = loadBrowserScript( 'wp-content/plugins/unfold/assets/js/portal.js' );
	const api = win.UnfoldPortalHelpers;
	assert.ok( api, 'UnfoldPortalHelpers should be registered' );

	const queued = api.formatExtractProgress( 'queued', null );
	assert.equal( queued.pct, 5 );
	assert.equal( queued.milestones[ 0 ].status, 'pending' );

	const aiProg = api.formatExtractProgress( 'extracting', {
		progress: {
			stage: 'ai',
			label: 'Extracting with qwen3:14b…',
			detail: 'Writing structured fields…',
			pct: 64,
			chars: 1900,
		},
	} );
	assert.equal( aiProg.pct, 64 );
	assert.equal( aiProg.title, 'Extracting with qwen3:14b…' );
	assert.match( aiProg.detail, /Writing structured fields/ );
	assert.match( aiProg.detail, /chars received/ );
	assert.deepEqual(
		Array.from( aiProg.milestones, m => m.status ),
		[ 'done', 'done', 'active', 'pending' ]
	);

	const savingProg = api.formatExtractProgress( 'extracting', {
		progress: { stage: 'saving', label: 'Saving…', pct: 95 },
	} );
	assert.deepEqual(
		Array.from( savingProg.milestones, m => m.status ),
		[ 'done', 'done', 'done', 'active' ]
	);

	// Instructor projects skip the Instructor review step in the progress stepper.
	const instSteps = api.stepper( 'approved', true );
	const studSteps = api.stepper( 'submitted', false );
	assert.equal( instSteps.includes( 'Instructor review' ), false );
	assert.equal( studSteps.includes( 'Instructor review' ), true );
} );

test( 'Simulation templates validate path_follow and speed_loop physics checks', () => {
	const code = fs.readFileSync( new URL( '../../wp-content/plugins/unfold/assets/js/templates.js', import.meta.url ), 'utf8' );
	const sandbox = { window: {}, document: { querySelectorAll: () => [] }, ResizeObserver: class { observe() {} disconnect() {} } };
	vm.runInNewContext( code, sandbox );
	const T = sandbox.window.UnfoldTemplates;
	assert.ok( T.has( 'path_follow' ) );
	assert.ok( T.has( 'speed_loop' ) );
	assert.equal( T.has( 'unknown_template' ), false );

	const noRoute = T.check( 'path_follow', { inputs: {}, streams: [] } );
	assert.equal( noRoute.ok, false );

	const straight = T.check( 'path_follow', {
		inputs: { duration: 1, v_ref: 0.5, k: 1, b: 0, bhat: 0 },
		streams: [
			{ role: 'route', cols: { x: [ 0, 2 ], y: [ 0, 0 ] } },
			{ role: 'run', label: 'Straight', cols: { t: [ 0, 0.5, 1 ], e: [ 0, 0, 0 ] }, set: [] },
		],
	} );
	assert.equal( straight.ok, true );

	const noSpeed = T.check( 'speed_loop', { inputs: { v_ref: 0.5 }, streams: [] } );
	assert.equal( noSpeed.ok, false );
} );

test( 'Auth script toggles password visibility and ARIA state cleanly', () => {
	const listeners = [];
	const input = { type: 'password' };
	const attrs = {};
	const btn = {
		textContent: 'Show',
		closest: () => ( { querySelector: () => input } ),
		addEventListener: ( ev, fn ) => listeners.push( { ev, fn } ),
		setAttribute: ( k, v ) => { attrs[ k ] = v; },
	};
	const code = fs.readFileSync( new URL( '../../wp-content/plugins/unfold/assets/js/auth.js', import.meta.url ), 'utf8' );
	vm.runInNewContext( code, { window: {}, document: { querySelectorAll: () => [ btn ] } } );
	assert.equal( listeners.length, 1 );
	listeners[ 0 ].fn();
	assert.equal( input.type, 'text' );
	assert.equal( btn.textContent, 'Hide' );
	assert.equal( attrs[ 'aria-pressed' ], 'true' );
	listeners[ 0 ].fn();
	assert.equal( input.type, 'password' );
	assert.equal( btn.textContent, 'Show' );
	assert.equal( attrs[ 'aria-pressed' ], 'false' );
} );

test( 'Instructor decision form validates empty return note and posts approve decision', async () => {
	const msgEl = { textContent: '' };
	const noteInput = { value: '   ', focused: false, focus() { this.focused = true; } };
	const buttons = [ { value: 'approve', disabled: false, addEventListener() {} }, { value: 'changes', disabled: false, addEventListener() {} } ];
	let submitHandler = null;
	const formEl = {
		note: noteInput,
		getAttribute: () => '77',
		querySelector: sel => ( sel === '.uf-decision-msg' ? msgEl : null ),
		querySelectorAll: () => buttons,
		addEventListener: ( ev, fn ) => { if ( ev === 'submit' ) submitHandler = fn; },
	};
	const assignMsg = { textContent: '' };
	let assignSubmit = null;
	const assignForm = {
		reviewer_id: { value: '12' },
		getAttribute: () => '77',
		querySelector: sel => ( sel === '.uf-assign-msg' ? assignMsg : sel === 'button[type=submit]' ? { disabled: false } : null ),
		addEventListener: ( ev, fn ) => { if ( ev === 'submit' ) assignSubmit = fn; },
	};
	const roleMsg = { textContent: '' };
	let roleChange = null;
	const roleSel = {
		value: 'unfold_instructor',
		disabled: false,
		getAttribute: () => '15',
		closest: () => ( { querySelector: () => roleMsg } ),
		addEventListener: ( ev, fn ) => { if ( ev === 'change' ) roleChange = fn; },
	};
	const fetchCalls = [];
	const code = fs.readFileSync( new URL( '../../wp-content/plugins/unfold/assets/js/instructor.js', import.meta.url ), 'utf8' );
	vm.runInNewContext( code, {
		window: { UNFOLD_PORTAL: { rest: 'http://localhost:8881/rest', nonce: 'n1' } },
		document: {
			querySelector: sel => ( sel === '[data-uf-decision]' ? formEl : sel === '[data-uf-assign]' ? assignForm : null ),
			querySelectorAll: sel => ( sel === '[data-uf-role-select]' ? [ roleSel ] : [] ),
		},
		location: { reload() {} },
		setTimeout,
		fetch: async ( url, opts ) => {
			fetchCalls.push( { url, opts } );
			return { ok: true, json: async () => ( { ok: true } ) };
		},
	} );
	assert.ok( submitHandler );
	// Returning without note warns instead of posting.
	submitHandler( { preventDefault() {}, submitter: { value: 'changes' } } );
	assert.match( msgEl.textContent, /Write a note/ );
	assert.equal( noteInput.focused, true );
	assert.equal( fetchCalls.length, 0 );

	// Approve posts to /projects/77/review.
	noteInput.value = 'Looks good';
	submitHandler( { preventDefault() {}, submitter: { value: 'approve' } } );
	await new Promise( r => setTimeout( r, 10 ) );
	assert.equal( fetchCalls.length, 1 );
	assert.equal( fetchCalls[ 0 ].url, 'http://localhost:8881/rest/projects/77/review' );
	assert.equal( JSON.parse( fetchCalls[ 0 ].opts.body ).decision, 'approve' );

	// Lead Instructor reviewer reassignment posts to /projects/77/reviewer.
	assert.ok( assignSubmit );
	assignSubmit( { preventDefault() {} } );
	await new Promise( r => setTimeout( r, 10 ) );
	assert.equal( fetchCalls[ 1 ].url, 'http://localhost:8881/rest/projects/77/reviewer' );
	assert.equal( JSON.parse( fetchCalls[ 1 ].opts.body ).reviewer_id, 12 );

	// Team role delegation posts to /team/role.
	assert.ok( roleChange );
	roleChange();
	await new Promise( r => setTimeout( r, 10 ) );
	assert.equal( fetchCalls[ 2 ].url, 'http://localhost:8881/rest/team/role' );
	assert.deepEqual( JSON.parse( fetchCalls[ 2 ].opts.body ), { user_id: 15, role: 'unfold_instructor' } );
} );
