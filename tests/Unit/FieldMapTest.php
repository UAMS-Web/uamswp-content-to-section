<?php

/**
 * Pins what UAMS_C2S_Field_Map resolves today: the name pairs, the WYSIWYG field,
 * and the Section field keys, from ACF when it is loaded and from the fallbacks
 * when it is not.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Filters\expectApplied;

covers(UAMS_C2S_Field_Map::class);

/**
 * Empty the class's runtime key cache, so a test starts from a cold resolver.
 */
function uams_c2s_reset_field_map_cache(): void
{
    $cache = new ReflectionProperty(UAMS_C2S_Field_Map::class, 'cache');
    $cache->setValue(null, []);
}

beforeEach(function (): void {
    uams_c2s_reset_field_map_cache();
});

/** The fallbacks, which match the uams-2020 theme with `$suffix = '_b'`. */
const UAMS_C2S_FALLBACK_KEYS = [
    'section_heading' => 'field_section_heading_b',
    'section_hide_heading' => 'field_section_hide_heading_b',
    'section_background_color' => 'field_section_background_color_b',
];

it('maps each Content field name to its Section field name', function (): void {
    expect(UAMS_C2S_Field_Map::pairs())->toBe([
        'content_heading' => 'section_heading',
        'content_hide_heading' => 'section_hide_heading',
        'content_background_color' => 'section_background_color',
    ]);
});

it('lets a filter replace the pairs', function (): void {
    expectApplied('uams_c2s_field_pairs')
        ->once()
        ->andReturn(['a' => 'b']);

    expect(UAMS_C2S_Field_Map::pairs())->toBe(['a' => 'b']);
});

it('names content_content as the WYSIWYG field', function (): void {
    expect(UAMS_C2S_Field_Map::wysiwyg_field())->toBe('content_content');
});

it('lets a filter rename the WYSIWYG field', function (): void {
    expectApplied('uams_c2s_wysiwyg_field')
        ->once()
        ->andReturn('body');

    expect(UAMS_C2S_Field_Map::wysiwyg_field())->toBe('body');
});

it('resolves the fallback keys when ACF returns no field group', function (): void {
    Functions\expect('acf_get_fields')
        ->once()
        ->with(UAMS_C2S_Field_Map::TARGET_GROUP)
        ->andReturn(false);

    expect(UAMS_C2S_Field_Map::target_keys())->toBe(UAMS_C2S_FALLBACK_KEYS);
});

it('prefers the live ACF keys over the fallbacks', function (): void {
    Functions\expect('acf_get_fields')
        ->once()
        ->andReturn([
            ['name' => 'section_heading', 'key' => 'field_live_heading'],
            ['name' => 'section_extra', 'key' => 'field_live_extra'],
        ]);

    expect(UAMS_C2S_Field_Map::target_keys())->toBe([
        'section_heading' => 'field_live_heading',
        'section_hide_heading' => 'field_section_hide_heading_b',
        'section_background_color' => 'field_section_background_color_b',
        'section_extra' => 'field_live_extra',
    ]);
});

it('skips ACF fields that lack a name or a key', function (): void {
    Functions\expect('acf_get_fields')
        ->once()
        ->andReturn([
            ['name' => '', 'key' => 'field_unnamed'],
            ['name' => 'section_heading'],
            ['key' => 'field_nameless'],
            ['name' => 'section_hide_heading', 'key' => 'field_ok'],
        ]);

    expect(UAMS_C2S_Field_Map::target_keys())->toBe([
        'section_heading' => 'field_section_heading_b',
        'section_hide_heading' => 'field_ok',
        'section_background_color' => 'field_section_background_color_b',
    ]);
});

it('asks ACF once and serves later calls from the cache', function (): void {
    Functions\expect('acf_get_fields')
        ->once()
        ->andReturn([['name' => 'section_heading', 'key' => 'field_cached']]);

    $first = UAMS_C2S_Field_Map::target_keys();
    $second = UAMS_C2S_Field_Map::target_keys();

    expect($second)->toBe($first)
        ->and($second['section_heading'])->toBe('field_cached');
});
