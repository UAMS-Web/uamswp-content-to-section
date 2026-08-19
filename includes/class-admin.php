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
	protected static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		add_action( 'admin_post_uams_c2s_save_options', array( $this, 'handle_save_options' ) );
		add_action( 'admin_post_uams_c2s_convert', array( $this, 'handle_convert' ) );
		add_action( 'admin_post_uams_c2s_revert', array( $this, 'handle_revert' ) );
		add_action( 'admin_post_uams_c2s_repair', array( $this, 'handle_repair' ) );
		add_action( 'admin_post_uams_c2s_batch', array( $this, 'handle_batch' ) );
	}

	/**
	 * Add the Tools submenu.
	 */
	public function menu() {
		add_management_page(
			__( 'UAMS Content → Section', 'uams-c2s' ),
			__( 'Content → Section', 'uams-c2s' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue the screen stylesheet.
	 *
	 * @param string $hook Current admin page.
	 */
	public function styles( $hook ) {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'uams-c2s-admin',
			UAMS_C2S_URL . 'assets/css/admin.css',
			array(),
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
	public function options() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $stored ) ? $stored : array(), uams_c2s_default_args() );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Render the Tools screen.
	 */
	public function render() {
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

			<?php if ( $total > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uams-c2s-batch">
					<input type="hidden" name="action" value="uams_c2s_batch" />
					<input type="hidden" name="offset" value="0" />
					<?php wp_nonce_field( 'uams_c2s_batch' ); ?>
					<?php
					submit_button(
						sprintf(
							/* translators: %d: number of posts. */
							__( 'Convert all %d posts', 'uams-c2s' ),
							$total
						),
						'primary',
						'submit',
						false
					);
					?>
					<span class="description"><?php esc_html_e( 'Runs in batches. Each post keeps a revision plus a one-click revert.', 'uams-c2s' ); ?></span>
				</form>
			<?php endif; ?>

			<?php $this->render_table( $page_ids, $preview_id ); ?>
			<?php $this->render_pagination( $paged, $pages ); ?>
			<?php $this->render_damaged( $options ); ?>
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
		$ids = UAMS_C2S_Scanner::find_damaged();

		if ( ! $ids ) {
			return;
		}

		$converter = new UAMS_C2S_Converter( $options );

		?>
		<h2><?php esc_html_e( 'Damaged conversions', 'uams-c2s' ); ?></h2>

		<div class="notice notice-error inline">
			<p>
				<?php
				printf(
					/* translators: %d: number of posts. */
					esc_html( _n( '%d post was converted by plugin 1.0.0, which saved the block markup without slashing it. Every block attribute escape on the page was flattened into literal "u003c" text.', '%d posts were converted by plugin 1.0.0, which saved the block markup without slashing it. Every block attribute escape on those pages was flattened into literal "u003c" text.', count( $ids ), 'uams-c2s' ) ),
					count( $ids )
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Repair restores the pre-conversion content from the post revision saved before the conversion, then converts it again with the current code. The 1.0.0 backup is not used: it was stored through the same unslashing and is damaged in the same way.', 'uams-c2s' ); ?>
			</p>
		</div>

		<table class="widefat striped uams-c2s-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Restores from', 'uams-c2s' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'uams-c2s' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $ids as $id ) : ?>
				<?php
				$plan  = $converter->repair_plan( $id );
				$title = get_the_title( $id );
				$view  = $this->view_link( $id );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $title ? $title : '(' . $id . ')' ); ?></a></strong>
						<div class="row-actions">
							<span>#<?php echo absint( $id ); ?></span>
							<?php if ( $view ) : ?>
								| <span><a href="<?php echo esc_url( $view['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $view['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'uams-c2s' ); ?></span></a></span>
							<?php endif; ?>
						</div>
					</td>
					<td><?php echo esc_html( (string) get_post_type( $id ) ); ?></td>
					<td><?php echo esc_html( (string) get_post_status( $id ) ); ?></td>
					<td>
						<?php if ( $plan['error'] ) : ?>
							<span class="uams-c2s-flag"><?php echo esc_html( $plan['error'] ); ?></span>
						<?php else : ?>
							<?php echo esc_html( $plan['label'] ); ?>
						<?php endif; ?>
					</td>
					<td class="uams-c2s-actions">
						<?php if ( ! $plan['error'] ) : ?>
							<?php $this->action_button( 'uams_c2s_repair', $id, __( 'Repair', 'uams-c2s' ), 'button-primary' ); ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
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

		$messages = array(
			'converted'  => array( 'success', __( 'Post converted.', 'uams-c2s' ) ),
			'nochange'   => array( 'info', __( 'Nothing to convert in that post.', 'uams-c2s' ) ),
			'reverted'   => array( 'success', __( 'Post restored to its pre-conversion content.', 'uams-c2s' ) ),
			'repaired'   => array( 'success', __( 'Post restored from its pre-conversion revision and converted again.', 'uams-c2s' ) ),
			'norepair'   => array( 'info', __( 'That post is not damaged; nothing to repair.', 'uams-c2s' ) ),
			'saved'      => array( 'success', __( 'Options saved.', 'uams-c2s' ) ),
			'batch_done' => array( 'success', __( 'Batch conversion finished.', 'uams-c2s' ) ),
			'error'      => array( 'error', __( 'The conversion failed. Check the error log.', 'uams-c2s' ) ),
		);

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
		if ( ! $ids ) {
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
						<strong><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $title ? $title : '(' . $id . ')' ); ?></a></strong>
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
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'preview' => $id ), admin_url( 'tools.php' ) ) ); ?>#uams-c2s-preview"><?php esc_html_e( 'Preview', 'uams-c2s' ); ?></a>
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
	protected function view_link( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$type = get_post_type_object( $post->post_type );

		if ( ! $type || ! is_post_type_viewable( $type ) ) {
			return null;
		}

		if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
			$url = get_permalink( $post );

			return $url ? array(
				'url'   => $url,
				'label' => __( 'View on site', 'uams-c2s' ),
			) : null;
		}

		$url = get_preview_post_link( $post );

		return $url ? array(
			'url'   => $url,
			'label' => __( 'Preview on site', 'uams-c2s' ),
		) : null;
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
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'type'      => 'array',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
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
	public function handle_save_options() {
		$this->authorize( 'uams_c2s_save_options' );

		$list_format = isset( $_POST['list_format'] ) ? sanitize_text_field( wp_unslash( $_POST['list_format'] ) ) : 'v3';

		update_option(
			self::OPTION,
			array(
				'include_geo'     => ! empty( $_POST['include_geo'] ),
				'demote_headings' => ! empty( $_POST['demote_headings'] ),
				'list_format'     => in_array( $list_format, array( 'v2', 'v3' ), true ) ? $list_format : 'v3',
			)
		);

		$this->redirect( 'saved' );
	}

	/**
	 * Convert one post.
	 */
	public function handle_convert() {
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
	public function handle_revert() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$this->authorize( 'uams_c2s_revert_' . $post_id );
		$this->authorize_post( $post_id );

		$result = ( new UAMS_C2S_Converter() )->revert_post( $post_id );

		$this->redirect( is_wp_error( $result ) ? 'error' : 'reverted' );
	}

	/**
	 * Re-run one mangled conversion.
	 */
	public function handle_repair() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$this->authorize( 'uams_c2s_repair_' . $post_id );
		$this->authorize_post( $post_id );

		$converter = new UAMS_C2S_Converter( $this->options() );
		$result    = $converter->repair_post( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error' );
		}

		$this->redirect( empty( $result['repaired'] ) ? 'norepair' : 'repaired' );
	}

	/**
	 * Convert a batch of posts, then chain to the next batch.
	 */
	public function handle_batch() {
		$this->authorize( 'uams_c2s_batch' );

		$options   = $this->options();
		$converter = new UAMS_C2S_Converter( $options );

		// Posts drop out of the scan as they convert, so always take the head of
		// the list; geo-skipped posts are excluded to avoid an endless loop.
		$skipped = get_transient( 'uams_c2s_batch_skipped' );
		$skipped = is_array( $skipped ) ? $skipped : array();

		$ids = UAMS_C2S_Scanner::find(
			array(
				'limit'   => self::BATCH_SIZE,
				'exclude' => $skipped,
			)
		);

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$skipped[] = $id;
				continue;
			}

			$result = $converter->convert_post( $id );

			if ( is_wp_error( $result ) || empty( $result['updated'] ) ) {
				$skipped[] = $id;
			}
		}

		$remaining = UAMS_C2S_Scanner::find( array( 'exclude' => $skipped ) );

		if ( $remaining ) {
			set_transient( 'uams_c2s_batch_skipped', array_values( array_unique( $skipped ) ), HOUR_IN_SECONDS );
			$this->render_batch_progress( count( $remaining ) );
		}

		delete_transient( 'uams_c2s_batch_skipped' );
		$this->redirect( 'batch_done' );
	}

	/**
	 * Self-submitting interstitial that runs the next batch.
	 *
	 * @param int $remaining Posts still to process.
	 */
	protected function render_batch_progress( $remaining ) {
		$url = admin_url( 'admin-post.php' );

		nocache_headers();

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<title>' . esc_html__( 'Converting…', 'uams-c2s' ) . '</title>';
		echo '</head><body style="font:14px -apple-system,system-ui,sans-serif;padding:3rem;text-align:center">';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of posts left. */
					_n( '%d post left to convert…', '%d posts left to convert…', $remaining, 'uams-c2s' ),
					$remaining
				)
			)
		);

		printf(
			'<form id="uams-c2s-continue" method="post" action="%1$s"><input type="hidden" name="action" value="uams_c2s_batch" />%2$s<button type="submit">%3$s</button></form>',
			esc_url( $url ),
			wp_nonce_field( 'uams_c2s_batch', '_wpnonce', true, false ),
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
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
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
				array(
					'page'            => self::PAGE,
					'uams_c2s_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);

		exit;
	}
}
