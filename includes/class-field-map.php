<?php
/**
 * Resolves ACF field names to field keys for the Section block.
 *
 * ACF blocks store their values in the block's `data` attribute as
 * `name => value` pairs, each shadowed by `_name => field_key`. Field keys are
 * a write-side concern only: the Content block is read by field *name*, and
 * only the Section block being written needs keys. The keys are read from the
 * live field group when ACF is available, so a theme that changes its `$suffix`
 * keeps working; the hard-coded fallbacks match the uams-2020 theme
 * (`$suffix = '_b'`).
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field key resolution for the Content and Section blocks.
 */
class UAMS_C2S_Field_Map {

	const TARGET_GROUP = 'group_uams_section';

	/**
	 * Fallback keys for the Section block.
	 *
	 * @var array<string,string>
	 */
	protected static $fallback_target = array(
		'section_heading'          => 'field_section_heading_b',
		'section_hide_heading'     => 'field_section_hide_heading_b',
		'section_background_color' => 'field_section_background_color_b',
	);

	/**
	 * Runtime cache.
	 *
	 * @var array<string,array<string,string>>
	 */
	protected static $cache = array();

	/**
	 * Source field name => target field name.
	 *
	 * @return array<string,string>
	 */
	public static function pairs() {
		/**
		 * Filters the Content-field to Section-field mapping.
		 *
		 * @param array<string,string> $pairs Source name => target name.
		 */
		return apply_filters(
			'uams_c2s_field_pairs',
			array(
				'content_heading'          => 'section_heading',
				'content_hide_heading'     => 'section_hide_heading',
				'content_background_color' => 'section_background_color',
			)
		);
	}

	/**
	 * Field name => field key for the Section block.
	 *
	 * @return array<string,string>
	 */
	public static function target_keys() {
		return self::keys( self::TARGET_GROUP, self::$fallback_target );
	}

	/**
	 * Name of the WYSIWYG field on the Content block.
	 *
	 * @return string
	 */
	public static function wysiwyg_field() {
		/**
		 * Filters the Content block field holding the rich text body.
		 *
		 * @param string $field Field name.
		 */
		return apply_filters( 'uams_c2s_wysiwyg_field', 'content_content' );
	}

	/**
	 * Resolve a field group's keys, preferring the live ACF registration.
	 *
	 * @param string               $group    Field group key.
	 * @param array<string,string> $fallback Hard-coded fallback map.
	 * @return array<string,string>
	 */
	protected static function keys( $group, array $fallback ) {
		if ( isset( self::$cache[ $group ] ) ) {
			return self::$cache[ $group ];
		}

		$map = $fallback;

		if ( function_exists( 'acf_get_fields' ) ) {
			$fields = acf_get_fields( $group );

			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					if ( ! empty( $field['name'] ) && ! empty( $field['key'] ) ) {
						$map[ $field['name'] ] = $field['key'];
					}
				}
			}
		}

		self::$cache[ $group ] = $map;

		return $map;
	}
}
