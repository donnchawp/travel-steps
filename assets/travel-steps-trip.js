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
	const MOVE_MS = 120; // A long move's fade, as .ts-map's transitions.
	const TRAVEL_MS = 1000; // Then the streets streaming past.
	const SLIDE_PX = 80; // How far the map slides as it fades.
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
	let move = 0; // The latest move: one still under way gives way to a newer one.
	let streak = null; // The blur along the way a long move goes.

	// A blur along one direction, as the map's streaks while it travels: a Gaussian blur
	// is only along the axes, so a diagonal is the nearest mix of the two.
	function streakFilter() {
		if ( ! streak ) {
			const ns = 'http://www.w3.org/2000/svg';
			const svg = document.createElementNS( ns, 'svg' );
			svg.setAttribute( 'aria-hidden', 'true' );
			svg.setAttribute( 'width', '0' );
			svg.setAttribute( 'height', '0' );
			svg.style.position = 'absolute';
			const filter = document.createElementNS( ns, 'filter' );
			filter.id = 'ts-streak';
			streak = document.createElementNS( ns, 'feGaussianBlur' );
			filter.append( streak );
			svg.append( filter );
			document.body.append( svg );
		}
		return streak;
	}

	// A move to much the same place glides. A longer one fades the map to nearly black,
	// streaked along the way it goes as it slides off, streets stream past for a moment,
	// and the new place appears: flying
	// zooms out and back in, which is jarring over Cork to Málaga or even Málaga to
	// Cómpeta.
	function moveTo( bounds, maxZoom ) {
		const mine = ++move;
		const options = { padding, maxZoom };
		const pane = map.getPane( 'mapPane' );
		// Whatever a long move still under way left.
		pane.style.filter = '';
		pane.style.translate = '';
		mapEl.classList.remove( 'is-moving' );
		if ( reduced.matches ) {
			map.fitBounds( bounds, { ...options, animate: false } );
			return;
		}
		// Much the same place, at whatever zoom: one takes in the middle of the other (into a
		// town at the end of a drive, or back out to the drive), or they're centred within a
		// quarter of the larger of the two (another day in the same town).
		const view = map.getBounds();
		const across = ( b ) => map.distance( b.getSouthWest(), b.getNorthEast() );
		const near = bounds.contains( view.getCenter() ) || view.contains( bounds.getCenter() ) ||
			map.distance( view.getCenter(), bounds.getCenter() ) <= Math.max( across( view ), across( bounds ) ) / 4;
		if ( near ) {
			// Leaflet stretches the routes with the map as it flies, until they cover it:
			// they're hidden until it lands. The markers stay.
			mapEl.classList.add( 'is-flying' );
			map.once( 'moveend', () => mapEl.classList.remove( 'is-flying' ) );
			map.flyToBounds( bounds, { ...options, duration: 1 } );
			return;
		}

		// Which way the new place is, on screen: the map slides the other way.
		const way = map.latLngToContainerPoint( bounds.getCenter() ).subtract( map.getSize().divideBy( 2 ) );
		const length = Math.hypot( way.x, way.y );
		const ux = length ? way.x / length : 0;
		const uy = length ? way.y / length : 0;
		const slide = ( by ) => `${ Math.round( -ux * by ) }px ${ Math.round( -uy * by ) }px`;
		streakFilter().setAttribute( 'stdDeviation', `${ 2 + 18 * Math.abs( ux ) } ${ 2 + 18 * Math.abs( uy ) }` );

		pane.style.filter = 'url(#ts-streak)';
		pane.style.translate = slide( SLIDE_PX );
		mapEl.classList.add( 'is-moving' );
		setTimeout( () => {
			if ( mine === move ) {
				travel( ux, uy, mine, () => {
					// The new place appears at once, sharp and undimmed.
					mapEl.classList.add( 'is-arriving' );
					map.fitBounds( bounds, { ...options, animate: false } );
					pane.style.filter = '';
					pane.style.translate = '';
					mapEl.classList.remove( 'is-moving' );
					pane.getBoundingClientRect();
					mapEl.classList.remove( 'is-arriving' );
				} );
			}
		}, MOVE_MS );
	}

	// On the darkened map, sparse thin white lines, like bits of street, stream past the
	// way the map would move, faster then slower, as it travels to somewhere further off.
	function travel( ux, uy, mine, done ) {
		const canvas = document.createElement( 'canvas' );
		canvas.className = 'ts-map-travel';
		const w = mapEl.clientWidth;
		const h = mapEl.clientHeight;
		const dpr = window.devicePixelRatio || 1;
		canvas.width = w * dpr;
		canvas.height = h * dpr;
		mapEl.append( canvas );
		const ctx = canvas.getContext( '2d' );
		ctx.scale( dpr, dpr );
		ctx.lineWidth = 1;
		ctx.lineCap = 'round';

		// A run of street at one of a few angles, some turning a corner.
		const streets = Array.from( { length: Math.max( 8, Math.round( ( w * h ) / 14000 ) ) }, () => {
			const angle = ( Math.floor( Math.random() * 4 ) * Math.PI ) / 4 + ( Math.random() - 0.5 ) * 0.3;
			const length = 40 + Math.random() * 140;
			const x = Math.random() * w;
			const y = Math.random() * h;
			const points = [ [ x, y ], [ x + Math.cos( angle ) * length, y + Math.sin( angle ) * length ] ];
			if ( Math.random() < 0.5 ) {
				const turn = angle + ( Math.random() < 0.5 ? 1 : -1 ) * ( Math.PI / 2 );
				const more = 20 + Math.random() * 70;
				points.push( [ points[ 1 ][ 0 ] + Math.cos( turn ) * more, points[ 1 ][ 1 ] + Math.sin( turn ) * more ] );
			}
			return points;
		} );
		// Off one edge, back on at the other.
		const wrap = ( v, size ) => ( ( ( ( v + 150 ) % ( size + 300 ) ) + size + 300 ) % ( size + 300 ) ) - 150;
		const distance = 1.5 * Math.max( w, h );
		const start = performance.now();

		const frame = ( now ) => {
			if ( mine !== move ) {
				canvas.remove();
				return;
			}
			const t = Math.min( 1, ( now - start ) / TRAVEL_MS );
			const eased = t < 0.5 ? 2 * t * t : 1 - ( ( -2 * t + 2 ) ** 2 ) / 2;
			const dx = -ux * eased * distance;
			const dy = -uy * eased * distance;
			ctx.clearRect( 0, 0, w, h );
			// In and out quickly at either end.
			ctx.strokeStyle = `rgb(255 255 255 / ${ 0.45 * Math.min( 1, t / 0.12, ( 1 - t ) / 0.12 ) })`;
			streets.forEach( ( points ) => {
				const ox = wrap( points[ 0 ][ 0 ] + dx, w ) - points[ 0 ][ 0 ];
				const oy = wrap( points[ 0 ][ 1 ] + dy, h ) - points[ 0 ][ 1 ];
				ctx.beginPath();
				points.forEach( ( [ x, y ], i ) => ctx[ i ? 'lineTo' : 'moveTo' ]( x + ox, y + oy ) );
				ctx.stroke();
			} );
			if ( t < 1 ) {
				requestAnimationFrame( frame );
			} else {
				canvas.remove();
				done();
			}
		};
		requestAnimationFrame( frame );
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
		// A heading without a marker of its own, such as photos at home at the end of a trip,
		// leaves the map where it is: the step's first marker could be the other end of it.
		// A step without headings has the step's markers.
		lit = place ? [ place ] : ( step && key === step.id && markers.get( step.dataset.step ) ) || [];
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
