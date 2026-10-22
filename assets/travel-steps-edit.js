/* global wp */
/**
 * The trip form: what the "Photos" choice changes on the page, and adding photos to a
 * trip of uploads through the media modal.
 */
( function () {
	'use strict';

	const form = document.querySelector( '.ts-trip-form' );
	const choice = form && form.querySelector( 'select[name="photos"]' );
	const sync = form && form.querySelector( 'button[name="sync"]' );
	if ( choice ) {
		const isNew = '0' === form.elements.trip_id.value;
		const save = form.querySelector( '.ts-save' );
		const hint = form.querySelector( '.ts-dates-hint' );
		choice.addEventListener( 'change', () => {
			const uploads = 'uploads' === choice.value;
			const picksPhotos = 'datesOptional' in choice.selectedOptions[ 0 ].dataset;
			[ form.elements.start, form.elements.end ].forEach( ( input ) => {
				input.required = ! picksPhotos;
			} );
			hint.hidden = ! picksPhotos;
			// A new trip of uploads has nothing to sync yet: saving it opens the media modal.
			if ( isNew ) {
				save.textContent = uploads ? save.dataset.uploadsLabel : save.dataset.label;
				sync.hidden = uploads;
			}
		} );
	}

	/* ---------- Share link ---------- */

	const share = form && form.querySelector( '.ts-share' );
	if ( share ) {
		const url = share.querySelector( '.ts-share-url' );
		const copy = share.querySelector( '.ts-share-copy' );
		const label = copy.textContent;
		// The link is for the saved password, on a published trip: until a change is saved, it's
		// out of date. Saving trims the password, so spaces around it change nothing.
		const stale = () => {
			const changed = form.elements.password.value.trim() !== share.dataset.password || 'publish' !== form.elements.status.value;
			url.disabled = changed;
			copy.disabled = changed;
			share.querySelector( '.ts-share-stale' ).hidden = ! changed;
		};
		form.elements.password.addEventListener( 'input', stale );
		form.elements.status.addEventListener( 'change', stale );
		copy.addEventListener( 'click', () => {
			const copied = () => {
				copy.textContent = copy.dataset.copied;
				setTimeout( () => {
					copy.textContent = label;
				}, 2000 );
			};
			url.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( url.value ).then( copied, () => {} );
			}
		} );
	}

	/* ---------- Adding photos ---------- */

	const panel = document.querySelector( '.ts-uploads' );
	const button = panel && panel.querySelector( '.ts-add-photos' );
	if ( ! button || ! window.wp || ! wp.media ) {
		return;
	}
	const trip = parseInt( form.elements.trip_id.value, 10 );
	// Only the author's page has these.
	const syncForm = document.querySelector( '.ts-sync-form' );
	const count = panel.querySelector( 'p' );
	const counted = count.textContent;
	let frame = null;
	let changed = false;
	let waiting = false;
	let dirty = false;
	form.addEventListener( 'input', () => {
		dirty = true;
	} );
	const busy = () => wp.Uploader.queue.some( ( attachment ) => attachment.get( 'uploading' ) );
	// Leaving the page would cut off uploads still going.
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( busy() ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	// After an upload or a deletion the trip's steps are out of date: the author's page
	// syncs it; anyone else's reloads to say the author needs to. Unsaved changes to the
	// trip form are saved on the way (and synced, for the author).
	function leave() {
		if ( dirty ) {
			form.requestSubmit( sync );
			return;
		}
		if ( syncForm ) {
			syncForm.submit();
			return;
		}
		const url = new URL( window.location.href );
		url.searchParams.delete( 'add' );
		url.searchParams.set( 'photos', '1' );
		window.location.assign( url );
	}

	function stopWaiting() {
		wp.Uploader.queue.off( 'change:uploading remove reset', finished );
		waiting = false;
		count.textContent = counted;
	}

	function finished() {
		if ( ! busy() ) {
			stopWaiting();
			closed();
		}
	}

	function closed() {
		if ( waiting ) {
			return;
		}
		if ( busy() ) {
			// Closing with uploads still going: finish them, then carry on.
			waiting = true;
			count.textContent = panel.dataset.finishing;
			wp.Uploader.queue.on( 'change:uploading remove reset', finished );
			return;
		}
		if ( changed ) {
			leave();
		}
	}

	function open() {
		if ( ! frame ) {
			frame = wp.media( {
				title: panel.dataset.title,
				multiple: true,
				library: { uploadedTo: trip, travel_steps_photo: 1, type: 'image' },
				// Closes the modal whether or not a photo is selected.
				button: { text: panel.dataset.done, requires: { selection: false } },
			} );
			const library = frame.state( 'library' ).get( 'library' );
			library.on( 'destroy', () => {
				changed = true;
			} );
			// Core only adds new uploads to a library whose query it fully understands, and
			// travel_steps_photo isn't one of its arguments: add each when it's uploaded. A
			// refused upload never gets an id.
			wp.Uploader.queue.on( 'change:uploading', ( attachment ) => {
				if ( ! attachment.get( 'uploading' ) && attachment.id ) {
					changed = true;
					library.add( attachment );
				}
			} );
			// What makes an upload one of the trip's photos (Uploads::REQUEST_FLAG).
			frame.on( 'open', () => {
				// Reopened while waiting: the next close carries on instead.
				if ( waiting ) {
					stopWaiting();
				}
				frame.uploader.uploader.param( 'post_id', trip );
				frame.uploader.uploader.param( 'travel_steps_photo', 1 );
			} );
			frame.on( 'close', closed );
		}
		frame.open();
	}

	button.addEventListener( 'click', open );
	// Saving a new trip of uploads comes here with ?add=1; a reload shouldn't reopen it.
	if ( panel.dataset.open ) {
		const url = new URL( window.location.href );
		url.searchParams.delete( 'add' );
		window.history.replaceState( null, '', url );
		open();
	}
}() );
