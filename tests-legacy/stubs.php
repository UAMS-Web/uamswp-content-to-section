<?php

declare(strict_types=1);
/**
 * Just enough WordPress to load and exercise UAMS_C2S_Converter outside a site.
 *
 * The stubs deliberately reproduce the two core behaviours this plugin has to
 * work around: wp_update_post() and add_post_meta() both run wp_unslash() over
 * what they are handed.
 */
define('ABSPATH', __DIR__.'/');
define('UAMS_C2S_VERSION', 'test');

/**
 * Minimal WP_Error.
 */
class WP_Error
{
    /**
     * Error code.
     *
     * @var string
     */
    public $code;

    /**
     * Error message.
     *
     * @var string
     */
    public $message;

    /**
     * Constructor.
     *
     * @param  string  $code  Error code.
     * @param  string  $message  Error message.
     */
    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    /**
     * Error message accessor.
     *
     * @return string
     */
    public function get_error_message()
    {
        return $this->message;
    }

    /**
     * Error code accessor.
     *
     * @return string
     */
    public function get_error_code()
    {
        return $this->code;
    }
}

/**
 * Minimal WP_Post.
 */
class WP_Post
{
    public $ID = 0;

    public $post_type = 'page';

    public $post_parent = 0;

    public $post_content = '';

    public $post_date_gmt = '';

    public $post_name = '';

    /**
     * Constructor.
     *
     * @param  array  $props  Property overrides.
     */
    public function __construct(array $props = [])
    {
        foreach ($props as $key => $value) {
            $this->$key = $value;
        }
    }
}

$GLOBALS['uams_c2s_test_posts'] = [];
$GLOBALS['uams_c2s_test_meta'] = [];

/**
 * Clear the fake site between cases.
 */
function uams_c2s_test_reset()
{
    $GLOBALS['uams_c2s_test_posts'] = [];
    $GLOBALS['uams_c2s_test_meta'] = [];
}

/**
 * Add a post or revision to the fake site.
 *
 * @param  array  $props  WP_Post properties.
 * @return WP_Post
 */
function uams_c2s_test_post(array $props)
{
    $post = new WP_Post($props);

    $GLOBALS['uams_c2s_test_posts'][$post->ID] = $post;

    return $post;
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function get_post($post)
{
    $id = $post instanceof WP_Post ? $post->ID : (int) $post;

    return isset($GLOBALS['uams_c2s_test_posts'][$id]) ? $GLOBALS['uams_c2s_test_posts'][$id] : null;
}

function get_post_meta($id, $key, $single = false)
{
    return isset($GLOBALS['uams_c2s_test_meta'][$id][$key]) ? $GLOBALS['uams_c2s_test_meta'][$id][$key] : '';
}

function metadata_exists($type, $id, $key)
{
    return isset($GLOBALS['uams_c2s_test_meta'][$id][$key]);
}

function add_post_meta($id, $key, $value, $unique = false)
{
    // Real WP writes nothing and returns false when $unique is set and a row
    // already exists. convert_post() depends on that to avoid replacing a good
    // backup with a damaged one, so the stub has to refuse too.
    if ($unique && metadata_exists('post', $id, $key)) {
        return false;
    }

    // Core unslashes meta values on the way in.
    $GLOBALS['uams_c2s_test_meta'][$id][$key] = is_string($value) ? wp_unslash($value) : $value;

    return true;
}

function update_post_meta($id, $key, $value)
{
    $GLOBALS['uams_c2s_test_meta'][$id][$key] = is_string($value) ? wp_unslash($value) : $value;

    return true;
}

function delete_post_meta($id, $key)
{
    unset($GLOBALS['uams_c2s_test_meta'][$id][$key]);

    return true;
}

function wp_slash($value)
{
    return addslashes($value);
}

function wp_unslash($value)
{
    return stripslashes($value);
}

function wp_update_post($postarr, $wp_error = false)
{
    $id = (int) $postarr['ID'];

    if (! isset($GLOBALS['uams_c2s_test_posts'][$id])) {
        return new WP_Error('invalid_post', 'No post.');
    }

    // Core unslashes everything it is handed.
    $GLOBALS['uams_c2s_test_posts'][$id]->post_content = wp_unslash($postarr['post_content']);

    return $id;
}

function wp_get_post_revisions($id, $args = [])
{
    $out = [];

    foreach ($GLOBALS['uams_c2s_test_posts'] as $post) {
        if ($post->post_type === 'revision' && (int) $post->post_parent === (int) $id) {
            $out[$post->ID] = $post;
        }
    }

    krsort($out); // Newest first, matching the DESC order the caller asks for.

    return $out;
}

function wp_is_post_autosave($post)
{
    return strpos($post->post_name, '-autosave') !== false;
}

function current_user_can($capability)
{
    return true;
}

function has_filter($tag, $callback = false)
{
    return false;
}

function kses_remove_filters() {}
function kses_init_filters() {}

function get_current_user_id()
{
    return 1;
}

function __($text, $domain = '')
{
    return $text;
}

function apply_filters($tag, $value)
{
    return $value;
}

function wp_parse_args($args, $defaults)
{
    return array_merge($defaults, (array) $args);
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

/*
 * Enough of the formatting API to load UAMS_C2S_HTML_To_Blocks. wpautop() and
 * shortcode_unautop() are identity here, which is faithful only for input that
 * is already block-level -- the paragraph-materialising behaviour the real
 * functions provide is not what these cases exercise.
 */

function wpautop($text, $br = true)
{
    return $text;
}

function shortcode_unautop($text)
{
    return $text;
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function wp_strip_all_tags($text, $remove_breaks = false)
{
    return strip_tags((string) $text);
}

function uams_c2s_default_args()
{
    return [
        'include_geo' => false,
        'demote_headings' => false,
        'max_heading' => 2,
        'list_format' => 'v3',
    ];
}

require_once dirname(__DIR__).'/includes/class-converter.php';
require_once dirname(__DIR__).'/includes/class-scanner.php';
require_once dirname(__DIR__).'/includes/class-html-to-blocks.php';

/**
 * Reaches the converter's protected restore path.
 */
class UAMS_C2S_Test_Converter extends UAMS_C2S_Converter
{
    /**
     * Public wrapper around restore_source().
     *
     * @param  int  $post_id  Post ID.
     * @return array|WP_Error
     */
    public function source($post_id)
    {
        return $this->restore_source(get_post($post_id));
    }

    /**
     * Public wrapper around write_content().
     *
     * @param  int  $post_id  Post ID.
     * @param  string  $content  Unslashed content.
     * @return int|WP_Error
     */
    public function write($post_id, $content)
    {
        return $this->write_content($post_id, $content);
    }

    /**
     * Canned convert_content() result, or null to fail loudly if reached.
     *
     * @var array|null
     */
    public $canned = null;

    /**
     * Stand in for the block parser so the post-level lifecycle can be driven.
     *
     * stubs.php defines no parse_blocks()/serialize_blocks(), so the real
     * convert_content() cannot run here. Overriding it at this seam lets
     * convert_post(), revert_post() and repair_post() execute for real -- which
     * is what actually exercises the backup write and the conversion record.
     * This deliberately does NOT test the block parser.
     *
     * @param  string  $content  Post content.
     */
    public function convert_content($content): array
    {
        if ($this->canned === null) {
            throw new RuntimeException('convert_content() reached with no canned result set.');
        }

        return array_merge(
            [
                'content' => $content,
                'converted' => 1,
                'skipped' => 0,
                'notices' => [],
            ],
            $this->canned
        );
    }
}

/* -------------------------------------------------------------------------
 * Assertions
 * ---------------------------------------------------------------------- */

$GLOBALS['uams_c2s_test_failures'] = 0;

/**
 * Assert and report.
 *
 * @param  string  $label  What is being checked.
 * @param  mixed  $actual  Value under test.
 * @param  mixed  $expected  Expected value.
 */
function uams_c2s_test_is($label, $actual, $expected)
{
    $pass = $actual === $expected;

    if (! $pass) {
        $GLOBALS['uams_c2s_test_failures']++;
    }

    printf(
        "%s  %-52s %s\n",
        $pass ? 'ok  ' : 'FAIL',
        $label,
        $pass ? '' : 'expected '.var_export($expected, true).', got '.var_export($actual, true)
    );
}

/**
 * Print the tally and set the exit code.
 */
function uams_c2s_test_done()
{
    $failures = $GLOBALS['uams_c2s_test_failures'];

    echo "\n".($failures ? sprintf("%d failure(s)\n", $failures) : "all green\n");

    exit($failures ? 1 : 0);
}
