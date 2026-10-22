/* global L */
( function () {
	'use strict';

	const root = document.querySelector( '.ts-trip' );
	if ( ! root ) {
		return;
	}
	// Opened from a share link: the cookie is set, so the code needn't stay in the address bar.
	if ( root.dataset.cleanUrl ) {
		history.replaceState( null, '', root.dataset.cleanUrl );
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
	const ROUTE = { color: '#e8591a', weight: 4, opacity: 0.9 };
	const FADE_MS = 400; // As .ts-map's opacity transition.
	const markers = new Map(); // Step id => [ { marker, label } ], one per place.
	const byAnchor = new Map(); // A place heading's id => { marker, label }.
	const padding = [ 30, 30 ];
	let map = null;
	let whole = null; // The whole trip's bounds.
	let areas = {}; // Element id => the area of the route under it.
	let legs = {}; // 'step-<id>' => [ segment, first, last ] runs of the route on its day.
	let route = [];
	const lines = []; // The whole route's polylines, with the style each is drawn in.
	let bold = null; // The step's own route, drawn over the faded whole.

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
		// On a touch screen one finger scrolls the page, even over the map, which fills the
		// top of a phone's: two fingers move and zoom it.
		map = L.map( mapEl, { scrollWheelZoom: false, zoomControl: true, dragging: ! window.matchMedia( '(hover: none)' ).matches } );
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		} ).addTo( map );

		const bounds = L.latLngBounds( [] );
		const segments = trip.route || [];

		const line = ( points, style ) => {
			lines.push( { line: L.polyline( points, style ).addTo( map ), style } );
		};
		segments.forEach( ( segment, i ) => {
			if ( segment.length > 1 ) {
				line( segment, ROUTE );
				segment.forEach( ( p ) => bounds.extend( p ) );
			}
			// Bridge gaps between segments (flights, ferries, phone off) with a dashed line.
			const next = segments[ i + 1 ];
			if ( next && segment.length && next.length ) {
				line( [ segment[ segment.length - 1 ], next[ 0 ] ], {
					color: '#e8591a', weight: 2, opacity: 0.7, dashArray: '6 8',
				} );
			}
		} );
		route = segments;
		bold = L.layerGroup().addTo( map );

		( trip.markers || [] ).forEach( ( m ) => {
			const marker = L.marker( [ m.lat, m.lon ], { icon: stepIcon( m.label, false ), title: m.title } )
				.addTo( map )
				.on( 'click', () => {
					const el = document.getElementById( m.anchor ) || document.getElementById( 'step-' + m.step );
					// To just above the reading line, so it's the place being read: at the top of a
					// phone it would be behind the map, and the place after it read instead.
					if ( el ) {
						window.scrollTo( { top: window.scrollY + el.getBoundingClientRect().top - readingLine() + 8, behavior: 'smooth' } );
					}
				} );
			const key = String( m.step );
			if ( ! markers.has( key ) ) {
				markers.set( key, [] );
			}
			markers.get( key ).push( { marker, label: m.label } );
			byAnchor.set( m.anchor, { marker, label: m.label } );
			bounds.extend( [ m.lat, m.lon ] );
		} );

		whole = bounds;
		areas = trip.areas || {};
		legs = trip.legs || {};
		showWhole( false );
		watchReading();
	}

	const reduced = window.matchMedia( '(prefers-reduced-motion: reduce)' );
	let move = 0; // The latest move: a fade still under way gives way to a newer one.

	// A short move, to a place already in view at about the same zoom (another day in the
	// same town), glides. A longer one fades out, jumps and fades back in: flying zooms out
	// and back in, which is jarring over Cork to Málaga or even Málaga to Cómpeta.
	function moveTo( bounds, maxZoom ) {
		const mine = ++move;
		const options = { padding, maxZoom };
		mapEl.classList.remove( 'is-fading' );
		if ( reduced.matches ) {
			map.fitBounds( bounds, { ...options, animate: false } );
			return;
		}
		// The padding is on every side, as fitBounds takes it.
		const zoom = Math.min( maxZoom, map.getBoundsZoom( bounds, false, L.point( padding ).multiplyBy( 2 ) ) );
		if ( map.getBounds().intersects( bounds ) && Math.abs( zoom - map.getZoom() ) <= 2 ) {
			// Leaflet stretches the routes with the map as it flies, until they cover it:
			// they're hidden until it lands. The markers stay.
			mapEl.classList.add( 'is-flying' );
			map.once( 'moveend', () => mapEl.classList.remove( 'is-flying' ) );
			map.flyToBounds( bounds, { ...options, duration: 3 } );
			return;
		}
		mapEl.classList.add( 'is-fading' );
		setTimeout( () => {
			if ( mine === move ) {
				map.fitBounds( bounds, { ...options, animate: false } );
				mapEl.classList.remove( 'is-fading' );
			}
		}, FADE_MS );
	}

	function showWhole( animate ) {
		if ( ! whole.isValid() ) {
			map.setView( [ 20, 0 ], 2, { animate } );
		} else if ( animate ) {
			moveTo( whole, map.getMaxZoom() );
		} else {
			map.fitBounds( whole, { padding, animate } );
		}
	}

	// Zoom to an area, unless it's already in view at about the zoom it would get, so going
	// between two places close together doesn't wobble.
	function showArea( area ) {
		const target = L.latLngBounds( area );
		const zoom = Math.min( 16, map.getBoundsZoom( target, false, L.point( padding ).multiplyBy( 2 ) ) );
		if ( map.getBounds().contains( target ) && zoom - map.getZoom() < 1 ) {
			return;
		}
		moveTo( target, 16 );
	}

	// While a step is read, the rest of the route fades, so a walk round a town isn't lost
	// among the other days' routes through it. A route synced without times has no legs,
	// and doesn't fade.
	function fadeAllBut( stepId ) {
		const own = legs[ stepId ];
		bold.clearLayers();
		lines.forEach( ( l ) => l.line.setStyle( own ? { opacity: 0.25, weight: Math.min( 2, l.style.weight ) } : l.style ) );
		( own || [] ).forEach( ( [ seg, first, last ] ) => {
			L.polyline( route[ seg ].slice( first, last + 1 ), ROUTE ).addTo( bold );
		} );
	}

	function highlight( list, active ) {
		list.forEach( ( m ) => {
			m.marker.setIcon( stepIcon( m.label, active ) ).setZIndexOffset( active ? 1000 : 0 );
		} );
	}

	let activeKey = 'trip';
	let activeStep = null;
	let lit = [];
	// key: the id of the place heading being read, 'step-<id>' for a step without one, or
	// 'trip' above the first step.
	function setActive( step, key ) {
		if ( key === activeKey ) {
			return;
		}
		activeKey = key;
		document.querySelectorAll( '.ts-step.is-active' ).forEach( ( el ) => el.classList.remove( 'is-active' ) );
		if ( step ) {
			step.classList.add( 'is-active' );
		}
		if ( step !== activeStep ) {
			activeStep = step;
			fadeAllBut( step ? step.id : null );
		}
		highlight( lit, false );
		const place = byAnchor.get( key );
		lit = place ? [ place ] : ( step && markers.get( step.dataset.step ) ) || [];
		highlight( lit, true );

		if ( ! step ) {
			showWhole( true );
		} else if ( areas[ key ] ) {
			showArea( areas[ key ] );
		} else if ( lit.length ) {
			map.panTo( lit[ 0 ].marker.getLatLng(), { animate: true } );
		}
	}

	// What's being read is what's at a line 30% down the part of the screen the timeline
	// shows: on narrow screens the map is stuck above it.
	const narrow = window.matchMedia( '(max-width: 860px)' );
	function readingLine() {
		const wrap = mapEl.parentElement;
		const top = narrow.matches ? ( parseFloat( getComputedStyle( wrap ).top ) || 0 ) + wrap.offsetHeight : 0;
		return top + 0.3 * ( window.innerHeight - top );
	}

	function lastAbove( elements, line ) {
		let last = null;
		elements.forEach( ( el ) => {
			if ( el.getBoundingClientRect().top <= line ) {
				last = el;
			}
		} );
		return last;
	}

	function follow() {
		const line = readingLine();
		const step = lastAbove( document.querySelectorAll( '.ts-step' ), line );
		if ( ! step ) {
			setActive( null, 'trip' );
			return;
		}
		// Before a step's first heading, its title and date, the first place is being read.
		const headings = step.querySelectorAll( '[id^="ts-place-"]' );
		const heading = lastAbove( headings, line ) || headings[ 0 ];
		setActive( step, heading ? heading.id : step.id );
	}

	function watchReading() {
		let queued = false;
		const queue = () => {
			if ( ! queued ) {
				queued = true;
				requestAnimationFrame( () => {
					queued = false;
					follow();
				} );
			}
		};
		window.addEventListener( 'scroll', queue, { passive: true } );
		window.addEventListener( 'resize', queue );
		follow();
	}

	if ( mapEl && window.L ) {
		api( '' ).then( drawTrip ).catch( () => {
			mapEl.classList.add( 'is-unavailable' );
		} );
	}

	/* ---------- Lightbox ---------- */

	const dialog = root.querySelector( '.ts-lightbox' );
	const strip = dialog.querySelector( '.ts-lightbox-strip' );
	let current = -1;
	let target = null; // Where an arrow is scrolling to, so a second press goes one further.

	// Only photos on screen: the ones inside a closed "Show more" don't count.
	function visibleOpeners() {
		return Array.from( root.querySelectorAll( '.ts-photo-open' ) ).filter( ( b ) => null !== b.offsetParent );
	}

	function slide( btn ) {
		const figure = document.createElement( 'figure' );
		figure.className = 'ts-lightbox-slide';
		const img = document.createElement( 'img' );
		img.dataset.src = btn.dataset.full;
		img.alt = btn.dataset.caption;
		const caption = document.createElement( 'figcaption' );
		caption.dir = 'auto'; // The strip is left to right whatever the page is.
		caption.textContent = btn.dataset.caption;
		figure.append( img, caption );
		return figure;
	}

	// The photo on screen and its neighbours load, so a swipe never shows a blank one.
	// The rest have none: a fast swipe doesn't fetch them all, nor a long one keep them all.
	// Only the photo on screen is read out, not every slide in the strip.
	function load() {
		strip.querySelectorAll( '.ts-lightbox-slide' ).forEach( ( figure, i ) => {
			const img = figure.querySelector( 'img' );
			if ( 1 < Math.abs( i - current ) ) {
				img.removeAttribute( 'src' );
			} else if ( ! img.getAttribute( 'src' ) ) {
				img.src = img.dataset.src;
			}
			figure.setAttribute( 'aria-hidden', i === current ? 'false' : 'true' );
		} );
	}

	// Arrows and keys go round from the last photo to the first; a swipe stops at the ends.
	function step( by ) {
		const count = strip.children.length;
		const from = target ?? current;
		target = ( from + by + count ) % count;
		const wrapped = 1 < Math.abs( target - from );
		strip.scrollTo( { left: target * strip.clientWidth, behavior: wrapped ? 'auto' : 'smooth' } );
	}

	strip.addEventListener( 'scroll', () => {
		const index = Math.round( strip.scrollLeft / strip.clientWidth );
		if ( index === target ) {
			target = null;
		}
		if ( index !== current ) {
			current = index;
			load();
		}
	} );
	// A swipe that interrupts an arrow's scroll ends somewhere else.
	strip.addEventListener( 'scrollend', () => {
		target = null;
	} );

	root.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '.ts-photo-open' );
		if ( ! btn ) {
			return;
		}
		const openers = visibleOpeners();
		strip.replaceChildren( ...openers.map( slide ) );
		dialog.showModal();
		current = openers.indexOf( btn );
		target = null;
		strip.scrollTo( { left: current * strip.clientWidth, behavior: 'auto' } );
		load();
	} );
	// A removed img keeps downloading, so each one stops first.
	dialog.addEventListener( 'close', () => {
		strip.querySelectorAll( 'img' ).forEach( ( img ) => img.removeAttribute( 'src' ) );
		strip.replaceChildren();
	} );
	dialog.querySelector( '.ts-lightbox-close' ).addEventListener( 'click', () => dialog.close() );
	dialog.querySelector( '.ts-lightbox-prev' ).addEventListener( 'click', () => step( -1 ) );
	dialog.querySelector( '.ts-lightbox-next' ).addEventListener( 'click', () => step( 1 ) );
	// Outside the photo: the dark around it, which is the dialog or a slide.
	dialog.addEventListener( 'click', ( e ) => {
		if ( e.target === dialog || e.target.classList.contains( 'ts-lightbox-slide' ) ) {
			dialog.close();
		}
	} );
	dialog.addEventListener( 'keydown', ( e ) => {
		if ( 'ArrowLeft' === e.key || 'ArrowRight' === e.key ) {
			e.preventDefault(); // A focused strip would scroll itself as well.
			step( 'ArrowLeft' === e.key ? -1 : 1 );
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
