<?php

/**
 * Pins what the main plugin file defines, registers and does today.
 *
 * The file runs once, as the Unit bootstrap loads it (Support/load-main-file.php),
 * so its constants and file-scope hook registrations are asserted from what that
 * load recorded, and its three functions are called directly. The lines that run
 * only at load time are reported uncovered by the driver whatever a test does;
 * each carries an annotation saying so, and was deleted by hand once to confirm
 * a test here turns red.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Filters\expectApplied;
use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

const UAMS_C2S_MAIN_FILE = __DIR__.'/../../uamswp-content-to-section.php';

/**
 * The defaults the plugin ships, before the site filters them.
 */
const UAMS_C2S_SHIPPED_DEFAULTS = [
    'include_geo' => false,
    'demote_headings' => false,
    'max_heading' => 2,
    'list_format' => 'v3',
];

it('defines the plugin constants from its own location', function (): void {
    $dir = dirname((string) realpath(UAMS_C2S_MAIN_FILE)).'/';

    expect(UAMS_C2S_VERSION)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(realpath(UAMS_C2S_FILE))->toBe(realpath(UAMS_C2S_MAIN_FILE))
        ->and(UAMS_C2S_DIR)->toBe($dir)
        ->and(UAMS_C2S_URL)->toBe('https://example.test/wp-content/plugins/uamswp-content-to-section/');
});

it('keeps the version constant equal to the plugin header', function (): void {
    preg_match('/^ \* Version:\s+(\S+)$/m', (string) file_get_contents(UAMS_C2S_MAIN_FILE), $header);

    expect($header[1] ?? null)->toBe(UAMS_C2S_VERSION);
});

it('loads every class file', function (): void {
    expect(class_exists('UAMS_C2S_Field_Map', false))->toBeTrue()
        ->and(class_exists('UAMS_C2S_HTML_To_Blocks', false))->toBeTrue()
        ->and(class_exists('UAMS_C2S_Converter', false))->toBeTrue()
        ->and(class_exists('UAMS_C2S_Scanner', false))->toBeTrue()
        ->and(class_exists('UAMS_C2S_Admin', false))->toBeTrue()
        ->and(class_exists('UAMS_C2S_CLI', false))->toBeTrue();
});

it('registers its two hooks as it loads, and nothing else', function (): void {
    $added = [];

    foreach ($GLOBALS['uams_c2s_main_file_hooks'] as $type => $hooks) {
        foreach ($hooks as $name => $entries) {
            foreach ($entries as $entry) {
                $added[] = [$type, $name, (string) $entry[0], $entry[1], $entry[2]];
            }
        }
    }

    expect($added)->toBe([
        ['actions', 'plugins_loaded', 'uams_c2s_bootstrap', 10, 1],
        ['actions', 'enqueue_block_editor_assets', 'uams_c2s_enqueue_editor_assets', 10, 1],
    ]);
});

it('registers its WP-CLI command only when WP-CLI is running', function (): void {
    // The registration is the file's last statement and is guarded on the WP_CLI
    // constant, which the Unit suite never defines.
    expect(defined('WP_CLI'))->toBeFalse();
});

it('ships the default conversion arguments', function (): void {
    expectApplied('uams_c2s_default_args')->once()->with(UAMS_C2S_SHIPPED_DEFAULTS)->andReturnFirstArg();

    expect(uams_c2s_default_args())->toBe(UAMS_C2S_SHIPPED_DEFAULTS);
});

it('lets a filter change the default conversion arguments', function (): void {
    expectApplied('uams_c2s_default_args')->once()->andReturn(['include_geo' => true]);

    expect(uams_c2s_default_args())->toBe(['include_geo' => true]);
});

it("loads translations from the plugin's languages folder", function (): void {
    when('plugin_basename')->alias(static fn (string $file): string => 'uamswp-content-to-section/'.basename($file));
    when('is_admin')->justReturn(false);
    Functions\expect('load_plugin_textdomain')
        ->once()
        ->with('uams-c2s', false, 'uamswp-content-to-section/languages');

    uams_c2s_bootstrap();
});

it('boots the admin screen only in the admin', function (bool $admin): void {
    when('plugin_basename')->justReturn('uamswp-content-to-section/uamswp-content-to-section.php');
    when('load_plugin_textdomain')->justReturn(true);
    when('is_admin')->justReturn($admin);

    $booted = 0;
    redefine('UAMS_C2S_Admin::hooks', function () use (&$booted): void {
        $booted++;
    });

    uams_c2s_bootstrap();

    expect($booted)->toBe($admin ? 1 : 0);
})->with([[true], [false]]);

it('enqueues the editor transform with the field map it reads', function (): void {
    when('file_exists')->justReturn(true);
    Functions\expect('wp_enqueue_script')
        ->once()
        ->with(
            'uams-c2s-editor-transform',
            UAMS_C2S_URL.'assets/js/editor-transform.js',
            ['wp-blocks', 'wp-block-editor', 'wp-hooks', 'wp-autop', 'wp-dom-ready'],
            UAMS_C2S_VERSION,
            true
        );
    Functions\expect('wp_localize_script')
        ->once()
        ->with(
            'uams-c2s-editor-transform',
            'uamsC2S',
            [
                'sourceBlock' => 'acf/uams-content',
                'targetBlock' => 'acf/uams-section',
                'targetKeys' => UAMS_C2S_Field_Map::target_keys(),
                'pairs' => UAMS_C2S_Field_Map::pairs(),
                'wysiwygField' => UAMS_C2S_Field_Map::wysiwyg_field(),
            ]
        );

    uams_c2s_enqueue_editor_assets();
});

it('enqueues nothing when the editor script is missing', function (): void {
    when('file_exists')->justReturn(false);
    Functions\expect('wp_enqueue_script')->never();
    Functions\expect('wp_localize_script')->never();

    uams_c2s_enqueue_editor_assets();
});

it('looks for the editor script inside the plugin folder', function (): void {
    $seen = [];
    when('file_exists')->alias(static function (string $path) use (&$seen): bool {
        $seen[] = $path;

        return false;
    });

    uams_c2s_enqueue_editor_assets();

    expect($seen)->toBe([UAMS_C2S_DIR.'assets/js/editor-transform.js']);
});

it('ships the editor script it looks for', function (): void {
    expect(file_exists(UAMS_C2S_DIR.'assets/js/editor-transform.js'))->toBeTrue();
});
