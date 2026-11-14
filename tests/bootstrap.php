<?php

/**
 * Test bootstrap (PHPUnit path, PHP 7.4-safe).
 *
 * The Unit suite runs with no WordPress: WordPress functions are mocked per
 * test with Brain Monkey. The Integration suite boots a real WordPress through
 * wp-phpunit (tests/Integration/bootstrap.php). Each suite runs in its own
 * process, and this file decides which one it is in.
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\when;
use function Brain\Monkey\setUp;
use function Brain\Monkey\tearDown;

/*
 * Runs at most once. phpunit.xml's `bootstrap` loads this file.
 */
if (defined('UAMS_C2S_TESTS_BOOTSTRAPPED')) {
    return;
}

define('UAMS_C2S_TESTS_BOOTSTRAPPED', true);

require_once __DIR__.'/../vendor/autoload.php';

/**
 * Whether this process runs the Integration suite.
 *
 * True for `--testsuite=Integration` (either spelling) and for a run naming a
 * path under tests/Integration. Anything else is the Unit suite.
 */
function uams_c2s_tests_want_wordpress(array $argv): bool
{
    foreach ($argv as $index => $argument) {
        if (! is_string($argument)) {
            continue;
        }

        if ($argument === '--testsuite=Integration') {
            return true;
        }

        if ($argument === '--testsuite' && ($argv[$index + 1] ?? null) === 'Integration') {
            return true;
        }

        if (strpos(str_replace('\\', '/', $argument), 'tests/Integration') !== false) {
            return true;
        }
    }

    return false;
}

$argv = $_SERVER['argv'] ?? [];

if (uams_c2s_tests_want_wordpress(is_array($argv) ? $argv : [])) {
    require __DIR__.'/Integration/bootstrap.php';

    return;
}

/*
 * The Unit suite.
 *
 * Patchwork first: it can only redefine functions declared in files loaded
 * after it, so loading it before the plugin is what lets a test mock one of the
 * plugin's own functions, not only WordPress'.
 */
require_once __DIR__.'/../vendor/antecedent/patchwork/Patchwork.php';

/*
 * Plugin/theme files open with an ABSPATH guard. Define it so they load,
 * pointing at a directory that is not a WordPress install.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/uams_c2s-tests-no-wordpress/');
}

/*
 * Load definitions+registrations inside one Brain Monkey session. Do not
 * define permanent WordPress function stubs in this file — Patchwork treats
 * this bootstrap as too early and Brain Monkey cannot redefine them in tests
 * (DefinedTooEarly). See the tests skill and the wordpress-theme template.
 */
setUp();

when('plugin_dir_path')->justReturn(__DIR__.'/../');
when('plugin_dir_url')->justReturn('https://example.test/wp-content/plugins/'.basename(dirname(__DIR__)).'/');
when('plugin_basename')->alias(static fn ($file): string => basename(dirname($file)).'/'.basename($file));
when('is_multisite')->justReturn(false);
when('get_site_option')->alias(static fn ($key, $default = false) => $default);
when('get_option')->alias(static fn ($key, $default = false) => $default);
when('update_option')->justReturn();
when('register_activation_hook')->justReturn();
when('register_deactivation_hook')->justReturn();
when('register_uninstall_hook')->justReturn();
when('load_plugin_textdomain')->justReturn();
when('is_admin')->justReturn(false);
when('wp_enqueue_script')->justReturn();
when('wp_enqueue_style')->justReturn();
when('wp_register_script')->justReturn();
when('wp_register_style')->justReturn();
when('wp_localize_script')->justReturn();
when('esc_html')->returnArg();
when('esc_attr')->returnArg();
when('esc_url')->returnArg();
when('__')->returnArg();
when('_e')->justReturn();
when('esc_html__')->returnArg();
when('esc_attr__')->returnArg();

if (is_readable(__DIR__.'/../includes/class-field-map.php')) {
    require_once __DIR__.'/../includes/class-field-map.php';
}

tearDown();

// cspell:ignore ABSPATH
