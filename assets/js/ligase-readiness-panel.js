/**
 * Ligase Readiness Panel (in-editor metabox)
 *
 * Fetches /wp-admin/admin-ajax.php?action=ligase_readiness and renders a
 * field-by-field status list for each relevant @type. No build step; vanilla.
 *
 * Listens to wp.data for save events so the panel auto-refreshes after publish.
 */
( function () {
	'use strict';

	if ( ! window.LIGASE_READINESS ) { return; }

	var STATE_CLASS = {
		auto:             'ligase-rdy-auto',
		manual:           'ligase-rdy-manual',
		missing_required: 'ligase-rdy-miss-req',
		missing_recommended: 'ligase-rdy-miss-rec',
		missing_optional: 'ligase-rdy-miss-opt',
	};

	var STATE_ICON = {
		auto:                '✓',
		manual:              '✓',
		missing_required:    '✗',
		missing_recommended: '○',
		missing_optional:    '○',
	};

	var i18n = window.LIGASE_READINESS.i18n;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( k === 'class' ) { node.className = attrs[ k ]; }
				else if ( k === 'text' ) { node.textContent = attrs[ k ]; }
				else if ( k === 'html' ) { node.innerHTML = attrs[ k ]; }
				else { node.setAttribute( k, attrs[ k ] ); }
			} );
		}
		if ( children ) {
			children.forEach( function ( c ) { if ( c ) { node.appendChild( c ); } } );
		}
		return node;
	}

	function renderField( field ) {
		var stateCls = STATE_CLASS[ field.state ] || 'ligase-rdy-other';
		var icon     = STATE_ICON[ field.state ] || '·';
		var meta     = el( 'span', { class: 'ligase-rdy-meta' } );
		if ( field.source ) {
			meta.textContent = i18n.fromSource + ' ' + field.source;
		}
		return el( 'li', { class: 'ligase-rdy-field ' + stateCls }, [
			el( 'span', { class: 'ligase-rdy-icon', text: icon } ),
			el( 'span', { class: 'ligase-rdy-label', text: field.label } ),
			el( 'span', { class: 'ligase-rdy-level', text: i18n[ field.level ] || field.level } ),
			meta,
		] );
	}

	function renderType( typeKey, data ) {
		var status     = data.eligible ? 'ok' : 'fail';
		var statusText = data.deprecated ? i18n.deprecated : ( data.eligible ? i18n.eligible : i18n.ineligible );
		var header = el( 'header', { class: 'ligase-rdy-header ligase-rdy-' + status }, [
			el( 'strong', { text: data.label } ),
			el( 'span', { class: 'ligase-rdy-status', text: statusText } ),
		] );
		var ul = el( 'ul', { class: 'ligase-rdy-fields' } );
		( data.fields || [] ).forEach( function ( f ) {
			ul.appendChild( renderField( f ) );
		} );
		return el( 'section', { class: 'ligase-rdy-type', 'data-type': typeKey }, [ header, ul ] );
	}

	function render( container, payload ) {
		container.innerHTML = '';
		var types = Object.keys( payload || {} );
		if ( ! types.length ) {
			container.appendChild( el( 'p', { class: 'description', text: i18n.noTypes } ) );
			return;
		}
		types.forEach( function ( t ) {
			container.appendChild( renderType( t, payload[ t ] ) );
		} );
		container.appendChild(
			el( 'button', {
				type:  'button',
				class: 'button button-secondary ligase-rdy-refresh',
				text:  i18n.refresh,
			} )
		);
		container.querySelector( '.ligase-rdy-refresh' ).addEventListener( 'click', function () {
			fetchAndRender( container );
		} );
	}

	function fetchAndRender( container ) {
		var postId = parseInt( container.getAttribute( 'data-post-id' ), 10 );
		if ( ! postId ) { return; }
		container.innerHTML = '<p class="description">' + i18n.loading + '</p>';

		var body = new FormData();
		body.append( 'action',  'ligase_readiness' );
		body.append( 'nonce',   window.LIGASE_READINESS.nonce );
		body.append( 'post_id', String( postId ) );

		fetch( window.LIGASE_READINESS.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					container.innerHTML = '<p class="description">' + i18n.fetchError + '</p>';
					return;
				}
				render( container, json.data );
			} )
			.catch( function () {
				container.innerHTML = '<p class="description">' + i18n.fetchError + '</p>';
			} );
	}

	function attachStyles() {
		if ( document.getElementById( 'ligase-rdy-styles' ) ) { return; }
		var css = '' +
			'#ligase-readiness-panel { font-size:12px; }' +
			'.ligase-rdy-type { margin-bottom: 12px; }' +
			'.ligase-rdy-header { display:flex; justify-content:space-between; align-items:center; padding:6px 8px; border-radius:3px; margin-bottom:4px; }' +
			'.ligase-rdy-ok { background: #e6f4ea; color: #1e7a3a; }' +
			'.ligase-rdy-fail { background: #fceaea; color: #9b1c1c; }' +
			'.ligase-rdy-fields { margin:0; padding:0; list-style:none; }' +
			'.ligase-rdy-field { display:grid; grid-template-columns: 16px 1fr auto; gap:6px; padding:3px 4px; border-bottom:1px solid #f1f1f1; }' +
			'.ligase-rdy-field:last-child { border-bottom:0; }' +
			'.ligase-rdy-icon { font-weight:bold; }' +
			'.ligase-rdy-level { color:#777; font-size:11px; }' +
			'.ligase-rdy-meta  { grid-column: 2 / span 2; color:#999; font-size:11px; }' +
			'.ligase-rdy-auto .ligase-rdy-icon { color:#2271b1; }' +
			'.ligase-rdy-manual .ligase-rdy-icon { color:#1e7a3a; }' +
			'.ligase-rdy-miss-req .ligase-rdy-icon { color:#9b1c1c; }' +
			'.ligase-rdy-miss-rec .ligase-rdy-icon, .ligase-rdy-miss-opt .ligase-rdy-icon { color:#999; }' +
			'.ligase-rdy-refresh { margin-top:6px; }';
		var s = document.createElement( 'style' );
		s.id = 'ligase-rdy-styles';
		s.textContent = css;
		document.head.appendChild( s );
	}

	function init() {
		var container = document.getElementById( 'ligase-readiness-panel' );
		if ( ! container ) { return; }
		attachStyles();
		fetchAndRender( container );

		// Auto-refresh after Gutenberg save.
		if ( window.wp && window.wp.data && window.wp.data.subscribe ) {
			var prevSaving = false;
			window.wp.data.subscribe( function () {
				var editor = window.wp.data.select( 'core/editor' );
				if ( ! editor ) { return; }
				var isSaving = editor.isSavingPost && editor.isSavingPost();
				var autosave = editor.isAutosavingPost && editor.isAutosavingPost();
				if ( prevSaving && ! isSaving && ! autosave ) {
					fetchAndRender( container );
				}
				prevSaving = isSaving;
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
