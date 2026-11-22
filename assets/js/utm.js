/**
 * Remembers where the visitor came from (utm_*, gclid, fbclid) so a form sent
 * a few pages later is still attributed to the ad. Last click wins; a visit
 * without tags keeps what is stored.
 */
( function () {
	'use strict';

	const COOKIE = 'catcode_flc_utm';
	const DAYS = 90;

	function read() {
		const m = document.cookie.match( new RegExp( '(?:^|; )' + COOKIE + '=([^;]*)' ) );
		if ( ! m ) {
			return null;
		}
		try {
			return JSON.parse( decodeURIComponent( m[ 1 ] ) );
		} catch ( e ) {
			return null;
		}
	}

	function write( data ) {
		const expires = new Date( Date.now() + DAYS * 864e5 ).toUTCString();
		const secure = 'https:' === location.protocol ? '; Secure' : '';
		document.cookie = COOKIE + '=' + encodeURIComponent( JSON.stringify( data ) ) + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
	}

	const params = new URLSearchParams( location.search );
	const data = {};
	[ 'source', 'medium', 'campaign', 'content', 'term' ].forEach( function ( key ) {
		const value = params.get( 'utm_' + key );
		if ( value ) {
			data[ key ] = value.slice( 0, 300 );
		}
	} );
	[ 'gclid', 'fbclid' ].forEach( function ( key ) {
		const value = params.get( key );
		if ( value ) {
			data[ key ] = value.slice( 0, 300 );
		}
	} );

	let ref = '';
	try {
		ref = document.referrer && new URL( document.referrer ).host !== location.host ? document.referrer : '';
	} catch ( e ) {
		ref = '';
	}

	if ( Object.keys( data ).length ) {
		if ( ref ) {
			data.referrer = ref.slice( 0, 300 );
		}
		data.landing = ( location.origin + location.pathname ).slice( 0, 300 );
		write( data );
	} else if ( ref && ! read() ) {
		// No tags at all: keep at least the referring site of the first visit.
		write( { referrer: ref.slice( 0, 300 ), landing: ( location.origin + location.pathname ).slice( 0, 300 ) } );
	}
}() );
