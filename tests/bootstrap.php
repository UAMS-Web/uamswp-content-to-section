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
 * Load the files that DEFINE classes, never the main plugin file: it registers
 * hooks at file scope, and Brain Monkey's add_action() and add_filter() exist
 * only inside a test. Hook registration is tested in the Integration suite.
 * The plugin's classes are not Composer-autoloaded, so each file a Unit test
 * needs is required here.
 */
require_once __DIR__.'/../includes/class-field-map.php';

// cspell:ignore ABSPATH autoloaded
