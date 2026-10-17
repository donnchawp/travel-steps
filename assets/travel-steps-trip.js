/* global L */
( function () {
	'use strict';

	const root = document.querySelector( '.ts-trip' );
	if ( ! root ) {
		return;
	}
	const nonce = root.dataset.nonce;

	function api( path, options = {} ) {
		const headers = Object.assign( { Accept: 'application/json' }, options.headers || {} );
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}
		return fetch( root.dataset.api + path, Object.assign( {}, options, { headers, credentials: 'same-origin' } ) )
			.then( ( r ) => ( r.ok ? r.json() : Promise.reject( new Error( r.status ) ) ) );
	}

	/* ---------- Map ---------- */

	const mapEl = document.getElementById( 'ts-map' );
	const markers = new Map(); // Step id => [ { marker, label } ], one per place.
	let map = null;

	function stepIcon( label, active ) {
		const span = document.createElement( 'span' );
		span.textContent = label;
		return L.divIcon( {
			className: 'ts-pin' + ( active ? ' is-active' : '' ),
			html: span,
			iconSize: [ 30, 30 ],
			iconAnchor: [ 15, 15 ],
		} );
	}

	function drawTrip( trip ) {
		map = L.map( mapEl, { scrollWheelZoom: false, zoomControl: true } );
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		} ).addTo( map );

		const bounds = L.latLngBounds( [] );
		const segments = trip.route || [];

		segments.forEach( ( segment, i ) => {
			if ( segment.length > 1 ) {
				L.polyline( segment, { color: '#e8591a', weight: 4, opacity: 0.9 } ).addTo( map );
				segment.forEach( ( p ) => bounds.extend( p ) );
			}
			// Bridge gaps between segments (flights, ferries, phone off) with a dashed line.
			const next = segments[ i + 1 ];
			if ( next && segment.length && next.length ) {
				L.polyline( [ segment[ segment.length - 1 ], next[ 0 ] ], {
					color: '#e8591a', weight: 2, opacity: 0.7, dashArray: '6 8',
				} ).addTo( map );
			}
		} );

		( trip.markers || [] ).forEach( ( m ) => {
			const marker = L.marker( [ m.lat, m.lon ], { icon: stepIcon( m.label, false ), title: m.title } )
				.addTo( map )
				.on( 'click', () => {
					const el = document.getElementById( m.anchor ) || document.getElementById( 'step-' + m.step );
					if ( el ) {
						el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				} );
			const key = String( m.step );
			if ( ! markers.has( key ) ) {
				markers.set( key, [] );
			}
			markers.get( key ).push( { marker, label: m.label } );
			bounds.extend( [ m.lat, m.lon ] );
		} );

		if ( bounds.isValid() ) {
			map.fitBounds( bounds, { padding: [ 30, 30 ] } );
		} else {
			map.setView( [ 20, 0 ], 2 );
		}
		watchSteps();
	}

	let activeStep = null;
	function setActive( id ) {
		if ( id === activeStep ) {
			return;
		}
		( markers.get( activeStep ) || [] ).forEach( ( m ) => {
			m.marker.setIcon( stepIcon( m.label, false ) ).setZIndexOffset( 0 );
		} );
		activeStep = id;
		document.querySelectorAll( '.ts-step.is-active' ).forEach( ( el ) => el.classList.remove( 'is-active' ) );
		const section = document.getElementById( 'step-' + id );
		if ( section ) {
			section.classList.add( 'is-active' );
		}
		const current = markers.get( id ) || [];
		current.forEach( ( m ) => {
			m.marker.setIcon( stepIcon( m.label, true ) ).setZIndexOffset( 1000 );
		} );
		if ( current.length ) {
			map.panTo( current[ 0 ].marker.getLatLng(), { animate: true } );
		}
	}

	// Follow the step nearest the top of the viewport as you scroll the timeline.
	function watchSteps() {
		const sections = document.querySelectorAll( '.ts-step' );
		if ( ! sections.length || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		const observer = new IntersectionObserver( ( entries ) => {
			const visible = entries.filter( ( e ) => e.isIntersecting )
				.sort( ( a, b ) => a.boundingClientRect.top - b.boundingClientRect.top );
			if ( visible.length ) {
				setActive( visible[ 0 ].target.dataset.step );
			}
		}, { rootMargin: '-30% 0px -60% 0px' } );
		sections.forEach( ( s ) => observer.observe( s ) );
	}

	if ( mapEl && window.L ) {
		api( '' ).then( drawTrip ).catch( () => {
			mapEl.classList.add( 'is-unavailable' );
		} );
	}

	/* ---------- Lightbox ---------- */

	const dialog = root.querySelector( '.ts-lightbox' );
	let openers = [];
	let current = -1;

	// Only photos on screen: the ones inside a closed "Show more" don't count.
	function visibleOpeners() {
		return Array.from( root.querySelectorAll( '.ts-photo-open' ) ).filter( ( b ) => null !== b.offsetParent );
	}

	function show( index ) {
		if ( ! openers.length ) {
			return;
		}
		current = ( index + openers.length ) % openers.length;
		const btn = openers[ current ];
		const old = dialog.querySelector( 'img' );
		// The same photo again is already showing, or still loading: leave it.
		if ( old.getAttribute( 'src' ) !== btn.dataset.full ) {
			// A new img, not a new src: the old one would show the last photo until this one loads.
			const img = document.createElement( 'img' );
			img.src = btn.dataset.full;
			img.alt = btn.dataset.caption || '';
			old.removeAttribute( 'src' ); // Or a photo skipped past keeps downloading.
			old.replaceWith( img );
		}
		dialog.querySelector( 'figcaption' ).textContent = btn.dataset.caption || '';
		if ( ! dialog.open ) {
			dialog.showModal();
		}
	}

	root.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '.ts-photo-open' );
		if ( btn ) {
			openers = visibleOpeners();
			show( openers.indexOf( btn ) );
		}
	} );
	dialog.querySelector( '.ts-lightbox-close' ).addEventListener( 'click', () => dialog.close() );
	dialog.querySelector( '.ts-lightbox-prev' ).addEventListener( 'click', () => show( current - 1 ) );
	dialog.querySelector( '.ts-lightbox-next' ).addEventListener( 'click', () => show( current + 1 ) );
	dialog.addEventListener( 'click', ( e ) => {
		if ( e.target === dialog ) {
			dialog.close();
		}
	} );
	dialog.addEventListener( 'keydown', ( e ) => {
		if ( 'ArrowLeft' === e.key ) {
			show( current - 1 );
		} else if ( 'ArrowRight' === e.key ) {
			show( current + 1 );
		}
	} );

	/* ---------- Pin / hide photos (editors) ---------- */

	root.querySelectorAll( '.ts-photo-state' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			const figure = btn.closest( '.ts-photo' );
			// Pressing the active button again returns the photo to automatic selection.
			const state = figure.dataset.state === btn.dataset.set ? 'default' : btn.dataset.set;
			btn.disabled = true;
			api( '/photo-state', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { asset: figure.dataset.asset, state } ),
			} ).then( () => {
				figure.dataset.state = state;
				figure.classList.remove( 'is-default', 'is-pinned', 'is-hidden' );
				figure.classList.add( 'is-' + state );
				figure.querySelectorAll( '.ts-photo-state' ).forEach( ( b ) => {
					b.setAttribute( 'aria-pressed', b.dataset.set === state ? 'true' : 'false' );
				} );
			} ).finally( () => {
				btn.disabled = false;
			} );
		} );
	} );

	/* ---------- Photo tools on touch screens (editors) ---------- */

	const editPhotos = root.querySelector( '.ts-edit-photos' );
	if ( editPhotos ) {
		editPhotos.addEventListener( 'click', () => {
			const on = 'true' !== editPhotos.getAttribute( 'aria-pressed' );
			editPhotos.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			root.classList.toggle( 'is-editing-photos', on );
		} );
	}

	/* ---------- Cover photo (editors) ---------- */

	function setHero( url ) {
		const hero = root.querySelector( '.ts-hero' );
		let img = hero.querySelector( '.ts-hero-img' );
		if ( url && ! img ) {
			img = document.createElement( 'img' );
			img.className = 'ts-hero-img';
			img.alt = '';
			hero.prepend( img );
		}
		if ( url ) {
			img.src = url;
		} else if ( img ) {
			img.remove();
		}
		hero.classList.toggle( 'has-cover', !! url );
	}

	root.querySelectorAll( '.ts-photo-cover' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			// Pressing the chosen cover again goes back to the automatic one.
			const asset = 'true' === btn.getAttribute( 'aria-pressed' ) ? '' : btn.closest( '.ts-photo' ).dataset.asset;
			btn.disabled = true;
			api( '/cover', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { asset } ),
			} ).then( ( result ) => {
				root.querySelectorAll( '.ts-photo-cover' ).forEach( ( b ) => {
					b.setAttribute( 'aria-pressed', result.asset && b.closest( '.ts-photo' ).dataset.asset === result.asset ? 'true' : 'false' );
				} );
				setHero( result.url );
			} ).finally( () => {
				btn.disabled = false;
			} );
		} );
	} );
}() );
