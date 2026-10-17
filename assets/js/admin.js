/**
 * BladeCP Migrator admin screen.
 *
 * Drives the time-boxed steps while the page is open: measuring the site,
 * building and uploading the package, and watching the remote migration.
 * The server renders every action; this script only calls the AJAX
 * endpoints, updates the progress text and percentage, reloads when the state
 * or the stage of the 7-stage trail changes, and copies the DNS value.
 */
( function () {
	'use strict';

	var config = window.bladecpMigrator;
	var root = document.querySelector( '.bcpm' );
	if ( ! config || ! root ) {
		return;
	}

	var i18n = config.i18n || {};

	function post( action ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function setText( id, text ) {
		var node = document.getElementById( id );
		if ( node ) {
			node.textContent = text;
		}
	}

	function format( template, values ) {
		var index = 0;
		return String( template ).replace( /%(\d+)\$s|%s/g, function ( match, position ) {
			var value = position ? values[ Number( position ) - 1 ] : values[ index++ ];
			return value === undefined ? match : String( value );
		} );
	}

	// Confirmation questions on destructive buttons.
	Array.prototype.forEach.call( root.querySelectorAll( 'form[data-confirm]' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	// Prevent double submissions.
	Array.prototype.forEach.call( root.querySelectorAll( 'form' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented ) {
				return;
			}
			Array.prototype.forEach.call( form.querySelectorAll( 'button' ), function ( button ) {
				window.setTimeout( function () {
					button.disabled = true;
				}, 0 );
			} );
		} );
	} );

	function measureLoop() {
		post( 'bladecp_migrator_measure' ).then( function ( reply ) {
			if ( ! reply || ! reply.success ) {
				window.setTimeout( measureLoop, 5000 );
				return;
			}
			if ( reply.data.done ) {
				window.location.reload();
				return;
			}
			setText( 'bcpm-measure-status', i18n.measuring + ' ' + format( i18n.measured, [ reply.data.files, reply.data.bytes ] ) );
			window.setTimeout( measureLoop, 300 );
		} ).catch( function () {
			setText( 'bcpm-measure-status', i18n.offline );
			window.setTimeout( measureLoop, 5000 );
		} );
	}

	var initialStage = root.getAttribute( 'data-stage' );
	var initialJourney = root.getAttribute( 'data-journey' ) || '';

	function jobLoop() {
		post( 'bladecp_migrator_step' ).then( function ( reply ) {
			if ( ! reply || ! reply.success ) {
				window.setTimeout( jobLoop, 5000 );
				return;
			}
			var progress = reply.data.progress;
			if ( progress.stage !== initialStage && ( ! progress.active || progress.stage === 'done' || progress.stage === 'failed' ) ) {
				window.location.reload();
				return;
			}
			// Building and sending are different stages of the trail: redraw it.
			if ( progress.journey !== null && progress.journey !== undefined && String( progress.journey ) !== initialJourney ) {
				window.location.reload();
				return;
			}
			setText( 'bcpm-progress-title', progress.title );
			setText( 'bcpm-progress-detail', progress.detail );
			var percent = document.getElementById( 'bcpm-progress-pct' );
			if ( percent ) {
				percent.textContent = progress.percent + '%';
				percent.setAttribute( 'aria-valuenow', String( progress.percent ) );
			}
			var delay = reply.data.busy || progress.waiting ? 3000 : 250;
			window.setTimeout( jobLoop, delay );
		} ).catch( function () {
			setText( 'bcpm-progress-detail', i18n.offline );
			window.setTimeout( jobLoop, 5000 );
		} );
	}

	var initialPhase = root.getAttribute( 'data-phase' );

	function remoteLoop() {
		post( 'bladecp_migrator_status' ).then( function ( reply ) {
			if ( reply && reply.success && reply.data.phase && reply.data.phase !== initialPhase ) {
				window.location.reload();
				return;
			}
			window.setTimeout( remoteLoop, 10000 );
		} ).catch( function () {
			window.setTimeout( remoteLoop, 15000 );
		} );
	}

	// Copy buttons of the DNS records: the value goes to the clipboard and the
	// button says so for a moment (and to screen readers through a live region).
	var copyStatus = document.getElementById( 'bcpm-copy-status' );
	Array.prototype.forEach.call( root.querySelectorAll( '[data-copy]' ), function ( button ) {
		var label = button.querySelector( 'span' );
		var original = label ? label.textContent : '';
		var timer = 0;
		function copied() {
			var text = button.getAttribute( 'data-copied' ) || '';
			button.classList.add( 'is-copied' );
			if ( label ) {
				label.textContent = text;
			}
			if ( copyStatus ) {
				copyStatus.textContent = text + ': ' + button.getAttribute( 'data-copy' );
			}
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				button.classList.remove( 'is-copied' );
				if ( label ) {
					label.textContent = original;
				}
			}, 2000 );
		}
		button.addEventListener( 'click', function () {
			var value = button.getAttribute( 'data-copy' ) || '';
			if ( window.navigator.clipboard && window.isSecureContext ) {
				window.navigator.clipboard.writeText( value ).then( copied, function () {} );
			}
		} );
	} );

	if ( root.getAttribute( 'data-measure' ) === '1' ) {
		measureLoop();
	}
	if ( root.getAttribute( 'data-job' ) === '1' ) {
		jobLoop();
	}
	if ( root.getAttribute( 'data-poll' ) === '1' ) {
		window.setTimeout( remoteLoop, 10000 );
	}
}() );
