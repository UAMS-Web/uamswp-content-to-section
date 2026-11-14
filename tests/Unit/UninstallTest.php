<?php

/**
 * Pins what uninstall.php removes today: the options row and the two transients
 * the batch runs park failures in, and nothing else (per-post meta stays so a
 * converted post can still be restored after a reinstall).
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\expect;
use function Brain\Monkey\Functions\when;

/**
 * Run uninstall.php as WordPress does, after defining its guard constant.
 */
function uams_c2s_run_uninstall(): void
{
    defined('WP_UNINSTALL_PLUGIN') || define('WP_UNINSTALL_PLUGIN', 'uamswp-content-to-section/uamswp-content-to-section.php');

    require __DIR__.'/../../uninstall.php';
}

it('deletes the options row and both transients', function (): void {
    expect('delete_option')->once()->with('uams_c2s_options');
    expect('delete_transient')->once()->with('uams_c2s_batch_skipped');
    expect('delete_transient')->once()->with('uams_c2s_repair_skipped');

    uams_c2s_run_uninstall();
});

it('leaves the per-post conversion records in place', function (): void {
    when('delete_option')->justReturn(true);
    when('delete_transient')->justReturn(true);

    expect('delete_post_meta')->never();
    expect('delete_metadata')->never();

    uams_c2s_run_uninstall();
});
