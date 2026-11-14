<?php

/**
 * Pins what UAMS_C2S_Scanner reads and asks of the database today.
 *
 * The scanner builds SQL for $wpdb, so each test hands it a recording $wpdb
 * whose prepare() returns the template with its parameters appended, and whose
 * read methods return queued results. The assertions are on the prepared SQL
 * and its parameters: a query that changed shape or lost a parameter fails.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Filters\expectApplied;
use function Brain\Monkey\Functions\when;

covers(UAMS_C2S_Scanner::class);

/**
 * A $wpdb that records its calls and answers from queues.
 */
function uams_c2s_fake_wpdb(array $answers = []): object
{
    return new class($answers)
    {
        public $posts = 'wp_posts';

        public $postmeta = 'wp_postmeta';

        /** @var list<array{string,array}> Every prepare() call: SQL, parameters. */
        public array $prepared = [];

        public function __construct(
            /** @var array<string,list<mixed>> Queued answers by read method. */
            public array $answers
        ) {}

        public function esc_like($text): string
        {
            return addcslashes((string) $text, '_%\\');
        }

        public function prepare($sql, $params = []): string
        {
            $this->prepared[] = [$sql, $params];

            return $sql.' |'.json_encode($params);
        }

        public function get_col($sql): mixed
        {
            return $this->next('get_col');
        }

        public function get_var($sql): mixed
        {
            return $this->next('get_var');
        }

        public function get_results($sql): mixed
        {
            return $this->next('get_results');
        }

        private function next(string $method): mixed
        {
            return array_shift($this->answers[$method]);
        }
    };
}

beforeEach(function (): void {
    when('sanitize_key')->alias(
        static fn ($key): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $key))
    );
    when('absint')->alias(static fn ($n): int => abs((int) $n));
    when('wp_parse_args')->alias(
        static fn ($args, $defaults = []): array => array_merge($defaults, $args)
    );
    when('maybe_unserialize')->alias(
        static fn ($value) => is_string($value) && str_starts_with($value, 'a:') ? unserialize($value) : $value
    );

    $GLOBALS['wpdb'] = null;
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

it('lists the five scannable post statuses', function (): void {
    expect(UAMS_C2S_Scanner::statuses())->toBe(['publish', 'draft', 'pending', 'future', 'private']);
});

it('lets a filter change the scannable statuses', function (): void {
    expectApplied('uams_c2s_statuses')->once()->andReturn(['publish']);

    expect(UAMS_C2S_Scanner::statuses())->toBe(['publish']);
});

it('returns every status when none is requested', function (mixed $requested): void {
    expect(UAMS_C2S_Scanner::narrow_statuses($requested))
        ->toBe(['publish', 'draft', 'pending', 'future', 'private']);
})->with(['empty string' => [''], 'empty array' => [[]], 'null' => [null]]);

it('narrows to a requested subset, never past the scannable statuses', function (): void {
    expect(UAMS_C2S_Scanner::narrow_statuses('draft'))->toBe(['draft'])
        ->and(UAMS_C2S_Scanner::narrow_statuses(['private', 'publish']))->toBe(['publish', 'private'])
        ->and(UAMS_C2S_Scanner::narrow_statuses(['trash', 'draft']))->toBe(['draft'])
        ->and(UAMS_C2S_Scanner::narrow_statuses('trash'))->toBe([])
        ->and(UAMS_C2S_Scanner::narrow_statuses('DRAFT!'))->toBe(['draft']);
});

it('queries every scannable status for posts holding a Content block', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [['7', '3']]]);

    $ids = UAMS_C2S_Scanner::find();

    expect($ids)->toBe([7, 3])
        ->and($GLOBALS['wpdb']->prepared)->toHaveCount(1);

    [$sql, $params] = $GLOBALS['wpdb']->prepared[0];

    expect($sql)->toBe('SELECT ID FROM wp_posts WHERE post_content LIKE %s AND post_status IN ( %s, %s, %s, %s, %s ) ORDER BY post_type ASC, ID ASC')
        ->and($params)->toBe(['%<!-- wp:acf/uams-content%', 'publish', 'draft', 'pending', 'future', 'private']);
});

it('escapes LIKE wildcards in the block name', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[]]]);

    UAMS_C2S_Scanner::find();

    expect($GLOBALS['wpdb']->prepared[0][1][0])->toBe('%'.addcslashes('<!-- wp:acf/uams-content', '_%\\').'%');
});

it('restricts a find to one post type, a status subset and an exclusion list', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[]]]);

    UAMS_C2S_Scanner::find(['post_type' => 'page', 'status' => 'draft', 'exclude' => ['5', -6]]);

    [$sql, $params] = $GLOBALS['wpdb']->prepared[0];

    expect($sql)->toBe('SELECT ID FROM wp_posts WHERE post_content LIKE %s AND post_status IN ( %s ) AND post_type = %s AND ID NOT IN ( %d, %d ) ORDER BY post_type ASC, ID ASC')
        ->and($params)->toBe(['%<!-- wp:acf/uams-content%', 'draft', 'page', 5, 6]);
});

it('accepts a single excluded id', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[]]]);

    UAMS_C2S_Scanner::find(['exclude' => 9]);

    expect($GLOBALS['wpdb']->prepared[0][1])->toContain(9)
        ->and($GLOBALS['wpdb']->prepared[0][0])->toContain('ID NOT IN ( %d )');
});

it('pages a find with a limit and an offset', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[]]]);

    UAMS_C2S_Scanner::find(['limit' => '10', 'offset' => '20']);

    [$sql, $params] = $GLOBALS['wpdb']->prepared[0];

    expect($sql)->toEndWith('ORDER BY post_type ASC, ID ASC LIMIT %d OFFSET %d')
        ->and(array_slice($params, -2))->toBe([10, 20]);
});

it('defaults the offset to zero when only a limit is given', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[]]]);

    UAMS_C2S_Scanner::find(['limit' => 1]);

    expect($GLOBALS['wpdb']->prepared[0][0])->toEndWith('LIMIT %d OFFSET %d')
        ->and(array_slice($GLOBALS['wpdb']->prepared[0][1], -2))->toBe([1, 0]);
});

it('adds no LIMIT for a zero or negative limit', function (int $limit): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[]]]);

    UAMS_C2S_Scanner::find(['limit' => $limit, 'offset' => 4]);

    expect($GLOBALS['wpdb']->prepared[0][0])->not->toContain('LIMIT');
})->with([0, -1]);

it('returns nothing, and runs no query, when no requested status is scannable', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb();

    expect(UAMS_C2S_Scanner::find(['status' => 'trash']))->toBe([])
        ->and($GLOBALS['wpdb']->prepared)->toBe([]);
});

it('treats an empty query result as no posts', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [null]]);

    expect(UAMS_C2S_Scanner::find())->toBe([]);
});

it('counts posts whose content holds a literal string, in the scannable statuses', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_var' => ['12']]);

    expect(UAMS_C2S_Scanner::count_containing('50%_off'))->toBe(12);

    [$sql, $params] = $GLOBALS['wpdb']->prepared[0];

    expect($sql)->toBe('SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE %s AND post_status IN ( %s, %s, %s, %s, %s )')
        ->and($params)->toBe(['%50\\%\\_off%', 'publish', 'draft', 'pending', 'future', 'private']);
});

it('counts matching posts per status and omits statuses with none', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_col' => [[1, 2], [], [3], [], []]]);

    expect(UAMS_C2S_Scanner::status_counts())->toBe(['publish' => 2, 'pending' => 1])
        ->and($GLOBALS['wpdb']->prepared)->toHaveCount(5)
        ->and(array_map(static fn (array $call): array => array_slice($call[1], 1), $GLOBALS['wpdb']->prepared))
        ->toBe([['publish'], ['draft'], ['pending'], ['future'], ['private']]);
});

it('finds posts converted by a version that did not slash its writes', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_results' => [[
        (object) ['post_id' => '4', 'meta_value' => serialize(['version' => '1.0.0'])],
        (object) ['post_id' => '5', 'meta_value' => serialize(['version' => '1.0.2'])],
        (object) ['post_id' => '6', 'meta_value' => serialize(['time' => 1])],
        (object) ['post_id' => '7', 'meta_value' => 'not a record'],
        (object) ['post_id' => '8', 'meta_value' => serialize(['version' => '1.0.0'])],
    ]]]);

    expect(UAMS_C2S_Scanner::find_at_risk())->toBe([4, 8])
        ->and($GLOBALS['wpdb']->prepared[0][1])->toBe('_uams_c2s_converted');
});

it('returns nothing at risk when no conversion record exists', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_results' => [null]]);

    expect(UAMS_C2S_Scanner::find_at_risk())->toBe([]);
});

it('prefilters damaged posts by the five escape markers, then confirms with the converter', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_results' => [[
        (object) ['ID' => '11', 'post_content' => 'text u003c broken'],
        (object) ['ID' => '12', 'post_content' => 'text \\u003c intact'],
        (object) ['ID' => '13', 'post_content' => 'nothing'],
    ]]]);

    expect(UAMS_C2S_Scanner::find_damaged())->toBe([11]);

    [$sql, $params] = $GLOBALS['wpdb']->prepared[0];

    expect($sql)->toBe('SELECT ID, post_content FROM wp_posts WHERE ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) AND post_status IN ( %s, %s, %s, %s, %s ) ORDER BY post_type ASC, ID ASC')
        ->and($params)->toBe([
            '%u0022%', '%u0026%', '%u002d%', '%u003c%', '%u003e%',
            'publish', 'draft', 'pending', 'future', 'private',
        ]);
});

it('returns nothing damaged when the prefilter finds no rows', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb(['get_results' => [null]]);

    expect(UAMS_C2S_Scanner::find_damaged())->toBe([]);
});

it('summarizes a missing post as no blocks', function (): void {
    Functions\expect('get_post')->once()->with(99)->andReturn(null);

    expect(UAMS_C2S_Scanner::summarize(99))->toBe(['blocks' => 0, 'geo' => 0]);
});

it('counts Content blocks, nested ones too, and the geo-targeted among them', function (): void {
    $post = (object) ['post_content' => 'raw'];
    Functions\expect('get_post')->once()->andReturn($post);
    Functions\expect('parse_blocks')->once()->with('raw')->andReturn([
        ['blockName' => 'acf/uams-content', 'attrs' => ['data' => ['content_geo' => '1', 'content_geo_region' => 'x']]],
        ['blockName' => 'core/group', 'innerBlocks' => [
            ['blockName' => 'acf/uams-content', 'attrs' => ['data' => ['content_geo' => '1', 'content_geo_region' => 'y']]],
            ['blockName' => 'acf/uams-content', 'attrs' => ['data' => ['content_geo' => '1']]],
            ['blockName' => 'acf/uams-content', 'attrs' => ['data' => ['content_geo_region' => 'z']]],
            ['blockName' => 'core/paragraph', 'attrs' => []],
            ['blockName' => 'acf/uams-content'],
        ]],
        ['blockName' => null],
        ['blockName' => 'acf/uams-section', 'attrs' => []],
    ]);

    expect(UAMS_C2S_Scanner::summarize($post))->toBe(['blocks' => 5, 'geo' => 2]);
});

it('reports a whole-site picture of the conversion', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb([
        'get_col' => [[
            'garbage',
            serialize(['time' => '100', 'version' => '1.0.2', 'converted' => '2']),
            serialize(['time' => '300', 'version' => '1.0.0', 'converted' => 3]),
            serialize(['version' => '1.0.2']),
            serialize(['time' => 200, 'converted' => '2.5']),
            serialize(['version' => 1.5]),
        ]],
        // The reads in order: backups, content, section.
        'get_var' => ['4', '7', '5'],
        'get_results' => [
            [(object) ['ID' => '1', 'post_content' => 'u003c']],
            [(object) ['post_id' => '2', 'meta_value' => serialize(['version' => '1.0.0'])]],
        ],
    ]);

    $status = UAMS_C2S_Scanner::status();

    expect($status)->toBe([
        'content' => 7,
        'section' => 5,
        'conversions' => 6,
        'blocks' => 7,
        'backups' => 4,
        'damaged' => 1,
        'at_risk' => 1,
        'last' => 300,
        'versions' => ['1.0.0' => 1, '1.0.2' => 2, '1.5' => 1, '?' => 1],
    ])
        ->and($status['last'])->toBeInt()
        ->and($GLOBALS['wpdb']->prepared[0][1])->toBe('_uams_c2s_converted')
        ->and($GLOBALS['wpdb']->prepared[1][1])->toBe('_uams_c2s_backup')
        ->and($GLOBALS['wpdb']->prepared[2][1][0])->toBe('%<!-- wp:acf/uams-content%')
        ->and($GLOBALS['wpdb']->prepared[3][1][0])->toBe('%<!-- wp:acf/uams-section%');
});

it('reports zero last-conversion time and blocks when no record carries them', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb([
        'get_col' => [['garbage', serialize(['version' => '1.0.2'])]],
        'get_var' => ['0', '0', '0'],
        'get_results' => [[], []],
    ]);

    $status = UAMS_C2S_Scanner::status();

    expect($status['last'])->toBe(0)
        ->and($status['blocks'])->toBe(0)
        ->and($status['conversions'])->toBe(2)
        ->and($status['versions'])->toBe(['1.0.2' => 1]);
});

it('reports an empty site when the record query returns nothing', function (): void {
    $GLOBALS['wpdb'] = uams_c2s_fake_wpdb([
        'get_col' => [null],
        'get_var' => ['0', '0', '0'],
        'get_results' => [null, null],
    ]);

    expect(UAMS_C2S_Scanner::status())->toBe([
        'content' => 0,
        'section' => 0,
        'conversions' => 0,
        'blocks' => 0,
        'backups' => 0,
        'damaged' => 0,
        'at_risk' => 0,
        'last' => 0,
        'versions' => [],
    ]);
});
