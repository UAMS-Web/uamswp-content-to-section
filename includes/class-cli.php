<?php
/**
 * WP-CLI commands.
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Convert UAMS Content Blocks into UAMS Section Blocks.
 */
class UAMS_C2S_CLI {

	/**
	 * Lists posts that still contain UAMS Content Blocks.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Restrict the scan to one post type.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp uams-c2s scan
	 *     wp uams-c2s scan --post_type=page --format=ids
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function scan( $args, $assoc_args ) {
		$post_type = WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type', '' );
		$format    = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$ids = UAMS_C2S_Scanner::find( array( 'post_type' => $post_type ) );

		if ( 'count' === $format ) {
			WP_CLI::line( count( $ids ) );

			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', $ids ) );

			return;
		}

		if ( ! $ids ) {
			WP_CLI::success( 'No UAMS Content Blocks found.' );

			return;
		}

		$rows = array();

		foreach ( $ids as $id ) {
			$summary = UAMS_C2S_Scanner::summarize( $id );

			$rows[] = array(
				'ID'        => $id,
				'type'      => get_post_type( $id ),
				'status'    => get_post_status( $id ),
				'blocks'    => $summary['blocks'],
				'geo'       => $summary['geo'],
				'title'     => get_the_title( $id ),
			);
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'type', 'status', 'blocks', 'geo', 'title' ) );
	}

	/**
	 * Converts Content Blocks into Section Blocks.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more post IDs. Omit to use --all.
	 *
	 * [--all]
	 * : Convert every post that contains a Content Block.
	 *
	 * [--post_type=<post_type>]
	 * : With --all, restrict to one post type.
	 *
	 * [--dry-run]
	 * : Report what would change without saving.
	 *
	 * [--include-geo]
	 * : Also convert geo-targeted blocks, dropping their region rules.
	 *
	 * [--demote-headings]
	 * : Demote H1/H2 in the body to H3 so they nest under the section heading.
	 *
	 * [--list-format=<format>]
	 * : List block markup to emit.
	 * ---
	 * default: v3
	 * options:
	 *   - v3
	 *   - v2
	 * ---
	 *
	 * [--verbose]
	 * : Print every conversion note.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uams-c2s convert --all --dry-run
	 *     wp uams-c2s convert 42 108 --verbose
	 *     wp uams-c2s convert --all --include-geo --demote-headings
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function convert( $args, $assoc_args ) {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$verbose = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );
		$all     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );

		$options = array(
			'include_geo'     => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'include-geo', false ),
			'demote_headings' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'demote-headings', false ),
			'list_format'     => WP_CLI\Utils\get_flag_value( $assoc_args, 'list-format', 'v3' ),
		);

		$ids = array_map( 'absint', $args );

		if ( ! $ids ) {
			if ( ! $all ) {
				WP_CLI::error( 'Pass one or more post IDs, or --all.' );
			}

			$ids = UAMS_C2S_Scanner::find(
				array( 'post_type' => WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type', '' ) )
			);
		}

		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to convert.' );

			return;
		}

		$converter = new UAMS_C2S_Converter( $options );

		$totals = array(
			'posts'     => 0,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);

		$progress = ( ! $verbose && count( $ids ) > 1 )
			? WP_CLI\Utils\make_progress_bar( $dry_run ? 'Analysing' : 'Converting', count( $ids ) )
			: null;

		foreach ( $ids as $id ) {
			$result = $dry_run ? $converter->preview_post( $id ) : $converter->convert_post( $id );

			if ( $progress ) {
				$progress->tick();
			}

			if ( is_wp_error( $result ) ) {
				++$totals['failed'];
				WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
				continue;
			}

			if ( $result['converted'] < 1 && $result['skipped'] < 1 ) {
				continue;
			}

			++$totals['posts'];
			$totals['converted'] += $result['converted'];
			$totals['skipped']   += $result['skipped'];

			if ( $verbose ) {
				WP_CLI::log(
					sprintf(
						'#%d %s — %d converted, %d skipped',
						$id,
						get_the_title( $id ),
						$result['converted'],
						$result['skipped']
					)
				);

				foreach ( $result['notices'] as $notice ) {
					WP_CLI::log( '    · ' . $notice );
				}
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		$summary = sprintf(
			'%d post(s), %d block(s) converted, %d skipped, %d failed.',
			$totals['posts'],
			$totals['converted'],
			$totals['skipped'],
			$totals['failed']
		);

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run: ' . $summary . ' Nothing was saved.' );

			return;
		}

		WP_CLI::success( $summary );
	}

	/**
	 * Restores posts to their pre-conversion content.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more post IDs. Omit to use --all.
	 *
	 * [--all]
	 * : Revert every post this plugin converted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp uams-c2s revert 42
	 *     wp uams-c2s revert --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function revert( $args, $assoc_args ) {
		global $wpdb;

		$ids = array_map( 'absint', $args );

		if ( ! $ids ) {
			if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
				WP_CLI::error( 'Pass one or more post IDs, or --all.' );
			}

			$ids = array_map(
				'absint',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
						UAMS_C2S_Converter::BACKUP_META
					)
				)
			);
		}

		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to revert.' );

			return;
		}

		$converter = new UAMS_C2S_Converter();
		$reverted  = 0;

		foreach ( $ids as $id ) {
			$result = $converter->revert_post( $id );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
				continue;
			}

			++$reverted;
		}

		WP_CLI::success( sprintf( '%d post(s) reverted.', $reverted ) );
	}
}
