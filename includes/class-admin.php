<?php
/**
 * Tools screen: scan, preview, convert, revert.
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class UAMS_C2S_Admin {

	const PAGE       = 'uams-c2s';

	const OPTION     = 'uams_c2s_options';

	const CAPABILITY = 'manage_options';

	const BATCH_SIZE = 10;

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	protected static $instance;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'styles' ] );
		add_action( 'admin_post_uams_c2s_save_options', [ $this, 'handle_save_options' ] );
		add_action( 'admin_post_uams_c2s_convert', [ $this, 'handle_convert' ] );
		add_action( 'admin_post_uams_c2s_revert', [ $this, 'handle_revert' ] );
		add_action( 'admin_post_uams_c2s_repair', [ $this, 'handle_repair' ] );
		add_action( 'admin_post_uams_c2s_repair_batch', [ $this, 'handle_repair_batch' ] );
		add_action( 'admin_post_uams_c2s_batch', [ $this, 'handle_batch' ] );
	}

	/**
	 * Add the Tools submenu.
	 */
	public function menu(): void {
		add_management_page(
			__( 'UAMS Content → Section', 'uams-c2s' ),
			__( 'Content → Section', 'uams-c2s' ),
			self::CAPABILITY,
			self::PAGE,
			[ $this, 'render' ]
		);
	}

	/**
	 * Enqueue the screen stylesheet.
	 *
	 * @param string $hook Current admin page.
	 */
	public function styles( $hook ): void {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'uams-c2s-admin',
			UAMS_C2S_URL . 'assets/css/admin.css',
			[],
			UAMS_C2S_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Options
	 * ------------------------------------------------------------------ */

	/**
	 * Stored conversion options merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function options(): array {
		$stored = get_option( self::OPTION, [] );

		return wp_parse_args( is_array( $stored ) ? $stored : [], uams_c2s_default_args() );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Render the Tools screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run this conversion.', 'uams-c2s' ) );
		}

		$options = $this->options();
		$ids     = UAMS_C2S_Scanner::find();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$preview_id = isset( $_GET['preview'] ) ? absint( $_GET['preview'] ) : 0;
		$notice     = isset( $_GET['uams_c2s_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['uams_c2s_notice'] ) ) : '';
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;
		$total    = count( $ids );
		$pages    = (int) max( 1, ceil( $total / $per_page ) );
		$paged    = min( $paged, $pages );
		$page_ids = array_slice( $ids, ( $paged - 1 ) * $per_page, $per_page );

		?>
		<div class="wrap uams-c2s">
			<h1><?php esc_html_e( 'UAMS Content Block → UAMS Section Block', 'uams-c2s' ); ?></h1>

			<?php $this->render_notice( $notice ); ?>
			<?php $this->render_environment_warnings(); ?>
			<?php $this->render_status(); ?>
			<?php $this->render_damaged( $options ); ?>

			<p class="description">
				<?php
				esc_html_e( 'Rewrites every acf/uams-content block into an acf/uams-section block. The heading, hide-heading and background colour settings carry across; the WYSIWYG body becomes real inner blocks (paragraphs, headings, lists, images, tables, embeds, shortcodes).', 'uams-c2s' );
				?>
			</p>

			<h2><?php esc_html_e( 'Conversion options', 'uams-c2s' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uams_c2s_save_options" />
				<?php wp_nonce_field( 'uams_c2s_save_options' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Geo-targeted blocks', 'uams-c2s' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="include_geo" value="1" <?php checked( ! empty( $options['include_geo'] ) ); ?> />
								<?php esc_html_e( 'Convert blocks that use GeoTargetingWP region rules', 'uams-c2s' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The Section block has no region filter, so those rules are dropped and the section shows to everyone. Off by default: geo-targeted blocks are skipped and reported.', 'uams-c2s' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Headings', 'uams-c2s' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="demote_headings" value="1" <?php checked( ! empty( $options['demote_headings'] ) ); ?> />
								<?php esc_html_e( 'Demote H1/H2 found in the body to H3', 'uams-c2s' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'The Section block renders its own H2, so body headings should start at H3 to keep the document outline valid.', 'uams-c2s' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'List markup', 'uams-c2s' ); ?></th>
						<td>
							<select name="list_format">
								<option value="v3" <?php selected( 'v3', $options['list_format'] ); ?>><?php esc_html_e( 'core/list + core/list-item (WordPress 6.0+)', 'uams-c2s' ); ?></option>
								<option value="v2" <?php selected( 'v2', $options['list_format'] ); ?>><?php esc_html_e( 'Single core/list block (legacy)', 'uams-c2s' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save options', 'uams-c2s' ) ); ?>
			</form>

			<h2>
				<?php
				printf(
					/* translators: %d: number of posts. */
					esc_html( _n( '%d post to convert', '%d posts to convert', $total, 'uams-c2s' ) ),
					absint( $total )
				);
				?>
			</h2>

			<?php $this->render_batch_buttons( $total ); ?>

			<?php $this->render_table( $page_ids, $preview_id ); ?>
			<?php $this->render_pagination( $paged, $pages ); ?>
			<?php $this->render_preview( $preview_id, $options ); ?>
		</div>
		<?php
	}

	/**
	 * Posts left mangled by plugin 1.0.0, with a per-post repair button.
	 *
	 * These no longer contain a Content block, so they have already dropped off
	 * the conversion table above and need their own listing.
	 *
	 * @param array<string,mixed> $options Conversion options.
	 */
	protected function render_damaged( array $options ) {
		$ids = $this->repair_queue();

		if ( $ids === [] ) {
			return;
		}

		$converter = new UAMS_C2S_Converter( $options );

		$plans = [];

		foreach ( $ids as $id ) {
			$plans[ $id ] = $converter->repair_plan( $id );
		}

		$ours    = 0;
		$visible = 0;

		foreach ( $plans as $plan ) {
			if ( ! empty( $plan['converted'] ) ) {
				++$ours;
			}

			if ( $plan['damaged'] ) {
				++$visible;
			}
		}

		?>
		<h2><?php esc_html_e( 'Needs re-converting', 'uams-c2s' ); ?></h2>

		<div class="notice notice-error inline">
			<p>
				<?php
				printf(
					/* translators: 1: total posts, 2: posts with visible damage. */
					esc_html__( '%1$d post(s) were written through the 1.0.0 slashing bug, or carry its signature. %2$d of them show it in their markup: block attribute escapes that should read backslash-u003c are stored as bare "u003c" text, so those blocks render their attributes as literal text.', 'uams-c2s' ),
					count( $plans ),
					absint( $visible )
				);
				?>
			</p>
			<p>
				<?php if ( count( $plans ) > $visible ) : ?>
					<strong><?php esc_html_e( 'A clean-looking post in this list is not proof it is undamaged.', 'uams-c2s' ); ?></strong>
					<?php esc_html_e( 'The detector can only recognise escapes whose stripped form is unmistakable. A lost backslash-n or backslash-t inside an attribute value leaves a bare letter that reads as ordinary text, so posts converted by an affected version are listed on the strength of their conversion record instead.', 'uams-c2s' ); ?>
				<?php endif; ?>
			</p>
			<p>
				<?php
				if ( $ours ) {
					esc_html_e( 'Repair restores the pre-conversion content from the revision saved before the conversion and converts it again with the current code. The old backup is not used, because add_post_meta() unslashed it the same way.', 'uams-c2s' );
				} else {
					esc_html_e( 'None of them carry a conversion record from this plugin, so it did not cause this. Any code that writes post_content without wp_slash() leaves the same signature — an import, a REST or API update, or a search-and-replace. Check the evidence column before acting.', 'uams-c2s' );
				}
				?>
			</p>
		</div>

		<?php
		// Count what will actually repair. Mismatches are refused by
		// repair_post(), so including them here promised a number the run could
		// not deliver -- a queue of three mismatches offered "Repair all 3" and
		// repaired none.
		$repairable = 0;

		foreach ( $plans as $plan ) {
			if ( ! $plan['error'] && 'mismatch' !== $plan['verified'] ) {
				++$repairable;
			}
		}
		?>

		<?php if ( $repairable > 1 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uams-c2s-batch">
				<input type="hidden" name="action" value="uams_c2s_repair_batch" />
				<?php wp_nonce_field( 'uams_c2s_repair_batch' ); ?>
				<?php
				submit_button(
					sprintf(
						/* translators: %d: number of posts. */
						__( 'Repair all %d posts', 'uams-c2s' ),
						$repairable
					),
					'primary',
					'submit',
					false
				);
				?>
				<span class="description"><?php esc_html_e( 'Runs in batches of 10. Each post is restored from its pre-conversion revision, then converted again.', 'uams-c2s' ); ?></span>
			</form>
		<?php endif; ?>

		<table class="widefat striped uams-c2s-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Converted by this plugin', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Visible damage', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Restores from', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'uams-c2s' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $plans as $id => $plan ) : ?>
				<?php
				$title = get_the_title( $id );
				$view  = $this->view_link( $id );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $title ?: '(' . $id . ')' ); ?></a></strong>
						<div class="row-actions">
							<span>#<?php echo absint( $id ); ?></span>
							| <span><?php echo esc_html( (string) get_post_status( $id ) ); ?></span>
							<?php if ( $view ) : ?>
								| <span><a href="<?php echo esc_url( $view['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $view['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'uams-c2s' ); ?></span></a></span>
							<?php endif; ?>
						</div>
					</td>
					<td><?php echo esc_html( (string) get_post_type( $id ) ); ?></td>
					<td>
						<?php if ( ! empty( $plan['converted'] ) ) : ?>
							<?php
							$record = $plan['converted'];

							echo esc_html(
								sprintf(
									/* translators: 1: plugin version, 2: date. */
									__( 'yes — v%1$s, %2$s', 'uams-c2s' ),
									$record['version'] ?? '?',
									isset( $record['time'] ) ? date_i18n( 'Y-m-d H:i', (int) $record['time'] ) : '?'
								)
							);
							?>
						<?php else : ?>
							<em><?php esc_html_e( 'no record', 'uams-c2s' ); ?></em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $plan['damaged'] ) : ?>
							<code class="uams-c2s-sample"><?php echo esc_html( $plan['sample'] ); ?></code>
						<?php else : ?>
							<em><?php esc_html_e( 'none visible — listed on its conversion record', 'uams-c2s' ); ?></em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $plan['error'] ) : ?>
							<span class="uams-c2s-flag"><?php echo esc_html( $plan['error'] ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $plan['label'] ); ?>
							<div class="row-actions">
								<?php if ( 'exact' === $plan['verified'] ) : ?>
									<span class="uams-c2s-verified"><?php esc_html_e( 'matches the pre-conversion backup', 'uams-c2s' ); ?></span>
								<?php elseif ( 'mismatch' === $plan['verified'] ) : ?>
									<span class="uams-c2s-flag"><?php esc_html_e( 'does not match the pre-conversion backup — this revision is not what was converted, so repairing would discard the difference. Compare them before acting.', 'uams-c2s' ); ?></span>
								<?php else : ?>
									<span class="uams-c2s-unverified"><?php esc_html_e( 'no backup survives to check it against', 'uams-c2s' ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</td>
					<td class="uams-c2s-actions">
						<?php if ( $plan['error'] ) : ?>
							&mdash;
						<?php elseif ( 'mismatch' === $plan['verified'] ) : ?>
							<?php // Refused by repair_post(); offering the button anyway, directly under the warning, is what made the two paths disagree. ?>
							<span class="uams-c2s-unverified"><?php esc_html_e( 'needs a manual comparison', 'uams-c2s' ); ?></span>
						<?php else : ?>
							<?php $this->action_button( 'uams_c2s_repair', $id, __( 'Repair', 'uams-c2s' ), 'button-primary' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Whole-site counts, and what they say about how the site got this way.
	 *
	 * The conversion list shrinks as posts convert, so "it said 60 yesterday and
	 * says 0 today" is ambiguous on its own. These counts disambiguate it
	 * without needing shell access.
	 */
	protected function render_status() {
		$status = UAMS_C2S_Scanner::status();

		$rows = [
			[
				__( 'Posts still holding a Content block', 'uams-c2s' ),
				$status['content'],
				__( 'What the list below counts.', 'uams-c2s' ),
			],
			[
				__( 'Posts holding a Section block', 'uams-c2s' ),
				$status['section'],
				__( 'Includes Section blocks that were authored by hand, not only converted ones.', 'uams-c2s' ),
			],
			[
				__( 'Conversions recorded by this plugin', 'uams-c2s' ),
				$status['conversions'],
				$status['last']
					? sprintf(
						/* translators: 1: block count, 2: per-version breakdown, 3: date. */
						__( '%1$d block(s) in total. %2$s. Most recent %3$s.', 'uams-c2s' ),
						$status['blocks'],
						$this->version_breakdown( $status['versions'] ),
						date_i18n( 'Y-m-d H:i', $status['last'] )
					)
					: __( 'This plugin has no record of converting anything on this site.', 'uams-c2s' ),
			],
			[
				__( 'Pre-conversion backups stored', 'uams-c2s' ),
				$status['backups'],
				__( 'One per converted post, until it is reverted.', 'uams-c2s' ),
			],
			[
				__( 'Posts with damaged block markup', 'uams-c2s' ),
				$status['damaged'],
				__( 'Block attribute escapes stored as literal text. This is what can be seen; it is a floor, not a total.', 'uams-c2s' ),
			],
			[
				__( 'Posts converted by an unslashed version', 'uams-c2s' ),
				$status['at_risk'],
				__( 'Written through the 1.0.0 bug whether or not damage is visible. Re-convert these from their pre-conversion revision to be certain.', 'uams-c2s' ),
			],
		];


		?>
		<h2><?php esc_html_e( 'Site status', 'uams-c2s' ); ?></h2>

		<table class="widefat striped uams-c2s-status">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
					<td class="uams-c2s-count"><?php echo absint( $row[1] ); ?></td>
					<td class="description"><?php echo esc_html( $row[2] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( 0 === $status['content'] && 0 === $status['conversions'] && $status['section'] > 0 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'There are Section blocks on this site but no Content blocks and no conversion record. If you expected posts to still be waiting to convert, this plugin is not what changed them — check whether you are looking at a different database than before, or whether something else rewrote those posts.', 'uams-c2s' ); ?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
     * "5 by v1.0.0, 3 by v1.0.1", affected versions marked.
     *
     * @param array<string,int> $versions Version => conversion count.
     */
    protected function version_breakdown( array $versions ): string {
		$parts = [];

		foreach ( $versions as $version => $count ) {
			$parts[] = sprintf(
				/* translators: 1: conversion count, 2: plugin version. */
				UAMS_C2S_Converter::wrote_unslashed( $version )
					? __( '%1$d by v%2$s (affected)', 'uams-c2s' )
					: __( '%1$d by v%2$s', 'uams-c2s' ),
				$count,
				$version
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Batch buttons: everything, plus one per post status that has posts.
	 *
	 * Converting every draft in one pass is a different risk from converting
	 * every published page, and the useful workflow is usually to sweep the
	 * drafts and walk the published ones by hand.
	 *
	 * @param int $total Posts awaiting conversion.
	 */
	protected function render_batch_buttons( $total ) {
		if ( $total < 1 ) {
			return;
		}

		$counts = UAMS_C2S_Scanner::status_counts();

		$scopes = [
			[
				'status' => '',
				'label'  => sprintf(
					/* translators: %d: number of posts. */
					__( 'Convert all %d posts', 'uams-c2s' ),
					$total
				),
			],
		];

		// A single status covering everything would just repeat the all button.
		if ( count( $counts ) > 1 ) {
			foreach ( $counts as $status => $count ) {
				$object = get_post_status_object( $status );

				$scopes[] = [
					'status' => $status,
					'label'  => sprintf(
						/* translators: 1: number of posts, 2: post status name. */
						__( 'Convert %1$d %2$s', 'uams-c2s' ),
						$count,
						strtolower( $object ? $object->label : $status )
					),
				];
			}
		}

		?>
		<div class="uams-c2s-scopes">
			<?php foreach ( $scopes as $scope ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uams-c2s-batch">
					<input type="hidden" name="action" value="uams_c2s_batch" />
					<input type="hidden" name="scope" value="<?php echo esc_attr( $scope['status'] ); ?>" />
					<?php wp_nonce_field( 'uams_c2s_batch' ); ?>
					<?php submit_button( $scope['label'], 'secondary', 'submit', false ); ?>
				</form>
			<?php endforeach; ?>
		</div>

		<p class="description">
			<?php esc_html_e( 'Runs in batches of 10. Each post keeps a revision plus a one-click revert. Preview a post first if you want to see what it will become.', 'uams-c2s' ); ?>
		</p>
		<?php
	}

	/**
	 * Warn about anything that would make conversion misleading.
	 */
	protected function render_environment_warnings() {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( ! $registry->is_registered( UAMS_C2S_Converter::TARGET_BLOCK ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The acf/uams-section block is not registered on this site. Converted posts will show "block not found" until the UAMS 2020 theme and ACF Pro are active.', 'uams-c2s' )
			);
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'PHP is missing the DOM extension. WYSIWYG content cannot be split into blocks and would be preserved as a single Custom HTML block.', 'uams-c2s' )
			);
		}
	}

	/**
	 * Render an action result notice.
	 *
	 * @param string $notice Notice slug.
	 */
	protected function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$messages = [
			'converted'  => [ 'success', __( 'Post converted.', 'uams-c2s' ) ],
			'nochange'   => [ 'info', __( 'Nothing to convert in that post.', 'uams-c2s' ) ],
			'reverted'   => [ 'success', __( 'Post restored to its pre-conversion content.', 'uams-c2s' ) ],
			'repaired'   => [ 'success', __( 'Post restored from its pre-conversion revision and converted again.', 'uams-c2s' ) ],
			'norepair'   => [ 'info', __( 'That post is not damaged; nothing to repair.', 'uams-c2s' ) ],

			'norepair_mismatch' => [ 'warning', __( 'Nothing was changed. The revision this post would restore from does not match the content that was actually converted, so repairing it would discard the difference. Compare the two by hand before deciding.', 'uams-c2s' ) ],
			'norepair_empty'    => [ 'warning', __( 'The pre-conversion content was restored, but it held no Content block to convert, so the post is now listed for conversion again rather than repaired. Check it before converting.', 'uams-c2s' ) ],

			'repair_batch_done' => [ 'success', __( 'Re-conversion finished. Anything still listed below could not be repaired automatically — check the reason given.', 'uams-c2s' ) ],
			'saved'      => [ 'success', __( 'Options saved.', 'uams-c2s' ) ],
			'batch_done' => [ 'success', __( 'Batch conversion finished.', 'uams-c2s' ) ],
			'error'      => [ 'error', __( 'The conversion failed. Check the error log.', 'uams-c2s' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Render the list of affected posts.
	 *
	 * @param int[] $ids        Post IDs on this page.
	 * @param int   $preview_id Currently previewed post.
	 */
	protected function render_table( array $ids, $preview_id ) {
		if ( $ids === [] ) {
			printf( '<p>%s</p>', esc_html__( 'No UAMS Content Blocks remain. Nothing to do.', 'uams-c2s' ) );

			return;
		}

		?>
		<table class="widefat striped uams-c2s-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Content blocks', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Geo-targeted', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'uams-c2s' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ids as $id ) : ?>
				<?php
				$post    = get_post( $id );
				$summary = UAMS_C2S_Scanner::summarize( $post );
				$backup  = metadata_exists( 'post', $id, UAMS_C2S_Converter::BACKUP_META );
				$title   = get_the_title( $id );
				$view    = $this->view_link( $id );
				?>
				<tr<?php echo $preview_id === (int) $id ? ' class="uams-c2s-current"' : ''; ?>>
					<td>
						<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $title ?: '(' . $id . ')' ); ?></a></strong>
						<div class="row-actions">
							<span>#<?php echo absint( $id ); ?></span>
							<?php if ( $view ) : ?>
								| <span><a href="<?php echo esc_url( $view['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $view['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'uams-c2s' ); ?></span></a></span>
							<?php endif; ?>
						</div>
					</td>
					<td><?php echo esc_html( (string) get_post_type( $id ) ); ?></td>
					<td><?php echo esc_html( (string) get_post_status( $id ) ); ?></td>
					<td><?php echo absint( $summary['blocks'] ); ?></td>
					<td>
						<?php if ( $summary['geo'] > 0 ) : ?>
							<span class="uams-c2s-flag"><?php echo absint( $summary['geo'] ); ?></span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td class="uams-c2s-actions">
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'preview' => $id ], admin_url( 'tools.php' ) ) ); ?>#uams-c2s-preview"><?php esc_html_e( 'Preview', 'uams-c2s' ); ?></a>
						<?php $this->action_button( 'uams_c2s_convert', $id, __( 'Convert', 'uams-c2s' ), 'button-primary' ); ?>
						<?php if ( $backup ) : ?>
							<?php $this->action_button( 'uams_c2s_revert', $id, __( 'Revert', 'uams-c2s' ), 'button-link-delete' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Front-end link for a post, or null when it has no viewable URL.
	 *
	 * Published and private posts get their permalink; drafts, pending and
	 * scheduled posts get a preview link, since their permalink is not live
	 * yet. Labels say "on site" to keep them distinct from the Preview button,
	 * which is a dry run of the conversion rather than a page view.
	 *
	 * @param int $post_id Post ID.
	 * @return array{url:string,label:string}|null
	 */
	protected function view_link( $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$type = get_post_type_object( $post->post_type );

		if ( ! $type || ! is_post_type_viewable( $type ) ) {
			return null;
		}

		if ( in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			$url = get_permalink( $post );

			return $url ? [
				'url'   => $url,
				'label' => __( 'View on site', 'uams-c2s' ),
			] : null;
		}

		$url = get_preview_post_link( $post );

		return $url ? [
			'url'   => $url,
			'label' => __( 'Preview on site', 'uams-c2s' ),
		] : null;
	}

	/**
	 * A single-post action button.
	 *
	 * @param string $action  admin-post action.
	 * @param int    $post_id Post ID.
	 * @param string $label   Button label.
	 * @param string $class   Extra CSS class.
	 */
	protected function action_button( $action, $post_id, $label, $class ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="post_id" value="<?php echo absint( $post_id ); ?>" />
			<?php wp_nonce_field( $action . '_' . $post_id ); ?>
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Pagination links.
	 *
	 * @param int $paged Current page.
	 * @param int $pages Total pages.
	 */
	protected function render_pagination( $paged, $pages ) {
		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			[
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'type'      => 'array',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			]
		);

		if ( ! $links ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post( implode( ' ', $links ) );
		echo '</div></div>';
	}

	/**
	 * Dry-run preview of a single post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $options Conversion options.
	 */
	protected function render_preview( $post_id, array $options ) {
		if ( ! $post_id ) {
			return;
		}

		$converter = new UAMS_C2S_Converter( $options );
		$result    = $converter->preview_post( $post_id );

		if ( is_wp_error( $result ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $result->get_error_message() )
			);

			return;
		}

		?>
		<h2 id="uams-c2s-preview">
			<?php
			printf(
				/* translators: %s: post title. */
				esc_html__( 'Dry run: %s', 'uams-c2s' ),
				esc_html( get_the_title( $post_id ) )
			);
			?>
		</h2>

		<p>
			<?php
			printf(
				/* translators: 1: converted count, 2: skipped count. */
				esc_html__( '%1$d block(s) would be converted, %2$d skipped. Nothing has been saved.', 'uams-c2s' ),
				absint( $result['converted'] ),
				absint( $result['skipped'] )
			);
			?>
		</p>

		<?php if ( ! empty( $result['notices'] ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Review before converting', 'uams-c2s' ); ?></strong></p>
				<ul class="uams-c2s-notices">
					<?php foreach ( $result['notices'] as $notice ) : ?>
						<li><?php echo esc_html( $notice ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="uams-c2s-diff">
			<div>
				<h3><?php esc_html_e( 'Before', 'uams-c2s' ); ?></h3>
				<textarea readonly rows="24" class="large-text code"><?php echo esc_textarea( $result['original'] ); ?></textarea>
			</div>
			<div>
				<h3><?php esc_html_e( 'After', 'uams-c2s' ); ?></h3>
				<textarea readonly rows="24" class="large-text code"><?php echo esc_textarea( $result['content'] ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Persist the conversion options.
	 */
	public function handle_save_options(): void {
		$this->authorize( 'uams_c2s_save_options' );

		$list_format = isset( $_POST['list_format'] ) ? sanitize_text_field( wp_unslash( $_POST['list_format'] ) ) : 'v3';

		update_option(
			self::OPTION,
			[
				'include_geo'     => ! empty( $_POST['include_geo'] ),
				'demote_headings' => ! empty( $_POST['demote_headings'] ),
				'list_format'     => in_array( $list_format, [ 'v2', 'v3' ], true ) ? $list_format : 'v3',
			]
		);

		$this->redirect( 'saved' );
	}

	/**
	 * Convert one post.
	 */
	public function handle_convert(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$this->authorize( 'uams_c2s_convert_' . $post_id );
		$this->authorize_post( $post_id );

		$converter = new UAMS_C2S_Converter( $this->options() );
		$result    = $converter->convert_post( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error' );
		}

		$this->redirect( empty( $result['updated'] ) ? 'nochange' : 'converted' );
	}

	/**
	 * Restore one post.
	 */
	public function handle_revert(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$this->authorize( 'uams_c2s_revert_' . $post_id );
		$this->authorize_post( $post_id );

		$result = ( new UAMS_C2S_Converter() )->revert_post( $post_id );

		$this->redirect( is_wp_error( $result ) ? 'error' : 'reverted' );
	}

	/**
	 * Re-run one mangled conversion.
	 */
	public function handle_repair(): void {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$this->authorize( 'uams_c2s_repair_' . $post_id );
		$this->authorize_post( $post_id );

		$converter = new UAMS_C2S_Converter( $this->options() );

		// Posts listed on their conversion record alone show no visible damage,
		// so the repair has to run without waiting to see any.
		$result = $converter->repair_post( $post_id, true );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error' );
		}

		if ( ! empty( $result['repaired'] ) ) {
			$this->redirect( 'repaired' );
		}

		// Each refusal says something different; reporting them all as "nothing
		// to repair" would tell an operator a mismatch was a no-op.
		$notices = [
			'clean'         => 'norepair',
			'mismatch'      => 'norepair_mismatch',
			'not_converted' => 'norepair_empty',
		];

		$reason = $result['reason'] ?? 'clean';

		$this->redirect( $notices[ $reason ] ?? 'norepair' );
	}

	/**
	 * Repair a batch of posts, then chain to the next batch.
	 */
	public function handle_repair_batch(): void {
		$this->authorize( 'uams_c2s_repair_batch' );

		$converter = new UAMS_C2S_Converter( $this->options() );

		// Posts leave the queue as they are repaired, so always take the head of
		// the list; failures are parked to avoid an endless loop.
		$skipped = get_transient( 'uams_c2s_repair_skipped' );
		$skipped = is_array( $skipped ) ? $skipped : [];

		foreach ( array_slice( $this->repair_queue( $skipped ), 0, self::BATCH_SIZE ) as $id ) {
			if ( ! current_user_can( 'edit_post' ) ) {
				$skipped[] = $id;
				continue;
			}

			// repair_post() refuses a restore that does not match the backup, so
			// the guard that used to sit here -- and the duplicate resolution it
			// cost, a second full revision walk per post -- is gone. A refusal
			// lands in the empty( $result['repaired'] ) branch below and parks
			// exactly as it did before.
			$result = $converter->repair_post( $id, true );

			if ( is_wp_error( $result ) || empty( $result['repaired'] ) ) {
				$skipped[] = $id;
			}
		}

		$remaining = $this->repair_queue( $skipped );

		if ( $remaining !== [] ) {
			set_transient( 'uams_c2s_repair_skipped', array_values( array_unique( $skipped ) ), HOUR_IN_SECONDS );
			$this->render_batch_progress(
				count( $remaining ),
				[
					'action'   => 'uams_c2s_repair_batch',
					'title'    => __( 'Repairing…', 'uams-c2s' ),
					/* translators: %d: number of posts left. */
					'progress' => _n( '%d post left to repair…', '%d posts left to repair…', count( $remaining ), 'uams-c2s' ),
				]
			);
		}

		delete_transient( 'uams_c2s_repair_skipped' );
		$this->redirect( 'repair_batch_done' );
	}

	/**
	 * Posts needing a re-conversion: visibly damaged, or converted by an
	 * affected version.
	 *
	 * Neither population contains the other. Damage can arrive from something
	 * that is not this plugin, and a conversion an affected version wrote can
	 * leave no signature the content scanner could recognise.
	 *
	 * @param int[] $skipped Post IDs to leave out.
	 * @return int[]
	 */
	protected function repair_queue( array $skipped = [] ): array {
		$ids = array_merge( UAMS_C2S_Scanner::find_damaged(), UAMS_C2S_Scanner::find_at_risk() );
		$ids = array_diff( array_unique( $ids ), array_map( 'absint', $skipped ) );

		sort( $ids );

		return array_values( $ids );
	}

	/**
	 * Convert a batch of posts, then chain to the next batch.
	 */
	public function handle_batch(): void {
		$this->authorize( 'uams_c2s_batch' );

		$options   = $this->options();
		$converter = new UAMS_C2S_Converter( $options );

		// Narrowing happens in the scanner, which intersects against its own
		// allow-list, so a scope arriving here can only shrink the run.
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';

		if ( $scope && ! UAMS_C2S_Scanner::narrow_statuses( $scope ) ) {
			$this->redirect( 'error' );
		}

		// Posts drop out of the scan as they convert, so always take the head of
		// the list; geo-skipped posts are excluded to avoid an endless loop.
		$skipped = get_transient( 'uams_c2s_batch_skipped' );
		$skipped = is_array( $skipped ) ? $skipped : [];

		$ids = UAMS_C2S_Scanner::find(
			[
				'status'  => $scope,
				'limit'   => self::BATCH_SIZE,
				'exclude' => $skipped,
			]
		);

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post' ) ) {
				$skipped[] = $id;
				continue;
			}

			$result = $converter->convert_post( $id );

			if ( is_wp_error( $result ) || empty( $result['updated'] ) ) {
				$skipped[] = $id;
			}
		}

		// Scoped, so a draft-only run finishes when the drafts are done rather
		// than chaining on into the published posts it was told to leave alone.
		$remaining = UAMS_C2S_Scanner::find(
			[
				'status'  => $scope,
				'exclude' => $skipped,
			]
		);

		if ( $remaining !== [] ) {
			set_transient( 'uams_c2s_batch_skipped', array_values( array_unique( $skipped ) ), HOUR_IN_SECONDS );
			$this->render_batch_progress(
				count( $remaining ),
				[ 'fields' => [ 'scope' => $scope ] ]
			);
		}

		delete_transient( 'uams_c2s_batch_skipped' );
		$this->redirect( 'batch_done' );
	}

	/**
	 * Self-submitting interstitial that runs the next batch.
	 *
	 * @param int   $remaining Posts still to process.
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type string             $action   admin-post action to chain to.
	 *     @type string             $title    Page title.
	 *     @type string             $progress Pluralised sprintf pattern taking the count.
	 *     @type array<string,string> $fields Hidden fields to carry into the next request.
	 * }
	 */
	protected function render_batch_progress( $remaining, array $args = [] ) {
		$args = wp_parse_args(
			$args,
			[
				'action'   => 'uams_c2s_batch',
				'title'    => __( 'Converting…', 'uams-c2s' ),
				'progress' => '',
				'fields'   => [],
			]
		);

		$progress = $args['progress'] ?: _n( '%d post left to convert…', '%d posts left to convert…', $remaining, 'uams-c2s' );

		nocache_headers();

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<title>' . esc_html( $args['title'] ) . '</title>';
		echo '</head><body style="font:14px -apple-system,system-ui,sans-serif;padding:3rem;text-align:center">';

		printf( '<p>%s</p>', esc_html( sprintf( $progress, $remaining ) ) );

		$hidden = '';

		foreach ( $args['fields'] as $name => $value ) {
			$hidden .= sprintf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}

		printf(
			'<form id="uams-c2s-continue" method="post" action="%1$s"><input type="hidden" name="action" value="%2$s" />%3$s%4$s<button type="submit">%5$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $args['action'] ),
			$hidden, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above.
			wp_nonce_field( $args['action'], '_wpnonce', true, false ),
			esc_html__( 'Continue', 'uams-c2s' )
		);

		echo '<script>document.getElementById("uams-c2s-continue").submit();</script>';
		echo '</body></html>';

		exit;
	}

	/**
	 * Capability and nonce gate.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	protected function authorize( $nonce_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to run this conversion.', 'uams-c2s' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Per-post capability gate.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function authorize_post( $post_id ) {
		if ( ! $post_id || ! current_user_can( 'edit_post' ) ) {
			wp_die( esc_html__( 'You cannot edit that post.', 'uams-c2s' ) );
		}
	}

	/**
	 * Redirect back to the Tools screen with a notice.
	 *
	 * @param string $notice Notice slug.
	 */
	protected function redirect( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => self::PAGE,
					'uams_c2s_notice' => $notice,
				],
				admin_url( 'tools.php' )
			)
		);

		exit;
	}
}
