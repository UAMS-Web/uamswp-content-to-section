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

/*
 * The header reader the main file calls for its version. It follows core's
 * get_file_data(): the first 8 KB, carriage returns normalized, and for each
 * field the first line that names it, up to the end of the line or a closing
 * comment.
 */
if (! function_exists('get_file_data')) {
    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    function get_file_data(string $file, array $headers): array
    {
        $source = str_replace("\r", "\n", (string) file_get_contents($file, false, null, 0, 8192));
        $data = [];

        foreach ($headers as $key => $field) {
            $found = preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*'.preg_quote($field, '/').':(.*)$/mi', $source, $match) === 1;

            $data[$key] = $found ? trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1])) : '';
        }

        return $data;
    }
}

require_once __DIR__.'/../../uamswp-content-to-section.php';

$storage = new ReflectionProperty(Container::instance()->hookStorage(), 'storage');

$GLOBALS['uams_c2s_main_file_hooks'] = $storage->getValue(Container::instance()->hookStorage())['added'];

Container::instance()->reset();
