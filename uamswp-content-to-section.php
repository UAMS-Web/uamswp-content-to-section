<?php

declare(strict_types=1);
/**
 * Plugin Name:       UAMS Content → Section Converter
 * Plugin URI:        https://github.com/UAMS-Web/uamswp-content-to-section
 * Description:       Converts legacy UAMS Content Blocks (acf/uams-content) into UAMS Section Blocks (acf/uams-section), turning the WYSIWYG field into native inner blocks. Ships a Tools screen with preview/convert/revert, an in-editor block transform, and WP-CLI commands.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      8.2
 * Author:            UAMS Web Services
 * Author URI:        https://communications.uams.edu/web/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uams-c2s
 */
// The file-scope statements below run once, as the Unit bootstrap loads this file,
// before any test records coverage, so the driver reports them uncovered whatever a
// test does. MainFileTest asserts what they leave behind (the four constants, the
// six classes, the two registered hooks); each mutation here was applied by hand
// and failed that suite or stopped the run, except where its comment says otherwise.
// With ABSPATH defined, `||` and `&&` differ only in that `&&` exits at once, which
// stops the whole run rather than failing a test.
// @pest-mutate-ignore: BooleanOrToBooleanAnd
defined('ABSPATH') || exit;

// The header's Version is the only place the release number is written.
// Emptying the header map leaves no 'Version' key to read, which fails the load
// before any test records coverage; applied by hand, it turned MainFileTest red.
define('UAMS_C2S_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']); // @pest-mutate-ignore: RemoveFunctionCall, RemoveArrayItem
define('UAMS_C2S_FILE', __FILE__); // @pest-mutate-ignore: RemoveFunctionCall
define('UAMS_C2S_DIR', plugin_dir_path(__FILE__)); // @pest-mutate-ignore: RemoveFunctionCall
define('UAMS_C2S_URL', plugin_dir_url(__FILE__)); // @pest-mutate-ignore: RemoveFunctionCall

// Dropping UAMS_C2S_DIR from a path leaves 'includes/class-x.php', which PHP also
// resolves against the including file's own folder, so the same file loads: no
// test or site can tell the difference. The other two concatenation mutants fail
// the load when applied by hand, but they run before coverage records, so the
// driver reports them uncovered all the same.
// @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
require_once UAMS_C2S_DIR.'includes/class-field-map.php';
// @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
require_once UAMS_C2S_DIR.'includes/class-html-to-blocks.php';
// @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
require_once UAMS_C2S_DIR.'includes/class-converter.php';
// @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
require_once UAMS_C2S_DIR.'includes/class-scanner.php';
// @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
require_once UAMS_C2S_DIR.'includes/class-admin.php';
// @pest-mutate-ignore: ConcatRemoveLeft, ConcatRemoveRight, ConcatSwitchSides
require_once UAMS_C2S_DIR.'includes/class-cli.php';

/**
 * Default conversion arguments, filterable site-wide.
 *
 * @return array<string,mixed>
 */
function uams_c2s_default_args()
{
    $defaults = [
        // Convert blocks that carry GeoTargetingWP rules. The Section block has no
        // geo support, so those rules would be silently dropped -- off by default.
        'include_geo' => false,
        // Push headings found inside the WYSIWYG below the Section block's own <h2>.
        'demote_headings' => false,
        // Heading level rendered by the Section block itself.
        'max_heading' => 2,
        // 'v3' = core/list + core/list-item (WP 6.0+). 'v2' = single core/list block.
        'list_format' => 'v3',
    ];

    /**
     * Filters the default conversion arguments.
     *
     * @param  array<string,mixed>  $defaults  Default arguments.
     */
    return apply_filters('uams_c2s_default_args', $defaults);
}

/**
 * Load translations and boot the admin screen.
 */
function uams_c2s_bootstrap(): void
{
    load_plugin_textdomain('uams-c2s', false, dirname(plugin_basename(UAMS_C2S_FILE)).'/languages');

    if (is_admin()) {
        UAMS_C2S_Admin::instance()->hooks();
    }
}

// Load-time registration, asserted by MainFileTest from what the bootstrap recorded.
add_action('plugins_loaded', 'uams_c2s_bootstrap'); // @pest-mutate-ignore: RemoveFunctionCall

/**
 * Register the in-editor "Transform to UAMS Section" option.
 */
function uams_c2s_enqueue_editor_assets(): void
{
    $asset = UAMS_C2S_DIR.'assets/js/editor-transform.js';

    if (! file_exists($asset)) {
        return;
    }

    wp_enqueue_script(
        'uams-c2s-editor-transform',
        UAMS_C2S_URL.'assets/js/editor-transform.js',
        ['wp-blocks', 'wp-block-editor', 'wp-hooks', 'wp-autop', 'wp-dom-ready'],
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
            'sourceBlock' => UAMS_C2S_Converter::SOURCE_BLOCK,
            'targetBlock' => UAMS_C2S_Converter::TARGET_BLOCK,
            'targetKeys' => UAMS_C2S_Field_Map::target_keys(),
            'pairs' => UAMS_C2S_Field_Map::pairs(),
            'wysiwygField' => UAMS_C2S_Field_Map::wysiwyg_field(),
        ]
    );
}

// Load-time registration, asserted by MainFileTest from what the bootstrap recorded.
add_action('enqueue_block_editor_assets', 'uams_c2s_enqueue_editor_assets'); // @pest-mutate-ignore: RemoveFunctionCall

// The Unit suite never defines WP_CLI, so the registration inside this block runs
// only under WP-CLI, where no Unit test can reach it: removing the call is
// unobservable here (applied by hand, the suite stays green). Negating the
// condition or turning `&&` into `||` runs WP_CLI::add_command() or reads the
// undefined constant, and the suite goes red.
if (defined('WP_CLI') && WP_CLI) { // @pest-mutate-ignore: IfNegated, BooleanAndToBooleanOr
    WP_CLI::add_command('uams-c2s', 'UAMS_C2S_CLI'); // @pest-mutate-ignore: RemoveMethodCall
}
