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
