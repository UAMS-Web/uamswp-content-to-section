<?php
/**
 * Block-level and post-level conversion.
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns acf/uams-content blocks into acf/uams-section blocks.
 */
class UAMS_C2S_Converter {

	const SOURCE_BLOCK = 'acf/uams-content';
	const TARGET_BLOCK = 'acf/uams-section';

	const BACKUP_META    = '_uams_c2s_backup';
	const CONVERTED_META = '_uams_c2s_converted';

	/**
	 * Conversion arguments.
	 *
	 * @var array<string,mixed>
	 */
	protected $args;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $args Conversion arguments.
	 */
	public function __construct( array $args = array() ) {
		$this->args = wp_parse_args( $args, uams_c2s_default_args() );
	}

	/* ---------------------------------------------------------------------
	 * Content level
	 * ------------------------------------------------------------------ */

	/**
	 * Convert every Content block found in a post_content string.
	 *
	 * @param string $content Post content.
	 * @return array{content:string,converted:int,skipped:int,notices:string[]}
	 */
	public function convert_content( $content ) {
		$stats = array(
			'converted' => 0,
			'skipped'   => 0,
			'notices'   => array(),
		);

		$blocks = parse_blocks( $content );
		$blocks = $this->walk( $blocks, $stats );

		return array(
			'content'   => serialize_blocks( $blocks ),
			'converted' => $stats['converted'],
			'skipped'   => $stats['skipped'],
			'notices'   => array_values( array_unique( $stats['notices'] ) ),
		);
	}

	/**
	 * Recursively convert a parsed block tree.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @param array   $stats  Running stats, by reference.
	 * @return array[]
	 */
	protected function walk( array $blocks, array &$stats ) {
		$out = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->walk( $block['innerBlocks'], $stats );
			}

			if ( isset( $block['blockName'] ) && self::SOURCE_BLOCK === $block['blockName'] ) {
				$converted = $this->convert_block( $block, $stats );

				if ( null !== $converted ) {
					$out[] = $converted;
					continue;
				}
			}

			$out[] = $block;
		}

		return $out;
	}

	/**
	 * Convert one Content block.
	 *
	 * @param array $block Parsed block.
	 * @param array $stats Running stats, by reference.
	 * @return array|null Null when the block was intentionally left alone.
	 */
	public function convert_block( array $block, array &$stats ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$data  = $this->block_data( $attrs );

		$geo        = isset( $data['content_geo'] ) ? $data['content_geo'] : '';
		$geo_region = isset( $data['content_geo_region'] ) ? $data['content_geo_region'] : array();

		$has_geo = ! empty( $geo ) && ! empty( $geo_region );

		if ( $has_geo && empty( $this->args['include_geo'] ) ) {
			++$stats['skipped'];
			$stats['notices'][] = __( 'Skipped a Content block with GeoTargetingWP rules. The Section block has no region filter; re-run with "include geo-targeted blocks" to convert it and drop the rules.', 'uams-c2s' );

			return null;
		}

		if ( $has_geo ) {
			$stats['notices'][] = sprintf(
				/* translators: 1: include or exclude, 2: comma separated region list. */
				__( 'Region rules were dropped from a converted block (%1$s: %2$s). It now displays everywhere.', 'uams-c2s' ),
				$geo,
				implode( ', ', (array) $geo_region )
			);
		}

		$target_keys = UAMS_C2S_Field_Map::target_keys();
		$pairs       = UAMS_C2S_Field_Map::pairs();

		$new_data = array();

		foreach ( $pairs as $from => $to ) {
			$value = isset( $data[ $from ] ) ? $data[ $from ] : '';

			if ( 'section_hide_heading' === $to ) {
				$value = $value ? 1 : 0;
			}

			if ( 'section_background_color' === $to && '' === $value ) {
				$value = 'bg-auto';
			}

			$new_data[ $to ]       = $value;
			$new_data[ '_' . $to ] = isset( $target_keys[ $to ] ) ? $target_keys[ $to ] : '';
		}

		// Body: WYSIWYG -> inner blocks.
		$wysiwyg = UAMS_C2S_Field_Map::wysiwyg_field();
		$html    = isset( $data[ $wysiwyg ] ) ? (string) $data[ $wysiwyg ] : '';

		$parser       = new UAMS_C2S_HTML_To_Blocks( $this->args );
		$inner_blocks = $parser->convert( $html );

		foreach ( $parser->get_notices() as $notice ) {
			$stats['notices'][] = $notice;
		}

		if ( ! $inner_blocks ) {
			$stats['notices'][] = __( 'A Content block had an empty body; the Section block was created with no inner blocks.', 'uams-c2s' );
		}

		$new_attrs = array(
			'name' => self::TARGET_BLOCK,
			'data' => $new_data,
		);

		// ACF generates its own block id when absent; mirror the source block's
		// convention rather than reusing its id.
		if ( ! empty( $attrs['id'] ) ) {
			$new_attrs['id'] = uniqid( 'block_' );
		}

		$new_attrs['align'] = ! empty( $attrs['align'] ) ? $attrs['align'] : 'full';
		$new_attrs['mode']  = 'preview';

		if ( ! empty( $attrs['anchor'] ) ) {
			$new_attrs['anchor'] = $attrs['anchor'];
		} else {
			$stats['notices'][] = __( 'A converted block had no custom anchor, so its rendered id changes from "content-…" to "uams-section-…". Check for in-page links pointing at it.', 'uams-c2s' );
		}

		if ( ! empty( $attrs['className'] ) ) {
			$new_attrs['className'] = $attrs['className'];
		}

		++$stats['converted'];

		$count = count( $inner_blocks );

		return array(
			'blockName'    => self::TARGET_BLOCK,
			'attrs'        => $new_attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '',
			'innerContent' => $this->inner_content_padding( $count ),
		);
	}

	/**
	 * Build the innerContent array core would produce for N inner blocks.
	 *
	 * @param int $count Inner block count.
	 * @return array
	 */
	protected function inner_content_padding( $count ) {
		if ( $count < 1 ) {
			return array( "\n" );
		}

		$content = array( "\n" );

		for ( $i = 0; $i < $count; $i++ ) {
			$content[] = null;
			$content[] = "\n";
		}

		return $content;
	}

	/**
	 * Read an ACF block's field values, tolerating the legacy flat format.
	 *
	 * @param array $attrs Block attributes.
	 * @return array<string,mixed>
	 */
	protected function block_data( array $attrs ) {
		if ( isset( $attrs['data'] ) && is_array( $attrs['data'] ) ) {
			return $attrs['data'];
		}

		$reserved = array( 'id', 'name', 'align', 'mode', 'anchor', 'className', 'data' );

		return array_diff_key( $attrs, array_flip( $reserved ) );
	}

	/* ---------------------------------------------------------------------
	 * Post level
	 * ------------------------------------------------------------------ */

	/**
	 * Convert a post without saving.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array|WP_Error
	 */
	public function preview_post( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return new WP_Error( 'uams_c2s_no_post', __( 'Post not found.', 'uams-c2s' ) );
		}

		$result             = $this->convert_content( $post->post_content );
		$result['post_id']  = (int) $post->ID;
		$result['original'] = $post->post_content;

		return $result;
	}

	/**
	 * Convert a post and save it.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array|WP_Error
	 */
	public function convert_post( $post ) {
		$post   = get_post( $post );
		$result = $this->preview_post( $post );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $result['converted'] < 1 ) {
			$result['updated'] = false;

			return $result;
		}

		// Keep the pre-conversion content addressable for a one-click revert,
		// alongside the revision WordPress stores on update.
		if ( ! metadata_exists( 'post', $post->ID, self::BACKUP_META ) ) {
			add_post_meta( $post->ID, self::BACKUP_META, $result['original'], true );
		}

		$kses_was_active = false;

		if ( ! current_user_can( 'unfiltered_html' ) && has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			// Block markup must survive intact; kses would mangle the comments.
			kses_remove_filters();
			$kses_was_active = true;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $result['content'],
			),
			true
		);

		if ( $kses_was_active ) {
			kses_init_filters();
		}

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_post_meta(
			$post->ID,
			self::CONVERTED_META,
			array(
				'time'      => time(),
				'user'      => get_current_user_id(),
				'converted' => $result['converted'],
				'skipped'   => $result['skipped'],
				'version'   => UAMS_C2S_VERSION,
			)
		);

		$result['updated'] = true;

		return $result;
	}

	/**
	 * Restore a post's pre-conversion content.
	 *
	 * @param int|WP_Post $post Post.
	 * @return true|WP_Error
	 */
	public function revert_post( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return new WP_Error( 'uams_c2s_no_post', __( 'Post not found.', 'uams-c2s' ) );
		}

		if ( ! metadata_exists( 'post', $post->ID, self::BACKUP_META ) ) {
			return new WP_Error( 'uams_c2s_no_backup', __( 'No pre-conversion backup is stored for this post.', 'uams-c2s' ) );
		}

		$backup = get_post_meta( $post->ID, self::BACKUP_META, true );

		$kses_was_active = false;

		if ( ! current_user_can( 'unfiltered_html' ) && has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			kses_remove_filters();
			$kses_was_active = true;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $backup,
			),
			true
		);

		if ( $kses_was_active ) {
			kses_init_filters();
		}

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		delete_post_meta( $post->ID, self::BACKUP_META );
		delete_post_meta( $post->ID, self::CONVERTED_META );

		return true;
	}
}
