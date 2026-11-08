/**
 * A trip's print version: the print button waits until every photo has loaded, so none
 * is blank on paper or in the PDF.
 */
( function () {
	// Opened from a share link: the cookie is set, so the code needn't stay in the address
	// bar, where a browser's print header would put it on the page.
	const root = document.querySelector( '.ts-print' );
	if ( root.dataset.cleanUrl ) {
		history.replaceState( null, '', root.dataset.cleanUrl );
	}
	const button = document.querySelector( '.ts-print-button' );
	const status = document.querySelector( '.ts-print-status' );
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

	// Lays out the book for double-sided printing, once images in the text have their
	// height, measuring at the print stylesheet's sizes. Text that fits on a page is centred
	// on it. Longer text stays a plain block, as a centred box doesn't break across pages
	// reliably in every browser. Each text page starts on a left page, the even numbers,
	// with a blank page before it if need be: browsers ignore break-before: left.
	const layOutBook = () => {
		const texts = Array.from( root.querySelectorAll( '.ts-book-text' ) );
		const ruler = document.createElement( 'div' );
		ruler.className = 'ts-book-ruler';
		root.append( ruler );
		document.body.classList.add( 'is-measuring' );
		texts.forEach( ( text ) => text.classList.add( 'is-short' ) );

		const page = ruler.offsetHeight;
		const pagesOf = ( height ) => Math.max( 1, Math.ceil( height / page ) );
		texts.filter( ( text ) => text.offsetHeight >= page * 0.98 )
			.forEach( ( text ) => text.classList.remove( 'is-short' ) );
		const textPages = new Map( texts.map( ( text ) => [ text, pagesOf( text.offsetHeight ) ] ) );
		let printed = pagesOf( root.querySelector( '.ts-print-cover' ).offsetHeight );

		document.body.classList.remove( 'is-measuring' );
		ruler.remove();

		root.querySelectorAll( '.ts-book-text, .ts-book-photos' ).forEach( ( section ) => {
			if ( textPages.has( section ) ) {
				if ( 0 === printed % 2 ) {
					const blank = document.createElement( 'div' );
					blank.className = 'ts-book-blank';
					section.before( blank );
					printed++;
				}
				printed += textPages.get( section );
			} else {
				printed++;
			}
		} );
	};

	button.disabled = true;
	status.textContent = status.dataset.loading;
	Promise.all( images.map( settled ) ).then( ( loaded ) => {
		layOutBook();
		button.disabled = false;
		status.textContent = loaded.every( Boolean ) ? status.dataset.tip : status.dataset.failed;
	} );

	button.addEventListener( 'click', () => window.print() );
}() );
