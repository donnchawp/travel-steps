/**
 * Editor side of the "Trip photos" block: previews the photos of the place it sits
 * under, found from the seeded place headings around it (anchors ts-place-<unix>).
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var PREFIX = 'ts-place-';

	function placeTime( block ) {
		var anchor = block && 'core/heading' === block.name ? block.attributes.anchor || '' : '';
		return 0 === anchor.indexOf( PREFIX ) ? parseInt( anchor.slice( PREFIX.length ), 10 ) || 0 : 0;
	}

	wp.blocks.registerBlockType( 'travel-steps/photos', {
		apiVersion: 3,
		title: __( 'Trip photos', 'travel-steps' ),
		category: 'media',
		icon: 'format-gallery',
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
					return start + ':' + end;
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
					attributes: { start: parseInt( range[ 0 ], 10 ), end: parseInt( range[ 1 ], 10 ) },
					urlQueryArgs: { post_id: postId },
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
