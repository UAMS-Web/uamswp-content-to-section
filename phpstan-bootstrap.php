<?php

/**
 * Constant stand-ins for static analysis.
 *
 * The main plugin file defines `UAMS_C2S_DIR` and `UAMS_C2S_URL` from function
 * calls (`plugin_dir_path()`, `plugin_dir_url()`), which PHPStan does not
 * evaluate when it discovers constants, so a use in another file reports
 * `constant.notFound`. These plain string values give the analyzer the same
 * names with a known type. PHPStan loads this file through `bootstrapFiles` in
 * `phpstan.neon.dist`; the plugin never does, and the download keep-list in
 * `.gitattributes` leaves it out of the archive.
 */

declare(strict_types=1);

define('UAMS_C2S_DIR', '/path/to/wp-content/plugins/uamswp-content-to-section/');
define('UAMS_C2S_URL', 'https://example.test/wp-content/plugins/uamswp-content-to-section/');
