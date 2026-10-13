/* global wp */
/**
 * The trip form: what the "Photos" choice changes on the page, and adding photos to a
 * trip of uploads through the media modal.
 */
( function () {
	'use strict';

	const form = document.querySelector( '.ts-trip-form' );
	const choice = form && form.querySelector( 'select[name="photos"]' );
	if ( choice ) {
		const isNew = '0' === form.elements.trip_id.value;
		const save = form.querySelector( '.ts-save' );
		const sync = form.querySelector( 'button[name="sync"]' );
		const hint = form.querySelector( '.ts-dates-hint' );
		choice.addEventListener( 'change', () => {
			const uploads = 'uploads' === choice.value;
			// A trip of uploads can take its dates from the photos.
			form.querySelectorAll( 'input[type="date"]' ).forEach( ( input ) => {
				input.required = ! uploads;
			} );
			hint.hidden = ! uploads;
			// A new trip of uploads has nothing to sync yet: saving it opens the media modal.
			if ( isNew ) {
				save.textContent = uploads ? save.dataset.uploadsLabel : save.dataset.label;
				sync.hidden = uploads;
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
	const syncButton = form.querySelector( 'button[name="sync"]' );
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
		}
	} );

	// After an upload or a deletion the trip's steps are out of date: the author's page
	// syncs it; anyone else's reloads to say the author needs to. Unsaved changes to the
	// trip form are saved on the way (and synced, for the author).
	function leave() {
		if ( dirty ) {
			form.requestSubmit( syncButton );
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

	function closed() {
		if ( waiting ) {
			return;
		}
		if ( busy() ) {
			// Done, or closing, with uploads still going: finish them, then carry on.
			waiting = true;
			panel.querySelector( 'p' ).textContent = panel.dataset.finishing;
			const finished = () => {
				if ( ! busy() ) {
					wp.Uploader.queue.off( 'change:uploading remove reset', finished );
					waiting = false;
					closed();
				}
			};
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
