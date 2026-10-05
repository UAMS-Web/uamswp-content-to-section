<?php
/**
 * Plugin Name:       UAMS Content → Section Converter
 * Plugin URI:        https://www.uams.edu/
 * Description:       Converts legacy UAMS Content Blocks (acf/uams-content) into UAMS Section Blocks (acf/uams-section), turning the WYSIWYG field into native inner blocks. Ships a Tools screen with preview/convert/revert, an in-editor block transform, and WP-CLI commands.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Brent Passmore
 * Author URI:        https://www.uams.edu/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uams-c2s
 *
 * @package UAMS_Content_To_Section
 */

defined( 'ABSPATH' ) || exit;

define( 'UAMS_C2S_VERSION', '1.0.2' );
define( 'UAMS_C2S_FILE', __FILE__ );
define( 'UAMS_C2S_DIR', plugin_dir_path( __FILE__ ) );
define( 'UAMS_C2S_URL', plugin_dir_url( __FILE__ ) );

require_once UAMS_C2S_DIR . 'includes/class-field-map.php';
require_once UAMS_C2S_DIR . 'includes/class-html-to-blocks.php';
require_once UAMS_C2S_DIR . 'includes/class-converter.php';
require_once UAMS_C2S_DIR . 'includes/class-scanner.php';
require_once UAMS_C2S_DIR . 'includes/class-admin.php';
require_once UAMS_C2S_DIR . 'includes/class-cli.php';

/**
 * Default conversion arguments, filterable site-wide.
 *
 * @return array<string,mixed>
 */
function uams_c2s_default_args() {
	$defaults = [
		// Convert blocks that carry GeoTargetingWP rules. The Section block has no
		// geo support, so those rules would be silently dropped -- off by default.
		'include_geo'     => false,
		// Push headings found inside the WYSIWYG below the Section block's own <h2>.
		'demote_headings' => false,
		// Heading level rendered by the Section block itself.
		'max_heading'     => 2,
		// 'v3' = core/list + core/list-item (WP 6.0+). 'v2' = single core/list block.
		'list_format'     => 'v3',
	];

	/**
	 * Filters the default conversion arguments.
	 *
	 * @param array<string,mixed> $defaults Default arguments.
	 */
	return apply_filters( 'uams_c2s_default_args', $defaults );
}

/**
 * Load translations and boot the admin screen.
 */
function uams_c2s_bootstrap(): void {
	load_plugin_textdomain( 'uams-c2s', false, dirname( plugin_basename( UAMS_C2S_FILE ) ) . '/languages' );

	if ( is_admin() ) {
		UAMS_C2S_Admin::instance()->hooks();
	}
}

add_action( 'plugins_loaded', 'uams_c2s_bootstrap' );

/**
 * Register the in-editor "Transform to UAMS Section" option.
 */
function uams_c2s_enqueue_editor_assets(): void {
	$asset = UAMS_C2S_DIR . 'assets/js/editor-transform.js';

	if ( ! file_exists( $asset ) ) {
		return;
	}

	wp_enqueue_script(
		'uams-c2s-editor-transform',
		UAMS_C2S_URL . 'assets/js/editor-transform.js',
		[ 'wp-blocks', 'wp-block-editor', 'wp-hooks', 'wp-autop', 'wp-dom-ready' ],
		UAMS_C2S_VERSION,
		true
	);

	wp_localize_script(
		'uams-c2s-editor-transform',
		'uamsC2S',
		// Every key here must have a reader in editor-transform.js. `sourceKeys`
		// and `i18n` did not, and shipped an acf_get_fields() call plus their
		// payload on every block-editor page load to be ignored.
		[
			'sourceBlock'  => UAMS_C2S_Converter::SOURCE_BLOCK,
			'targetBlock'  => UAMS_C2S_Converter::TARGET_BLOCK,
			'targetKeys'   => UAMS_C2S_Field_Map::target_keys(),
			'pairs'        => UAMS_C2S_Field_Map::pairs(),
			'wysiwygField' => UAMS_C2S_Field_Map::wysiwyg_field(),
		]
	);
}

add_action( 'enqueue_block_editor_assets', 'uams_c2s_enqueue_editor_assets' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'uams-c2s', 'UAMS_C2S_CLI' );
}
