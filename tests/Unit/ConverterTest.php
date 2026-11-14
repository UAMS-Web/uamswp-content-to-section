<?php

/**
 * Pins what UAMS_C2S_Converter does to block markup and to posts today.
 *
 * The block-level tests hand the converter a parsed block and read back the
 * Section block it builds. The post-level tests run against a small in-memory
 * WordPress (posts, post meta, revisions) that applies the same slashing rules
 * WordPress does, so a write that forgets to slash, or a backup stored without
 * slashes, shows up as changed content rather than as a missing mock call.
 * The HTML parser is replaced by a recording stand-in: its own tests live with it.
 */

declare(strict_types=1);

use function Brain\Monkey\Filters\expectApplied;
use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

covers(UAMS_C2S_Converter::class);

const UAMS_C2S_TEST_SOURCE = 'acf/uams-content';

/**
 * An in-memory WordPress for the post-level code.
 */
function uams_c2s_world(): object
{
    $world = new class
    {
        /** @var array<int,WP_Post> */
        public array $posts = [];

        /** @var array<int,list<WP_Post>> Revisions by parent, newest first. */
        public array $revisions = [];

        /** @var list<int> Revision ids that are autosaves. */
        public array $autosaves = [];

        /** @var array<int,array<string,mixed>> Stored (already unslashed) meta by post. */
        public array $meta = [];

        /** @var list<array{string,mixed}> Every meta write: operation, arguments. */
        public array $writes = [];

        /** @var list<array<string,mixed>> Every wp_update_post() argument array. */
        public array $updates = [];

        /** @var list<string> The kses calls, in order, with the filter state seen at each update. */
        public array $kses = [];

        /** @var list<array<string,mixed>> Arguments of each wp_get_post_revisions() call. */
        public array $revisionQueries = [];

        /** @var list<mixed> Every id handed to get_post(), as it was passed. */
        public array $lookups = [];

        public bool $unfilteredHtml = true;

        public bool $ksesFilterAttached = false;

        public bool $failUpdate = false;

        public function post(int $id, string $content, array $extra = []): WP_Post
        {
            return $this->posts[$id] = new WP_Post(['ID' => $id, 'post_content' => $content] + $extra);
        }

        public function revision(int $parent, int $id, string $content, string $date = '2026-01-01 00:00:00'): WP_Post
        {
            $revision = new WP_Post([
                'ID' => $id,
                'post_content' => $content,
                'post_type' => 'revision',
                'post_parent' => $parent,
                'post_date_gmt' => $date,
            ]);

            $this->revisions[$parent][] = $revision;

            return $revision;
        }

        public function find(mixed $id): ?WP_Post
        {
            if ($id instanceof WP_Post) {
                return $id;
            }

            $this->lookups[] = $id;

            if (isset($this->posts[$id])) {
                return $this->posts[$id];
            }

            foreach ($this->revisions as $list) {
                foreach ($list as $revision) {
                    if ($revision->ID == $id) {
                        return $revision;
                    }
                }
            }

            return null;
        }
    };

    when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof WP_Error);
    when('get_post')->alias(static fn ($post): ?\WP_Post => $world->find($post));
    when('wp_slash')->alias(static fn ($value) => is_string($value) ? addslashes($value) : $value);
    when('wp_unslash')->alias(static fn ($value) => is_string($value) ? stripslashes($value) : $value);
    when('get_current_user_id')->justReturn(7);
    when('current_user_can')->alias(static fn ($cap): bool => $cap === 'unfiltered_html' && $world->unfilteredHtml);
    when('has_filter')->alias(
        static fn ($hook, $callback = false): bool => $hook === 'content_save_pre'
            && $callback === 'wp_filter_post_kses'
            && $world->ksesFilterAttached
    );
    when('kses_remove_filters')->alias(static function () use ($world): void {
        $world->kses[] = 'remove';
    });
    when('kses_init_filters')->alias(static function () use ($world): void {
        $world->kses[] = 'init';
    });
    when('wp_is_post_autosave')->alias(static fn ($revision): bool => in_array($revision->ID, $world->autosaves, true));
    when('wp_get_post_revisions')->alias(static function ($id, $args = []) use ($world): array {
        $world->revisionQueries[] = [$id, $args];

        return $world->revisions[$id] ?? [];
    });
    when('metadata_exists')->alias(
        static fn ($type, $id, $key): bool => array_key_exists($key, $world->meta[$id] ?? [])
    );
    when('get_post_meta')->alias(static function ($id, $key, $single = false) use ($world) {
        if (! array_key_exists($key, $world->meta[$id] ?? [])) {
            return $single ? '' : [];
        }

        // Asked for every value of the key, WordPress hands back a list.
        return $single ? $world->meta[$id][$key] : [$world->meta[$id][$key]];
    });
    when('add_post_meta')->alias(static function ($id, $key, $value, $unique = false) use ($world): false|int {
        $world->writes[] = ['add', [$id, $key, $value, $unique]];

        if ($unique && array_key_exists($key, $world->meta[$id] ?? [])) {
            return false;
        }

        // WordPress unslashes meta values on the way in.
        $world->meta[$id][$key] = stripslashes($value);

        return 1;
    });
    when('update_post_meta')->alias(static function ($id, $key, $value) use ($world): true {
        $world->writes[] = ['update', [$id, $key, $value]];
        $world->meta[$id][$key] = is_string($value) ? stripslashes($value) : $value;

        return true;
    });
    when('delete_post_meta')->alias(static function ($id, $key) use ($world): bool {
        $world->writes[] = ['delete', [$id, $key]];
        unset($world->meta[$id][$key]);

        return true;
    });
    when('wp_update_post')->alias(static function ($data, $wp_error = false) use ($world) {
        $world->updates[] = $data + ['_kses_seen' => $world->kses, '_wp_error' => $wp_error];

        if ($world->failUpdate) {
            return new WP_Error('db_update_error', 'Could not update.');
        }

        // WordPress unslashes what it is handed before storing it.
        $world->posts[$data['ID']]->post_content = stripslashes($data['post_content']);

        return $data['ID'];
    });

    return $world;
}

/**
 * Stand in for the HTML parser: record what it is built and called with.
 *
 * @param  list<array>  $blocks  What convert() returns.
 * @param  list<string>  $notices  What get_notices() returns.
 */
function uams_c2s_stub_parser(array $blocks = [], array $notices = []): object
{
    $log = new class
    {
        public array $args = [];

        public array $html = [];
    };

    redefine(
        'UAMS_C2S_HTML_To_Blocks::convert',
        function ($html) use ($log, $blocks): array {
            $log->args[] = $this->args;
            $log->html[] = $html;

            return $blocks;
        }
    );
    redefine('UAMS_C2S_HTML_To_Blocks::get_notices', fn (): array => $notices);

    return $log;
}

/**
 * A parsed Content block.
 */
function uams_c2s_content_block(array $attrs = [], array $inner = []): array
{
    return [
        'blockName' => UAMS_C2S_TEST_SOURCE,
        'attrs' => $attrs,
        'innerBlocks' => $inner,
        'innerHTML' => '',
        'innerContent' => [],
    ];
}

/** A Content block's attributes with the usual `data` nesting. */
function uams_c2s_data(array $fields, array $attrs = []): array
{
    return ['data' => $fields] + $attrs;
}

/**
 * Post content that is recognizably a Content block to the revision search, and
 * that the stand-in block parser can still read (it skips the leading comment).
 */
function uams_c2s_marked(array $blocks): string
{
    return '<!-- wp:acf/uams-content -->'.json_encode($blocks);
}

/** Post content that holds a Content block, so a conversion is attempted. */
const UAMS_C2S_WITH_BLOCK = '<!-- wp:acf/uams-content {"data":{"content_heading":"Hi"}} /-->';

beforeEach(function (): void {
    $cache = new ReflectionProperty(UAMS_C2S_Field_Map::class, 'cache');
    $cache->setValue(null, []);

    stubTranslationFunctions();
    when('wp_parse_args')->alias(
        static fn ($args, $defaults = []): array => array_merge($defaults, $args)
    );
    when('uams_c2s_default_args')->justReturn([
        'include_geo' => false,
        'demote_headings' => false,
        'max_heading' => 2,
        'list_format' => 'v3',
    ]);
    when('serialize_blocks')->alias(static fn (array $blocks): string => json_encode($blocks));
    when('parse_blocks')->alias(
        static fn (string $content): array => json_decode((string) preg_replace('/^<!--[^>]*-->/', '', $content), true) ?? []
    );
});

/* ---------------------------------------------------------------------
 * Content level
 * ------------------------------------------------------------------ */

it("reads a block's fields from its data attribute only", function (mixed $attrs, array $expected): void {
    expect(UAMS_C2S_Converter::block_data($attrs))->toBe($expected);
})->with([
    'nested under data' => [['data' => ['content_heading' => 'A']], ['content_heading' => 'A']],
    'empty data' => [['data' => []], []],
    'flat legacy layout' => [['content_heading' => 'A'], []],
    'data that is not an array' => [['data' => 'x'], []],
    'data that is null' => [['data' => null], []],
    'no attributes' => [[], []],
]);

it('converts a Content block into a Section block with the mapped fields', function (): void {
    uams_c2s_stub_parser();
    $converter = new UAMS_C2S_Converter;
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = $converter->convert_block(
        uams_c2s_content_block(uams_c2s_data(
            ['content_heading' => 'Hello', 'content_hide_heading' => '1', 'content_background_color' => 'bg-blue'],
            ['anchor' => 'top', 'className' => 'extra', 'align' => 'wide']
        )),
        $stats
    );

    expect($section['blockName'])->toBe('acf/uams-section')
        ->and($section['attrs'])->toBe([
            'name' => 'acf/uams-section',
            'data' => [
                'section_heading' => 'Hello',
                '_section_heading' => 'field_section_heading_b',
                'section_hide_heading' => 1,
                '_section_hide_heading' => 'field_section_hide_heading_b',
                'section_background_color' => 'bg-blue',
                '_section_background_color' => 'field_section_background_color_b',
            ],
            'align' => 'wide',
            'mode' => 'preview',
            'anchor' => 'top',
            'className' => 'extra',
        ])
        ->and($section['innerHTML'])->toBe('')
        ->and($stats['converted'])->toBe(1)
        ->and($stats['skipped'])->toBe(0);
});

it('stores the hide-heading flag as 1 or 0', function (mixed $value, int $expected): void {
    uams_c2s_stub_parser();
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_hide_heading' => $value], ['anchor' => 'a'])),
        $stats
    );

    expect($section['attrs']['data']['section_hide_heading'])->toBe($expected);
})->with([
    'checked' => ['1', 1],
    'true' => [true, 1],
    'unchecked' => ['0', 0],
    'empty' => ['', 0],
    'false' => [false, 0],
]);

it('defaults an empty background color to bg-auto and keeps any other value', function (): void {
    uams_c2s_stub_parser();
    $converter = new UAMS_C2S_Converter;

    $empty = ['converted' => 0, 'skipped' => 0, 'notices' => []];
    $blank = $converter->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_background_color' => ''], ['anchor' => 'a'])),
        $empty
    );
    $missing = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a'])), $empty);
    $set = $converter->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_background_color' => 'bg-red'], ['anchor' => 'a'])),
        $empty
    );

    expect($blank['attrs']['data']['section_background_color'])->toBe('bg-auto')
        ->and($missing['attrs']['data']['section_background_color'])->toBe('bg-auto')
        ->and($set['attrs']['data']['section_background_color'])->toBe('bg-red')
        // The heading text is not defaulted.
        ->and($missing['attrs']['data']['section_heading'])->toBe('');
});

it('fills the field key of a mapped field the Section group does not list with an empty string', function (): void {
    uams_c2s_stub_parser();
    expectApplied('uams_c2s_field_pairs')->once()->andReturn(['content_heading' => 'section_extra']);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_heading' => 'H'], ['anchor' => 'a'])),
        $stats
    );

    expect($section['attrs']['data'])->toBe(['section_extra' => 'H', '_section_extra' => '']);
});

it('hands the body field to the parser and uses the blocks it returns', function (): void {
    $log = uams_c2s_stub_parser([['blockName' => 'core/paragraph'], ['blockName' => 'core/list']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter(['demote_headings' => true]))->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_content' => '<p>Body</p>'], ['anchor' => 'a'])),
        $stats
    );

    expect($log->html)->toBe(['<p>Body</p>'])
        ->and($log->args)->toHaveCount(1)
        ->and($log->args[0]['demote_headings'])->toBeTrue()
        ->and($log->args[0]['max_heading'])->toBe(2)
        ->and($section['innerBlocks'])->toBe([['blockName' => 'core/paragraph'], ['blockName' => 'core/list']])
        ->and($section['innerContent'])->toBe(["\n", null, "\n", null, "\n"])
        ->and($stats['notices'])->toBe([]);
});

it('passes a non-string body field to the parser as a string and a missing one as empty', function (): void {
    $log = uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];
    $converter = new UAMS_C2S_Converter;

    $converter->convert_block(uams_c2s_content_block(uams_c2s_data(['content_content' => 42], ['anchor' => 'a'])), $stats);
    $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a'])), $stats);

    expect($log->html)->toBe(['42', '']);
});

it('reads the body from the filtered WYSIWYG field name', function (): void {
    $log = uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    expectApplied('uams_c2s_wysiwyg_field')->once()->andReturn('custom_body');
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(uams_c2s_data(['custom_body' => 'X', 'content_content' => 'Y'], ['anchor' => 'a'])),
        $stats
    );

    expect($log->html)->toBe(['X']);
});

it('pads the inner content for no inner blocks and one inner block', function (): void {
    $converter = new UAMS_C2S_Converter;
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    uams_c2s_stub_parser([]);
    $none = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a'])), $stats);

    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $one = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a'])), $stats);

    expect($none['innerContent'])->toBe(["\n"])
        ->and($one['innerContent'])->toBe(["\n", null, "\n"]);
});

it('reports an empty body, still converting the block', function (): void {
    uams_c2s_stub_parser([]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_content' => ''], ['anchor' => 'a'])),
        $stats
    );

    expect($section['innerBlocks'])->toBe([])
        ->and($stats['converted'])->toBe(1)
        ->and($stats['notices'])->toBe(['A Content block had an empty body; the Section block was created with no inner blocks.']);
});

it("collects the parser's notices ahead of its own", function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']], ['first note', 'second note']);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => ['earlier']];

    (new UAMS_C2S_Converter)->convert_block(uams_c2s_content_block(uams_c2s_data([])), $stats);

    expect($stats['notices'])->toBe([
        'earlier',
        'first note',
        'second note',
        'A converted block had no custom anchor, so its rendered id changes from "content-…" to "uams-section-…". Check for in-page links pointing at it.',
    ]);
});

it('gives the Section a fresh id only when the Content block had one', function (): void {
    uams_c2s_stub_parser();
    $converter = new UAMS_C2S_Converter;
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $with = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['id' => 'block_old', 'anchor' => 'a'])), $stats);
    $without = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a'])), $stats);
    $empty = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['id' => '', 'anchor' => 'a'])), $stats);

    expect($with['attrs']['id'])->toStartWith('block_')->not->toBe('block_old')
        ->and($without['attrs'])->not->toHaveKey('id')
        ->and($empty['attrs'])->not->toHaveKey('id');
});

it('aligns full unless the Content block set an alignment', function (): void {
    uams_c2s_stub_parser();
    $converter = new UAMS_C2S_Converter;
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $default = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a'])), $stats);
    $blank = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a', 'align' => ''])), $stats);
    $wide = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a', 'align' => 'wide'])), $stats);

    expect($default['attrs']['align'])->toBe('full')
        ->and($blank['attrs']['align'])->toBe('full')
        ->and($wide['attrs']['align'])->toBe('wide');
});

it('carries a class name over and notes a missing anchor', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $converter = new UAMS_C2S_Converter;

    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];
    $plain = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => '', 'className' => ''])), $stats);

    expect($plain['attrs'])->not->toHaveKey('anchor')->not->toHaveKey('className')
        ->and($stats['notices'])->toHaveCount(1)
        ->and($stats['notices'][0])->toStartWith('A converted block had no custom anchor');

    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];
    $kept = $converter->convert_block(uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'keep', 'className' => 'a b'])), $stats);

    expect($kept['attrs']['anchor'])->toBe('keep')
        ->and($kept['attrs']['className'])->toBe('a b')
        ->and($stats['notices'])->toBe([]);
});

it('reports a Content block that does not nest its fields under data', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(['content_heading' => 'Flat', 'anchor' => 'a']),
        $stats
    );

    expect($stats['notices'])->toBe(['A Content block did not store its fields under "data". Its heading and background colour could not be read, so the Section was created with the defaults. Check that block by hand.'])
        ->and($section['attrs']['data']['section_heading'])->toBe('');
});

it('converts a block with no attributes at all', function (): void {
    uams_c2s_stub_parser();
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];
    $block = uams_c2s_content_block();
    unset($block['attrs']);

    $section = (new UAMS_C2S_Converter)->convert_block($block, $stats);

    expect($section['attrs']['name'])->toBe('acf/uams-section')
        ->and($stats['converted'])->toBe(1);
});

it('treats attributes that are not an array as none', function (): void {
    uams_c2s_stub_parser();
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];
    $block = uams_c2s_content_block();
    $block['attrs'] = 'not an array';

    $section = (new UAMS_C2S_Converter)->convert_block($block, $stats);

    expect($section['attrs']['align'])->toBe('full');
});

it('skips a geo-targeted block by default and says why', function (): void {
    $log = uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $result = (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_geo' => 'include', 'content_geo_region' => ['US']], ['anchor' => 'a'])),
        $stats
    );

    expect($result)->toBeNull()
        ->and($stats['skipped'])->toBe(1)
        ->and($stats['converted'])->toBe(0)
        ->and($stats['notices'])->toBe(['Skipped a Content block with GeoTargetingWP rules. The Section block has no region filter; re-run with "include geo-targeted blocks" to convert it and drop the rules.'])
        ->and($log->html)->toBe([]);
});

it('converts a geo-targeted block when asked, naming the rules it drops', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter(['include_geo' => true]))->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_geo' => 'exclude', 'content_geo_region' => ['US', 'CA']], ['anchor' => 'a'])),
        $stats
    );

    expect($section)->not->toBeNull()
        ->and($stats['skipped'])->toBe(0)
        ->and($stats['converted'])->toBe(1)
        ->and($stats['notices'])->toBe(['Region rules were dropped from a converted block (exclude: US, CA). It now displays everywhere.']);
});

it('names a single region given as a string', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    (new UAMS_C2S_Converter(['include_geo' => true]))->convert_block(
        uams_c2s_content_block(uams_c2s_data(['content_geo' => 'include', 'content_geo_region' => 'US'], ['anchor' => 'a'])),
        $stats
    );

    expect($stats['notices'])->toBe(['Region rules were dropped from a converted block (include: US). It now displays everywhere.']);
});

it('counts a block as geo-targeted only when both the mode and a region are set', function (array $fields): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $stats = ['converted' => 0, 'skipped' => 0, 'notices' => []];

    $section = (new UAMS_C2S_Converter)->convert_block(
        uams_c2s_content_block(uams_c2s_data($fields, ['anchor' => 'a'])),
        $stats
    );

    expect($section)->not->toBeNull()
        ->and($stats['skipped'])->toBe(0)
        ->and($stats['notices'])->toBe([]);
})->with([
    'mode without a region' => [['content_geo' => 'include', 'content_geo_region' => []]],
    'region without a mode' => [['content_geo' => '', 'content_geo_region' => ['US']]],
    'neither' => [[]],
    'region with no mode key at all' => [['content_geo_region' => ['US']]],
]);

it('converts every Content block in a content string, leaving other blocks alone', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $other = ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => 'x', 'innerContent' => ['x']];
    $freeform = ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "\n", 'innerContent' => ["\n"]];
    $content = json_encode([$freeform, uams_c2s_content_block(uams_c2s_data(['content_heading' => 'One'], ['anchor' => 'a'])), $other]);

    $result = (new UAMS_C2S_Converter)->convert_content($content);
    $blocks = json_decode($result['content'], true);

    expect($result['converted'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and($result['notices'])->toBe([])
        ->and($blocks)->toHaveCount(3)
        ->and($blocks[0])->toBe($freeform)
        ->and($blocks[1]['blockName'])->toBe('acf/uams-section')
        ->and($blocks[1]['attrs']['data']['section_heading'])->toBe('One')
        ->and($blocks[2])->toBe($other);
});

it('converts Content blocks nested inside other blocks and inside each other', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $inner = uams_c2s_content_block(uams_c2s_data(['content_heading' => 'Inner'], ['anchor' => 'i']));
    $outer = uams_c2s_content_block(uams_c2s_data(['content_heading' => 'Outer'], ['anchor' => 'o']), [$inner]);
    $group = ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [$outer], 'innerHTML' => '', 'innerContent' => [null]];

    $result = (new UAMS_C2S_Converter)->convert_content(json_encode([$group]));
    $blocks = json_decode($result['content'], true);

    expect($result['converted'])->toBe(2)
        ->and($blocks[0]['blockName'])->toBe('core/group')
        ->and($blocks[0]['innerBlocks'][0]['blockName'])->toBe('acf/uams-section')
        ->and($blocks[0]['innerBlocks'][0]['attrs']['data']['section_heading'])->toBe('Outer');
});

it('leaves a skipped Content block in place and counts it', function (): void {
    uams_c2s_stub_parser();
    $geo = uams_c2s_content_block(uams_c2s_data(['content_geo' => 'include', 'content_geo_region' => ['US']], ['anchor' => 'a']));

    $result = (new UAMS_C2S_Converter)->convert_content(json_encode([$geo]));

    expect($result['converted'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and(json_decode($result['content'], true))->toBe([$geo]);
});

it('lists each distinct notice once, re-indexed', function (): void {
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']], ['parser note']);
    // The first block has an anchor and adds only the parser's note; the second
    // repeats it and adds the anchor note, so removing the duplicate leaves a gap.
    $one = uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']));
    $two = uams_c2s_content_block(uams_c2s_data([]));

    $result = (new UAMS_C2S_Converter)->convert_content(json_encode([$one, $two]));

    expect($result['converted'])->toBe(2)
        ->and($result['notices'])->toHaveCount(2)
        ->and($result['notices'][0])->toBe('parser note')
        ->and(array_keys($result['notices']))->toBe([0, 1]);
});

it('returns content with no blocks unchanged in shape', function (): void {
    uams_c2s_stub_parser();

    $result = (new UAMS_C2S_Converter)->convert_content('[]');

    expect($result)->toBe(['content' => '[]', 'converted' => 0, 'skipped' => 0, 'notices' => []]);
});

/* ---------------------------------------------------------------------
 * Damage detection
 * ------------------------------------------------------------------ */

it('spots each stripped escape', function (string $code): void {
    expect(UAMS_C2S_Converter::looks_damaged('{"a":"x'.$code.'y"}'))->toBeTrue();
})->with(['u003c', 'u003e', 'u0026', 'u0022', 'u002d', 'U003C', 'u003E']);

it('does not flag an intact escape or ordinary text', function (string $content): void {
    expect(UAMS_C2S_Converter::looks_damaged($content))->toBeFalse();
})->with([
    'intact lower' => ['{"a":"\\u003cp\\u003e"}'],
    'intact upper' => ['{"a":"\\u0022"}'],
    'a different code' => ['u0041'],
    'a partial code' => ['u003'],
    'plain text' => ['hello world'],
    'empty' => [''],
]);

it('flags a stripped escape that follows an intact one', function (): void {
    expect(UAMS_C2S_Converter::looks_damaged('\\u003c then u003e'))->toBeTrue();
});

it('reads non-string content as a string when checking for damage', function (): void {
    expect(UAMS_C2S_Converter::looks_damaged(null))->toBeFalse()
        ->and(UAMS_C2S_Converter::looks_damaged(false))->toBeFalse();
});

it('names plugin version 1.0.0 as the one that wrote content unslashed', function (): void {
    expect(UAMS_C2S_Converter::wrote_unslashed('1.0.0'))->toBeTrue()
        ->and(UAMS_C2S_Converter::wrote_unslashed('1.0.1'))->toBeFalse()
        ->and(UAMS_C2S_Converter::wrote_unslashed(''))->toBeFalse()
        ->and(UAMS_C2S_Converter::wrote_unslashed(null))->toBeFalse()
        ->and(UAMS_C2S_Converter::wrote_unslashed('1.0.0 '))->toBeFalse()
        // A version handed over as an object that prints as the version string.
        ->and(UAMS_C2S_Converter::wrote_unslashed(new class
        {
            public function __toString(): string
            {
                return '1.0.0';
            }
        }))->toBeTrue();
});

it('shows the text around the first stripped escape', function (): void {
    $content = str_repeat('a', 100).'u003c'.str_repeat('b', 100);

    $sample = UAMS_C2S_Converter::damage_sample($content, 10);

    expect($sample)->toBe('…'.str_repeat('a', 10).'u003c'.str_repeat('b', 10).'…');
});

it('pads the sample by sixty characters on each side by default', function (): void {
    $content = str_repeat('a', 100).'u003c'.str_repeat('b', 100);

    $sample = UAMS_C2S_Converter::damage_sample($content);

    expect($sample)->toBe('…'.str_repeat('a', 60).'u003c'.str_repeat('b', 60).'…');
});

it('starts the sample at the beginning of the content without a leading ellipsis', function (): void {
    expect(UAMS_C2S_Converter::damage_sample('abu003cdef', 10))->toBe('abu003cdef…')
        ->and(UAMS_C2S_Converter::damage_sample('u003cdefgh', 2))->toBe('u003cdefg…')
        ->and(UAMS_C2S_Converter::damage_sample('xxu003c', 2))->toBe('xxu003c…');
});

it('marks a sample that starts one character into the content as cut', function (): void {
    expect(UAMS_C2S_Converter::damage_sample('xxu003c', 1))->toBe('…xu003c…');
});

it('does not start the sample before the content when the padding is longer than the lead', function (): void {
    expect(UAMS_C2S_Converter::damage_sample('abu003c', 50))->toBe('abu003c…');
});

it('trims whitespace from the sample edges', function (): void {
    expect(UAMS_C2S_Converter::damage_sample("   u003c   \n", 10))->toBe('u003c…');
});

it('returns an empty sample when nothing matched', function (): void {
    expect(UAMS_C2S_Converter::damage_sample('clean \\u003c content'))->toBe('')
        ->and(UAMS_C2S_Converter::damage_sample(null))->toBe('');
});

it('reports the plugin version that converted a post', function (): void {
    $world = uams_c2s_world();
    $world->meta[5]['_uams_c2s_converted'] = ['version' => '1.0.1'];
    $world->meta[6]['_uams_c2s_converted'] = ['version' => ''];
    $world->meta[7]['_uams_c2s_converted'] = ['time' => 1];
    $world->meta[8]['_uams_c2s_converted'] = 'not a record';
    $world->meta[10]['_uams_c2s_converted'] = ['version' => 2];
    $converter = new UAMS_C2S_Converter;

    expect($converter->converted_by(5))->toBe('1.0.1')
        ->and($converter->converted_by(6))->toBe('')
        ->and($converter->converted_by(7))->toBe('')
        ->and($converter->converted_by(8))->toBe('')
        ->and($converter->converted_by(9))->toBe('')
        ->and($converter->converted_by(10))->toBe('2');
});

/* ---------------------------------------------------------------------
 * Post level
 * ------------------------------------------------------------------ */

it('previews a conversion without saving anything', function (): void {
    $world = uams_c2s_world();
    $world->post(11, json_encode([uams_c2s_content_block(uams_c2s_data(['content_heading' => 'T'], ['anchor' => 'a']))]));
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    $result = (new UAMS_C2S_Converter)->preview_post(11);

    expect($result['converted'])->toBe(1)
        ->and($result['post_id'])->toBe(11)
        ->and($result['original'])->toBe($world->posts[11]->post_content)
        ->and($result['content'])->not->toBe($result['original'])
        ->and($world->writes)->toBe([])
        ->and($world->updates)->toBe([]);
});

it('previews a post given as an object, with its id as an integer', function (): void {
    $world = uams_c2s_world();
    $post = $world->post(12, '[]');
    $post->ID = '12';

    expect((new UAMS_C2S_Converter)->preview_post($post)['post_id'])->toBe(12);
});

it('refuses to preview a post that does not exist', function (): void {
    uams_c2s_world();

    $result = (new UAMS_C2S_Converter)->preview_post(404);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_no_post')
        ->and($result->get_error_message())->toBe('Post not found.');
});

it('writes nothing when a post holds no Content block', function (): void {
    $world = uams_c2s_world();
    $world->post(13, '[]');
    uams_c2s_stub_parser();

    $result = (new UAMS_C2S_Converter)->convert_post(13);

    expect($result['updated'])->toBeFalse()
        ->and($result['converted'])->toBe(0)
        ->and($world->writes)->toBe([])
        ->and($world->updates)->toBe([]);
});

it('does not convert a post that does not exist', function (): void {
    uams_c2s_world();

    $result = (new UAMS_C2S_Converter)->convert_post(404);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_no_post');
});

it('converts and saves a post, keeping a backup and a record of what it did', function (): void {
    $world = uams_c2s_world();
    // Backslashes are what the slashing exists to protect.
    $original = json_encode([uams_c2s_content_block(uams_c2s_data(['content_heading' => 'A \\u003c B'], ['anchor' => 'a']))]);
    $world->post(14, $original);
    $world->revision(14, 301, 'older', '2026-02-02 00:00:00');
    $world->revision(14, 300, 'oldest', '2026-01-01 00:00:00');
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    $before = time();

    $result = (new UAMS_C2S_Converter)->convert_post(14);

    $record = $world->meta[14]['_uams_c2s_converted'];

    expect($result['updated'])->toBeTrue()
        ->and($result['converted'])->toBe(1)
        ->and($world->meta[14]['_uams_c2s_backup'])->toBe($original)
        ->and($world->posts[14]->post_content)->toBe($result['content'])
        ->and($world->posts[14]->post_content)->not->toBe($original)
        ->and($record['converted'])->toBe(1)
        ->and($record['skipped'])->toBe(0)
        ->and($record['user'])->toBe(7)
        ->and($record['version'])->toBe(UAMS_C2S_VERSION)
        ->and($record['source_revision'])->toBe(301)
        ->and($record['time'])->toBeInt()->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time())
        ->and(array_keys($record))->toBe(['time', 'user', 'converted', 'skipped', 'version', 'source_revision']);
});

it('stores the backup slashed, so the stored copy matches the original byte for byte', function (): void {
    $world = uams_c2s_world();
    $original = json_encode([uams_c2s_content_block(uams_c2s_data(['content_heading' => 'x \\ y'], ['anchor' => 'a']))]);
    $world->post(15, $original);
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(15);

    expect($world->writes[0][0])->toBe('add')
        ->and($world->writes[0][1][2])->toBe(addslashes($original))
        ->and($world->writes[0][1][3])->toBeTrue()
        ->and($world->meta[15]['_uams_c2s_backup'])->toBe($original);
});

it('keeps the first backup when a post is converted again', function (): void {
    $world = uams_c2s_world();
    $world->post(16, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->meta[16]['_uams_c2s_backup'] = 'the first backup';
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(16);

    expect($world->meta[16]['_uams_c2s_backup'])->toBe('the first backup')
        ->and(array_filter($world->writes, static fn (array $w): bool => $w[0] === 'add'))->toBe([]);
});

it('records revision 0 when the post has no revisions', function (): void {
    $world = uams_c2s_world();
    $world->post(17, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(17);

    expect($world->meta[17]['_uams_c2s_converted']['source_revision'])->toBe(0);
});

it('notes the newest revision that is not an autosave', function (): void {
    $world = uams_c2s_world();
    $world->post(18, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->revision(18, 502, 'autosave');
    $world->revision(18, 501, 'real');
    $world->revision(18, 500, 'older');
    $world->autosaves = [502];
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(18);

    expect($world->meta[18]['_uams_c2s_converted']['source_revision'])->toBe(501)
        ->and($world->revisionQueries[0])->toBe([18, ['order' => 'DESC', 'orderby' => 'date ID']]);
});

it('returns the write error and records nothing when saving fails', function (): void {
    $world = uams_c2s_world();
    $world->post(19, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->failUpdate = true;
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    $result = (new UAMS_C2S_Converter)->convert_post(19);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('db_update_error')
        ->and($world->meta[19])->not->toHaveKey('_uams_c2s_converted');
});

it('slashes content on the way into wp_update_post and asks for an error object', function (): void {
    $world = uams_c2s_world();
    $world->post(20, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    $result = (new UAMS_C2S_Converter)->convert_post(20);

    expect($world->updates)->toHaveCount(1)
        ->and($world->updates[0]['ID'])->toBe(20)
        ->and($world->updates[0]['post_content'])->toBe(addslashes($result['content']))
        ->and($world->updates[0]['_wp_error'])->toBeTrue();
});

it('switches the content filters off around a save by a user who cannot post unfiltered HTML', function (): void {
    $world = uams_c2s_world();
    $world->post(21, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->unfilteredHtml = false;
    $world->ksesFilterAttached = true;
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(21);

    expect($world->kses)->toBe(['remove', 'init'])
        ->and($world->updates[0]['_kses_seen'])->toBe(['remove']);
});

it('leaves the content filters alone for a user who can post unfiltered HTML', function (): void {
    $world = uams_c2s_world();
    $world->post(22, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->unfilteredHtml = true;
    $world->ksesFilterAttached = true;
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(22);

    expect($world->kses)->toBe([]);
});

it('leaves the content filters alone when the kses filter is not attached', function (): void {
    $world = uams_c2s_world();
    $world->post(23, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->unfilteredHtml = false;
    $world->ksesFilterAttached = false;
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(23);

    expect($world->kses)->toBe([]);
});

it('restores the content filters even when the save fails', function (): void {
    $world = uams_c2s_world();
    $world->post(24, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->unfilteredHtml = false;
    $world->ksesFilterAttached = true;
    $world->failUpdate = true;
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(24);

    expect($world->kses)->toBe(['remove', 'init']);
});

/* ---------------------------------------------------------------------
 * Reverting
 * ------------------------------------------------------------------ */

it('reverts a converted post to its pre-conversion revision and clears its records', function (): void {
    $world = uams_c2s_world();
    $old = '<!-- wp:acf/uams-content {"a":"\\u003c"} /-->';
    $world->post(30, 'converted content');
    $world->revision(30, 600, $old);
    $world->meta[30] = ['_uams_c2s_backup' => $old, '_uams_c2s_converted' => ['source_revision' => 600, 'version' => '1.0.1']];

    $result = (new UAMS_C2S_Converter)->revert_post(30);

    expect($result)->toBeTrue()
        ->and($world->posts[30]->post_content)->toBe($old)
        ->and($world->meta[30])->toBe([])
        ->and(array_map(static fn (array $w): array => [$w[0], $w[1][1]], $world->writes))->toBe([
            ['delete', '_uams_c2s_backup'],
            ['delete', '_uams_c2s_converted'],
        ]);
});

it('reverts from the stored backup when no revision survives', function (): void {
    $world = uams_c2s_world();
    $world->post(31, 'converted content');
    $world->meta[31] = ['_uams_c2s_backup' => 'the backup', '_uams_c2s_converted' => ['version' => '1.0.1']];

    expect((new UAMS_C2S_Converter)->revert_post(31))->toBeTrue()
        ->and($world->posts[31]->post_content)->toBe('the backup');
});

it('does not revert a post that does not exist', function (): void {
    uams_c2s_world();

    $result = (new UAMS_C2S_Converter)->revert_post(404);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_no_post')
        ->and($result->get_error_message())->toBe('Post not found.');
});

it('does not revert a post that has neither a revision nor a backup', function (): void {
    $world = uams_c2s_world();
    $world->post(32, 'converted content');

    $result = (new UAMS_C2S_Converter)->revert_post(32);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_no_backup')
        ->and($result->get_error_message())->toBe('No pre-conversion backup or usable revision is stored for this post.')
        ->and($world->posts[32]->post_content)->toBe('converted content');
});

it('keeps the records when the revert write fails', function (): void {
    $world = uams_c2s_world();
    $world->post(33, 'converted content');
    $world->meta[33] = ['_uams_c2s_backup' => 'the backup', '_uams_c2s_converted' => ['version' => '1.0.1']];
    $world->failUpdate = true;

    $result = (new UAMS_C2S_Converter)->revert_post(33);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($world->meta[33])->toHaveKeys(['_uams_c2s_backup', '_uams_c2s_converted']);
});

it('refuses a backup written by a plugin version that unslashed it', function (): void {
    $world = uams_c2s_world();
    $world->post(34, 'converted content');
    $world->meta[34] = ['_uams_c2s_backup' => 'looks fine', '_uams_c2s_converted' => ['version' => '1.0.0']];

    $result = (new UAMS_C2S_Converter)->revert_post(34);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_damaged_backup')
        ->and($result->get_error_message())->toBe('The stored backup was written by a plugin version that unslashed it, and no clean pre-conversion revision survives. Restore this post from a database backup instead.');
});

it('refuses a backup that shows the stripped-backslash signature', function (): void {
    $world = uams_c2s_world();
    $world->post(35, 'converted content');
    $world->meta[35] = ['_uams_c2s_backup' => 'has u003c in it', '_uams_c2s_converted' => ['version' => '1.0.1']];

    $result = (new UAMS_C2S_Converter)->revert_post(35);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_damaged_backup');
});

it('accepts a backup with no record when it shows no damage', function (): void {
    $world = uams_c2s_world();
    $world->post(36, 'converted content');
    $world->meta[36] = ['_uams_c2s_backup' => 'plain backup'];

    expect((new UAMS_C2S_Converter)->revert_post(36))->toBeTrue()
        ->and($world->posts[36]->post_content)->toBe('plain backup');
});

/* ---------------------------------------------------------------------
 * Finding the pre-conversion revision
 * ------------------------------------------------------------------ */

const UAMS_C2S_OLD_CONTENT = '<!-- wp:acf/uams-content {"data":{}} /--> old';

it('finds no revision for a post that does not exist', function (): void {
    uams_c2s_world();

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(404))->toBeNull();
});

it('prefers the revision the conversion record names', function (): void {
    $world = uams_c2s_world();
    $world->post(40, 'converted');
    $world->revision(40, 702, UAMS_C2S_OLD_CONTENT.' newer');

    $named = $world->revision(40, 701, UAMS_C2S_OLD_CONTENT.' named');
    $world->meta[40]['_uams_c2s_converted'] = ['source_revision' => 701, 'time' => 1];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(40))->toBe($named);
});

it('falls back to a search when the named revision is not usable', function (string $problem): void {
    $world = uams_c2s_world();
    $world->post(41, 'converted');

    $good = $world->revision(41, 710, UAMS_C2S_OLD_CONTENT.' good', '2020-01-01 00:00:00');

    $named = match ($problem) {
        'missing' => 999,
        'wrong type' => ($world->posts[42] = new WP_Post(['ID' => 42, 'post_type' => 'post', 'post_parent' => 41, 'post_content' => UAMS_C2S_OLD_CONTENT]))->ID,
        'wrong parent' => ($world->revisions[77][] = new WP_Post(['ID' => 43, 'post_type' => 'revision', 'post_parent' => 77, 'post_content' => UAMS_C2S_OLD_CONTENT]))->ID,
        'no content block' => $world->revision(41, 44, 'nothing to see')->ID,
        'the block name without the comment opener' => $world->revision(41, 47, 'acf/uams-content as plain text')->ID,
        'another block' => $world->revision(41, 48, '<!-- wp:core/paragraph --><p>x</p><!-- /wp:core/paragraph -->')->ID,
        'same as the post' => (function () use ($world): int {
            $world->posts[41]->post_content = UAMS_C2S_OLD_CONTENT;

            return $world->revision(41, 45, UAMS_C2S_OLD_CONTENT)->ID;
        })(),
        'damaged' => $world->revision(41, 46, UAMS_C2S_OLD_CONTENT.' u003c')->ID,
    };
    $world->meta[41]['_uams_c2s_converted'] = ['source_revision' => $named];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(41))->toBe($good);
})->with(['missing', 'wrong type', 'wrong parent', 'no content block', 'the block name without the comment opener', 'another block', 'same as the post', 'damaged']);

it('searches newest first and takes the first usable revision', function (): void {
    $world = uams_c2s_world();
    $world->post(46, 'converted');
    $world->revision(46, 721, 'no block here');

    $newest = $world->revision(46, 720, UAMS_C2S_OLD_CONTENT.' newest');
    $world->revision(46, 719, UAMS_C2S_OLD_CONTENT.' older');

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(46))->toBe($newest);
});

it('skips autosaves while searching', function (): void {
    $world = uams_c2s_world();
    $world->post(47, 'converted');
    $world->revision(47, 731, UAMS_C2S_OLD_CONTENT.' autosave');

    $real = $world->revision(47, 730, UAMS_C2S_OLD_CONTENT.' real');
    $world->autosaves = [731];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(47))->toBe($real);
});

it('ignores revisions saved after the conversion while searching', function (): void {
    $world = uams_c2s_world();
    $world->post(48, 'converted');
    $world->revision(48, 742, UAMS_C2S_OLD_CONTENT.' later edit', '2026-03-01 00:00:00');

    $before = $world->revision(48, 741, UAMS_C2S_OLD_CONTENT.' before', '2026-01-01 00:00:00');
    $world->meta[48]['_uams_c2s_converted'] = ['time' => strtotime('2026-02-01 00:00:00 +0000')];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(48))->toBe($before);
});

it('keeps a revision saved in the same second as the conversion', function (): void {
    $world = uams_c2s_world();
    $world->post(49, 'converted');

    $same = $world->revision(49, 751, UAMS_C2S_OLD_CONTENT, '2026-02-01 00:00:00');
    $world->meta[49]['_uams_c2s_converted'] = ['time' => strtotime('2026-02-01 00:00:00 +0000')];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(49))->toBe($same);
});

it('searches every revision when the record has no time', function (): void {
    $world = uams_c2s_world();
    $world->post(50, 'converted');

    $future = $world->revision(50, 761, UAMS_C2S_OLD_CONTENT, '2999-01-01 00:00:00');
    $world->meta[50]['_uams_c2s_converted'] = ['version' => '1.0.1'];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(50))->toBe($future);
});

it('reads a conversion record that is not an array as no record', function (): void {
    $world = uams_c2s_world();
    $world->post(51, 'converted');

    $only = $world->revision(51, 771, UAMS_C2S_OLD_CONTENT);
    $world->meta[51]['_uams_c2s_converted'] = 'garbage';

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(51))->toBe($only);
});

it('finds no revision when none is usable', function (): void {
    $world = uams_c2s_world();
    $world->post(52, 'converted');
    $world->revision(52, 781, 'no block');

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(52))->toBeNull();
});

it('accepts a post object when looking for the revision', function (): void {
    $world = uams_c2s_world();
    $post = $world->post(53, 'converted');
    $only = $world->revision(53, 791, UAMS_C2S_OLD_CONTENT);

    expect((new UAMS_C2S_Converter)->pre_conversion_revision($post))->toBe($only);
});

/* ---------------------------------------------------------------------
 * Repairing
 * ------------------------------------------------------------------ */

const UAMS_C2S_DAMAGED = 'mangled u003cp u003e';

it('refuses to repair a post that does not exist', function (): void {
    uams_c2s_world();

    $result = (new UAMS_C2S_Converter)->repair_post(404);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_no_post');
});

it('declines to repair a post that shows no damage', function (): void {
    $world = uams_c2s_world();
    $world->post(60, 'clean content');

    expect((new UAMS_C2S_Converter)->repair_post(60))->toBe(['post_id' => 60, 'repaired' => false, 'reason' => 'clean'])
        ->and($world->updates)->toBe([]);
});

it('repairs a damaged post by restoring the revision and converting it again', function (): void {
    $world = uams_c2s_world();
    $source = uams_c2s_marked([uams_c2s_content_block(uams_c2s_data(['content_heading' => 'R'], ['anchor' => 'a']))]);
    $world->post(61, UAMS_C2S_DAMAGED);
    $world->revision(61, 801, $source);
    $world->meta[61] = ['_uams_c2s_backup' => $source, '_uams_c2s_converted' => ['version' => '1.0.0', 'source_revision' => 801]];
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    $result = (new UAMS_C2S_Converter)->repair_post(61);

    expect($result['repaired'])->toBeTrue()
        ->and($result['updated'])->toBeTrue()
        ->and($result['source'])->toBe('revision')
        ->and($result['label'])->toBe('revision #801 (2026-01-01 00:00:00 GMT)')
        ->and($world->posts[61]->post_content)->toBe($result['content'])
        ->and(UAMS_C2S_Converter::looks_damaged($world->posts[61]->post_content))->toBeFalse()
        // A clean pair replaces the damaged one: the backup is the restored source.
        ->and($world->meta[61]['_uams_c2s_backup'])->toBe($source)
        ->and($world->meta[61]['_uams_c2s_converted']['version'])->toBe(UAMS_C2S_VERSION);
});

it('repairs a post with no visible damage when forced', function (): void {
    $world = uams_c2s_world();
    $source = uams_c2s_marked([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]);
    $world->post(62, 'converted but clean-looking');
    $world->revision(62, 811, $source);
    $world->meta[62] = ['_uams_c2s_converted' => ['version' => '1.0.0', 'source_revision' => 811]];
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    $plain = (new UAMS_C2S_Converter)->repair_post(62);
    $forced = (new UAMS_C2S_Converter)->repair_post(62, true);

    expect($plain['reason'])->toBe('clean')
        ->and($forced['repaired'])->toBeTrue();
});

it('passes a restore failure back from a repair', function (): void {
    $world = uams_c2s_world();
    $world->post(63, UAMS_C2S_DAMAGED);

    $result = (new UAMS_C2S_Converter)->repair_post(63);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('uams_c2s_no_backup')
        ->and($world->updates)->toBe([]);
});

it('refuses a repair whose revision does not match what was converted', function (): void {
    $world = uams_c2s_world();
    $world->post(64, UAMS_C2S_DAMAGED);
    $world->revision(64, 821, UAMS_C2S_OLD_CONTENT.' stale');
    $world->meta[64] = ['_uams_c2s_backup' => 'what was really converted', '_uams_c2s_converted' => ['version' => '1.0.1']];

    $result = (new UAMS_C2S_Converter)->repair_post(64);

    expect($result)->toBe(['post_id' => 64, 'repaired' => false, 'reason' => 'mismatch'])
        ->and($world->updates)->toBe([])
        ->and($world->meta[64])->toHaveKeys(['_uams_c2s_backup', '_uams_c2s_converted']);
});

it('repairs when the revision matches the backup after unslashing', function (): void {
    $world = uams_c2s_world();
    $source = uams_c2s_marked([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]);
    $world->post(65, UAMS_C2S_DAMAGED);
    $world->revision(65, 831, addslashes($source));
    $world->meta[65] = ['_uams_c2s_backup' => $source, '_uams_c2s_converted' => ['version' => '1.0.0']];
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    // The restored text no longer parses as blocks (the slashes stay), which is why the
    // repair stops at not_converted rather than refusing: it got past the comparison.
    expect((new UAMS_C2S_Converter)->repair_post(65))->toBe(['post_id' => 65, 'repaired' => false, 'reason' => 'not_converted']);
});

it('repairs when there is no backup to compare the revision with', function (): void {
    $world = uams_c2s_world();
    $source = uams_c2s_marked([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]);
    $world->post(66, UAMS_C2S_DAMAGED);
    $world->revision(66, 841, $source);
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    expect((new UAMS_C2S_Converter)->repair_post(66)['repaired'])->toBeTrue();
});

it('passes a write failure back from a repair, keeping the damaged records', function (): void {
    $world = uams_c2s_world();
    $world->post(67, UAMS_C2S_DAMAGED);
    $world->revision(67, 851, UAMS_C2S_OLD_CONTENT);
    $world->meta[67] = ['_uams_c2s_converted' => ['version' => '1.0.1']];
    $world->failUpdate = true;

    $result = (new UAMS_C2S_Converter)->repair_post(67);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('db_update_error')
        ->and($world->meta[67])->toHaveKey('_uams_c2s_converted');
});

it('passes a failure back from the second conversion', function (): void {
    $world = uams_c2s_world();
    $source = uams_c2s_marked([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]);
    $world->post(68, UAMS_C2S_DAMAGED);
    $world->revision(68, 861, $source);
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);
    // The first write (the restore) succeeds; the second (the conversion) fails.
    $calls = 0;
    when('wp_update_post')->alias(static function ($data) use ($world, &$calls) {
        $calls++;
        if ($calls === 2) {
            return new WP_Error('second_failed', 'Second write failed.');
        }

        $world->posts[$data['ID']]->post_content = stripslashes($data['post_content']);

        return $data['ID'];
    });

    $result = (new UAMS_C2S_Converter)->repair_post(68);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('second_failed');
});

it('reports a repair whose restored content holds no Content block as not converted', function (): void {
    $world = uams_c2s_world();
    $world->post(69, UAMS_C2S_DAMAGED);
    $world->meta[69] = ['_uams_c2s_backup' => 'restored text with no block', '_uams_c2s_converted' => ['version' => '1.0.1']];
    uams_c2s_stub_parser();

    $result = (new UAMS_C2S_Converter)->repair_post(69);

    expect($result)->toBe(['post_id' => 69, 'repaired' => false, 'reason' => 'not_converted'])
        ->and($world->posts[69]->post_content)->toBe('restored text with no block')
        ->and($world->meta[69])->toBe([]);
});

/* ---------------------------------------------------------------------
 * The repair plan
 * ------------------------------------------------------------------ */

it('plans nothing for a post that does not exist', function (): void {
    uams_c2s_world();

    expect((new UAMS_C2S_Converter)->repair_plan(404))->toBe([
        'post_id' => 0,
        'damaged' => false,
        'at_risk' => false,
        'source' => '',
        'label' => '',
        'verified' => '',
        'error' => 'Post not found.',
        'sample' => '',
        'converted' => [],
    ]);
});

it('plans nothing for a clean post, still showing its conversion record', function (): void {
    $world = uams_c2s_world();
    $world->post(70, 'clean');
    $world->meta[70]['_uams_c2s_converted'] = ['version' => '1.0.1', 'converted' => 2];

    expect((new UAMS_C2S_Converter)->repair_plan(70))->toBe([
        'post_id' => 70,
        'damaged' => false,
        'at_risk' => false,
        'source' => '',
        'label' => '',
        'verified' => '',
        'error' => '',
        'sample' => '',
        'converted' => ['version' => '1.0.1', 'converted' => 2],
    ]);
});

it('plans a repair for a damaged post, with a sample and the source it would use', function (): void {
    $world = uams_c2s_world();
    $world->post(71, UAMS_C2S_DAMAGED);
    $world->revision(71, 871, UAMS_C2S_OLD_CONTENT);
    $world->meta[71] = ['_uams_c2s_backup' => UAMS_C2S_OLD_CONTENT, '_uams_c2s_converted' => ['version' => '1.0.1']];

    $plan = (new UAMS_C2S_Converter)->repair_plan(71);

    expect($plan['damaged'])->toBeTrue()
        ->and($plan['at_risk'])->toBeFalse()
        ->and($plan['sample'])->toBe('mangled u003cp u003e…')
        ->and($plan['source'])->toBe('revision')
        ->and($plan['label'])->toBe('revision #871 (2026-01-01 00:00:00 GMT)')
        ->and($plan['verified'])->toBe('exact')
        ->and($plan['error'])->toBe('')
        ->and($plan['converted'])->toBe(['version' => '1.0.1']);
});

it('plans a repair for a post an affected version converted, though nothing looks damaged', function (): void {
    $world = uams_c2s_world();
    $world->post(72, 'clean looking');
    $world->revision(72, 881, UAMS_C2S_OLD_CONTENT);
    $world->meta[72]['_uams_c2s_converted'] = ['version' => '1.0.0'];

    $plan = (new UAMS_C2S_Converter)->repair_plan(72);

    expect($plan['damaged'])->toBeFalse()
        ->and($plan['at_risk'])->toBeTrue()
        ->and($plan['sample'])->toBe('')
        ->and($plan['source'])->toBe('revision')
        ->and($plan['verified'])->toBe('unknown');
});

it('reports why a plan has no source', function (): void {
    $world = uams_c2s_world();
    $world->post(73, UAMS_C2S_DAMAGED);

    $plan = (new UAMS_C2S_Converter)->repair_plan(73);

    expect($plan['damaged'])->toBeTrue()
        ->and($plan['source'])->toBe('')
        ->and($plan['verified'])->toBe('')
        ->and($plan['error'])->toBe('No pre-conversion backup or usable revision is stored for this post.');
});

it('plans from a post object and reads a conversion record that is not an array as none', function (): void {
    $world = uams_c2s_world();
    $post = $world->post(74, 'clean');
    $world->meta[74]['_uams_c2s_converted'] = 'garbage';

    $plan = (new UAMS_C2S_Converter)->repair_plan($post);

    expect($plan['post_id'])->toBe(74)
        ->and($plan['converted'])->toBe([])
        ->and($plan['at_risk'])->toBeFalse();
});

it('plans a version-less record as not at risk', function (): void {
    $world = uams_c2s_world();
    $world->post(75, 'clean');
    $world->meta[75]['_uams_c2s_converted'] = ['converted' => 1];

    expect((new UAMS_C2S_Converter)->repair_plan(75)['at_risk'])->toBeFalse();
});

it('plans a mismatch when the revision differs from the backup', function (): void {
    $world = uams_c2s_world();
    $world->post(76, UAMS_C2S_DAMAGED);
    $world->revision(76, 891, UAMS_C2S_OLD_CONTENT);
    $world->meta[76]['_uams_c2s_backup'] = 'something else';
    $world->meta[76]['_uams_c2s_converted'] = ['version' => '1.0.1'];

    expect((new UAMS_C2S_Converter)->repair_plan(76)['verified'])->toBe('mismatch');
});

it('plans from the stored backup, marked exact', function (): void {
    $world = uams_c2s_world();
    $world->post(77, UAMS_C2S_DAMAGED);
    $world->meta[77]['_uams_c2s_backup'] = 'good backup';
    $world->meta[77]['_uams_c2s_converted'] = ['version' => '1.0.1'];

    $plan = (new UAMS_C2S_Converter)->repair_plan(77);

    expect($plan['source'])->toBe('backup')
        ->and($plan['label'])->toBe('stored backup')
        ->and($plan['verified'])->toBe('exact');
});

/* ---------------------------------------------------------------------
 * Ids that arrive as numeric strings, and values stored as other types
 * ------------------------------------------------------------------ */

it('reports ids as integers even when the post object carries them as strings', function (): void {
    $world = uams_c2s_world();
    $clean = $world->post(90, 'clean');
    $clean->ID = '90';

    $damaged = $world->post(91, UAMS_C2S_DAMAGED);
    $damaged->ID = '91';

    $converter = new UAMS_C2S_Converter;

    expect($converter->repair_post($clean))->toBe(['post_id' => 90, 'repaired' => false, 'reason' => 'clean'])
        ->and($converter->repair_plan($clean)['post_id'])->toBe(90)
        ->and($converter->repair_plan($damaged)['post_id'])->toBe(91);
});

it('follows a revision id the conversion record holds as a numeric string', function (): void {
    $world = uams_c2s_world();
    $world->post(92, 'converted');

    $named = $world->revision(92, 901, UAMS_C2S_OLD_CONTENT);
    $world->meta[92]['_uams_c2s_converted'] = ['source_revision' => '901'];
    // The search alone would find the newer one, so only the recorded id reaches 901.
    $world->revisions[92] = [new WP_Post([
        'ID' => 902, 'post_type' => 'revision', 'post_parent' => 92,
        'post_content' => UAMS_C2S_OLD_CONTENT.' newer', 'post_date_gmt' => '2026-06-01 00:00:00',
    ]), $named];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision(92))->toBe($named)
        // get_post() is handed an integer id, not the stored string.
        ->and(in_array(901, $world->lookups, true))->toBeTrue()
        ->and(in_array('901', $world->lookups, true))->toBeFalse();
});

it('matches a revision to its post when both ids are numeric strings', function (): void {
    $world = uams_c2s_world();
    $post = $world->post(93, 'converted');
    $post->ID = '93';

    $named = $world->revision(93, 911, UAMS_C2S_OLD_CONTENT);
    $named->post_parent = '93';
    $world->revisions[93] = [new WP_Post([
        'ID' => 912, 'post_type' => 'revision', 'post_parent' => 93,
        'post_content' => UAMS_C2S_OLD_CONTENT.' newer', 'post_date_gmt' => '2026-06-01 00:00:00',
    ]), $named];
    $world->meta[93]['_uams_c2s_converted'] = ['source_revision' => 911];

    expect((new UAMS_C2S_Converter)->pre_conversion_revision($post))->toBe($named);
});

it('records the newest revision id as an integer when the revision carries it as a string', function (): void {
    $world = uams_c2s_world();
    $world->post(94, json_encode([uams_c2s_content_block(uams_c2s_data([], ['anchor' => 'a']))]));
    $world->revision(94, 921, 'x')->ID = '921';
    uams_c2s_stub_parser([['blockName' => 'core/paragraph']]);

    (new UAMS_C2S_Converter)->convert_post(94);

    expect($world->meta[94]['_uams_c2s_converted']['source_revision'])->toBe(921);
});

it('reads a stored backup that is not a string as its string form', function (): void {
    $world = uams_c2s_world();
    $world->post(95, 'converted content');
    $world->meta[95] = ['_uams_c2s_backup' => 12345];

    expect((new UAMS_C2S_Converter)->revert_post(95))->toBeTrue()
        ->and($world->posts[95]->post_content)->toBe('12345');
});

it('compares a candidate restore with the backup as strings', function (): void {
    $world = uams_c2s_world();
    $world->meta[96] = ['_uams_c2s_backup' => 5];
    $matches = new ReflectionMethod(UAMS_C2S_Converter::class, 'matches_backup');
    $converter = new UAMS_C2S_Converter;

    expect($matches->invoke($converter, 96, 5))->toBe('exact')
        ->and($matches->invoke($converter, 96, '5'))->toBe('exact')
        ->and($matches->invoke($converter, 96, 6))->toBe('mismatch')
        ->and($matches->invoke($converter, 97, 5))->toBe('unknown');
});

it('compares a candidate that prints as a string with the backup, as it is and unslashed', function (): void {
    $world = uams_c2s_world();
    $world->meta[98] = ['_uams_c2s_backup' => 'a\\b'];
    $world->meta[100] = ['_uams_c2s_backup' => "it's"];
    $matches = new ReflectionMethod(UAMS_C2S_Converter::class, 'matches_backup');
    $printing = static fn (string $text): object => new class($text)
    {
        public function __construct(private string $text) {}

        public function __toString(): string
        {
            return $this->text;
        }
    };
    $converter = new UAMS_C2S_Converter;

    expect($matches->invoke($converter, 98, $printing('a\\b')))->toBe('exact')
        ->and($matches->invoke($converter, 100, $printing("it\\'s")))->toBe('exact')
        ->and($matches->invoke($converter, 100, $printing('other')))->toBe('mismatch');
});

it('reads a revision date as GMT whatever the server time zone is', function (): void {
    $zone = date_default_timezone_get();
    date_default_timezone_set('America/Chicago');

    try {
        $world = uams_c2s_world();
        $world->post(99, 'converted');
        // One hour before the conversion in GMT; read as Chicago time it would fall hours after.
        $world->revision(99, 931, UAMS_C2S_OLD_CONTENT, '2026-02-01 11:00:00');
        $world->meta[99]['_uams_c2s_converted'] = ['time' => strtotime('2026-02-01 12:00:00 +0000')];

        $found = (new UAMS_C2S_Converter)->pre_conversion_revision(99);

        expect($found)->not->toBeNull()
            ->and($found->ID)->toBe(931);
    } finally {
        date_default_timezone_set($zone);
    }
});
