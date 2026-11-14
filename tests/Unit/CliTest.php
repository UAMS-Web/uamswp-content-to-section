<?php

/**
 * Pins what the four WP-CLI commands do today: what they ask the scanner and the
 * converter for, and what they print.
 *
 * The scanner and the converter are replaced by recording stand-ins (their own
 * tests live beside them), and WP-CLI by Support/wp-cli-stubs.php, which records
 * every line, log, warning, table and progress bar in order. WP_CLI::error() halts
 * a real command, so the stand-in throws and a test expects the halt.
 */

declare(strict_types=1);

use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;

covers(UAMS_C2S_CLI::class);

/** An em dash, written as an escape so the character never appears in this file. */
const UAMS_C2S_CLI_DASH = "\u{2014}";

/**
 * The scanner and converter stand-ins, and what they were asked.
 */
function uams_c2s_cli_world(): object
{
    $world = new class
    {
        /** @var list<array<string,mixed>> Arguments of each Scanner::find() call. */
        public array $finds = [];

        /** @var list<int> Ids Scanner::find() answers with. */
        public array $found = [];

        /** @var array<int,array{blocks:int,geo:int}> */
        public array $summaries = [];

        /** @var list<mixed> Arguments of each narrow_statuses() call. */
        public array $narrowed = [];

        /** @var list<string> What narrow_statuses() answers with. */
        public array $narrowedTo = ['draft'];

        /** @var list<int> */
        public array $damaged = [];

        /** @var list<int> */
        public array $atRisk = [];

        /** @var array<int,mixed> Result per post for preview/convert/revert/repair. */
        public array $results = [];

        /** @var array<int,array<string,mixed>> */
        public array $plans = [];

        /** @var list<array{string,mixed,array<string,mixed>}> Converter calls: method, id, options. */
        public array $calls = [];

        /** @var array<int,array{type:string,status:string,title:string}> */
        public array $posts = [];

        public function result(string $method, mixed $id, array $options, mixed ...$extra): mixed
        {
            $this->calls[] = [$method, $id, $options, ...$extra];

            return $this->results[$id] ?? ['converted' => 0, 'skipped' => 0, 'notices' => []];
        }
    };

    redefine('UAMS_C2S_Scanner::find', static function (array $args = []) use ($world): array {
        $world->finds[] = $args;

        return $world->found;
    });
    redefine('UAMS_C2S_Scanner::summarize', static fn ($id): array => $world->summaries[$id] ?? ['blocks' => 0, 'geo' => 0]);
    redefine('UAMS_C2S_Scanner::narrow_statuses', static function ($status) use ($world): array {
        $world->narrowed[] = $status;

        return $world->narrowedTo;
    });
    redefine('UAMS_C2S_Scanner::statuses', static fn (): array => ['publish', 'draft']);
    redefine('UAMS_C2S_Scanner::find_damaged', static fn (): array => $world->damaged);
    redefine('UAMS_C2S_Scanner::find_at_risk', static fn (): array => $world->atRisk);

    redefine('UAMS_C2S_Converter::preview_post', fn ($id): mixed => $world->result('preview_post', $id, $this->args));
    redefine('UAMS_C2S_Converter::convert_post', fn ($id): mixed => $world->result('convert_post', $id, $this->args));
    redefine('UAMS_C2S_Converter::revert_post', function ($id) use ($world) {
        $world->calls[] = ['revert_post', $id, $this->args];

        return $world->results[$id] ?? true;
    });
    redefine('UAMS_C2S_Converter::repair_post', function ($id, $force = false) use ($world) {
        $world->calls[] = ['repair_post', $id, $this->args, $force];

        return $world->results[$id] ?? ['repaired' => true, 'label' => 'revision #1', 'converted' => 1];
    });
    redefine('UAMS_C2S_Converter::repair_plan', function ($id) use ($world) {
        $world->calls[] = ['repair_plan', $id, $this->args];

        return $world->plans[$id] ?? [
            'damaged' => false, 'converted' => [], 'error' => '', 'verified' => 'exact', 'label' => '', 'sample' => '',
        ];
    });

    when('absint')->alias(static fn ($n): int => abs((int) $n));
    when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof WP_Error);
    when('get_post_type')->alias(static fn ($id) => $world->posts[$id]['type'] ?? 'post');
    when('get_post_status')->alias(static fn ($id) => $world->posts[$id]['status'] ?? 'publish');
    when('get_the_title')->alias(static fn ($id) => $world->posts[$id]['title'] ?? 'Post '.$id);
    when('wp_parse_args')->alias(static fn ($args, $defaults = []): array => array_merge($defaults, $args));
    stubTranslationFunctions();

    return $world;
}

beforeEach(function (): void {
    WP_CLI::reset();
    $GLOBALS['wpdb'] = null;
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
});

/**
 * Run a command and return what the stand-in WP-CLI recorded, or the halt message.
 */
function uams_c2s_cli_run(string $command, array $args = [], array $assoc = []): ?string
{
    try {
        (new UAMS_C2S_CLI)->{$command}($args, $assoc);
    } catch (UAMS_C2S_Test_CLI_Halt $uAMSC2STestCLIHalt) {
        return $uAMSC2STestCLIHalt->getMessage();
    }

    return null;
}

/* ---------------------------------------------------------------------
 * scan
 * ------------------------------------------------------------------ */

it('scans for every post type and prints a table by default', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [7, 9];
    $world->summaries = [7 => ['blocks' => 2, 'geo' => 1], 9 => ['blocks' => 1, 'geo' => 0]];
    $world->posts = [7 => ['type' => 'page', 'status' => 'publish', 'title' => 'Seven'], 9 => ['type' => 'post', 'status' => 'draft', 'title' => 'Nine']];

    uams_c2s_cli_run('scan');

    expect($world->finds)->toBe([['post_type' => '']])
        ->and(WP_CLI::$calls)->toBe([['format_items', [
            'table',
            [
                ['ID' => 7, 'type' => 'page', 'status' => 'publish', 'blocks' => 2, 'geo' => 1, 'title' => 'Seven'],
                ['ID' => 9, 'type' => 'post', 'status' => 'draft', 'blocks' => 1, 'geo' => 0, 'title' => 'Nine'],
            ],
            ['ID', 'type', 'status', 'blocks', 'geo', 'title'],
        ]]]);
});

it('scans one post type and renders in the format asked for', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [7];

    uams_c2s_cli_run('scan', [], ['post_type' => 'page', 'format' => 'csv']);

    expect($world->finds)->toBe([['post_type' => 'page']])
        ->and(WP_CLI::$calls[0][1][0])->toBe('csv');
});

it('prints only the count, or only the ids, when asked', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [7, 9, 12];

    uams_c2s_cli_run('scan', [], ['format' => 'count']);
    uams_c2s_cli_run('scan', [], ['format' => 'ids']);

    expect(WP_CLI::$calls)->toBe([['line', 3], ['line', '7 9 12']]);
});

it('reports a scan that found nothing, still printing a count of zero or no ids for those formats', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [];

    uams_c2s_cli_run('scan');
    uams_c2s_cli_run('scan', [], ['format' => 'count']);
    uams_c2s_cli_run('scan', [], ['format' => 'ids']);

    expect(WP_CLI::$calls)->toBe([
        ['success', 'No UAMS Content Blocks found.'],
        ['line', 0],
        ['line', ''],
    ]);
});

/* ---------------------------------------------------------------------
 * convert
 * ------------------------------------------------------------------ */

it('halts a conversion given neither ids nor --all', function (): void {
    uams_c2s_cli_world();

    expect(uams_c2s_cli_run('convert'))->toBe('Pass one or more post IDs, or --all.');
});

it('converts the posts it is given, as integers, and prints the totals', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [
        42 => ['converted' => 2, 'skipped' => 1, 'notices' => []],
        108 => ['converted' => 3, 'skipped' => 0, 'notices' => []],
    ];

    uams_c2s_cli_run('convert', ['42', '108']);

    expect(array_map(static fn (array $c): array => [$c[0], $c[1]], $world->calls))->toBe([['convert_post', 42], ['convert_post', 108]])
        ->and($world->finds)->toBe([])
        ->and(WP_CLI::messages('success'))->toBe(['2 post(s), 5 block(s) converted, 1 skipped, 0 failed.']);
});

it('hands the converter the options the flags set, and its defaults otherwise', function (): void {
    $world = uams_c2s_cli_world();

    uams_c2s_cli_run('convert', ['1']);
    uams_c2s_cli_run('convert', ['1'], ['include-geo' => true, 'demote-headings' => true, 'list-format' => 'v2']);

    expect($world->calls[0][2])->toMatchArray(['include_geo' => false, 'demote_headings' => false, 'list_format' => 'v3'])
        ->and($world->calls[1][2])->toMatchArray(['include_geo' => true, 'demote_headings' => true, 'list_format' => 'v2']);
});

it('finds every post when --all is given, with no type or status limit by default', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [5];

    uams_c2s_cli_run('convert', [], ['all' => true]);

    expect($world->finds)->toBe([['post_type' => '', 'status' => '']])
        ->and($world->narrowed)->toBe([])
        ->and($world->calls[0][1])->toBe(5);
});

it('limits --all to a post type and to the statuses named, trimmed', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [5];

    uams_c2s_cli_run('convert', [], ['all' => true, 'post_type' => 'page', 'post_status' => 'draft, publish']);

    expect($world->finds)->toBe([['post_type' => 'page', 'status' => ['draft', 'publish']]])
        ->and($world->narrowed)->toBe([['draft', 'publish']]);
});

it('halts when no status named is one the scanner covers, listing the ones it does', function (): void {
    $world = uams_c2s_cli_world();
    $world->narrowedTo = [];

    $message = uams_c2s_cli_run('convert', [], ['all' => true, 'post_status' => 'trash, spam']);

    expect($message)->toBe('No scannable post status matched "trash,spam". Choose from: publish, draft.')
        ->and($world->finds)->toBe([]);
});

it('ignores --all when ids are given', function (): void {
    $world = uams_c2s_cli_world();

    uams_c2s_cli_run('convert', ['3'], ['all' => true]);

    expect($world->finds)->toBe([])
        ->and($world->calls[0][1])->toBe(3);
});

it('reports nothing to convert, without building a converter', function (): void {
    $world = uams_c2s_cli_world();
    $world->found = [];

    uams_c2s_cli_run('convert', [], ['all' => true]);

    expect(WP_CLI::$calls)->toBe([['success', 'Nothing to convert.']])
        ->and($world->calls)->toBe([]);
});

it('previews instead of saving on a dry run, and says nothing was saved', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [4 => ['converted' => 1, 'skipped' => 0, 'notices' => []]];

    uams_c2s_cli_run('convert', ['4'], ['dry-run' => true]);

    expect($world->calls[0][0])->toBe('preview_post')
        ->and(WP_CLI::messages('success'))->toBe(['Dry run: 1 post(s), 1 block(s) converted, 0 skipped, 0 failed. Nothing was saved.']);
});

it('shows a progress bar for more than one post unless verbose, labelled by mode', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [1 => ['converted' => 1, 'skipped' => 0, 'notices' => []], 2 => ['converted' => 1, 'skipped' => 0, 'notices' => []]];

    uams_c2s_cli_run('convert', ['1', '2']);
    uams_c2s_cli_run('convert', ['1', '2'], ['dry-run' => true]);
    uams_c2s_cli_run('convert', ['1'], []);
    uams_c2s_cli_run('convert', ['1', '2'], ['verbose' => true]);

    $bars = array_values(array_filter(WP_CLI::$calls, static fn (array $c): bool => $c[0] === 'progress_bar'));

    expect($bars)->toHaveCount(2)
        ->and($bars[0][1]->message)->toBe('Converting')
        ->and($bars[0][1]->count)->toBe(2)
        ->and($bars[0][1]->ticks)->toBe(2)
        ->and($bars[0][1]->finished)->toBeTrue()
        ->and($bars[1][1]->message)->toBe('Analysing');
});

it('counts a post that failed, warning with its id and the error', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [
        8 => new WP_Error('uams_c2s_no_post', 'Post not found.'),
        9 => ['converted' => 1, 'skipped' => 0, 'notices' => []],
    ];

    uams_c2s_cli_run('convert', ['8', '9']);

    expect(WP_CLI::messages('warning'))->toBe(['#8: Post not found.'])
        ->and(WP_CLI::messages('success'))->toBe(['1 post(s), 1 block(s) converted, 0 skipped, 1 failed.']);
});

it('leaves a post with nothing converted and nothing skipped out of the totals', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [
        1 => ['converted' => 0, 'skipped' => 0, 'notices' => []],
        2 => ['converted' => 0, 'skipped' => 2, 'notices' => []],
        3 => ['converted' => 1, 'skipped' => 0, 'notices' => []],
    ];

    uams_c2s_cli_run('convert', ['1', '2', '3']);

    expect(WP_CLI::messages('success'))->toBe(['2 post(s), 1 block(s) converted, 2 skipped, 0 failed.']);
});

it('counts a post that only skipped blocks, whether one or several', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [1 => ['converted' => 0, 'skipped' => 1, 'notices' => []]];

    uams_c2s_cli_run('convert', ['1']);

    expect(WP_CLI::messages('success'))->toBe(['1 post(s), 0 block(s) converted, 1 skipped, 0 failed.']);
});

it('passes the conversion flags to the converter as booleans', function (): void {
    $world = uams_c2s_cli_world();

    uams_c2s_cli_run('convert', ['1'], ['include-geo' => 1, 'demote-headings' => 'yes']);
    uams_c2s_cli_run('repair', ['1'], ['include-geo' => 1, 'demote-headings' => 'yes']);

    expect($world->calls[0][2]['include_geo'])->toBeTrue()
        ->and($world->calls[0][2]['demote_headings'])->toBeTrue()
        ->and($world->calls[1][2]['include_geo'])->toBeTrue()
        ->and($world->calls[1][2]['demote_headings'])->toBeTrue();
});

it('carries on to the next post after one repair errors', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [4 => new WP_Error('x', 'Could not write.')];

    uams_c2s_cli_run('repair', ['4', '5']);

    expect(WP_CLI::messages('success'))->toBe(['1 post(s) repaired, 0 not repaired, 1 failed.']);
});

it('logs each converted post and its notices when verbose', function (): void {
    $world = uams_c2s_cli_world();
    $world->posts = [6 => ['type' => 'page', 'status' => 'publish', 'title' => 'Six']];
    $world->results = [
        6 => ['converted' => 2, 'skipped' => 1, 'notices' => ['first note', 'second note']],
        7 => ['converted' => 0, 'skipped' => 0, 'notices' => ['never shown']],
    ];

    uams_c2s_cli_run('convert', ['6', '7'], ['verbose' => true]);

    expect(WP_CLI::messages('log'))->toBe([
        '#6 Six '.UAMS_C2S_CLI_DASH.' 2 converted, 1 skipped',
        '    · first note',
        '    · second note',
    ]);
});

it('logs nothing when not verbose', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [6 => ['converted' => 2, 'skipped' => 1, 'notices' => ['a note']]];

    uams_c2s_cli_run('convert', ['6']);

    expect(WP_CLI::messages('log'))->toBe([]);
});

/* ---------------------------------------------------------------------
 * revert
 * ------------------------------------------------------------------ */

it('halts a revert given neither ids nor --all', function (): void {
    uams_c2s_cli_world();

    expect(uams_c2s_cli_run('revert'))->toBe('Pass one or more post IDs, or --all.');
});

it('reverts the posts it is given and counts them', function (): void {
    $world = uams_c2s_cli_world();

    uams_c2s_cli_run('revert', ['5', '6']);

    expect(array_map(static fn (array $c): array => [$c[0], $c[1]], $world->calls))->toBe([['revert_post', 5], ['revert_post', 6]])
        ->and(WP_CLI::messages('success'))->toBe(['2 post(s) reverted.']);
});

it('warns about a post that could not be reverted and leaves it out of the count', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [5 => new WP_Error('uams_c2s_no_backup', 'No backup.')];

    uams_c2s_cli_run('revert', ['5', '6']);

    expect(WP_CLI::messages('warning'))->toBe(['#5: No backup.'])
        ->and(WP_CLI::messages('success'))->toBe(['1 post(s) reverted.']);
});

it('reverts every post that has a backup when --all is given', function (): void {
    $world = uams_c2s_cli_world();
    $GLOBALS['wpdb'] = new class
    {
        public $postmeta = 'wp_postmeta';

        public array $prepared = [];

        public function prepare($sql, $param): string
        {
            $this->prepared[] = [$sql, $param];

            return 'PREPARED';
        }

        public function get_col($sql): array
        {
            return $sql === 'PREPARED' ? ['11', '12'] : [];
        }
    };

    uams_c2s_cli_run('revert', [], ['all' => true]);

    expect($GLOBALS['wpdb']->prepared)->toBe([['SELECT post_id FROM wp_postmeta WHERE meta_key = %s', '_uams_c2s_backup']])
        ->and(array_map(static fn (array $c): array => [$c[0], $c[1]], $world->calls))->toBe([['revert_post', 11], ['revert_post', 12]]);
});

it('reports nothing to revert when no post has a backup', function (): void {
    uams_c2s_cli_world();
    $GLOBALS['wpdb'] = new class
    {
        public $postmeta = 'wp_postmeta';

        public function prepare($sql, $param): string
        {
            return $sql;
        }

        public function get_col($sql): array
        {
            return [];
        }
    };

    uams_c2s_cli_run('revert', [], ['all' => true]);

    expect(WP_CLI::$calls)->toBe([['success', 'Nothing to revert.']]);
});

/* ---------------------------------------------------------------------
 * repair
 * ------------------------------------------------------------------ */

it('halts a repair given neither ids nor --all', function (): void {
    uams_c2s_cli_world();

    expect(uams_c2s_cli_run('repair'))->toBe('Pass one or more post IDs, or --all.');
});

it('repairs every damaged or at-risk post once, in id order, when --all is given', function (): void {
    $world = uams_c2s_cli_world();
    $world->damaged = [9, 3];
    $world->atRisk = [3, 5];

    uams_c2s_cli_run('repair', [], ['all' => true]);

    expect(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe([3, 5, 9]);
});

it('reports no damaged conversions when --all finds none', function (): void {
    $world = uams_c2s_cli_world();

    uams_c2s_cli_run('repair', [], ['all' => true]);

    expect(WP_CLI::$calls)->toBe([['success', 'No damaged conversions found.']])
        ->and($world->calls)->toBe([]);
});

it('repairs the posts it is given, forcing the repair, with the conversion options', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [4 => ['repaired' => true, 'label' => 'revision #10 (x GMT)', 'converted' => 2]];
    $world->posts = [4 => ['type' => 'post', 'status' => 'publish', 'title' => 'Four']];

    uams_c2s_cli_run('repair', ['4'], ['include-geo' => true, 'demote-headings' => true, 'list-format' => 'v2']);

    expect($world->calls[0][0])->toBe('repair_post')
        ->and($world->calls[0][1])->toBe(4)
        ->and($world->calls[0][3])->toBeTrue()
        ->and($world->calls[0][2])->toMatchArray(['include_geo' => true, 'demote_headings' => true, 'list_format' => 'v2'])
        ->and(WP_CLI::messages('log'))->toBe(['#4 Four '.UAMS_C2S_CLI_DASH.' restored from revision #10 (x GMT), 2 block(s) re-converted'])
        ->and(WP_CLI::messages('success'))->toBe(['1 post(s) repaired, 0 not repaired, 0 failed.']);
});

it('defaults the repair options', function (): void {
    $world = uams_c2s_cli_world();

    uams_c2s_cli_run('repair', ['4']);

    expect($world->calls[0][2])->toMatchArray(['include_geo' => false, 'demote_headings' => false, 'list_format' => 'v3']);
});

it('counts a repair that errored as failed, warning with the error', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [4 => new WP_Error('x', 'Could not write.')];

    uams_c2s_cli_run('repair', ['4']);

    expect(WP_CLI::messages('warning'))->toBe(['#4: Could not write.'])
        ->and(WP_CLI::messages('success'))->toBe(['0 post(s) repaired, 0 not repaired, 1 failed.']);
});

it('counts a refused repair as not repaired, warning for every reason but "clean"', function (): void {
    $world = uams_c2s_cli_world();
    $world->results = [
        1 => ['repaired' => false, 'reason' => 'clean'],
        2 => ['repaired' => false, 'reason' => 'mismatch'],
        3 => ['repaired' => false, 'reason' => 'not_converted'],
        4 => ['repaired' => false],
        5 => ['repaired' => false, 'reason' => 'something_new'],
    ];

    uams_c2s_cli_run('repair', ['1', '2', '3', '4', '5']);

    expect(WP_CLI::messages('warning'))->toBe([
        '#2 was refused: its restore source does not match the content that was converted, so repairing would discard the difference. Compare them by hand.',
        '#3 was restored to its pre-conversion content, but it held no Content block to convert, so it is listed for conversion again rather than repaired.',
    ])
        ->and(WP_CLI::messages('success'))->toBe(['0 post(s) repaired, 5 not repaired, 0 failed.']);
});

it('previews a repair as a table without repairing anything', function (): void {
    $world = uams_c2s_cli_world();
    $world->posts = [
        1 => ['type' => 'post', 'status' => 'publish', 'title' => 'One'],
        2 => ['type' => 'post', 'status' => 'publish', 'title' => 'Two'],
        3 => ['type' => 'post', 'status' => 'publish', 'title' => 'Three'],
        4 => ['type' => 'post', 'status' => 'publish', 'title' => 'Four'],
        5 => ['type' => 'post', 'status' => 'publish', 'title' => 'Five'],
    ];
    $world->plans = [
        1 => ['damaged' => true, 'converted' => ['version' => '1.0.0'], 'error' => '', 'verified' => 'exact', 'label' => 'revision #1', 'sample' => 'S1'],
        2 => ['damaged' => false, 'converted' => ['version' => '1.0.0'], 'error' => 'No backup.', 'verified' => '', 'label' => '', 'sample' => ''],
        3 => ['damaged' => true, 'converted' => ['time' => 1], 'error' => '', 'verified' => 'mismatch', 'label' => 'revision #3', 'sample' => 'S3'],
        4 => ['damaged' => true, 'converted' => [], 'error' => '', 'verified' => 'unknown', 'label' => 'stored backup', 'sample' => 'around u003c here'],
        5 => ['damaged' => false, 'converted' => [], 'error' => '', 'verified' => 'exact', 'label' => 'revision #5', 'sample' => ''],
    ];

    uams_c2s_cli_run('repair', ['1', '2', '3', '4', '5'], ['dry-run' => true]);

    $table = array_values(array_filter(WP_CLI::$calls, static fn (array $c): bool => $c[0] === 'format_items'))[0][1];

    expect($table[0])->toBe('table')
        ->and($table[2])->toBe(['ID', 'title', 'damaged', 'converted', 'restores', 'blocker'])
        ->and($table[1][0])->toBe(['ID' => 1, 'title' => 'One', 'damaged' => 'yes', 'converted' => 'v1.0.0', 'restores' => 'revision #1', 'blocker' => ''])
        ->and($table[1][1])->toBe(['ID' => 2, 'title' => 'Two', 'damaged' => 'not visible', 'converted' => 'v1.0.0', 'restores' => '', 'blocker' => 'No backup.'])
        ->and($table[1][2])->toBe(['ID' => 3, 'title' => 'Three', 'damaged' => 'yes', 'converted' => 'v?', 'restores' => '', 'blocker' => 'restore does not match the backup; compare by hand'])
        ->and($table[1][3])->toBe(['ID' => 4, 'title' => 'Four', 'damaged' => 'yes', 'converted' => 'no record', 'restores' => 'stored backup', 'blocker' => ''])
        ->and($table[1][4])->toBe(['ID' => 5, 'title' => 'Five', 'damaged' => 'not visible', 'converted' => 'no record', 'restores' => 'revision #5', 'blocker' => ''])
        ->and(array_filter($world->calls, static fn (array $c): bool => $c[0] === 'repair_post'))->toBe([]);
});

it('warns about each damaged post with no conversion record, naming what matched', function (): void {
    $world = uams_c2s_cli_world();
    $world->plans = [
        4 => ['damaged' => true, 'converted' => [], 'error' => '', 'verified' => 'unknown', 'label' => 'stored backup', 'sample' => 'around u003c here'],
        5 => ['damaged' => false, 'converted' => [], 'error' => '', 'verified' => 'exact', 'label' => 'x', 'sample' => 'not shown'],
        6 => ['damaged' => true, 'converted' => ['version' => '1.0.1'], 'error' => '', 'verified' => 'exact', 'label' => 'x', 'sample' => 'not shown either'],
    ];

    uams_c2s_cli_run('repair', ['4', '5', '6'], ['dry-run' => true]);

    expect(WP_CLI::messages('warning'))->toBe(['#4 is damaged but carries no conversion record, so this plugin did not cause it. Matched: around u003c here'])
        ->and(WP_CLI::messages('success'))->toBe(['Dry run. Nothing was saved.']);
});
