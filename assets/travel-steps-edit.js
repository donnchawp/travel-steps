/**
 * The trip form: what the "Photos" choice changes on the page.
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
				if ( sync ) {
					sync.hidden = uploads;
				}
			}
		} );
	}
}() );
