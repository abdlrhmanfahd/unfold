/**
 * Student portal: chunked ZIP upload and the project workspace
 * (extraction status → review form → submit → instructor decision).
 */
( function () {
	'use strict';
	var C = window.UNFOLD_PORTAL;
	if ( ! C ) return;

	function api( method, path, body, raw ) {
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if ( raw ) { opts.body = body; opts.headers[ 'Content-Type' ] = 'application/octet-stream'; }
		else if ( body !== undefined ) { opts.body = JSON.stringify( body ); opts.headers[ 'Content-Type' ] = 'application/json'; }
		return fetch( C.rest + path, opts ).then( function ( r ) {
			return r.text().then( function ( t ) {
				var j = null;
				try { j = t ? JSON.parse( t ) : null; } catch {}
				if ( ! r.ok ) { var err = new Error( ( j && j.message ) || ( 'Request failed (' + r.status + ')' ) ); err.data = j; throw err; }
				return j;
			} );
		} );
	}
	function h( html ) { var d = document.createElement( 'div' ); d.innerHTML = html; return d.firstElementChild; }
	function esc( s ) { return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }
	function mb( n ) { return ( n / 1048576 ).toFixed( n > 104857600 ? 0 : 1 ) + ' MB'; }

	function mountAi( host ) {
		if ( ! host ) return null;
		var aiCfg = C.ai || { presets: [], user: {}, defaults: {}, models: {} };
		var presets = aiCfg.presets || [], u = aiCfg.user || {}, defs = aiCfg.defaults || {}, curated = aiCfg.models || {};
		var activeVal = 'quick';
		if ( u.mode === 'preset' && presets.some( function ( p ) { return p.id === u.preset_id; } ) ) activeVal = 'preset:' + u.preset_id;
		else if ( u.mode === 'byok' ) activeVal = 'byok';
		else if ( presets.length && u.mode === 'preset' ) activeVal = 'preset:' + presets[ 0 ].id;

		var radios = presets.map( function ( p ) {
			return '<label class="uf-ai-opt"><input type="radio" name="uf_ai_mode" value="' + esc( 'preset:' + p.id ) + '"' + ( activeVal === 'preset:' + p.id ? ' checked' : '' ) + '>'
				+ '<span><strong>' + esc( p.label ) + '</strong><small>' + esc( p.provider + ' · ' + p.model ) + '</small></span></label>';
		} ).join( '' )
		+ '<label class="uf-ai-opt"><input type="radio" name="uf_ai_mode" value="byok"' + ( activeVal === 'byok' ? ' checked' : '' ) + '>'
			+ '<span><strong>Use my own API key (saved to my account)</strong><small>Paste a Gemini, Groq, OpenRouter, OpenAI, or Anthropic key — provider &amp; available models are detected automatically.</small></span></label>'
		+ '<label class="uf-ai-opt"><input type="radio" name="uf_ai_mode" value="quick"' + ( activeVal === 'quick' ? ' checked' : '' ) + '>'
			+ '<span><strong>Quick Extract (No AI — instant)</strong><small>Parses the report sections, subsystems, code parameters, CSV columns, metrics &amp; figures cleanly without an API key.</small></span></label>';

		var prov = u.provider || 'gemini';
		var initModel = u.model || defs[ prov ] || '';
		host.innerHTML = '<p class="uf-ai-title">Extraction method</p>'
			+ '<div class="uf-ai-modes">' + radios + '</div>'
			+ '<div class="uf-ai-byok" data-uf-ai-byok' + ( activeVal === 'byok' ? '' : ' hidden' ) + '>'
			+ '<label class="uf-field"><span>API Key <small>(paste your key first to auto-detect provider &amp; load models)</small></span>'
			+ '<input type="password" autocomplete="new-password" data-uf-ai-key placeholder="' + esc( u.has_key ? 'Saved (…' + ( u.key_hint || '****' ) + ') — leave blank to keep' : 'Paste your API key (AIza…, gsk_…, sk-or-…, sk-…)' ) + '" value="">'
			+ '<span class="uf-ai-status" data-uf-ai-status' + ( u.has_key ? '' : ' hidden' ) + '>Saved on your account (…' + esc( u.key_hint || '****' ) + ')</span></label>'
			+ '<div class="uf-field-row">'
			+ '<label class="uf-field"><span>Provider</span><select data-uf-ai-provider>'
				+ '<option value="gemini"' + ( prov === 'gemini' ? ' selected' : '' ) + '>Google Gemini (AI Studio Free / Paid)</option>'
				+ '<option value="openai_compat"' + ( prov === 'openai_compat' ? ' selected' : '' ) + '>OpenAI-Compatible (Groq / OpenRouter / OpenAI)</option>'
				+ '<option value="ollama"' + ( prov === 'ollama' ? ' selected' : '' ) + '>Ollama (Local Server)</option>'
				+ '<option value="anthropic"' + ( prov === 'anthropic' ? ' selected' : '' ) + '>Anthropic (Claude)</option>'
			+ '</select></label>'
			+ '<label class="uf-field"><span>Model</span><select data-uf-ai-model-select></select>'
			+ '<input type="text" data-uf-ai-model-custom placeholder="Enter custom model ID" value="" hidden></label>'
			+ '</div>'
			+ '<label class="uf-field" data-uf-ai-url-row' + ( prov === 'openai_compat' || prov === 'ollama' ? '' : ' hidden' ) + '><span>Base URL</span><input type="url" data-uf-ai-url placeholder="https://api.groq.com/openai/v1" value="' + esc( u.base_url || '' ) + '"></label>'
			+ '<div class="uf-actions" style="margin-top:0!important">'
			+ '<button type="button" class="uf-btn uf-btn--ghost" data-uf-ai-refresh>Refresh models</button>'
			+ '<button type="button" class="uf-btn uf-btn--ghost" data-uf-ai-save>Save AI settings</button>'
			+ '<button type="button" class="uf-btn uf-btn--ghost" data-uf-ai-clear' + ( u.has_key ? '' : ' hidden' ) + '>Clear saved key</button>'
			+ '</div>'
			+ '<p class="uf-ai-msg" data-uf-ai-msg aria-live="polite"></p>'
			+ '</div>';

		var byokBox = host.querySelector( '[data-uf-ai-byok]' ),
			provEl = host.querySelector( '[data-uf-ai-provider]' ),
			modelSel = host.querySelector( '[data-uf-ai-model-select]' ),
			modelCustom = host.querySelector( '[data-uf-ai-model-custom]' ),
			urlRow = host.querySelector( '[data-uf-ai-url-row]' ),
			urlEl = host.querySelector( '[data-uf-ai-url]' ),
			keyEl = host.querySelector( '[data-uf-ai-key]' ),
			statusEl = host.querySelector( '[data-uf-ai-status]' ),
			clearBtn = host.querySelector( '[data-uf-ai-clear]' ),
			msgEl = host.querySelector( '[data-uf-ai-msg]' );
		var fetchT = null;

		function populateModels( list, desired ) {
			var items = ( list && list.length ) ? list.slice() : ( curated[ provEl.value ] || [] ).slice();
			if ( desired && items.indexOf( desired ) === -1 && desired !== '__custom__' ) items.unshift( desired );
			modelSel.innerHTML = items.map( function ( m ) {
				return '<option value="' + esc( m ) + '">' + esc( m ) + '</option>';
			} ).join( '' ) + '<option value="__custom__">Other (custom model)…</option>';
			if ( desired && items.indexOf( desired ) !== -1 ) {
				modelSel.value = desired;
				modelCustom.hidden = true;
			} else if ( desired ) {
				modelSel.value = '__custom__';
				modelCustom.value = desired;
				modelCustom.hidden = false;
			} else if ( items.length ) {
				modelSel.value = items[ 0 ];
				modelCustom.hidden = true;
			}
		}

		function currentModel() {
			return modelSel.value === '__custom__' ? modelCustom.value.trim() : modelSel.value;
		}

		function syncUrlVisibility( nextProv ) {
			urlRow.hidden = nextProv !== 'openai_compat' && nextProv !== 'ollama';
			if ( nextProv === 'openai_compat' && ( ! urlEl.value.trim() || urlEl.value.indexOf( '11434' ) !== -1 ) ) urlEl.value = 'https://api.groq.com/openai/v1';
			if ( nextProv === 'ollama' && ( ! urlEl.value.trim() || urlEl.value.indexOf( 'groq.com' ) !== -1 ) ) urlEl.value = 'http://127.0.0.1:11434';
		}

		function detectFromKey( rawKey ) {
			var k = rawKey.trim();
			if ( ! k ) return null;
			if ( k.indexOf( 'AIza' ) === 0 ) return { provider: 'gemini', label: 'Google Gemini', base_url: '', model: 'gemini-2.5-flash' };
			if ( k.indexOf( 'gsk_' ) === 0 ) return { provider: 'openai_compat', label: 'Groq', base_url: 'https://api.groq.com/openai/v1', model: 'llama-3.3-70b-versatile' };
			if ( k.indexOf( 'sk-or-' ) === 0 ) return { provider: 'openai_compat', label: 'OpenRouter', base_url: 'https://openrouter.ai/api/v1', model: 'google/gemini-2.0-flash-exp:free' };
			if ( k.indexOf( 'sk-ant-' ) === 0 ) return { provider: 'anthropic', label: 'Anthropic', base_url: '', model: 'claude-sonnet-4-6' };
			if ( k.indexOf( 'sk-' ) === 0 && k.length >= 20 ) return { provider: 'openai_compat', label: 'OpenAI', base_url: 'https://api.openai.com/v1', model: 'gpt-4o-mini' };
			return null;
		}

		function loadAvailableModels( preferred, detectedLabel ) {
			msgEl.textContent = detectedLabel ? ( 'Detected ' + detectedLabel + ' key — loading available models…' ) : 'Loading models…';
			api( 'POST', '/me/ai/models', {
				provider: provEl.value,
				base_url: urlEl.value.trim(),
				api_key: keyEl.value.trim(),
			} ).then( function ( res ) {
				var models = ( res && res.models ) || [];
				populateModels( models, preferred || ( models[ 0 ] || '' ) );
				if ( res && res.live ) {
					msgEl.textContent = ( detectedLabel ? 'Detected ' + detectedLabel + ' · ' : '' ) + 'Loaded ' + models.length + ' live models from your key.';
				} else if ( detectedLabel ) {
					msgEl.textContent = 'Detected ' + detectedLabel + ' (using default model list).';
				} else {
					msgEl.textContent = ( res && res.error ) ? ( 'Using default models (' + res.error + ').' ) : '';
				}
			} ).catch( function () {
				populateModels( curated[ provEl.value ] || [], preferred );
				msgEl.textContent = detectedLabel ? ( 'Detected ' + detectedLabel + '.' ) : '';
			} );
		}

		populateModels( curated[ prov ] || [], initModel );

		function selected() {
			var r = host.querySelector( 'input[name="uf_ai_mode"]:checked' );
			return r ? r.value : 'quick';
		}

		function payload( includeKey ) {
			var val = selected();
			var mode = val.indexOf( 'preset:' ) === 0 ? 'preset' : val;
			var out = {
				mode: mode,
				preset_id: val.indexOf( 'preset:' ) === 0 ? val.slice( 7 ) : '',
				provider: provEl.value,
				model: currentModel(),
				base_url: urlEl.value.trim(),
			};
			if ( includeKey !== false && keyEl.value.trim() ) out.api_key = keyEl.value.trim();
			return out;
		}

		function applyUserState( next ) {
			aiCfg.user = next;
			keyEl.value = '';
			keyEl.placeholder = next.has_key ? 'Saved (…' + ( next.key_hint || '****' ) + ') — leave blank to keep' : 'Paste your API key (AIza…, gsk_…, sk-or-…, sk-…)';
			statusEl.hidden = ! next.has_key;
			statusEl.textContent = next.has_key ? 'Saved on your account (…' + ( next.key_hint || '****' ) + ')' : '';
			clearBtn.hidden = ! next.has_key;
		}

		host.querySelectorAll( 'input[name="uf_ai_mode"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				var v = selected();
				byokBox.hidden = v !== 'byok';
				api( 'PUT', '/me/ai', payload( false ) ).then( applyUserState ).catch( function () {} );
			} );
		} );

		provEl.addEventListener( 'change', function () {
			var next = provEl.value;
			syncUrlVisibility( next );
			populateModels( curated[ next ] || [], defs[ next ] || '' );
			if ( keyEl.value.trim() || u.has_key || next === 'ollama' ) loadAvailableModels( defs[ next ] || '', '' );
		} );

		modelSel.addEventListener( 'change', function () {
			modelCustom.hidden = modelSel.value !== '__custom__';
			if ( ! modelCustom.hidden ) modelCustom.focus();
		} );

		keyEl.addEventListener( 'input', function () {
			clearTimeout( fetchT );
			var det = detectFromKey( keyEl.value );
			if ( det ) {
				provEl.value = det.provider;
				if ( det.base_url ) urlEl.value = det.base_url;
				syncUrlVisibility( det.provider );
				populateModels( curated[ det.provider ] || [], det.model );
			}
			var raw = keyEl.value.trim();
			if ( raw.length >= 16 ) {
				fetchT = setTimeout( function () {
					loadAvailableModels( det ? det.model : currentModel(), det ? det.label : '' );
				}, 350 );
			}
		} );

		host.querySelector( '[data-uf-ai-refresh]' ).addEventListener( 'click', function () {
			loadAvailableModels( currentModel(), '' );
		} );

		host.querySelector( '[data-uf-ai-save]' ).addEventListener( 'click', function () {
			msgEl.textContent = 'Saving…';
			api( 'PUT', '/me/ai', payload( true ) ).then( function ( next ) {
				applyUserState( next );
				msgEl.textContent = 'Saved to your account.';
			} ).catch( function ( e ) { msgEl.textContent = e.message; } );
		} );

		clearBtn.addEventListener( 'click', function () {
			var body = payload( false );
			body.clear_key = true;
			msgEl.textContent = 'Clearing…';
			api( 'PUT', '/me/ai', body ).then( function ( next ) {
				applyUserState( next );
				msgEl.textContent = 'Saved API key removed.';
			} ).catch( function ( e ) { msgEl.textContent = e.message; } );
		} );

		return { payload: function () { return payload( true ); } };
	}

	/* ------------------------------------------------------------------
	 * Upload (dashboard and "replace files")
	 * ---------------------------------------------------------------- */
	function upload( file, projectId, ui, getAi ) {
		if ( ! /\.zip$/i.test( file.name ) ) return Promise.reject( new Error( 'Choose a .zip file.' ) );
		if ( file.size > C.maxZip ) return Promise.reject( new Error( 'The ZIP must be smaller than 1 GB.' ) );
		ui.start( file );
		return api( 'POST', '/uploads', { name: file.name, size: file.size, project: projectId || 0 } ).then( function ( s ) {
			var offset = 0, size = s.chunk_bytes, t0 = Date.now();
			function next() {
				if ( offset >= file.size ) {
					var doneBody = { project: projectId || 0 };
					if ( getAi ) { var aiVal = getAi(); if ( aiVal ) doneBody.ai = aiVal; }
					return api( 'POST', '/uploads/' + s.upload_id + '/complete', doneBody );
				}
				var blob = file.slice( offset, Math.min( file.size, offset + size ) );
				return api( 'PUT', '/uploads/' + s.upload_id + '?offset=' + offset, blob, true ).then( function ( r ) {
					offset = r.received;
					var secs = ( Date.now() - t0 ) / 1000, rate = offset / Math.max( secs, 0.1 );
					ui.progress( offset / file.size, mb( offset ) + ' of ' + mb( file.size ) + ( offset < file.size ? ' · about ' + Math.ceil( ( file.size - offset ) / rate ) + ' s left' : ' · checking the archive…' ) );
					return next();
				} );
			}
			return next();
		} );
	}

	function bindDrop( zone, onFile ) {
		var input = zone.querySelector( 'input[type=file]' );
		input.addEventListener( 'change', function () {
			if ( input.files[ 0 ] ) {
				var f = input.files[ 0 ];
				input.value = '';
				onFile( f );
			}
		} );
		[ 'dragenter', 'dragover' ].forEach( function ( ev ) { zone.addEventListener( ev, function ( e ) { e.preventDefault(); zone.classList.add( 'is-over' ); } ); } );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) { zone.addEventListener( ev, function ( e ) { e.preventDefault(); zone.classList.remove( 'is-over' ); } ); } );
		zone.addEventListener( 'drop', function ( e ) { var f = e.dataTransfer.files[ 0 ]; if ( f ) onFile( f ); } );
	}

	function uploadUI( root ) {
		var prog = root.querySelector( '[data-uf-progress]' ), bar = prog.querySelector( '.uf-progress-bar span' ), txt = root.querySelector( '[data-uf-progress-text]' ), err = root.querySelector( '[data-uf-upload-error]' ), drop = root.querySelector( '[data-uf-drop]' );
		return {
			start: function ( f ) { err.hidden = true; prog.hidden = false; drop.classList.add( 'is-busy' ); bar.style.width = '0%'; txt.textContent = 'Uploading ' + f.name + '…'; },
			progress: function ( p, t ) { bar.style.width = Math.round( p * 100 ) + '%'; txt.textContent = t; },
			fail: function ( e ) { prog.hidden = true; drop.classList.remove( 'is-busy' ); err.hidden = false; err.textContent = e.message; },
		};
	}

	var dash = document.getElementById( 'uf-upload' );
	if ( dash ) {
		var ui = uploadUI( dash );
		var dashAi = mountAi( dash.querySelector( '[data-uf-ai]' ) );
		bindDrop( dash.querySelector( '[data-uf-drop]' ), function ( f ) {
			upload( f, 0, ui, function () { return dashAi ? dashAi.payload() : null; } ).then( function ( p ) { location.href = C.workspace + ( C.workspace.indexOf( '?' ) === -1 ? '?' : '&' ) + 'project=' + p.id; } ).catch( ui.fail );
		} );
	}

	/* ------------------------------------------------------------------
	 * Workspace
	 * ---------------------------------------------------------------- */
	var ws = document.getElementById( 'uf-workspace' );
	if ( ! ws || ! C.project ) return;

	var P = null, form = null, dirty = false, dirtyWhileSaving = false, saving = null, pollT = null, saveT = null;
	var STEPS = [ [ 'upload', 'Upload' ], [ 'read', 'AI reads your files' ], [ 'review', 'You review' ], [ 'submit', 'Instructor review' ], [ 'live', 'Published' ] ];
	var STEP_OF = { queued: 1, extracting: 1, failed: 1, review: 2, changes: 2, submitted: 3, approved: 4 };

	function stepper( state ) {
		var cur = STEP_OF[ state ];
		return '<ol class="uf-steps">' + STEPS.map( function ( s, i ) {
			var cls = i < cur ? 'is-done' : i === cur ? 'is-current' + ( state === 'failed' || state === 'changes' ? ' is-alert' : '' ) : '';
			return '<li class="' + cls + '"' + ( i === cur ? ' aria-current="step"' : '' ) + '><span class="uf-step-dot">' + ( i < cur ? '✓' : i + 1 ) + '</span><span>' + esc( s[ 1 ] ) + '</span></li>';
		} ).join( '' ) + '</ol>';
	}

	function load() { return api( 'GET', '/projects/' + C.project ).then( function ( p ) { P = p; render(); } ); }

	// "1:42 so far · usually about 4 min" — the reader is one AI request, so there is no true
	// percentage; the typical time comes from earlier projects read on this site (none: not shown).
	function readTiming( since, typical ) {
		var el = Math.max( 0, Date.now() / 1000 - since ), t = Math.floor( el / 60 ) + ':' + String( Math.floor( el % 60 ) ).padStart( 2, '0' ) + ' so far';
		if ( typical ) t += el < typical ? ' · usually about ' + Math.max( 1, Math.round( typical / 60 ) ) + ' min' : ' · taking longer than usual';
		return t;
	}
	setInterval( function () {
		var n = document.querySelector( '[data-uf-since]' );
		if ( n ) n.textContent = readTiming( +n.dataset.ufSince, +n.dataset.ufTypical );
	}, 1000 );

	function render() {
		clearTimeout( pollT );
		var s = P.state, html = stepper( s );
		var totals = P.manifest.totals || {}, filesLine = P.zip ? '<p class="uf-muted">' + esc( P.zip ) + ' · ' + P.manifest.count + ' files (' + Object.keys( totals ).map( function ( k ) { return totals[ k ] + ' ' + k; } ).join( ', ' ) + ')</p>' : '';

		if ( s === 'queued' || s === 'extracting' ) {
			if ( s === 'queued' && ! P.worker_online ) {
				html += '<div class="uf-state-card"><div><h2 class="uf-h3">Uploaded — waiting for the AI reader</h2>'
					+ '<p>Your files are saved. The AI reader that fills in the form is not running right now, so nothing is happening yet. It will start on its own as soon as the site administrator turns it on; you can leave this page.</p>'
					+ filesLine + '</div></div>';
				pollT = setTimeout( load, 15000 );
				ws.innerHTML = html;
				return;
			}
			html += '<div class="uf-state-card uf-state-card--busy"><div class="uf-spinner" aria-hidden="true"></div><div><h2 class="uf-h3">' + ( s === 'queued' ? 'Waiting for the AI to start…' : 'Reading your report and files…' ) + '</h2>'
				+ '<p>Unfold is breaking the project into parts, collecting equations, parameters and results, and noting where in the report each value comes from. You can leave this page.</p>'
				+ ( s === 'extracting' && P.job && P.job.claimed_at ? '<p class="uf-muted" data-uf-since="' + ( P.job.claimed_at - ( P.now - Date.now() / 1000 ) ) + '" data-uf-typical="' + ( P.typical_s || 0 ) + '">' + readTiming( P.job.claimed_at - ( P.now - Date.now() / 1000 ), P.typical_s ) + '</p>' : '' )
				+ filesLine + '</div></div>';
			pollT = setTimeout( load, 5000 );
		} else if ( s === 'failed' ) {
			html += '<div class="uf-state-card uf-state-card--alert"><div><h2 class="uf-h3">The AI could not finish reading this project</h2><p class="uf-muted">' + esc( ( P.job && P.job.error ) || 'Unknown error.' ) + '</p>' + filesLine
				+ '<div class="uf-ai-box" data-uf-ai></div>'
				+ '<p class="uf-actions"><button class="uf-btn" data-uf-retry>Try again</button></p></div></div>';
		} else if ( s === 'submitted' ) {
			html += '<div class="uf-state-card"><div><h2 class="uf-h3">Submitted — waiting for your instructor</h2><p>You will see the decision here. The project cannot be edited while it is being reviewed.</p><p class="uf-actions"><a class="uf-btn uf-btn--ghost" href="' + previewUrl() + '">Preview the page</a></p></div></div>';
		} else if ( s === 'approved' ) {
			html += '<div class="uf-state-card uf-state-card--ok"><div><h2 class="uf-h3">Published</h2><p>Your project is live on the site.</p><p class="uf-actions"><a class="uf-btn" href="' + esc( P.url ) + '">View the live page</a></p></div></div>';
		}

		if ( P.review && ( s === 'changes' || s === 'review' ) && P.review.decision === 'changes' && P.review.note ) {
			html += '<div class="uf-alert uf-alert--warn" role="status"><strong>Your instructor returned the project with notes:</strong><br>' + esc( P.review.note ) + '</div>';
		}

		var editing = ( s === 'review' || s === 'changes' ) && P.editable;
		if ( editing ) {
			html += '<div class="uf-editor-intro"><div><h2 class="uf-h3">Check what Unfold found</h2><p class="uf-muted">Everything below was read from your report. Fix anything wrong, fill in what is missing, and mark each number <strong>✓ Confirmed</strong> once you have checked it. Values that do not exist in your project can be marked <em>Doesn’t apply</em>. Never guess a number.</p>' + filesLine + '</div>'
				+ '<div class="uf-editor-meter" data-uf-meter></div></div>'
				+ '<div class="uf-errors" data-uf-errors></div>'
				+ mediaNotes()
				+ '<div class="uf-form-host" data-uf-form></div>'
				+ '<div class="uf-savebar" role="region" aria-label="Save and submit"><span class="uf-save-status" data-uf-save-status aria-live="polite"></span>'
				+ '<a class="uf-btn uf-btn--ghost" href="' + previewUrl() + '" data-uf-preview>Preview</a>'
				+ '<button class="uf-btn uf-btn--ghost" data-uf-save>Save</button>'
				+ '<button class="uf-btn" data-uf-submit>Submit for review</button></div>';
		}
		ws.innerHTML = html;

		var retryAi = mountAi( ws.querySelector( '[data-uf-ai]' ) );
		var retry = ws.querySelector( '[data-uf-retry]' );
		if ( retry ) retry.addEventListener( 'click', function () {
			api( 'POST', '/projects/' + C.project + '/extract', { ai: retryAi ? retryAi.payload() : undefined } ).then( function ( p ) { P = p; render(); } ).catch( function ( e ) {
				ws.innerHTML = '<div class="uf-alert uf-alert--error">' + esc( e.message ) + '</div>';
			} );
		} );

		if ( editing ) mountEditor();
	}

	function previewUrl() { return C.workspace + ( C.workspace.indexOf( '?' ) === -1 ? '?' : '&' ) + 'project=' + C.project + '&preview=1'; }

	function mediaNotes() {
		var n = P.media_notes || {}, keys = Object.keys( n );
		if ( ! keys.length ) return '';
		return '<div class="uf-alert uf-alert--warn" role="status"><strong>' + ( keys.length === 1 ? 'One figure or video' : keys.length + ' figures or videos' ) + ' will not show as expected:</strong><ul>'
			+ keys.map( function ( k ) { return '<li><code>' + esc( k ) + '</code>: ' + esc( n[ k ] ) + '</li>'; } ).join( '' ) + '</ul></div>';
	}

	function mountEditor() {
		var data = P.data || { schema_version: '0.1' };
		form = window.UnfoldForm.mount( ws.querySelector( '[data-uf-form]' ), {
			schema: C.schema, data: data, files: P.files || [],
			onChange: function () { dirty = true; if ( saving ) dirtyWhileSaving = true; status( 'Unsaved changes' ); meter(); clearTimeout( saveT ); saveT = setTimeout( function () { save().catch( function () {} ); }, 2500 ); },
		} );
		meter();
		showErrors( P.errors );
		ws.querySelector( '[data-uf-save]' ).addEventListener( 'click', function () { save().catch( function () {} ); } );
		ws.querySelector( '[data-uf-submit]' ).addEventListener( 'click', submit );
		ws.querySelector( '[data-uf-preview]' ).addEventListener( 'click', function ( e ) {
			if ( dirty ) { e.preventDefault(); var href = this.href; save().then( function () { location.href = href; } ).catch( function () {} ); }
		} );
		status( P.valid ? 'All changes saved' : '' );
	}

	function meter() {
		var c = form.counts(), pct = c.tracked ? Math.round( 100 * c.confirmed / c.tracked ) : 0;
		var m = ws.querySelector( '[data-uf-meter]' );
		if ( m ) m.innerHTML = '<p class="uf-editor-meter-num">' + pct + '%</p><p class="uf-muted">' + c.confirmed + ' of ' + c.tracked + ' values checked' + ( c.missing ? ' · ' + c.missing + ' not in the report' : '' ) + '</p><div class="uf-meter"><span style="width:' + pct + '%"></span></div>';
	}

	function status( t ) { var s = ws.querySelector( '[data-uf-save-status]' ); if ( s ) s.textContent = t; }

	function showErrors( errors ) {
		var box = ws.querySelector( '[data-uf-errors]' );
		if ( ! box ) return;
		if ( ! errors || ! errors.length ) { box.innerHTML = ''; return; }
		box.innerHTML = '<div class="uf-alert uf-alert--error" role="alert"><strong>' + errors.length + ( errors.length === 1 ? ' thing needs fixing' : ' things need fixing' ) + ' before you can submit:</strong><ul>' + errors.slice( 0, 30 ).map( function ( e ) {
			var i = e.indexOf( ': ' ), path = i > 0 ? e.slice( 0, i ) : '', msg = i > 0 ? e.slice( i + 2 ) : e;
			return '<li>' + ( path && path !== '(top level)' ? '<button type="button" class="uf-linkbtn" data-path="' + esc( path ) + '">' + esc( pretty( path ) ) + '</button> — ' : '' ) + esc( msg ) + '</li>';
		} ).join( '' ) + '</ul></div>';
		box.querySelectorAll( '[data-path]' ).forEach( function ( b ) { b.addEventListener( 'click', function () { form.focusPath( b.getAttribute( 'data-path' ) ); } ); } );
	}

	// "subsystems[2].parameters[0].status" → "Subsystem 3 › parameters 1 › status"
	function pretty( path ) {
		return path.replace( /\[(\d+)\]/g, function ( m, n ) { return ' ' + ( +n + 1 ); } ).split( '.' ).map( function ( p ) { return p.replace( /_/g, ' ' ); } ).join( ' › ' );
	}

	function save() {
		if ( ! form ) return Promise.resolve();
		clearTimeout( saveT );
		if ( saving ) return saving;
		status( 'Saving…' );
		dirtyWhileSaving = false;
		saving = api( 'PUT', '/projects/' + C.project + '/data', { data: form.getData() } ).then( function ( p ) {
			P.errors = p.errors; P.valid = p.valid; P.state = p.state;
			showErrors( p.errors );
			if ( dirtyWhileSaving ) {
				saving = null;
				return save();
			}
			dirty = false;
			status( p.errors.length ? 'Saved — ' + p.errors.length + ' to fix before submitting' : 'All changes saved' );
		}, function ( e ) {
			saving = null;
			status( 'Could not save: ' + e.message );
			throw e;
		} ).then( function ( res ) {
			saving = null;
			return res;
		} );
		return saving;
	}

	function submit() {
		save().then( function () {
			if ( P.errors && P.errors.length ) { ws.querySelector( '[data-uf-errors]' ).scrollIntoView( { behavior: 'smooth' } ); return; }
			var c = form.counts();
			var msg = c.tracked - c.confirmed > 0 ? ( c.tracked - c.confirmed ) + ' values are not marked as checked yet. Submit anyway? Unchecked values are shown to visitors with an "unconfirmed" marker.' : 'Submit this project for review? You cannot edit it while it is being reviewed.';
			var sims = ( ( form.getData() || {} ).subsystems || [] ).filter( function ( s ) { return s.sim && s.sim.template && s.sim.student_approved; } ).length;
			if ( sims ) msg += ' ' + sims + ' simulation' + ( sims > 1 ? 's are' : ' is' ) + ' marked as checked and will be shown to visitors; make sure you tried ' + ( sims > 1 ? 'them' : 'it' ) + '.';
			confirmBox( msg, function () {
				api( 'POST', '/projects/' + C.project + '/submit' ).then( function ( p ) { P = p; render(); window.scrollTo( { top: 0, behavior: 'smooth' } ); } ).catch( function ( e ) { status( e.message ); } );
			} );
		} ).catch( function () {} );
	}

	// In-page confirmation (no browser dialogs).
	function confirmBox( msg, ok ) {
		var old = ws.querySelector( '.uf-confirm' );
		if ( old ) old.remove();
		var bar = ws.querySelector( '.uf-savebar' );
		var box = h( '<div class="uf-confirm" role="alertdialog" aria-live="assertive"><p>' + esc( msg ) + '</p><p class="uf-actions"><button class="uf-btn" data-yes>Submit</button> <button class="uf-btn uf-btn--ghost" data-no>Keep editing</button></p></div>' );
		bar.parentNode.insertBefore( box, bar );
		box.querySelector( '[data-yes]' ).focus();
		box.querySelector( '[data-yes]' ).addEventListener( 'click', function () { box.remove(); ok(); } );
		box.querySelector( '[data-no]' ).addEventListener( 'click', function () { box.remove(); } );
	}

	window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
	load().catch( function ( e ) { ws.innerHTML = '<div class="uf-alert uf-alert--error">' + esc( e.message ) + '</div>'; } );
} )();
