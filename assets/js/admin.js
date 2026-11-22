/**
 * Settings screen: shows the cards of the connections in use and runs their buttons.
 */
( function () {
	'use strict';

	const config = window.catcodeFlc;
	const crm = document.getElementById( 'ccflc-crm' );
	const telegram = document.getElementById( 'ccflc-telegram-on' );

	function inUse( id ) {
		if ( 'telegram' === id ) {
			return ! telegram || telegram.checked;
		}
		if ( crm && crm.value === id ) {
			return true;
		}
		const extra = document.querySelector( 'input[name="extra_crms[]"][value="' + id + '"]' );
		return !! extra && extra.checked && ! extra.disabled;
	}

	function refresh() {
		document.querySelectorAll( '.ccflc-card[data-card]' ).forEach( function ( card ) {
			card.hidden = ! inUse( card.dataset.card );
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target === crm || event.target === telegram || 'extra_crms[]' === event.target.name ) {
			refresh();
		}
	} );
	refresh();

	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( 'button[data-do]' );
		if ( ! button ) {
			return;
		}
		const out = button.closest( '.ccflc-card' ).querySelector( '.ccflc-result' );
		const body = new URLSearchParams( {
			action: 'catcode_flc_action',
			_ajax_nonce: config.nonce,
			do: button.dataset.do,
			target: button.dataset.target,
		} );

		out.textContent = config.wait;
		out.className = 'ccflc-result';
		button.disabled = true;

		fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				const data = json && json.data ? json.data : {};
				out.textContent = data.message || config.network;
				out.classList.add( json && json.success && data.ok ? 'is-ok' : 'is-error' );
			} )
			.catch( function () {
				out.textContent = config.network;
				out.classList.add( 'is-error' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
}() );
