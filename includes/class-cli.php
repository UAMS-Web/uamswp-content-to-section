<?php
use function WP_CLI\Utils\get_flag_value;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\make_progress_bar;

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
	public function scan( $args, $assoc_args ): void {
		$post_type = get_flag_value( $assoc_args, 'post_type', '' );
		$format    = get_flag_value( $assoc_args, 'format', 'table' );

		$ids = UAMS_C2S_Scanner::find( [ 'post_type' => $post_type ] );

		if ( 'count' === $format ) {
			WP_CLI::line( count( $ids ) );

			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', $ids ) );

			return;
		}

		if ( $ids === [] ) {
			WP_CLI::success( 'No UAMS Content Blocks found.' );

			return;
		}

		$rows = [];

		foreach ( $ids as $id ) {
			$summary = UAMS_C2S_Scanner::summarize( $id );

			$rows[] = [
				'ID'        => $id,
				'type'      => get_post_type( $id ),
				'status'    => get_post_status( $id ),
				'blocks'    => $summary['blocks'],
				'geo'       => $summary['geo'],
				'title'     => get_the_title( $id ),
			];
		}

		format_items( $format, $rows, [ 'ID', 'type', 'status', 'blocks', 'geo', 'title' ] );
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
	 * [--post_status=<post_status>]
	 * : With --all, restrict to one post status — draft, publish, pending,
	 * future or private. Comma-separate for several.
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
	 *     wp uams-c2s convert --all --post_status=draft
	 *     wp uams-c2s convert --all --include-geo --demote-headings
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function convert( $args, $assoc_args ): void {
		$dry_run = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$verbose = (bool) get_flag_value( $assoc_args, 'verbose', false );
		$all     = (bool) get_flag_value( $assoc_args, 'all', false );

		$options = [
			'include_geo'     => (bool) get_flag_value( $assoc_args, 'include-geo', false ),
			'demote_headings' => (bool) get_flag_value( $assoc_args, 'demote-headings', false ),
			'list_format'     => get_flag_value( $assoc_args, 'list-format', 'v3' ),
		];

		$ids = array_map( 'absint', $args );

		if ( $ids === [] ) {
			if ( ! $all ) {
				WP_CLI::error( 'Pass one or more post IDs, or --all.' );
			}

			$status = get_flag_value( $assoc_args, 'post_status', '' );
			$status = $status ? array_map( 'trim', explode( ',', $status ) ) : '';

			if ( $status && ! UAMS_C2S_Scanner::narrow_statuses( $status ) ) {
				WP_CLI::error(
					sprintf(
						'No scannable post status matched "%s". Choose from: %s.',
						implode( ',', $status ),
						implode( ', ', UAMS_C2S_Scanner::statuses() )
					)
				);
			}

			$ids = UAMS_C2S_Scanner::find(
				[
					'post_type' => get_flag_value( $assoc_args, 'post_type', '' ),
					'status'    => $status,
				]
			);
		}

		if ( $ids === [] ) {
			WP_CLI::success( 'Nothing to convert.' );

			return;
		}

		$converter = new UAMS_C2S_Converter( $options );

		$totals = [
			'posts'     => 0,
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		];

		$progress = ( ! $verbose && count( $ids ) > 1 )
			? make_progress_bar( $dry_run ? 'Analysing' : 'Converting', count( $ids ) )
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
	public function revert( $args, $assoc_args ): void {
		global $wpdb;

		$ids = array_map( 'absint', $args );

		if ( $ids === [] ) {
			if ( ! get_flag_value( $assoc_args, 'all', false ) ) {
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

		if ( $ids === [] ) {
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

	/**
	 * Re-runs conversions that plugin 1.0.0 saved without slashing the markup.
	 *
	 * 1.0.0 handed the serialized blocks to wp_update_post() unslashed, so every
	 * block attribute escape on a converted page was flattened into literal
	 * "u003c" text. Repair restores the pre-conversion content from the post
	 * revision saved before the conversion, then converts again with the current
	 * code. The 1.0.0 backup meta is not used: it was written through the same
	 * unslashing and is damaged in the same way.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more post IDs. Omit to use --all.
	 *
	 * [--all]
	 * : Repair every damaged post on the site.
	 *
	 * [--dry-run]
	 * : Report what would be repaired, and from where, without saving.
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
	 * ## EXAMPLES
	 *
	 *     wp uams-c2s repair --all --dry-run
	 *     wp uams-c2s repair --all
	 *     wp uams-c2s repair 42
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function repair( $args, $assoc_args ): void {
		$dry_run = (bool) get_flag_value( $assoc_args, 'dry-run', false );

		$options = [
			'include_geo'     => (bool) get_flag_value( $assoc_args, 'include-geo', false ),
			'demote_headings' => (bool) get_flag_value( $assoc_args, 'demote-headings', false ),
			'list_format'     => get_flag_value( $assoc_args, 'list-format', 'v3' ),
		];

		$ids = array_map( 'absint', $args );

		if ( $ids === [] ) {
			if ( ! get_flag_value( $assoc_args, 'all', false ) ) {
				WP_CLI::error( 'Pass one or more post IDs, or --all.' );
			}

			$ids = array_values(
				array_unique(
					array_merge( UAMS_C2S_Scanner::find_damaged(), UAMS_C2S_Scanner::find_at_risk() )
				)
			);

			sort( $ids );
		}

		if ( $ids === [] ) {
			WP_CLI::success( 'No damaged conversions found.' );

			return;
		}

		$converter = new UAMS_C2S_Converter( $options );

		if ( $dry_run ) {
			$rows = [];

			foreach ( $ids as $id ) {
				$plan = $converter->repair_plan( $id );

				$rows[] = [
					'ID'        => $id,
					'title'     => get_the_title( $id ),
					'damaged'   => $plan['damaged'] ? 'yes' : 'not visible',
					'converted' => empty( $plan['converted'] )
						? 'no record'
						: sprintf( 'v%s', $plan['converted']['version'] ?? '?' ),
					// A mismatch is refused by repair_post(), so the dry run has to
					// report it as a blocker. Showing the revision it would have
					// restored from, with an empty blocker, promised work the run
					// will decline.
					'restores'  => ( $plan['error'] || 'mismatch' === $plan['verified'] ) ? '' : $plan['label'],
					'blocker'   => $plan['error'] ?: ('mismatch' === $plan['verified']
							? __( 'restore does not match the backup; compare by hand', 'uams-c2s' )
							: ''),
				];
			}

			format_items( 'table', $rows, [ 'ID', 'title', 'damaged', 'converted', 'restores', 'blocker' ] );

			foreach ( $rows as $row ) {
				if ( 'no record' === $row['converted'] && 'yes' === $row['damaged'] ) {
					WP_CLI::warning(
						sprintf(
							'#%d is damaged but carries no conversion record, so this plugin did not cause it. Matched: %s',
							$row['ID'],
							$converter->repair_plan( $row['ID'] )['sample']
						)
					);
				}
			}

			WP_CLI::success( 'Dry run. Nothing was saved.' );

			return;
		}

		$repaired = 0;
		$skipped  = 0;
		$failed   = 0;

		$reasons = [
			'clean'         => 'is not damaged; nothing to repair',
			'mismatch'      => 'was refused: its restore source does not match the content that was converted, so repairing would discard the difference. Compare them by hand',
			'not_converted' => 'was restored to its pre-conversion content, but it held no Content block to convert, so it is listed for conversion again rather than repaired',
		];

		foreach ( $ids as $id ) {
			$result = $converter->repair_post( $id, true );

			if ( is_wp_error( $result ) ) {
				++$failed;
				WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
				continue;
			}

			if ( empty( $result['repaired'] ) ) {
				++$skipped;

				$reason = $result['reason'] ?? 'clean';

				// A refusal is not the same as a no-op, and 'mismatch' in
				// particular is the one an operator has to act on.
				if ( isset( $reasons[ $reason ] ) && 'clean' !== $reason ) {
					WP_CLI::warning( sprintf( '#%d %s.', $id, $reasons[ $reason ] ) );
				}

				continue;
			}

			++$repaired;

			WP_CLI::log(
				sprintf(
					'#%d %s — restored from %s, %d block(s) re-converted',
					$id,
					get_the_title( $id ),
					$result['label'],
					$result['converted']
				)
			);
		}

		WP_CLI::success(
			sprintf( '%d post(s) repaired, %d not repaired, %d failed.', $repaired, $skipped, $failed )
		);
	}
}
