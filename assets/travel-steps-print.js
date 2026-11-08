/**
 * A trip's print version: the print button waits until every photo has loaded, so none
 * is blank on paper or in the PDF.
 */
( function () {
	// Opened from a share link: the cookie is set, so the code needn't stay in the address
	// bar, where a browser's print header would put it on the page.
	const root = document.querySelector( '.ts-print' );
	if ( root && root.dataset.cleanUrl ) {
		history.replaceState( null, '', root.dataset.cleanUrl );
	}
	const button = document.querySelector( '.ts-print-button' );
	const status = document.querySelector( '.ts-print-status' );
	if ( ! button || ! status ) {
		return;
	}
	const images = Array.from( document.querySelectorAll( '.ts-print img' ) );
	// WordPress makes images in a story lazy, and a lazy image far down the page wouldn't
	// load until it's scrolled to, so the button would wait for ever.
	images.forEach( ( img ) => {
		img.loading = 'eager';
	} );
	const settled = ( img ) => img.complete
		? Promise.resolve( img.naturalWidth > 0 )
		: new Promise( ( resolve ) => {
			img.addEventListener( 'load', () => resolve( true ), { once: true } );
			img.addEventListener( 'error', () => resolve( false ), { once: true } );
		} );

	button.disabled = true;
	status.textContent = status.dataset.loading;
	const ready = Promise.all( images.map( settled ) ).then( ( loaded ) => {
		button.disabled = false;
		status.textContent = loaded.every( Boolean ) ? status.dataset.tip : status.dataset.failed;
	} );

	button.addEventListener( 'click', () => ready.then( () => window.print() ) );
}() );
