/**
 * Editor side of the "Trip photos" block: previews the photos of the place it sits
 * under, found from the seeded place headings around it (anchors ts-place-<unix>).
 * Under a heading still waiting for its first photo (ts-place-pick, from the "Trip
 * place" pattern) it lists all the step's photos, and clicking one sets that heading's
 * anchor to its time.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var PREFIX = 'ts-place-';
	var PICK = 'ts-place-pick';

	function placeAnchor( block ) {
		var anchor = block && 'core/heading' === block.name ? block.attributes.anchor || '' : '';
		return 0 === anchor.indexOf( PREFIX ) ? anchor : '';
	}

	function placeTime( block ) {
		return parseInt( placeAnchor( block ).slice( PREFIX.length ), 10 ) || 0;
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
					var pick = false;
					var heading = '';
					var i;
					// The nearest place heading above: one with a time, or one waiting for it.
					for ( i = index - 1; i >= 0 && ! heading; i-- ) {
						pick = PICK === placeAnchor( blocks[ i ] );
						start = placeTime( blocks[ i ] );
						heading = pick || start ? blocks[ i ].clientId : '';
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
					// Times other place headings start at: picking one would give two headings
					// the same anchor.
					var taken = blocks.map( placeTime ).filter( Boolean ).join( ',' );
					return [ start, end, lead, pick, heading, taken ].join( ':' );
				},
				[ props.clientId ]
			).split( ':' );
			var pick = 'true' === range[ 3 ];
			var heading = range[ 4 ];
			var taken = range[ 5 ] ? range[ 5 ].split( ',' ).map( Number ) : [];
			var postId = wp.data.useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			}, [] );
			var update = wp.data.useDispatch( 'core/block-editor' ).updateBlockAttributes;

			// Clicks only pick while the heading waits for its first photo, so a stray tap
			// can't move a section. Photos already starting a place are disabled buttons.
			function onClick( event ) {
				var photo = pick ? event.target.closest( 'button[data-t]:not(:disabled)' ) : null;
				if ( photo ) {
					update( heading, { anchor: PREFIX + parseInt( photo.getAttribute( 'data-t' ), 10 ) } );
				}
			}

			return el(
				'div',
				wp.blockEditor.useBlockProps( { onClick: onClick } ),
				heading && ! pick
					? el(
							wp.blockEditor.InspectorControls,
							null,
							el(
								wp.components.PanelBody,
								{ title: __( 'Place', 'travel-steps-storyteller' ) },
								el(
									wp.components.Button,
									{
										variant: 'secondary',
										onClick: function () {
											update( heading, { anchor: PICK } );
										},
									},
									__( 'Change first photo', 'travel-steps-storyteller' )
								)
							)
					  )
					: null,
				el( wp.serverSideRender, {
					block: 'travel-steps/photos',
					attributes: {
						start: parseInt( range[ 0 ], 10 ),
						end: parseInt( range[ 1 ], 10 ),
						lead: parseInt( range[ 2 ], 10 ),
						pick: pick,
						taken: taken,
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
