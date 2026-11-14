<?php

/**
 * Load the main plugin file once, and keep what it registered at file scope.
 *
 * The main file defines constants, requires every class file and calls
 * add_action() as it loads. Brain Monkey's add_action() records into a store
 * that every test's teardown empties, so the registrations are copied out here,
 * straight after the load, into $GLOBALS['uams_c2s_main_file_hooks'] for
 * MainFileTest to assert on.
 *
 * Only the Unit bootstrap loads this file.
 */

declare(strict_types=1);

use Brain\Monkey\Container;

use function Brain\Monkey\setUp;

/*
 * Brain Monkey's hook functions (add_action() and the rest) are declared by
 * setUp(); calling it here, outside a test, only loads them.
 */
setUp();

/*
 * The two path helpers the main file calls as it loads. Real WordPress derives
 * them from the plugin file; these return the same shape.
 */
if (! function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return rtrim(dirname($file), '/').'/';
    }
}

if (! function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/wp-content/plugins/'.basename(dirname($file)).'/';
    }
}

require_once __DIR__.'/../../uamswp-content-to-section.php';

$storage = new ReflectionProperty(Container::instance()->hookStorage(), 'storage');

$GLOBALS['uams_c2s_main_file_hooks'] = $storage->getValue(Container::instance()->hookStorage())['added'];

Container::instance()->reset();
