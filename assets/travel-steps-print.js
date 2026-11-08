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

	// Lays out the book for double-sided printing, once images in the text have their
	// height, measuring at the print stylesheet's sizes. Text that fits on a page is centred
	// on it. Longer text stays a plain block, as a centred box doesn't break across pages
	// reliably in every browser. Each text page starts on a left page, the even numbers,
	// with a blank page before it if need be: browsers ignore break-before: left.
	const layOutBook = () => {
		const ruler = document.createElement( 'div' );
		ruler.style.height = '255mm'; // The shorter paper's printable height, Letter's.
		document.body.append( ruler );
		const page = ruler.offsetHeight;
		ruler.remove();
		const pagesOf = ( height ) => Math.max( 1, Math.ceil( height / page ) );

		document.body.style.setProperty( 'font-size', '11pt', 'important' );
		root.style.maxWidth = '186mm';
		root.style.padding = '0';
		const texts = Array.from( root.querySelectorAll( '.ts-book-text' ) );
		const textPages = new Map( texts.map( ( text ) => {
			text.style.paddingTop = '0';
			text.style.minHeight = '0';
			text.classList.add( 'is-short' );
			const fits = text.offsetHeight < page * 0.98;
			text.classList.toggle( 'is-short', fits );
			const pages = fits ? 1 : pagesOf( text.offsetHeight );
			text.style.paddingTop = '';
			text.style.minHeight = '';
			return [ text, pages ];
		} ) );
		const cover = root.querySelector( '.ts-print-cover' );
		let printed = cover ? pagesOf( cover.offsetHeight ) : 0;
		document.body.style.removeProperty( 'font-size' );
		root.style.maxWidth = '';
		root.style.padding = '';

		root.querySelectorAll( '.ts-book-blank' ).forEach( ( blank ) => blank.remove() );
		texts.concat( Array.from( root.querySelectorAll( '.ts-book-photos' ) ) )
			.sort( ( a, b ) => ( a.compareDocumentPosition( b ) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1 ) )
			.forEach( ( section ) => {
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
	const ready = Promise.all( images.map( settled ) ).then( ( loaded ) => {
		layOutBook();
		button.disabled = false;
		status.textContent = loaded.every( Boolean ) ? status.dataset.tip : status.dataset.failed;
	} );

	button.addEventListener( 'click', () => ready.then( () => window.print() ) );
}() );
