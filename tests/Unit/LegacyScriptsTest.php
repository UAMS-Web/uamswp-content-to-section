<?php

/**
 * Runs the two original script suites in their own process, so they stay in CI.
 *
 * tests-legacy/ holds plain PHP scripts with their own WordPress stubs. Each one
 * declares those stubs as global functions and classes, so it cannot share a
 * process with Brain Monkey.
 */

declare(strict_types=1);

it('passes the legacy script suite', function (string $script): void {
    $output = [];
    $status = 1;

    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/tests-legacy/'.$script).' 2>&1', $output, $status);

    expect(implode("\n", $output))->toContain('all green')
        ->and($status)->toBe(0);
})->with([
    'slashing' => ['test-slashing.php'],
    'html to blocks' => ['test-html-to-blocks.php'],
]);
