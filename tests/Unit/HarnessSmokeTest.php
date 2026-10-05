<?php

/**
 * First unit test pinning harness load for UAMS Content to Section.
 */

declare(strict_types=1);

namespace UamsC2s\Tests\Unit;

use UamsC2s\Tests\Support\UnitTestCase;

final class HarnessSmokeTest extends UnitTestCase
{
    public function test_harness_loads_plugin_surface(): void
    {
        $this->assertTrue(\class_exists('UAMS_C2S_Field_Map', false) || \class_exists('UAMS_C2S_Field_Map'));
    }
}
