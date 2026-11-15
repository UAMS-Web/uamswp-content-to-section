<?php

/**
 * Pins what the Tools screen renders and what its admin-post handlers do.
 *
 * The scanner and the converter are replaced by recording stand-ins (their own
 * tests live beside them). WordPress functions are Brain Monkey aliases that
 * behave like the real ones in the one way each test needs; the escaping and
 * i18n functions are real enough to make the markup readable. wp_die() and
 * wp_safe_redirect() throw, so a handler that would have ended the request ends
 * the test at the same point, and the test reads where it was going.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;
use function Patchwork\redefine;
use function Patchwork\relay;

covers(UAMS_C2S_Admin::class);

/** An arrow and an em dash, written as escapes so neither appears in this file. */
const UAMS_C2S_ADMIN_ARROW = "\u{2192}";

class UAMS_C2S_Admin_Halt extends RuntimeException {}

/**
 * Everything the stand-ins answer with and record.
 */
function uams_c2s_admin_world(): object
{
    $world = new class
    {
        /** @var array<string,bool> Capability => allowed. Anything absent is allowed. */
        public array $caps = [];

        /** @var array<int,bool> Post id => may edit. Anything absent is allowed. */
        public array $edit = [];

        public mixed $option = [];

        /** @var list<array{string,mixed}> */
        public array $updated = [];

        /** @var array<string,mixed> */
        public array $transients = [];

        /** @var list<array{string,mixed}> */
        public array $setTransients = [];

        /** @var list<string> */
        public array $deletedTransients = [];

        /** @var list<string> Nonce actions check_admin_referer() was asked about. */
        public array $nonces = [];

        /** @var list<string> What esc_html() and esc_attr() were given, in order. */
        public array $escaped = [];

        /** @var list<array<string,mixed>> Arguments of each Scanner::find() call. */
        public array $finds = [];

        /** @var list<int>|callable Ids Scanner::find() answers with. */
        public mixed $found = [];

        /** @var array<string,int> */
        public array $statusCounts = [];

        /** @var array<string,mixed> */
        public array $status = [];

        /** @var list<int> */
        public array $damaged = [];

        /** @var list<int> */
        public array $atRisk = [];

        /** @var array<int,array{blocks:int,geo:int}> */
        public array $summaries = [];

        public mixed $narrowed = ['draft'];

        /** @var array<int,mixed> Result per post, by converter method. */
        public array $results = [];

        /** @var array<int,array<string,mixed>> */
        public array $plans = [];

        /** @var list<array<int,mixed>> Converter calls: method, id, options, extra. */
        public array $calls = [];

        /** @var list<mixed> Options each converter was built with. */
        public array $built = [];

        /** @var array<int,array<string,mixed>> Post fields by id. */
        public array $posts = [];

        /** @var array<string,string|false> Permalink / preview link by post id. */
        public array $permalinks = [];

        public array $previews = [];

        /** @var array<int,bool> Post ids that have a backup. */
        public array $backups = [];

        /** @var array<string,mixed> Post statuses with an object. */
        public array $statusObjects = [];

        /** @var list<array<string,mixed>> Arguments of each paginate_links() call. */
        public array $paginated = [];

        public mixed $links = ['<a>1</a>', '<a>2</a>'];

        /** @var array<string,bool> Post types that are viewable. */
        public array $viewable = ['post' => true, 'page' => true];

        public int $nocache = 0;

        /** @var list<array<int,mixed>> */
        public array $nonceFields = [];

        public function result(string $method, mixed $id, mixed $args): mixed
        {
            $this->calls[] = [$method, $id, $args];

            return $this->results[$id] ?? ['converted' => 0, 'skipped' => 0, 'notices' => [], 'updated' => false];
        }
    };

    redefine('UAMS_C2S_Scanner::find', static function (array $args = []) use ($world): array {
        $world->finds[] = $args;

        if (is_callable($world->found)) {
            return ($world->found)($args);
        }

        return $world->found;
    });
    redefine('UAMS_C2S_Scanner::status', static fn (): array => $world->status);
    redefine('UAMS_C2S_Scanner::status_counts', static fn (): array => $world->statusCounts);
    redefine('UAMS_C2S_Scanner::find_damaged', static fn (): array => $world->damaged);
    redefine('UAMS_C2S_Scanner::find_at_risk', static fn (): array => $world->atRisk);
    redefine('UAMS_C2S_Scanner::narrow_statuses', static fn ($status): mixed => $world->narrowed);
    redefine('UAMS_C2S_Scanner::summarize', static fn ($post): array => $world->summaries[$post] ?? ['blocks' => 0, 'geo' => 0]);

    redefine('UAMS_C2S_Converter::preview_post', function ($id) use ($world) {
        $world->built[] = $this->args;

        return $world->result('preview_post', $id, $this->args);
    });
    redefine('UAMS_C2S_Converter::convert_post', function ($id) use ($world) {
        $world->built[] = $this->args;

        return $world->result('convert_post', $id, $this->args);
    });
    redefine('UAMS_C2S_Converter::revert_post', function ($id) use ($world) {
        $world->calls[] = ['revert_post', $id, $this->args];

        return $world->results[$id] ?? true;
    });
    redefine('UAMS_C2S_Converter::repair_post', function ($id, $force = false) use ($world) {
        $world->built[] = $this->args;
        $world->calls[] = ['repair_post', $id, $force];

        return $world->results[$id] ?? ['repaired' => true];
    });
    redefine('UAMS_C2S_Converter::repair_plan', function ($id) use ($world) {
        $world->built[] = $this->args;

        return $world->plans[$id] ?? [
            'damaged' => false, 'converted' => [], 'error' => '', 'verified' => 'exact', 'label' => 'revision #1', 'sample' => '',
        ];
    });

    stubTranslationFunctions();

    when('esc_html')->alias(static function (string $text) use ($world): string {
        $world->escaped[] = $text;

        return htmlspecialchars($text, ENT_QUOTES);
    });
    when('esc_attr')->alias(static function (string $text) use ($world): string {
        $world->escaped[] = $text;

        return htmlspecialchars($text, ENT_QUOTES);
    });
    when('esc_url')->alias(static fn (string $url): string => $url);
    when('esc_textarea')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
    when('wp_kses_post')->alias(static fn ($html): string => (string) $html);
    when('absint')->alias(static fn ($n): int => abs((int) $n));
    when('sanitize_text_field')->alias(static fn ($s): string => trim((string) $s));
    when('sanitize_key')->alias(static fn ($s): string => strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $s)));
    when('wp_unslash')->alias(static fn ($s) => is_string($s) ? stripslashes($s) : $s);
    when('wp_parse_args')->alias(static fn ($args, $defaults = []): array => array_merge($defaults, $args));
    when('apply_filters')->alias(static fn ($hook, $value) => $value);
    when('admin_url')->alias(static fn ($path = ''): string => 'https://example.test/wp-admin/'.$path);
    when('add_query_arg')->alias(static fn ($key, $value = null, $url = null): string => is_array($key)
        ? $value.'?'.http_build_query($key)
        : 'current?'.$key.'='.$value);
    when('date_i18n')->alias(static fn ($format, $ts): string => is_int($ts) ? gmdate($format, $ts) : throw new TypeError('timestamp must be an int'));
    when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof WP_Error);
    when('checked')->alias(static function ($checked): void {
        echo $checked ? ' checked="checked"' : '';
    });
    when('selected')->alias(static function ($selected, $current): void {
        echo $selected === $current ? ' selected="selected"' : '';
    });
    when('wp_nonce_field')->alias(static function ($action, $name = '_wpnonce', $referer = true, $echo = true) use ($world): string {
        $world->nonceFields[] = [$action, $name, $referer, $echo];
        $field = '[nonce:'.$action.']';

        if ($echo) {
            echo $field;

            return $field;
        }

        return $field;
    });
    when('submit_button')->alias(static function ($text, $type = 'primary', $name = 'submit', $wrap = true): void {
        echo '[submit:'.$text.'|'.$type.'|'.$name.'|'.var_export($wrap, true).']';
    });
    when('get_the_title')->alias(static fn ($id) => $world->posts[$id]['title'] ?? 'Post '.$id);
    when('get_edit_post_link')->alias(static fn ($id): ?string => isset($world->posts[$id]['nullish']) ? null : 'https://example.test/wp-admin/post.php?post='.$id);
    when('get_post_status')->alias(static fn ($id) => isset($world->posts[$id]['nullish']) ? null : ($world->posts[$id]['status'] ?? 'publish'));
    when('get_post_type')->alias(static fn ($id) => isset($world->posts[$id]['nullish']) ? null : ($world->posts[$id]['type'] ?? 'post'));
    when('get_post')->alias(static function ($id) use ($world) {
        if (is_object($id)) {
            return $id;
        }

        if (isset($world->posts[$id]['missing'])) {
            return null;
        }

        return new WP_Post([
            'ID' => $id,
            'post_type' => $world->posts[$id]['type'] ?? 'post',
            'post_status' => $world->posts[$id]['status'] ?? 'publish',
        ]);
    });
    when('metadata_exists')->alias(static fn ($type, $id, $key): bool => $world->backups[$id] ?? false);
    when('get_post_type_object')->alias(static fn ($type) => isset($world->viewable[$type]) ? (object) ['name' => $type] : false);
    when('is_post_type_viewable')->alias(static fn ($type): bool => $world->viewable[$type->name] ?? false);
    when('get_permalink')->alias(static fn ($post) => $world->permalinks[$post->ID] ?? 'https://example.test/?p='.$post->ID);
    when('get_preview_post_link')->alias(static fn ($post) => $world->previews[$post->ID] ?? 'https://example.test/?p='.$post->ID.'&preview=true');
    when('get_post_status_object')->alias(static fn ($status) => $world->statusObjects[$status] ?? null);
    when('paginate_links')->alias(static function ($args) use ($world) {
        $world->paginated[] = $args;

        return $world->links;
    });
    when('get_option')->alias(static fn ($name, $default = false): mixed => $world->option);
    when('update_option')->alias(static function ($name, $value) use ($world): bool {
        $world->updated[] = [$name, $value];

        return true;
    });
    when('get_transient')->alias(static fn ($name) => $world->transients[$name] ?? false);
    when('set_transient')->alias(static function ($name, $value, $ttl) use ($world): bool {
        $world->setTransients[] = [$name, $value, $ttl];

        return true;
    });
    when('delete_transient')->alias(static function ($name) use ($world): bool {
        $world->deletedTransients[] = $name;

        return true;
    });
    when('current_user_can')->alias(static function ($cap, $id = null) use ($world): bool {
        if ($cap === 'edit_post') {
            return $world->edit[$id] ?? true;
        }

        return $world->caps[$cap] ?? true;
    });
    when('check_admin_referer')->alias(static function ($action) use ($world): int {
        $world->nonces[] = $action;

        return 1;
    });
    when('wp_die')->alias(static function ($message): never {
        throw new UAMS_C2S_Admin_Halt('die: '.$message);
    });
    when('wp_safe_redirect')->alias(static function ($url): never {
        throw new UAMS_C2S_Admin_Halt('redirect: '.$url);
    });
    when('nocache_headers')->alias(static function () use ($world): never {
        $world->nocache++;

        throw new UAMS_C2S_Admin_Halt('progress page');
    });

    WP_Block_Type_Registry::$registered = [UAMS_C2S_Converter::TARGET_BLOCK];

    return $world;
}

beforeEach(function (): void {
    $_GET = [];
    $_POST = [];
});

afterEach(function (): void {
    $_GET = [];
    $_POST = [];
    WP_Block_Type_Registry::$registered = [];
});

/** Run a callable and return what it printed. */
function uams_c2s_admin_output(callable $run): string
{
    ob_start();

    try {
        $run();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

/** The message of the halt a handler ends in, or null if it returned. */
function uams_c2s_admin_halt(callable $run): ?string
{
    try {
        $run();
    } catch (UAMS_C2S_Admin_Halt $uAMSC2SAdminHalt) {
        return $uAMSC2SAdminHalt->getMessage();
    }

    return null;
}

function uams_c2s_admin_redirect(string $notice): string
{
    return 'redirect: https://example.test/wp-admin/tools.php?page=uams-c2s&uams_c2s_notice='.$notice;
}

/** A status block with every count at zero, overridable. */
function uams_c2s_admin_status(array $over = []): array
{
    return array_merge([
        'content' => 3, 'section' => 2, 'conversions' => 4, 'blocks' => 9, 'backups' => 5,
        'damaged' => 1, 'at_risk' => 2, 'last' => 0, 'versions' => [],
    ], $over);
}

function uams_c2s_admin_render(object $world): string
{
    return uams_c2s_admin_output(static fn () => (new UAMS_C2S_Admin)->render());
}

/* ---------------------------------------------------------------------
 * Wiring
 * ------------------------------------------------------------------ */

it('hands out one instance', function (): void {
    $property = new ReflectionProperty(UAMS_C2S_Admin::class, 'instance');
    $property->setValue(null, null);

    $first = UAMS_C2S_Admin::instance();

    expect($first)->toBeInstanceOf(UAMS_C2S_Admin::class)
        ->and(UAMS_C2S_Admin::instance())->toBe($first);

    $property->setValue(null, null);
});

it('registers its screen, stylesheet and admin-post handlers', function (): void {
    $admin = new UAMS_C2S_Admin;
    $admin->hooks();

    expect(has_action('admin_menu', [$admin, 'menu']))->toBe(10)
        ->and(has_action('admin_enqueue_scripts', [$admin, 'styles']))->toBe(10)
        ->and(has_action('admin_post_uams_c2s_save_options', [$admin, 'handle_save_options']))->toBe(10)
        ->and(has_action('admin_post_uams_c2s_convert', [$admin, 'handle_convert']))->toBe(10)
        ->and(has_action('admin_post_uams_c2s_revert', [$admin, 'handle_revert']))->toBe(10)
        ->and(has_action('admin_post_uams_c2s_repair', [$admin, 'handle_repair']))->toBe(10)
        ->and(has_action('admin_post_uams_c2s_repair_batch', [$admin, 'handle_repair_batch']))->toBe(10)
        ->and(has_action('admin_post_uams_c2s_batch', [$admin, 'handle_batch']))->toBe(10);
});

it('adds the screen under Tools for administrators', function (): void {
    stubTranslationFunctions();
    $admin = new UAMS_C2S_Admin;

    Functions\expect('add_management_page')->once()->with(
        'UAMS Content '.UAMS_C2S_ADMIN_ARROW.' Section',
        'Content '.UAMS_C2S_ADMIN_ARROW.' Section',
        'manage_options',
        'uams-c2s',
        [$admin, 'render']
    );

    $admin->menu();
});

it('enqueues its stylesheet on its own screen only', function (): void {
    $admin = new UAMS_C2S_Admin;

    Functions\expect('wp_enqueue_style')->once()->with(
        'uams-c2s-admin',
        UAMS_C2S_URL.'assets/css/admin.css',
        [],
        UAMS_C2S_VERSION
    );

    $admin->styles('tools_page_uams-c2s');
    $admin->styles('tools_page_other');
    $admin->styles('');
});

/* ---------------------------------------------------------------------
 * Options
 * ------------------------------------------------------------------ */

it('merges stored options over the defaults', function (): void {
    $world = uams_c2s_admin_world();
    $world->option = ['include_geo' => true, 'list_format' => 'v2'];

    expect((new UAMS_C2S_Admin)->options())->toBe(array_merge(uams_c2s_default_args(), ['include_geo' => true, 'list_format' => 'v2']));
});

it('falls back to the defaults when the stored option is not an array', function (): void {
    $world = uams_c2s_admin_world();
    $world->option = 'garbage';

    expect((new UAMS_C2S_Admin)->options())->toBe(uams_c2s_default_args());
});

/* ---------------------------------------------------------------------
 * The screen
 * ------------------------------------------------------------------ */

it('refuses to render for a user who cannot manage options', function (): void {
    $world = uams_c2s_admin_world();
    $world->caps['manage_options'] = false;

    expect(uams_c2s_admin_halt(static fn (): string => uams_c2s_admin_render($world)))->toBe('die: You do not have permission to run this conversion.');
});

it('renders an empty screen with nothing to convert', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<h1>UAMS Content Block '.UAMS_C2S_ADMIN_ARROW.' UAMS Section Block</h1>')
        ->and($html)->toContain('0 posts to convert')
        ->and($html)->toContain('No UAMS Content Blocks remain. Nothing to do.')
        ->and($html)->not->toContain('uams-c2s-scopes')
        ->and($html)->not->toContain('tablenav')
        ->and($html)->not->toContain('Needs re-converting')
        ->and($html)->toContain('Site status')
        ->and($html)->toContain('name="action" value="uams_c2s_save_options"')
        ->and($html)->toContain('[nonce:uams_c2s_save_options]')
        ->and($html)->toContain('[submit:Save options|primary|submit|true]')
        ->and($html)->toContain('action="https://example.test/wp-admin/admin-post.php"');
});

it('says one post, not posts, when exactly one remains', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [5];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('1 post to convert')
        ->and($html)->not->toContain('1 posts to convert');
});

it('shows the saved options as checked and selected', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->option = ['include_geo' => true, 'demote_headings' => false, 'list_format' => 'v2'];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('name="include_geo" value="1"  checked="checked" />')
        ->and($html)->toContain('name="demote_headings" value="1"  />')
        ->and($html)->toContain('<option value="v2"  selected="selected">')
        ->and($html)->toContain('<option value="v3" >');
});

it('shows the other options as checked and selected when they are set', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->option = ['include_geo' => false, 'demote_headings' => true, 'list_format' => 'v3'];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('name="include_geo" value="1"  />')
        ->and($html)->toContain('name="demote_headings" value="1"  checked="checked" />')
        ->and($html)->toContain('<option value="v3"  selected="selected">')
        ->and($html)->toContain('<option value="v2" >');
});

it('pages the table twenty-five posts at a time', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = range(1, 30);

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('30 posts to convert')
        ->and($html)->toContain('#25')
        ->and($html)->not->toContain('#26<')
        ->and($world->paginated)->toHaveCount(1)
        ->and($world->paginated[0]['current'])->toBe(1)
        ->and($world->paginated[0]['total'])->toBe(2);
});

it('shows the second page, and clamps a page past the end', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = range(1, 30);
    $_GET['paged'] = '2';

    $second = uams_c2s_admin_render($world);

    $_GET['paged'] = '99';
    $clamped = uams_c2s_admin_render($world);

    $_GET['paged'] = '0';
    $zero = uams_c2s_admin_render($world);

    expect($second)->toContain('#26')->and($second)->toContain('#30')->and($second)->not->toContain('#25<')
        ->and($clamped)->toContain('#26')->and($clamped)->not->toContain('#25<')
        ->and($zero)->toContain('#25')->and($zero)->not->toContain('#26<')
        ->and($world->paginated[0]['current'])->toBe(2)
        ->and($world->paginated[1]['current'])->toBe(2)
        ->and($world->paginated[2]['current'])->toBe(1);
});

it('fits exactly twenty-five posts on one page, and needs two for twenty-six', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();

    $world->found = range(1, 25);

    $one = uams_c2s_admin_render($world);
    $pagesForOne = $world->paginated;

    $world->found = range(1, 26);
    $two = uams_c2s_admin_render($world);

    expect($pagesForOne)->toBe([])
        ->and($one)->toContain('#25')
        ->and($world->paginated[0]['total'])->toBe(2)
        ->and($two)->not->toContain('#26<');
});

it('previews the post named in the query string and marks its row', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [4, 7];
    $world->results = [7 => ['converted' => 2, 'skipped' => 1, 'notices' => [], 'original' => 'before', 'content' => 'after']];
    $_GET['preview'] = '7';

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<tr class="uams-c2s-current">')
        ->and(substr_count($html, 'uams-c2s-current'))->toBe(1)
        ->and($html)->toContain('id="uams-c2s-preview"')
        ->and($world->calls)->toBe([['preview_post', 7, array_merge(uams_c2s_default_args(), [])]]);
});

it('shows a notice named in the query string, cleaned', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $_GET['uams_c2s_notice'] = '  saved  ';

    expect(uams_c2s_admin_render($world))->toContain('<div class="notice notice-success is-dismissible"><p>Options saved.</p></div>');
});

it('warns when the Section block is not registered, and when DOM is missing', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();

    $registered = uams_c2s_admin_render($world);

    WP_Block_Type_Registry::$registered = [];

    $missing = uams_c2s_admin_render($world);

    expect($registered)->not->toContain('The acf/uams-section block is not registered')
        ->and($registered)->not->toContain('PHP is missing the DOM extension')
        ->and($missing)->toContain('<div class="notice notice-warning"><p>The acf/uams-section block is not registered on this site.');
});

it('warns when PHP has no DOM extension', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();

    redefine('class_exists', static fn (string $class, bool $autoload = true): bool => $class === 'DOMDocument' ? false : relay());

    expect(uams_c2s_admin_render($world))->toContain('<div class="notice notice-error"><p>PHP is missing the DOM extension.');
});

dataset('uams_c2s_admin_notices', [
    'converted' => ['converted', 'success', 'Post converted.'],
    'nochange' => ['nochange', 'info', 'Nothing to convert in that post.'],
    'reverted' => ['reverted', 'success', 'Post restored to its pre-conversion content.'],
    'repaired' => ['repaired', 'success', 'Post restored from its pre-conversion revision and converted again.'],
    'norepair' => ['norepair', 'info', 'That post is not damaged; nothing to repair.'],
    'norepair_mismatch' => ['norepair_mismatch', 'warning', 'Nothing was changed. The revision this post would restore from does not match'],
    'norepair_empty' => ['norepair_empty', 'warning', 'The pre-conversion content was restored, but it held no Content block'],
    'repair_batch_done' => ['repair_batch_done', 'success', 'Re-conversion finished.'],
    'saved' => ['saved', 'success', 'Options saved.'],
    'batch_done' => ['batch_done', 'success', 'Batch conversion finished.'],
    'error' => ['error', 'error', 'The conversion failed. Check the error log.'],
]);

it('words each notice and gives it its level', function (string $slug, string $level, string $text): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $_GET['uams_c2s_notice'] = $slug;

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<div class="notice notice-'.$level.' is-dismissible"><p>'.$text);
})->with('uams_c2s_admin_notices');

it('shows nothing for a notice it does not know, or none', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();

    $none = uams_c2s_admin_render($world);

    $_GET['uams_c2s_notice'] = 'bogus';
    $unknown = uams_c2s_admin_render($world);

    expect($none)->not->toContain('is-dismissible')
        ->and($unknown)->not->toContain('is-dismissible');
});

/* ---------------------------------------------------------------------
 * Site status
 * ------------------------------------------------------------------ */

it('lists the site counts with what each one means', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status(['content' => 11, 'section' => 12, 'conversions' => 13, 'backups' => 14, 'damaged' => 15, 'at_risk' => 16]);

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<th scope="row">Posts still holding a Content block</th>')
        ->and($html)->toContain('<td class="uams-c2s-count">11</td>')
        ->and($html)->toContain('<td class="uams-c2s-count">12</td>')
        ->and($html)->toContain('<td class="uams-c2s-count">13</td>')
        ->and($html)->toContain('<td class="uams-c2s-count">14</td>')
        ->and($html)->toContain('<td class="uams-c2s-count">15</td>')
        ->and($html)->toContain('<td class="uams-c2s-count">16</td>')
        ->and($html)->toContain('This plugin has no record of converting anything on this site.')
        ->and($html)->toContain('What the list below counts.')
        ->and($html)->toContain('One per converted post, until it is reverted.')
        ->and($html)->toContain('it is a floor, not a total.')
        ->and($html)->toContain('Written through the 1.0.0 bug');
});

it('breaks the conversions down by version, marking the affected one', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status(['blocks' => 9, 'last' => 1700000000, 'versions' => ['1.0.0' => 5, '1.0.1' => 3]]);

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('9 block(s) in total. 5 by v1.0.0 (affected), 3 by v1.0.1. Most recent 2023-11-14 22:13.')
        ->and($html)->not->toContain('This plugin has no record of converting');
});

it('explains a site with Section blocks but no Content blocks and no record', function (): void {
    $world = uams_c2s_admin_world();

    $world->status = uams_c2s_admin_status(['content' => 0, 'conversions' => 0, 'section' => 4]);

    $warned = uams_c2s_admin_render($world);

    $world->status = uams_c2s_admin_status(['content' => 1, 'conversions' => 0, 'section' => 4]);
    $hasContent = uams_c2s_admin_render($world);

    $world->status = uams_c2s_admin_status(['content' => 0, 'conversions' => 1, 'section' => 4]);
    $hasRecord = uams_c2s_admin_render($world);

    $world->status = uams_c2s_admin_status(['content' => 0, 'conversions' => 0, 'section' => 0]);
    $noSections = uams_c2s_admin_render($world);

    expect($warned)->toContain('There are Section blocks on this site but no Content blocks and no conversion record.')
        ->and($hasContent)->not->toContain('There are Section blocks on this site')
        ->and($hasRecord)->not->toContain('There are Section blocks on this site')
        ->and($noSections)->not->toContain('There are Section blocks on this site');
});

/* ---------------------------------------------------------------------
 * Batch buttons
 * ------------------------------------------------------------------ */

it('offers one button for everything when the posts share a status', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [1, 2, 3];
    $world->statusCounts = ['draft' => 3];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('[submit:Convert all 3 posts|secondary|submit|false]')
        ->and($html)->toContain('<input type="hidden" name="scope" value="" />')
        ->and($html)->toContain('name="action" value="uams_c2s_batch"')
        ->and($html)->toContain('[nonce:uams_c2s_batch]')
        ->and(substr_count($html, 'uams_c2s_batch"'))->toBe(1)
        ->and($html)->toContain('Runs in batches of 10.');
});

it('adds a button for each status when the posts span several', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [1, 2, 3];
    $world->statusCounts = ['publish' => 2, 'draft' => 1];
    $world->statusObjects = ['publish' => (object) ['label' => 'Published']];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('[submit:Convert all 3 posts|secondary|submit|false]')
        ->and($html)->toContain('[submit:Convert 2 published|secondary|submit|false]')
        ->and($html)->toContain('[submit:Convert 1 draft|secondary|submit|false]')
        ->and($html)->toContain('<input type="hidden" name="scope" value="publish" />')
        ->and($html)->toContain('<input type="hidden" name="scope" value="draft" />');
});

/* ---------------------------------------------------------------------
 * The conversion table
 * ------------------------------------------------------------------ */

it('lists each post with its counts and actions', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [7, 8];
    $world->posts = [
        7 => ['title' => 'Seven', 'type' => 'page', 'status' => 'publish'],
        8 => ['title' => '', 'type' => 'post', 'status' => 'draft'],
    ];
    $world->summaries = [7 => ['blocks' => 3, 'geo' => 2], 8 => ['blocks' => 1, 'geo' => 0]];
    $world->backups = [7 => true];
    $world->permalinks = [7 => 'https://example.test/seven/'];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<a href="https://example.test/wp-admin/post.php?post=7">Seven</a>')
        ->and($html)->toContain('<a href="https://example.test/wp-admin/post.php?post=8">(8)</a>')
        ->and($html)->toContain('<td>page</td>')
        ->and($html)->toContain('<td>draft</td>')
        ->and($html)->toContain('<td>3</td>')
        ->and($html)->toContain('<span class="uams-c2s-flag">2</span>')
        ->and($html)->toContain('&mdash;')
        ->and($html)->toContain('href="https://example.test/seven/" target="_blank" rel="noopener">View on site')
        ->and($html)->toContain('Preview on site')
        ->and($html)->toContain('href="https://example.test/wp-admin/tools.php?page=uams-c2s&preview=7#uams-c2s-preview"')
        ->and($html)->toContain('[nonce:uams_c2s_convert_7]')
        ->and($html)->toContain('[nonce:uams_c2s_convert_8]')
        ->and($html)->toContain('[nonce:uams_c2s_revert_7]')
        ->and($html)->not->toContain('[nonce:uams_c2s_revert_8]')
        ->and($html)->toContain('<input type="hidden" name="post_id" value="7" />')
        ->and($html)->toContain('<button type="submit" class="button button-primary">Convert</button>')
        ->and($html)->toContain('<button type="submit" class="button button-link-delete">Revert</button>');
});

it('counts a post with no geo blocks as a dash, and one geo block as a flag', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [1, 2];
    $world->summaries = [1 => ['blocks' => 1, 'geo' => 1], 2 => ['blocks' => 1, 'geo' => 0]];

    $html = uams_c2s_admin_render($world);

    expect(substr_count($html, 'class="uams-c2s-flag"'))->toBe(1)
        ->and($html)->toContain('<span class="uams-c2s-flag">1</span>');
});

it('gives a post no site link when its type is not viewable, or it is gone', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [1, 2, 3, 4, 5];
    $world->posts = [
        1 => ['type' => 'hidden'],
        2 => ['missing' => true],
        3 => ['type' => 'unregistered'],
        4 => ['status' => 'private'],
        5 => ['status' => 'future'],
    ];
    $world->viewable = ['post' => true, 'hidden' => false];
    $world->permalinks = [4 => 'https://example.test/four/'];

    $html = uams_c2s_admin_render($world);

    expect(substr_count($html, 'target="_blank"'))->toBe(2)
        ->and($html)->toContain('href="https://example.test/four/" target="_blank" rel="noopener">View on site')
        ->and($html)->toContain('href="https://example.test/?p=5&preview=true" target="_blank" rel="noopener">Preview on site');
});

it('gives a post no site link when WordPress has no URL for it', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [1, 2];
    $world->posts = [2 => ['status' => 'draft']];
    $world->permalinks = [1 => ''];
    $world->previews = [2 => ''];

    expect(uams_c2s_admin_render($world))->not->toContain('target="_blank"');
});

it('links pagination through the links WordPress builds, and says nothing when it builds none', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = range(1, 30);

    $with = uams_c2s_admin_render($world);

    $world->links = false;
    $without = uams_c2s_admin_render($world);

    expect($with)->toContain('<div class="tablenav"><div class="tablenav-pages"><a>1</a> <a>2</a></div></div>')
        ->and($without)->not->toContain('tablenav')
        ->and($world->paginated[0])->toBe([
            'base' => 'current?paged=%#%',
            'format' => '',
            'current' => 1,
            'total' => 2,
            'type' => 'array',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ]);
});

/* ---------------------------------------------------------------------
 * The preview
 * ------------------------------------------------------------------ */

it('shows what a preview would convert, with the markup before and after', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->posts = [7 => ['title' => 'Seven <b>']];
    $world->results = [7 => ['converted' => 2, 'skipped' => 1, 'notices' => ['check <this>', 'and that'], 'original' => '<p>old</p>', 'content' => '<p>new</p>']];
    $_GET['preview'] = '7';

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('Dry run: Seven &lt;b&gt;')
        ->and($html)->toContain('2 block(s) would be converted, 1 skipped. Nothing has been saved.')
        ->and($html)->toContain('<li>check &lt;this&gt;</li>')
        ->and($html)->toContain('<li>and that</li>')
        ->and($html)->toContain('Review before converting')
        ->and($html)->toContain('<textarea readonly rows="24" class="large-text code">&lt;p&gt;old&lt;/p&gt;</textarea>')
        ->and($html)->toContain('<textarea readonly rows="24" class="large-text code">&lt;p&gt;new&lt;/p&gt;</textarea>');
});

it('omits the review box when a preview has no notices', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->results = [7 => ['converted' => 1, 'skipped' => 0, 'notices' => [], 'original' => 'a', 'content' => 'b']];
    $_GET['preview'] = '7';

    expect(uams_c2s_admin_render($world))->not->toContain('Review before converting');
});

it('shows the error when a preview fails', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->results = [7 => new WP_Error('x', 'No such <post>.')];
    $_GET['preview'] = '7';

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<div class="notice notice-error"><p>No such &lt;post&gt;.</p></div>')
        ->and($html)->not->toContain('uams-c2s-preview"');
});

/* ---------------------------------------------------------------------
 * Damaged posts
 * ------------------------------------------------------------------ */

function uams_c2s_admin_plan(array $over = []): array
{
    return array_merge([
        'damaged' => true, 'converted' => ['version' => '1.0.0', 'time' => 1700000000], 'error' => '',
        'verified' => 'exact', 'label' => 'revision #9', 'sample' => 'around u003c here',
    ], $over);
}

it('lists damaged posts with the evidence for each', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5];
    $world->atRisk = [5, 6];
    $world->posts = [5 => ['title' => 'Five', 'status' => 'publish'], 6 => ['title' => '', 'status' => 'draft', 'type' => 'page']];
    $world->plans = [
        5 => uams_c2s_admin_plan(),
        6 => uams_c2s_admin_plan(['damaged' => false, 'converted' => ['x' => 1], 'verified' => 'none']),
    ];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('Needs re-converting')
        ->and($html)->toContain('2 post(s) were written through the 1.0.0 slashing bug, or carry its signature. 1 of them show it')
        ->and($html)->toContain('A clean-looking post in this list is not proof it is undamaged.')
        ->and($html)->toContain('Repair restores the pre-conversion content from the revision')
        ->and($html)->not->toContain('None of them carry a conversion record')
        ->and($html)->toContain('<a href="https://example.test/wp-admin/post.php?post=5">Five</a>')
        ->and($html)->toContain('<a href="https://example.test/wp-admin/post.php?post=6">(6)</a>')
        ->and($html)->toContain('yes '."\u{2014}".' v1.0.0, 2023-11-14 22:13')
        ->and($html)->toContain('yes '."\u{2014}".' v?, ?')
        ->and($html)->toContain('<code class="uams-c2s-sample">around u003c here</code>')
        ->and($html)->toContain('none visible '."\u{2014}".' listed on its conversion record')
        ->and($html)->toContain('matches the pre-conversion backup')
        ->and($html)->toContain('no backup survives to check it against')
        ->and($html)->toContain('[nonce:uams_c2s_repair_5]')
        ->and($html)->toContain('[nonce:uams_c2s_repair_6]')
        ->and($html)->toContain('[submit:Repair all 2 posts|primary|submit|false]')
        ->and($html)->toContain('[nonce:uams_c2s_repair_batch]');
});

it('does not call a post damaged-looking when every listed post shows damage', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5];
    $world->plans = [5 => uams_c2s_admin_plan()];

    $html = uams_c2s_admin_render($world);

    expect($html)->not->toContain('A clean-looking post in this list is not proof')
        ->and($html)->not->toContain('Repair all');
});

it('says so when none of the listed posts were converted by this plugin', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5];
    $world->plans = [5 => uams_c2s_admin_plan(['converted' => []])];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('None of them carry a conversion record from this plugin')
        ->and($html)->not->toContain('Repair restores the pre-conversion content')
        ->and($html)->toContain('<em>no record</em>');
});

it('offers a batch repair only for more than one repairable post, never counting refusals', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [1, 2, 3, 4];
    $world->plans = [
        1 => uams_c2s_admin_plan(),
        2 => uams_c2s_admin_plan(['verified' => 'mismatch']),
        3 => uams_c2s_admin_plan(['error' => 'No backup.']),
        4 => uams_c2s_admin_plan(),
    ];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('[submit:Repair all 2 posts|primary|submit|false]');

    $world->plans[4] = uams_c2s_admin_plan(['verified' => 'mismatch']);
    $single = uams_c2s_admin_render($world);

    expect($single)->not->toContain('Repair all');
});

it('shows a refused or blocked repair as a reason, not a button', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [1, 2];
    $world->plans = [
        1 => uams_c2s_admin_plan(['verified' => 'mismatch']),
        2 => uams_c2s_admin_plan(['error' => 'No backup <here>.', 'label' => 'ignored']),
    ];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('does not match the pre-conversion backup')
        ->and($html)->toContain('needs a manual comparison')
        ->and($html)->toContain('<span class="uams-c2s-flag">No backup &lt;here&gt;.</span>')
        ->and($html)->toContain('&mdash;')
        ->and($html)->not->toContain('[nonce:uams_c2s_repair_1]')
        ->and($html)->not->toContain('[nonce:uams_c2s_repair_2]')
        ->and($html)->not->toContain('ignored');
});

it('builds the converter for damaged posts from the saved options', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5];
    $world->option = ['demote_headings' => true];

    uams_c2s_admin_render($world);

    expect($world->built[0])->toBe(array_merge(uams_c2s_default_args(), ['demote_headings' => true]));
});

/* ---------------------------------------------------------------------
 * Handlers
 * ------------------------------------------------------------------ */

it('refuses every handler to a user who cannot manage options', function (string $method): void {
    $world = uams_c2s_admin_world();
    $world->caps['manage_options'] = false;
    $_POST['post_id'] = '7';

    expect(uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->{$method}()))->toBe('die: You do not have permission to run this conversion.')
        ->and($world->nonces)->toBe([])
        ->and($world->calls)->toBe([])
        ->and($world->updated)->toBe([]);
})->with(['handle_save_options', 'handle_convert', 'handle_revert', 'handle_repair', 'handle_repair_batch', 'handle_batch']);

it('saves the options it is given, and nothing else', function (): void {
    $world = uams_c2s_admin_world();
    $_POST = ['include_geo' => '1', 'demote_headings' => '1', 'list_format' => 'v2', 'extra' => 'x'];

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_save_options());

    expect($halt)->toBe(uams_c2s_admin_redirect('saved'))
        ->and($world->nonces)->toBe(['uams_c2s_save_options'])
        ->and($world->updated)->toBe([['uams_c2s_options', ['include_geo' => true, 'demote_headings' => true, 'list_format' => 'v2']]]);
});

it('saves unticked boxes as off and a missing or unknown list format as v3', function (): void {
    $world = uams_c2s_admin_world();

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_save_options());

    $_POST = ['list_format' => 'v9', 'include_geo' => '0'];
    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_save_options());

    $_POST = ['list_format' => 'v3'];
    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_save_options());

    expect($world->updated[0][1])->toBe(['include_geo' => false, 'demote_headings' => false, 'list_format' => 'v3'])
        ->and($world->updated[1][1])->toBe(['include_geo' => false, 'demote_headings' => false, 'list_format' => 'v3'])
        ->and($world->updated[2][1]['list_format'])->toBe('v3');
});

it('unslashes the list format it reads', function (): void {
    $world = uams_c2s_admin_world();
    $_POST = ['list_format' => 'v\\2'];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_save_options());

    expect($world->updated[0][1]['list_format'])->toBe('v2');
});

it('converts one post and says whether anything changed', function (): void {
    $world = uams_c2s_admin_world();
    $world->option = ['include_geo' => true];
    $world->results = [7 => ['updated' => true], 8 => ['updated' => false], 9 => [], 10 => new WP_Error('x', 'no')];

    $outcomes = [];

    foreach ([7, 8, 9, 10] as $id) {
        $_POST['post_id'] = (string) $id;
        $outcomes[$id] = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_convert());
    }

    expect($outcomes)->toBe([
        7 => uams_c2s_admin_redirect('converted'),
        8 => uams_c2s_admin_redirect('nochange'),
        9 => uams_c2s_admin_redirect('nochange'),
        10 => uams_c2s_admin_redirect('error'),
    ])
        ->and($world->nonces)->toBe(['uams_c2s_convert_7', 'uams_c2s_convert_8', 'uams_c2s_convert_9', 'uams_c2s_convert_10'])
        ->and($world->calls[0])->toBe(['convert_post', 7, array_merge(uams_c2s_default_args(), ['include_geo' => true])]);
});

it('refuses a single-post handler for a missing id or a post the user cannot edit', function (string $method): void {
    $world = uams_c2s_admin_world();
    $world->edit = [7 => false];

    $missing = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->{$method}());

    $_POST['post_id'] = '7';
    $denied = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->{$method}());

    $_POST['post_id'] = '-7';
    $negative = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->{$method}());

    expect($missing)->toBe('die: You cannot edit that post.')
        ->and($denied)->toBe('die: You cannot edit that post.')
        ->and($negative)->toBe('die: You cannot edit that post.')
        ->and($world->calls)->toBe([]);
})->with(['handle_convert', 'handle_revert', 'handle_repair']);

it('checks the nonce before the post', function (string $method, string $action): void {
    $world = uams_c2s_admin_world();
    $world->edit = [7 => false];
    $_POST['post_id'] = '7';

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->{$method}());

    expect($world->nonces)->toBe([$action.'7']);
})->with([
    ['handle_convert', 'uams_c2s_convert_'],
    ['handle_revert', 'uams_c2s_revert_'],
    ['handle_repair', 'uams_c2s_repair_'],
]);

it('reverts one post', function (): void {
    $world = uams_c2s_admin_world();
    $world->results = [8 => new WP_Error('x', 'no')];

    $_POST['post_id'] = '7';
    $ok = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_revert());

    $_POST['post_id'] = '8';
    $failed = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_revert());

    expect($ok)->toBe(uams_c2s_admin_redirect('reverted'))
        ->and($failed)->toBe(uams_c2s_admin_redirect('error'))
        ->and($world->calls[0][0])->toBe('revert_post');
});

it('repairs one post, forcing it, and words each outcome', function (): void {
    $world = uams_c2s_admin_world();
    $world->option = ['demote_headings' => true];
    $world->results = [
        1 => ['repaired' => true],
        2 => new WP_Error('x', 'no'),
        3 => ['repaired' => false, 'reason' => 'clean'],
        4 => ['repaired' => false, 'reason' => 'mismatch'],
        5 => ['repaired' => false, 'reason' => 'not_converted'],
        6 => ['repaired' => false],
        7 => ['repaired' => false, 'reason' => 'something_new'],
    ];

    $outcomes = [];

    foreach (array_keys($world->results) as $id) {
        $_POST['post_id'] = (string) $id;
        $outcomes[$id] = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair());
    }

    expect($outcomes)->toBe([
        1 => uams_c2s_admin_redirect('repaired'),
        2 => uams_c2s_admin_redirect('error'),
        3 => uams_c2s_admin_redirect('norepair'),
        4 => uams_c2s_admin_redirect('norepair_mismatch'),
        5 => uams_c2s_admin_redirect('norepair_empty'),
        6 => uams_c2s_admin_redirect('norepair'),
        7 => uams_c2s_admin_redirect('norepair'),
    ])
        ->and($world->calls[0])->toBe(['repair_post', 1, true])
        ->and($world->built[0])->toBe(array_merge(uams_c2s_default_args(), ['demote_headings' => true]));
});

/* ---------------------------------------------------------------------
 * Batch repair
 * ------------------------------------------------------------------ */

it('repairs the head of the queue, ten at a time, and chains while posts remain', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = range(1, 12);
    $world->atRisk = [12, 13];
    $world->results = [];

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($halt)->toBe('progress page')
        ->and($world->nonces)->toBe(['uams_c2s_repair_batch'])
        ->and(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe(range(1, 10))
        ->and($world->calls[0])->toBe(['repair_post', 1, true])
        ->and($world->setTransients)->toBe([['uams_c2s_repair_skipped', [], HOUR_IN_SECONDS]])
        ->and($world->nocache)->toBe(1);
});

it('parks posts it could not repair and carries them to the next batch', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [1, 2, 3, 4];
    $world->results = [
        1 => ['repaired' => true],
        2 => new WP_Error('x', 'no'),
        3 => ['repaired' => false, 'reason' => 'mismatch'],
    ];
    $world->edit = [4 => false];
    $world->transients = ['uams_c2s_repair_skipped' => [9]];

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($halt)->toBe('progress page')
        ->and(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe([1, 2, 3])
        ->and($world->setTransients)->toBe([['uams_c2s_repair_skipped', [9, 2, 3, 4], HOUR_IN_SECONDS]])
        ->and($world->deletedTransients)->toBe([]);
});

it('finishes a batch repair when the queue is empty, clearing the parked list', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [1];
    $world->results = [];

    // The stand-in repairs without removing the post from the queue, so give the
    // queue a way to empty: the scanner stops reporting it once it was repaired.
    $world->damaged = [];
    $world->transients = ['uams_c2s_repair_skipped' => [1]];

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($halt)->toBe(uams_c2s_admin_redirect('repair_batch_done'))
        ->and($world->deletedTransients)->toBe(['uams_c2s_repair_skipped'])
        ->and($world->setTransients)->toBe([])
        ->and($world->calls)->toBe([]);
});

it('treats a parked list that is not an array as empty', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [1];
    $world->transients = ['uams_c2s_repair_skipped' => 'junk'];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($world->setTransients)->toBe([['uams_c2s_repair_skipped', [], HOUR_IN_SECONDS]]);
});

it('leaves parked posts out of the queue, and the queue sorted and without repeats', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [9, 3, 5];
    $world->atRisk = [5, 1, 7];
    $world->transients = ['uams_c2s_repair_skipped' => ['3', 7]];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe([1, 5, 9]);
});

it('words the repair progress page, and chains back to the repair action', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [1, 2];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($world->escaped)->toContain('Repairing…')
        ->and($world->escaped)->toContain('uams_c2s_repair_batch')
        ->and($world->escaped)->toContain('2 posts left to repair…');
});

it('says one post left, not posts, on the repair progress page', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [1, 2];
    $world->results = [1 => ['repaired' => false]];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($world->escaped)->not->toContain('1 posts left to repair…')
        ->and($world->escaped)->toContain('1 post left to repair…');
});

it('builds the progress page that submits itself to the next batch', function (): void {
    uams_c2s_admin_world();
    $admin = new UAMS_C2S_Admin;
    $markup = new ReflectionMethod($admin, 'batch_progress_markup');

    $html = $markup->invoke($admin, 3, ['fields' => ['scope' => 'dr"aft']]);

    expect($html)->toBe(
        '<!doctype html><html><head><meta charset="utf-8"><title>Converting…</title></head>'
        .'<body style="font:14px -apple-system,system-ui,sans-serif;padding:3rem;text-align:center">'
        .'<p>3 posts left to convert…</p>'
        .'<form id="uams-c2s-continue" method="post" action="https://example.test/wp-admin/admin-post.php">'
        .'<input type="hidden" name="action" value="uams_c2s_batch" />'
        .'<input type="hidden" name="scope" value="dr&quot;aft" />'
        .'[nonce:uams_c2s_batch]'
        .'<button type="submit">Continue</button></form>'
        .'<script>document.getElementById("uams-c2s-continue").submit();</script></body></html>'
    );
});

it('takes the title, action and progress wording it is given for the progress page', function (): void {
    uams_c2s_admin_world();
    $admin = new UAMS_C2S_Admin;
    $markup = new ReflectionMethod($admin, 'batch_progress_markup');

    $html = $markup->invoke($admin, 1, ['action' => 'other', 'title' => 'T <1>', 'progress' => '%d to go']);

    expect($html)->toContain('<title>T &lt;1&gt;</title>')
        ->and($html)->toContain('<p>1 to go</p>')
        ->and($html)->toContain('name="action" value="other"')
        ->and($html)->toContain('[nonce:other]')
        ->and($html)->toContain('<p>1 to go</p>')
        ->and($html)->not->toContain('name="scope"');
});

it('says one post left to convert when it is one', function (): void {
    uams_c2s_admin_world();
    $admin = new UAMS_C2S_Admin;
    $markup = new ReflectionMethod($admin, 'batch_progress_markup');

    expect($markup->invoke($admin, 1, []))->toContain('<p>1 post left to convert…</p>');
});

it('sends no-cache headers before it prints the progress page', function (): void {
    $world = uams_c2s_admin_world();
    $admin = new UAMS_C2S_Admin;
    $render = new ReflectionMethod($admin, 'render_batch_progress');

    $halt = uams_c2s_admin_halt(static fn (): mixed => $render->invoke($admin, 2, []));

    expect($halt)->toBe('progress page')
        ->and($world->nocache)->toBe(1);
});

/* ---------------------------------------------------------------------
 * Batch convert
 * ------------------------------------------------------------------ */

it('converts the head of the list, ten at a time, within the scope it was given', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = static fn (array $args): array => isset($args['limit']) ? range(1, 10) : range(11, 15);
    $world->option = ['include_geo' => true];
    $world->results = [1 => ['updated' => true]];
    $_POST['scope'] = 'Draft';

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($halt)->toBe('progress page')
        ->and($world->nonces)->toBe(['uams_c2s_batch'])
        ->and($world->finds)->toBe([
            ['status' => 'draft', 'limit' => 10, 'exclude' => []],
            ['status' => 'draft', 'exclude' => [2, 3, 4, 5, 6, 7, 8, 9, 10]],
        ])
        ->and(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe(range(1, 10))
        ->and($world->built[0])->toBe(array_merge(uams_c2s_default_args(), ['include_geo' => true]))
        ->and($world->setTransients)->toBe([['uams_c2s_batch_skipped', [2, 3, 4, 5, 6, 7, 8, 9, 10], HOUR_IN_SECONDS]]);
});

it('converts without a scope by default', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = [];

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($halt)->toBe(uams_c2s_admin_redirect('batch_done'))
        ->and($world->finds[0]['status'])->toBe('')
        ->and($world->deletedTransients)->toBe(['uams_c2s_batch_skipped'])
        ->and($world->setTransients)->toBe([]);
});

it('refuses a scope the scanner does not cover', function (): void {
    $world = uams_c2s_admin_world();
    $world->narrowed = [];
    $_POST['scope'] = 'trash';

    $halt = uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($halt)->toBe(uams_c2s_admin_redirect('error'))
        ->and($world->finds)->toBe([])
        ->and($world->calls)->toBe([]);
});

it('unslashes and cleans the scope it reads', function (): void {
    $world = uams_c2s_admin_world();
    $_POST['scope'] = 'dr\\aft!';

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($world->finds[0]['status'])->toBe('draft');
});

it("parks posts that fail, are unchanged or are not the user's to edit", function (): void {
    $world = uams_c2s_admin_world();
    $world->found = static fn (array $args): array => isset($args['limit']) ? [1, 2, 3, 4, 5] : [6];
    $world->results = [
        1 => ['updated' => true],
        2 => new WP_Error('x', 'no'),
        3 => ['updated' => false],
        4 => [],
    ];
    $world->edit = [5 => false];
    $world->transients = ['uams_c2s_batch_skipped' => [9]];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe([1, 2, 3, 4])
        ->and($world->finds[0]['exclude'])->toBe([9])
        ->and($world->finds[1]['exclude'])->toBe([9, 2, 3, 4, 5])
        ->and($world->setTransients)->toBe([['uams_c2s_batch_skipped', [9, 2, 3, 4, 5], HOUR_IN_SECONDS]]);
});

it('keeps each parked post once, and ignores a parked list that is not an array', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = static fn (array $args): array => isset($args['limit']) ? [3] : [4];
    $world->results = [3 => ['updated' => false]];
    $world->transients = ['uams_c2s_batch_skipped' => [3, 3]];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($world->setTransients[0][1])->toBe([3]);

    $world->setTransients = [];
    $world->finds = [];
    $world->transients = ['uams_c2s_batch_skipped' => 'junk'];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($world->finds[0]['exclude'])->toBe([]);
});

it('chains to the next batch carrying the scope', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = static fn (array $args): array => isset($args['limit']) ? [] : [1, 2];
    $_POST['scope'] = 'draft';

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($world->escaped)->toContain('scope')
        ->and($world->escaped)->toContain('draft')
        ->and($world->escaped)->toContain('2 posts left to convert…')
        ->and($world->escaped)->toContain('uams_c2s_batch');
});

it('finishes the batch when nothing is left in scope', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = [];

    expect(uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch()))->toBe(uams_c2s_admin_redirect('batch_done'));
});

/* ---------------------------------------------------------------------
 * Redirecting
 * ------------------------------------------------------------------ */

it('redirects back to the Tools screen with the notice', function (): void {
    uams_c2s_admin_world();
    $admin = new UAMS_C2S_Admin;
    $redirect = new ReflectionMethod($admin, 'redirect');

    expect(uams_c2s_admin_halt(static fn (): mixed => $redirect->invoke($admin, 'saved')))->toBe(uams_c2s_admin_redirect('saved'));
});

/* ---------------------------------------------------------------------
 * Wording and structure the screen must keep
 * ------------------------------------------------------------------ */

it('prints every label of the options form and the conversion table', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [7];
    $world->posts = [7 => ['title' => 'Seven']];

    $html = uams_c2s_admin_render($world);

    foreach ([
        'Rewrites every acf/uams-content block into an acf/uams-section block.',
        'Conversion options',
        'Geo-targeted blocks',
        'Convert blocks that use GeoTargetingWP region rules',
        'The Section block has no region filter',
        'Headings',
        'Demote H1/H2 found in the body to H3',
        'The Section block renders its own H2',
        'List markup',
        'core/list + core/list-item (WordPress 6.0+)',
        'Single core/list block (legacy)',
        '<th scope="col">Post</th>',
        '<th scope="col">Type</th>',
        '<th scope="col">Status</th>',
        '<th scope="col">Content blocks</th>',
        '<th scope="col">Geo-targeted</th>',
        '<th scope="col">Actions</th>',
        '(opens in a new tab)',
        '>Preview</a>',
    ] as $text) {
        expect($html)->toContain($text);
    }
});

it('prints every label of the damaged-posts table and its explanations', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5];
    $world->atRisk = [6, 7];
    $world->posts = [5 => ['title' => 'Five'], 6 => ['title' => 'Six']];
    $world->plans = [5 => uams_c2s_admin_plan(), 6 => uams_c2s_admin_plan(['damaged' => false, 'sample' => '']), 7 => uams_c2s_admin_plan()];

    $html = uams_c2s_admin_render($world);

    foreach ([
        'The detector can only recognise escapes whose stripped form is unmistakable.',
        'Runs in batches of 10. Each post is restored from its pre-conversion revision, then converted again.',
        '<th scope="col">Post</th>',
        '<th scope="col">Type</th>',
        '<th scope="col">Converted by this plugin</th>',
        '<th scope="col">Visible damage</th>',
        '<th scope="col">Restores from</th>',
        '<th scope="col">Actions</th>',
        '(opens in a new tab)',
        '>Repair</button>',
    ] as $text) {
        expect($html)->toContain($text);
    }
});

it('prints the preview headings', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->results = [7 => ['converted' => 1, 'skipped' => 0, 'notices' => [], 'original' => 'a', 'content' => 'b']];
    $_GET['preview'] = '7';

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<h3>Before</h3>')->and($html)->toContain('<h3>After</h3>');
});

it('marks only the previewed row, whatever type the scanner hands back ids in', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = ['4', '7'];
    $world->results = [7 => ['converted' => 1, 'skipped' => 0, 'notices' => [], 'original' => 'a', 'content' => 'b']];
    $_GET['preview'] = '7';

    $html = uams_c2s_admin_render($world);

    expect($html)->toMatch('/<tr class="uams-c2s-current">\s*<td>\s*<strong><a href="[^"]*post=7"/')
        ->and($html)->toMatch('/<tr>\s*<td>\s*<strong><a href="[^"]*post=4"/');
});

it('copes with a post WordPress cannot name, type or link', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->found = [3];
    $world->damaged = [3];
    $world->posts = [3 => ['nullish' => true]];
    $world->plans = [3 => uams_c2s_admin_plan()];

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('<a href="">Post 3</a>')
        ->and($html)->toContain('<td></td>');
});

it('formats a conversion time that arrives as a string', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5];
    $world->plans = [5 => uams_c2s_admin_plan(['converted' => ['version' => '1.0.1', 'time' => '1700000000']])];

    expect(uams_c2s_admin_render($world))->toContain('v1.0.1, 2023-11-14 22:13');
});

it('shows the damage sample only for a post that shows damage', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5, 6];
    $world->plans = [5 => uams_c2s_admin_plan(), 6 => uams_c2s_admin_plan(['damaged' => false, 'sample' => 'SHOULD NOT SHOW'])];

    $html = uams_c2s_admin_render($world);

    expect(substr_count($html, 'class="uams-c2s-sample"'))->toBe(1)
        ->and($html)->not->toContain('SHOULD NOT SHOW')
        ->and(substr_count($html, 'none visible'))->toBe(1);
});

it('links damaged posts to the site only where they can be viewed', function (): void {
    $world = uams_c2s_admin_world();
    $world->status = uams_c2s_admin_status();
    $world->damaged = [5, 6];
    $world->posts = [5 => ['status' => 'draft'], 6 => ['type' => 'hidden']];
    $world->viewable = ['post' => true, 'hidden' => false];
    $world->plans = [5 => uams_c2s_admin_plan(), 6 => uams_c2s_admin_plan()];

    $html = uams_c2s_admin_render($world);

    expect(substr_count($html, 'target="_blank"'))->toBe(1)
        ->and($html)->toContain('Preview on site');
});

it('offers the batch buttons for a single post, and warns on a single orphaned Section block', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = [5];
    $world->statusCounts = ['draft' => 1];

    $world->status = uams_c2s_admin_status(['content' => 0, 'conversions' => 0, 'section' => 1]);

    $html = uams_c2s_admin_render($world);

    expect($html)->toContain('[submit:Convert all 1 posts|secondary|submit|false]')
        ->and($html)->toContain('There are Section blocks on this site but no Content blocks');
});

it('keeps repairing after one post the user cannot edit', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [1, 2, 3];
    $world->edit = [1 => false];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe([2, 3]);
});

it('keeps converting after one post the user cannot edit', function (): void {
    $world = uams_c2s_admin_world();
    $world->found = static fn (array $args): array => isset($args['limit']) ? [1, 2, 3] : [];
    $world->edit = [1 => false];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect(array_map(static fn (array $c): mixed => $c[1], $world->calls))->toBe([2, 3]);
});

it('parks each post once, in order, whichever batch parked it', function (): void {
    $world = uams_c2s_admin_world();
    $world->damaged = [3, 4];
    $world->results = [3 => ['repaired' => false]];
    $world->transients = ['uams_c2s_repair_skipped' => [3, 3, 5]];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_repair_batch());

    expect($world->setTransients[0][1])->toBe([3, 5]);

    $world = uams_c2s_admin_world();
    $world->found = static fn (array $args): array => isset($args['limit']) ? [3] : [4];
    $world->results = [3 => ['updated' => false]];
    $world->transients = ['uams_c2s_batch_skipped' => [3, 3, 5]];

    uams_c2s_admin_halt(static fn () => (new UAMS_C2S_Admin)->handle_batch());

    expect($world->setTransients[0][1])->toBe([3, 5]);
});

it('carries every field into the next batch, and asks for the nonce without the referer field', function (): void {
    $world = uams_c2s_admin_world();
    $admin = new UAMS_C2S_Admin;
    $markup = new ReflectionMethod($admin, 'batch_progress_markup');

    $html = $markup->invoke($admin, 3, ['fields' => ['a' => '1', 'b' => '2']]);

    expect($html)->toContain('<input type="hidden" name="a" value="1" /><input type="hidden" name="b" value="2" />')
        ->and($world->nonceFields)->toBe([['uams_c2s_batch', '_wpnonce', true, false]]);
});
