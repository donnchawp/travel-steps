/**
 * Editor side of the "Trip photos" block: previews the photos of the place it sits
 * under, found from the seeded place headings around it (anchors ts-place-<unix>).
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var PREFIX = 'ts-place-';

	function placeTime( block ) {
		var anchor = block && 'core/heading' === block.name ? block.attributes.anchor || '' : '';
		return 0 === anchor.indexOf( PREFIX ) ? parseInt( anchor.slice( PREFIX.length ), 10 ) || 0 : 0;
	}

	// Title, icon and the rest come from the server's registration.
	wp.blocks.registerBlockType( 'travel-steps/photos', {
		edit: function ( props ) {
			// A string, so the selector returns an equal value while nothing relevant changes.
			var range = wp.data.useSelect(
				function ( select ) {
					var editor = select( 'core/block-editor' );
					var root = editor.getBlockHierarchyRootClientId( props.clientId );
					var blocks = editor.getBlocks();
					var index = blocks.findIndex( function ( block ) {
						return block.clientId === root;
					} );
					var start = 0;
					var end = 0;
					var i;
					for ( i = index - 1; i >= 0 && ! start; i-- ) {
						start = placeTime( blocks[ i ] );
					}
					for ( i = index + 1; i < blocks.length && ! end; i++ ) {
						end = placeTime( blocks[ i ] );
					}
					// The trip page puts photos taken before the earliest place heading above the
					// first heading. With no photos block up there, the first block shows them.
					var lead = 0;
					var first = ! blocks.slice( 0, index ).some( function ( block ) {
						return 'travel-steps/photos' === block.name;
					} );
					if ( start && first ) {
						lead = Math.min.apply( null, blocks.map( placeTime ).filter( Boolean ) );
					}
					return start + ':' + end + ':' + lead;
				},
				[ props.clientId ]
			).split( ':' );
			var postId = wp.data.useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			}, [] );

			return el(
				'div',
				wp.blockEditor.useBlockProps(),
				el( wp.serverSideRender, {
					block: 'travel-steps/photos',
					attributes: {
						start: parseInt( range[ 0 ], 10 ),
						end: parseInt( range[ 1 ], 10 ),
						lead: parseInt( range[ 2 ], 10 ),
					},
					urlQueryArgs: { post_id: postId },
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
