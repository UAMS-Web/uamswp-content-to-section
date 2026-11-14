<?php

/**
 * Unit bootstrap.
 *
 * The Unit suite runs with no WordPress: WordPress functions are mocked per
 * test with Brain Monkey. The Integration suite does not come through here; it
 * has its own Composer setup and bootstrap under tests/Integration.
 */

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

/*
 * Patchwork first: it can only redefine functions declared in files loaded
 * after it, so loading it before the plugin is what lets a test mock one of the
 * plugin's own functions, not only WordPress'.
 */
require_once __DIR__.'/../vendor/antecedent/patchwork/Patchwork.php';

/*
 * Plugin files open with an ABSPATH guard. Define it so they load, pointing at
 * a directory that is not a WordPress install: code that requires a core file
 * through ABSPATH fails loudly here, which is the sign it belongs in the
 * Integration suite.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/uams-c2s-tests-no-wordpress/');
}

/*
 * Load the main plugin file, and through it every class file.
 *
 * The plugin's classes are not Composer-autoloaded: the main file requires each
 * one, so loading it is the only way to get them, and it is the same chain
 * WordPress runs. The main file registers hooks at file scope, so
 * Support/load-main-file.php captures the registrations as they happen (Brain
 * Monkey forgets them after each test). Nothing else requires a class file, so a
 * dropped require in the main file leaves a class missing and a test red.
 */
require_once __DIR__.'/Support/wp-stubs.php';
require_once __DIR__.'/Support/wp-cli-stubs.php';
require_once __DIR__.'/Support/load-main-file.php';

// cspell:ignore ABSPATH autoloaded
