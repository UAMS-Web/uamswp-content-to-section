<?php
/**
 * Finds posts that still contain UAMS Content Blocks.
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content block scanner.
 */
class UAMS_C2S_Scanner {

	/**
	 * Post statuses worth touching.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		/**
		 * Filters the post statuses included in a scan.
		 *
		 * @param string[] $statuses Post statuses.
		 */
		return apply_filters(
			'uams_c2s_statuses',
			array( 'publish', 'draft', 'pending', 'future', 'private' )
		);
	}

	/**
	 * IDs of posts containing at least one Content block.
	 *
	 * @param array<string,mixed> $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string   $post_type Restrict to one post type.
	 *     @type int      $limit     Maximum rows. 0 for all.
	 *     @type int      $offset    Offset.
	 *     @type int[]    $exclude   Post IDs to skip.
	 * }
	 * @return int[]
	 */
	public static function find( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'post_type' => '',
				'limit'     => 0,
				'offset'    => 0,
				'exclude'   => array(),
			)
		);

		$statuses     = self::statuses();
		$status_slots = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$needle = '%' . $wpdb->esc_like( '<!-- wp:' . UAMS_C2S_Converter::SOURCE_BLOCK ) . '%';

		$sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ( {$status_slots} )";
		$params = array_merge( array( $needle ), $statuses );

		if ( $args['post_type'] ) {
			$sql     .= ' AND post_type = %s';
			$params[] = $args['post_type'];
		}

		if ( ! empty( $args['exclude'] ) ) {
			$exclude      = array_map( 'absint', (array) $args['exclude'] );
			$exclude_slots = implode( ', ', array_fill( 0, count( $exclude ), '%d' ) );
			$sql          .= " AND ID NOT IN ( {$exclude_slots} )";
			$params        = array_merge( $params, $exclude );
		}

		$sql .= ' ORDER BY post_type ASC, ID ASC';

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['limit'];
			$params[] = (int) $args['offset'];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Count posts containing at least one Content block.
	 *
	 * @param string $post_type Optional post type filter.
	 * @return int
	 */
	public static function count( $post_type = '' ) {
		return count( self::find( array( 'post_type' => $post_type ) ) );
	}

	/**
	 * Summarise one post: how many blocks, and whether any are geo-targeted.
	 *
	 * @param int|WP_Post $post Post.
	 * @return array{blocks:int,geo:int}
	 */
	public static function summarize( $post ) {
		$post = get_post( $post );

		$summary = array(
			'blocks' => 0,
			'geo'    => 0,
		);

		if ( ! $post ) {
			return $summary;
		}

		self::tally( parse_blocks( $post->post_content ), $summary );

		return $summary;
	}

	/**
	 * Walk a block tree counting Content blocks.
	 *
	 * @param array[] $blocks  Parsed blocks.
	 * @param array   $summary Running tally, by reference.
	 */
	protected static function tally( array $blocks, array &$summary ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && UAMS_C2S_Converter::SOURCE_BLOCK === $block['blockName'] ) {
				++$summary['blocks'];

				$data = isset( $block['attrs']['data'] ) && is_array( $block['attrs']['data'] )
					? $block['attrs']['data']
					: array();

				if ( ! empty( $data['content_geo'] ) && ! empty( $data['content_geo_region'] ) ) {
					++$summary['geo'];
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::tally( $block['innerBlocks'], $summary );
			}
		}
	}

	/**
	 * Post types that currently hold Content blocks.
	 *
	 * @return array<string,int> Post type slug => count.
	 */
	public static function post_type_counts() {
		$counts = array();

		foreach ( self::find() as $id ) {
			$type = get_post_type( $id );

			if ( ! $type ) {
				continue;
			}

			$counts[ $type ] = isset( $counts[ $type ] ) ? $counts[ $type ] + 1 : 1;
		}

		ksort( $counts );

		return $counts;
	}
}
