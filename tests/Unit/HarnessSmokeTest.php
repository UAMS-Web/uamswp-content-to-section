<?php

/**
 * First unit test pinning harness load for UAMS Content to Section.
 */

declare(strict_types=1);

it('loads the plugin surface', function (): void {
    expect(class_exists('UAMS_C2S_Field_Map'))->toBeTrue();
});
