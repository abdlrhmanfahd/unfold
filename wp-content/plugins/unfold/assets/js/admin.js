/** Unfold admin: instant JSON syntax check, Format button, sample loader. */
( function () {
	'use strict';
	var ta = document.querySelector( 'textarea#unfold-json' );
	if ( ! ta ) return;
	var live = document.querySelector( '[data-unfold-live]' );
	var file = document.querySelector( 'input[name="unfold_json_file"]' );

	function lineCol( text, pos ) {
		var before = text.slice( 0, pos ).split( '\n' );
		return 'line ' + before.length + ', column ' + ( before[ before.length - 1 ].length + 1 );
	}

	function check() {
		var v = ta.value;
		if ( ! v.trim() ) {
			live.textContent = '';
			live.className = 'unfold-live';
			return;
		}
		try {
			JSON.parse( v );
			live.textContent = 'JSON syntax OK. The full schema check runs when you click Update / Publish.';
			live.className = 'unfold-live is-ok';
		} catch ( e ) {
			var m = /position (\d+)/.exec( e.message );
			live.textContent = 'JSON syntax error: ' + e.message.replace( /^JSON\.parse: /, '' ) + ( m ? ' (' + lineCol( v, +m[ 1 ] ) + ')' : '' );
			live.className = 'unfold-live is-error';
		}
	}

	var t;
	ta.addEventListener( 'input', function () {
		clearTimeout( t );
		t = setTimeout( check, 250 );
	} );
	check();

	var fmt = document.querySelector( '[data-unfold-format]' );
	fmt && fmt.addEventListener( 'click', function () {
		try {
			ta.value = JSON.stringify( JSON.parse( ta.value ), null, 2 );
			check();
		} catch {
			check();
		}
	} );

	var sample = document.querySelector( '[data-unfold-sample]' );
	sample && sample.addEventListener( 'click', function () {
		if ( ta.value.trim() && ta.value.trim() !== '{}' ) {
			live.textContent = 'Clear the box first if you want to replace it with the sample project.';
			live.className = 'unfold-live is-error';
			return;
		}
		fetch( sample.getAttribute( 'data-url' ), { credentials: 'same-origin' } )
			.then( function ( r ) {
				if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
				return r.text();
			} )
			.then( function ( txt ) { ta.value = txt; check(); } )
			.catch( function ( err ) {
				live.textContent = 'Could not load sample project: ' + err.message;
				live.className = 'unfold-live is-error';
			} );
	} );

	// Reading an uploaded file into the box lets the editor see it before saving.
	file && file.addEventListener( 'change', function () {
		var f = file.files && file.files[ 0 ];
		if ( ! f ) return;
		var r = new FileReader();
		r.onload = function () { ta.value = String( r.result ); check(); };
		r.readAsText( f );
	} );
} )();
