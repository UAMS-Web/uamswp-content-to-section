/**
 * Adds a "Transform to → UAMS Section" option to the UAMS Content Block.
 *
 * The block toolbar transform uses the editor's own raw-HTML handler, so the
 * WYSIWYG body is converted with exactly the same rules the editor uses when
 * you paste HTML into the canvas.
 *
 * @package UAMS_Content_To_Section
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.blocks || ! config ) {
		return;
	}

	var SOURCE = config.sourceBlock;
	var TARGET = config.targetBlock;

	/**
	 * Convert the WYSIWYG value into an array of blocks.
	 *
	 * @param {string} html Raw field value.
	 * @return {Object[]} Blocks.
	 */
	function bodyToBlocks( html ) {
		if ( ! html ) {
			return [];
		}

		// ACF stores the unfiltered editor value; the front end used to run it
		// through wpautop, so materialise the paragraphs first.
		var prepared = wp.autop && wp.autop.autop ? wp.autop.autop( html ) : html;

		var blocks = wp.blocks.rawHandler( { HTML: prepared } );

		return Array.isArray( blocks ) ? blocks : [];
	}

	/**
	 * PHP's notion of truthiness, which JavaScript's does not share.
	 *
	 * ACF block data is form-serialized, so a true/false field saved as false
	 * commonly arrives as the string "0". PHP reads that as false; JavaScript
	 * reads any non-empty string as true. Written with `value ? 1 : 0` on both
	 * sides, the editor transform hid a heading the batch converter showed.
	 *
	 * This must keep agreeing with `empty()` as used in
	 * UAMS_C2S_Converter::convert_block().
	 *
	 * @param {*} value Value from an ACF field.
	 * @return {boolean} What PHP would consider this value to be.
	 */
	function phpTruthy( value ) {
		if ( Array.isArray( value ) ) {
			return value.length > 0;
		}

		return ! (
			undefined === value ||
			null === value ||
			false === value ||
			'' === value ||
			'0' === value ||
			0 === value
		);
	}

	/**
	 * Build the Section block's `data` attribute from the Content block's.
	 *
	 * Mirrors UAMS_C2S_Converter::convert_block(); the two run over the same
	 * blocks and must produce the same result.
	 *
	 * @param {Object} data Source block data.
	 * @return {Object} Target block data.
	 */
	function mapData( data ) {
		var source = data || {};
		var mapped = {};

		Object.keys( config.pairs ).forEach( function ( from ) {
			var to = config.pairs[ from ];

			// PHP reads the value with isset(), which treats an explicit null as
			// absent; `typeof !== 'undefined'` alone would keep it.
			var present = typeof source[ from ] !== 'undefined' && null !== source[ from ];
			var value   = present ? source[ from ] : '';

			if ( 'section_hide_heading' === to ) {
				value = phpTruthy( value ) ? 1 : 0;
			}

			if ( 'section_background_color' === to && '' === value ) {
				value = 'bg-auto';
			}

			mapped[ to ] = value;
			mapped[ '_' + to ] = config.targetKeys[ to ] || '';
		} );

		return mapped;
	}

	/**
	 * Whether the block carries GeoTargetingWP rules that cannot survive.
	 *
	 * @param {Object} data Source block data.
	 * @return {boolean} True when region rules are set.
	 */
	function hasGeo( data ) {
		if ( ! data ) {
			return false;
		}

		// PHP: ! empty( $geo ) && ! empty( $geo_region ). phpTruthy() covers the
		// empty-array case the explicit Array.isArray check used to handle, and
		// the "0" case it got wrong.
		return phpTruthy( data.content_geo ) && phpTruthy( data.content_geo_region );
	}

	wp.hooks.addFilter(
		'blocks.registerBlockType',
		'uams-c2s/content-to-section',
		function ( settings, name ) {
			if ( name !== SOURCE ) {
				return settings;
			}

			var transforms = settings.transforms ? Object.assign( {}, settings.transforms ) : {};
			var to = transforms.to ? transforms.to.slice() : [];

			to.push( {
				type: 'block',
				blocks: [ TARGET ],
				transform: function ( attributes ) {
					var data = attributes && attributes.data ? attributes.data : {};

					if ( hasGeo( data ) ) {
						// eslint-disable-next-line no-console
						console.warn(
							'[UAMS] This Content Block has region targeting. The Section Block has no region filter, so those rules are dropped by this transform.'
						);
					}

					var inner = bodyToBlocks( data[ 'content_content' ] );

					return wp.blocks.createBlock(
						TARGET,
						{
							name: TARGET,
							data: mapData( data ),
							align: attributes && attributes.align ? attributes.align : 'full',
							mode: 'preview',
							anchor: attributes && attributes.anchor ? attributes.anchor : undefined,
							className:
								attributes && attributes.className ? attributes.className : undefined,
						},
						inner
					);
				},
			} );

			transforms.to = to;
			settings.transforms = transforms;

			return settings;
		}
	);
} )( window.wp, window.uamsC2S );
