<?php

/**
 * Pins how the Content block's WYSIWYG HTML becomes blocks.
 *
 * The converter reads a real DOMDocument, so the tests feed it real HTML and read
 * the blocks back. Only the WordPress helpers it calls are replaced: the two
 * paragraph filters by a pair of markers that show each ran, in order; the
 * escapers by their plain PHP equivalents; and the attachment lookup by a fixed
 * map. A block is printed as one line (name, attributes, inner content), so each
 * expectation reads as the markup the editor would receive.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

use function Brain\Monkey\Functions\when;

covers(UAMS_C2S_HTML_To_Blocks::class);

/** Attachment ids the media lookup knows, by URL. */
const UAMS_C2S_H2B_ATTACHMENTS = [
    'https://e.test/known.jpg' => 77,
];

function uams_c2s_h2b_world(): void
{
    Functions\stubTranslationFunctions();

    when('wp_parse_args')->alias(static fn ($args, $defaults = []): array => array_merge($defaults, $args));
    when('apply_filters')->alias(static fn ($hook, $value) => $value);
    when('wpautop')->alias(static fn ($html): string => str_replace('AAA', 'BBB', $html));
    when('shortcode_unautop')->alias(static fn ($html): string => str_replace('BBB', 'CCC', $html));
    when('wp_strip_all_tags')->alias(static fn ($html): string => strip_tags($html));
    when('esc_attr')->alias(static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES));
    when('esc_url')->alias(static fn (string $url): string => $url);
    when('wp_parse_url')->alias(static fn ($url, $component = -1) => parse_url($url, $component));
    when('attachment_url_to_postid')->alias(static fn ($url): int => UAMS_C2S_H2B_ATTACHMENTS[$url] ?? 0);
}

/**
 * Convert and return the printed blocks and the notices.
 *
 * @param  array<string,mixed>  $args
 * @return array{blocks: list<string>, notices: list<string>, raw: array}
 */
function uams_c2s_h2b(string $html, array $args = []): array
{
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks($args);
    $blocks = $converter->convert($html);

    return ['blocks' => uams_c2s_h2b_lines($blocks), 'notices' => $converter->get_notices(), 'raw' => $blocks];
}

/**
 * One line per block, children indented under their parent.
 *
 * @param  array[]  $blocks
 * @return list<string>
 */
function uams_c2s_h2b_lines(array $blocks, int $depth = 0): array
{
    $lines = [];
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    foreach ($blocks as $block) {
        $lines[] = str_repeat('  ', $depth).$block['blockName'].' '.json_encode($block['attrs'], $flags).' '.json_encode($block['innerContent'], $flags);

        foreach (uams_c2s_h2b_lines($block['innerBlocks'], $depth + 1) as $child) {
            $lines[] = $child;
        }
    }

    return $lines;
}

/**
 * Every block in a tree, flattened.
 *
 * @param  array[]  $blocks
 * @return list<array>
 */
function uams_c2s_h2b_flatten(array $blocks): array
{
    $flat = [];

    foreach ($blocks as $block) {
        $flat[] = $block;

        foreach (uams_c2s_h2b_flatten($block['innerBlocks']) as $child) {
            $flat[] = $child;
        }
    }

    return $flat;
}
dataset('uams_c2s_h2b_cases', [
    'empty string' => [
        '',
        [],
        '',
    ],
    'whitespace only' => [
        "  \n\t ",
        [],
        '',
    ],
    'marker through wpautop then unautop' => [
        '<p>AAA</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>CCC</p>\n"]
        TXT,
    ],
    'text node paragraph' => [
        'just text',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>just text</p>\n"]
        TXT,
    ],
    'text node shortcode' => [
        '[gallery ids="1,2"]',
        [],
        <<<'TXT'
        core/shortcode [] ["\n[gallery ids=\"1,2\"]\n"]
        TXT,
    ],
    'text node with markup' => [
        'hello <em>world</em>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>hello</p>\n"]
        core/html [] ["\n<em>world</em>\n"]
        note: Unrecognised <em> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'comment is dropped' => [
        '<!-- note --><p>kept</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>kept</p>\n"]
        TXT,
    ],
    'br at top level' => [
        '<br><p>kept</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>kept</p>\n"]
        TXT,
    ],
    'opaque script' => [
        '<script>x</script>',
        [],
        <<<'TXT'
        core/html [] ["\n<script>x</script>\n"]
        note: <script> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'opaque style' => [
        '<style>x</style>',
        [],
        <<<'TXT'
        core/html [] ["\n<style>x</style>\n"]
        note: <style> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'opaque form' => [
        '<form>x</form>',
        [],
        <<<'TXT'
        core/html [] ["\n<form>x</form>\n"]
        note: <form> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'opaque object' => [
        '<object>x</object>',
        [],
        <<<'TXT'
        core/html [] ["\n<object>x</object>\n"]
        note: <object> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'opaque video' => [
        '<video>x</video>',
        [],
        <<<'TXT'
        core/html [] ["\n<video>x</video>\n"]
        note: <video> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'opaque audio' => [
        '<audio>x</audio>',
        [],
        <<<'TXT'
        core/html [] ["\n<audio>x</audio>\n"]
        note: <audio> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'opaque noscript' => [
        '<noscript>x</noscript>',
        [],
        <<<'TXT'
        core/html [] ["\n<noscript>x</noscript>\n"]
        note: <noscript> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'wrapper div' => [
        '<div class="a">x</div>',
        [],
        <<<'TXT'
        core/html [] ["\n<div class=\"a\">x</div>\n"]
        note: A <div> wrapper was preserved as a Custom HTML block.
        TXT,
    ],
    'wrapper section' => [
        '<section class="a">x</section>',
        [],
        <<<'TXT'
        core/html [] ["\n<section class=\"a\">x</section>\n"]
        note: A <section> wrapper was preserved as a Custom HTML block.
        TXT,
    ],
    'wrapper article' => [
        '<article class="a">x</article>',
        [],
        <<<'TXT'
        core/html [] ["\n<article class=\"a\">x</article>\n"]
        note: A <article> wrapper was preserved as a Custom HTML block.
        TXT,
    ],
    'wrapper aside' => [
        '<aside class="a">x</aside>',
        [],
        <<<'TXT'
        core/html [] ["\n<aside class=\"a\">x</aside>\n"]
        note: A <aside> wrapper was preserved as a Custom HTML block.
        TXT,
    ],
    'wrapper span' => [
        '<span class="a">x</span>',
        [],
        <<<'TXT'
        core/html [] ["\n<span class=\"a\">x</span>\n"]
        note: A <span> wrapper was preserved as a Custom HTML block.
        TXT,
    ],
    'unrecognised tag' => [
        '<custom-thing>x</custom-thing>',
        [],
        <<<'TXT'
        core/html [] ["\n<custom-thing>x</custom-thing>\n"]
        note: Unrecognised <custom-thing> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'top-level em' => [
        '<em>x</em>',
        [],
        <<<'TXT'
        core/html [] ["\n<em>x</em>\n"]
        note: Unrecognised <em> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'empty paragraph' => [
        '<p></p>',
        [],
        '',
    ],
    'nbsp paragraph' => [
        '<p>&nbsp;</p>',
        [],
        '',
    ],
    'br paragraph' => [
        '<p><br></p>',
        [],
        '',
    ],
    'br and nbsp paragraph' => [
        '<p><br/> &nbsp; <br></p>',
        [],
        '',
    ],
    'empty inline element paragraph' => [
        '<p><b></b></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p><b></b></p>\n"]
        TXT,
    ],
    'zero width paragraph' => [
        "<p>\u{200b}</p>",
        [],
        '',
    ],
    'paragraph with script' => [
        '<p>a <script>x</script> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <script>x</script> b\n"]
        note: <script> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with style' => [
        '<p>a <style>x</style> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <style>x</style> b\n"]
        note: <style> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with form' => [
        '<p>a <form>x</form> b</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>a</p>\n"]
        core/html [] ["\n<form>x</form>\n"]
        core/paragraph [] ["\n<p>b</p>\n"]
        note: <form> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with object' => [
        '<p>a <object>x</object> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <object>x</object> b\n"]
        note: <object> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with video' => [
        '<p>a <video>x</video> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <video>x</video> b\n"]
        note: <video> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with audio' => [
        '<p>a <audio>x</audio> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <audio>x</audio> b\n"]
        note: <audio> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with embed' => [
        '<p>a <embed>x</embed> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <embed>x b\n"]
        note: <embed> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with canvas' => [
        '<p>a <canvas>x</canvas> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <canvas>x</canvas> b\n"]
        note: <canvas> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with svg' => [
        '<p>a <svg>x</svg> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <svg>x</svg> b\n"]
        note: <svg> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'paragraph with noscript' => [
        '<p>a <noscript>x</noscript> b</p>',
        [],
        <<<'TXT'
        core/html [] ["\na <noscript>x</noscript> b\n"]
        note: <noscript> markup was preserved as a Custom HTML block.
        TXT,
    ],
    'shortcode paragraph' => [
        '<p>[button url="x"]</p>',
        [],
        <<<'TXT'
        core/shortcode [] ["\n[button url=\"x\"]\n"]
        TXT,
    ],
    'shortcode with text paragraph' => [
        '<p>see [button] here</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>see [button] here</p>\n"]
        TXT,
    ],
    'url paragraph youtube' => [
        '<p>https://www.youtube.com/watch?v=abc123</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.youtube.com/watch?v=abc123","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc123\n</div></figure>\n"]
        TXT,
    ],
    'url paragraph unknown host' => [
        '<p>http://example.com/a?b=1&amp;c=2</p>',
        [],
        <<<'TXT'
        core/embed {"url":"http://example.com/a?b=1&c=2","type":"rich"} ["\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttp://example.com/a?b=1&c=2\n</div></figure>\n"]
        note: An embed for "example.com" is not a block-editor provider; confirm it renders.
        TXT,
    ],
    'url paragraph httpv' => [
        '<p>httpv://www.youtube.com/watch?v=abc</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>httpv://www.youtube.com/watch?v=abc</p>\n"]
        note: A "httpv://" YouTube Lyte URL was kept as plain text -- confirm it still lazy-loads.
        TXT,
    ],
    'url paragraph uppercase httpv' => [
        '<p>HTTPV://youtu.be/abc</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>HTTPV://youtu.be/abc</p>\n"]
        note: A "httpv://" YouTube Lyte URL was kept as plain text -- confirm it still lazy-loads.
        TXT,
    ],
    'url paragraph with spaces' => [
        '<p>https://example.com/a b</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>https://example.com/a b</p>\n"]
        TXT,
    ],
    'url inside em' => [
        '<p><em>https://example.com/a</em></p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://example.com/a","type":"rich"} ["\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://example.com/a\n</div></figure>\n"]
        note: An embed for "example.com" is not a block-editor provider; confirm it renders.
        TXT,
    ],
    'single image paragraph' => [
        '<p><img src="https://e.test/a.jpg" alt="A"></p>',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'single iframe paragraph youtube' => [
        '<p><iframe src="https://www.youtube.com/embed/abc"></iframe></p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc\n</div></figure>\n"]
        TXT,
    ],
    'linked image paragraph' => [
        '<p><a href="https://e.test/x"><img src="https://e.test/a.jpg" alt="A"></a></p>',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"custom"} ["\n<figure class=\"wp-block-image size-full\"><a href=\"https://e.test/x\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></a></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'link with text paragraph' => [
        '<p><a href="https://e.test/x">text</a></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p><a href=\"https://e.test/x\">text</a></p>\n"]
        TXT,
    ],
    'link with image and text' => [
        '<p><a href="https://e.test/x"><img src="https://e.test/a.jpg" alt="A"> caption</a></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p><a href=\"https://e.test/x\"><img src=\"https://e.test/a.jpg\" alt=\"A\"> caption</a></p>\n"]
        TXT,
    ],
    'link with two images' => [
        '<p><a href="https://e.test/x"><img src="https://e.test/a.jpg" alt="A"><img src="https://e.test/a.jpg" alt="A"></a></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p><a href=\"https://e.test/x\"><img src=\"https://e.test/a.jpg\" alt=\"A\"><img src=\"https://e.test/a.jpg\" alt=\"A\"></a></p>\n"]
        TXT,
    ],
    'link with one span child' => [
        '<p><a href="https://e.test/x"><span>t</span></a></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p><a href=\"https://e.test/x\"><span>t</span></a></p>\n"]
        TXT,
    ],
    'single span paragraph' => [
        '<p><span>t</span></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p><span>t</span></p>\n"]
        TXT,
    ],
    'image and text paragraph' => [
        '<p>look <img src="https://e.test/a.jpg" alt="A"></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>look <img src=\"https://e.test/a.jpg\" alt=\"A\"></p>\n"]
        TXT,
    ],
    'plain paragraph' => [
        '<p>Hello <strong>there</strong></p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>Hello <strong>there</strong></p>\n"]
        TXT,
    ],
    'paragraph text-center' => [
        '<p class="text-center">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"center"} ["\n<p class=\"has-text-align-center\">x</p>\n"]
        TXT,
    ],
    'paragraph text-right' => [
        '<p class="text-right">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"right"} ["\n<p class=\"has-text-align-right\">x</p>\n"]
        TXT,
    ],
    'paragraph text-left' => [
        '<p class="text-left">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"left"} ["\n<p class=\"has-text-align-left\">x</p>\n"]
        TXT,
    ],
    'paragraph has-text-align-center' => [
        '<p class="has-text-align-center">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"center"} ["\n<p class=\"has-text-align-center\">x</p>\n"]
        TXT,
    ],
    'paragraph has-text-align-right' => [
        '<p class="has-text-align-right">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"right"} ["\n<p class=\"has-text-align-right\">x</p>\n"]
        TXT,
    ],
    'paragraph has-text-align-left' => [
        '<p class="has-text-align-left">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"left"} ["\n<p class=\"has-text-align-left\">x</p>\n"]
        TXT,
    ],
    'paragraph extra classes' => [
        '<p class="lead  big">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"className":"lead big"} ["\n<p class=\"lead big\">x</p>\n"]
        TXT,
    ],
    'paragraph extra and align' => [
        '<p class="lead text-center">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"center","className":"lead"} ["\n<p class=\"lead has-text-align-center\">x</p>\n"]
        TXT,
    ],
    'paragraph both align forms' => [
        '<p class="text-center has-text-align-center">x</p>',
        [],
        <<<'TXT'
        core/paragraph {"align":"center"} ["\n<p class=\"has-text-align-center\">x</p>\n"]
        TXT,
    ],
    'paragraph entity text' => [
        '<p>a &amp; b &lt; c</p>',
        [],
        <<<'TXT'
        core/paragraph [] ["\n<p>a &amp; b &lt; c</p>\n"]
        TXT,
    ],
    'heading h1' => [
        '<h1>T</h1>',
        [],
        <<<'TXT'
        core/heading {"level":1} ["\n<h1 class=\"wp-block-heading\">T</h1>\n"]
        note: An <h1> sits inside the section, which already renders an <h2>. Consider the "demote headings" option.
        TXT,
    ],
    'heading h2' => [
        '<h2>T</h2>',
        [],
        <<<'TXT'
        core/heading [] ["\n<h2 class=\"wp-block-heading\">T</h2>\n"]
        note: An <h2> sits inside the section, which already renders an <h2>. Consider the "demote headings" option.
        TXT,
    ],
    'heading h3' => [
        '<h3>T</h3>',
        [],
        <<<'TXT'
        core/heading {"level":3} ["\n<h3 class=\"wp-block-heading\">T</h3>\n"]
        TXT,
    ],
    'heading h4' => [
        '<h4>T</h4>',
        [],
        <<<'TXT'
        core/heading {"level":4} ["\n<h4 class=\"wp-block-heading\">T</h4>\n"]
        TXT,
    ],
    'heading h5' => [
        '<h5>T</h5>',
        [],
        <<<'TXT'
        core/heading {"level":5} ["\n<h5 class=\"wp-block-heading\">T</h5>\n"]
        TXT,
    ],
    'heading h6' => [
        '<h6>T</h6>',
        [],
        <<<'TXT'
        core/heading {"level":6} ["\n<h6 class=\"wp-block-heading\">T</h6>\n"]
        TXT,
    ],
    'empty heading' => [
        '<h2> </h2>',
        [],
        '',
    ],
    'heading demoted h1' => [
        '<h1>T</h1>',
        ['demote_headings' => true],
        <<<'TXT'
        core/heading {"level":3} ["\n<h3 class=\"wp-block-heading\">T</h3>\n"]
        TXT,
    ],
    'heading demoted h2' => [
        '<h2>T</h2>',
        ['demote_headings' => true],
        <<<'TXT'
        core/heading {"level":3} ["\n<h3 class=\"wp-block-heading\">T</h3>\n"]
        TXT,
    ],
    'heading not demoted h3' => [
        '<h3>T</h3>',
        ['demote_headings' => true],
        <<<'TXT'
        core/heading {"level":3} ["\n<h3 class=\"wp-block-heading\">T</h3>\n"]
        TXT,
    ],
    'heading demote clamps at six' => [
        '<h6>T</h6>',
        ['demote_headings' => true, 'max_heading' => 6],
        <<<'TXT'
        core/heading {"level":6} ["\n<h6 class=\"wp-block-heading\">T</h6>\n"]
        TXT,
    ],
    'heading demote max 3' => [
        '<h3>T</h3>',
        ['demote_headings' => true, 'max_heading' => 3],
        <<<'TXT'
        core/heading {"level":4} ["\n<h4 class=\"wp-block-heading\">T</h4>\n"]
        TXT,
    ],
    'heading note with max 3' => [
        '<h3>T</h3>',
        ['max_heading' => 3],
        <<<'TXT'
        core/heading {"level":3} ["\n<h3 class=\"wp-block-heading\">T</h3>\n"]
        note: An <h3> sits inside the section, which already renders an <h3>. Consider the "demote headings" option.
        TXT,
    ],
    'heading string max' => [
        '<h2>T</h2>',
        ['max_heading' => '1'],
        <<<'TXT'
        core/heading [] ["\n<h2 class=\"wp-block-heading\">T</h2>\n"]
        TXT,
    ],
    'heading anchor' => [
        '<h3 id="top">T</h3>',
        [],
        <<<'TXT'
        core/heading {"level":3,"anchor":"top"} ["\n<h3 class=\"wp-block-heading\" id=\"top\">T</h3>\n"]
        TXT,
    ],
    'heading anchor needs escaping' => [
        '<h3 id=\'a"b\'>T</h3>',
        [],
        <<<'TXT'
        core/heading {"level":3,"anchor":"a\"b"} ["\n<h3 class=\"wp-block-heading\" id=\"a&quot;b\">T</h3>\n"]
        TXT,
    ],
    'heading align center' => [
        '<h3 class="text-center">T</h3>',
        [],
        <<<'TXT'
        core/heading {"level":3,"textAlign":"center"} ["\n<h3 class=\"wp-block-heading has-text-align-center\">T</h3>\n"]
        TXT,
    ],
    'heading align right' => [
        '<h3 class="has-text-align-right">T</h3>',
        [],
        <<<'TXT'
        core/heading {"level":3,"textAlign":"right"} ["\n<h3 class=\"wp-block-heading has-text-align-right\">T</h3>\n"]
        TXT,
    ],
    'heading inline markup' => [
        '<h3>A <em>b</em></h3>',
        [],
        <<<'TXT'
        core/heading {"level":3} ["\n<h3 class=\"wp-block-heading\">A <em>b</em></h3>\n"]
        TXT,
    ],
    'ul' => [
        '<ul><li>a</li><li>b</li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,null,"</ul>\n"]
          core/list-item [] ["<li>a</li>"]
          core/list-item [] ["<li>b</li>"]
        TXT,
    ],
    'ol' => [
        '<ol><li>a</li></ol>',
        [],
        <<<'TXT'
        core/list {"ordered":true} ["\n<ol class=\"wp-block-list\">",null,"</ol>\n"]
          core/list-item [] ["<li>a</li>"]
        TXT,
    ],
    'ol start 3' => [
        '<ol start="3"><li>a</li></ol>',
        [],
        <<<'TXT'
        core/list {"ordered":true,"start":3} ["\n<ol class=\"wp-block-list\" start=\"3\">",null,"</ol>\n"]
          core/list-item [] ["<li>a</li>"]
        TXT,
    ],
    'ol start 0' => [
        '<ol start="0"><li>a</li></ol>',
        [],
        <<<'TXT'
        core/list {"ordered":true} ["\n<ol class=\"wp-block-list\">",null,"</ol>\n"]
          core/list-item [] ["<li>a</li>"]
        TXT,
    ],
    'ul start ignored' => [
        '<ul start="3"><li>a</li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li>a</li>"]
        TXT,
    ],
    'ol start needs escaping' => [
        '<ol start=\'3"x\'><li>a</li></ol>',
        [],
        <<<'TXT'
        core/list {"ordered":true,"start":3} ["\n<ol class=\"wp-block-list\" start=\"3&quot;x\">",null,"</ol>\n"]
          core/list-item [] ["<li>a</li>"]
        TXT,
    ],
    'empty ul' => [
        '<ul></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul></ul>\n"]
        TXT,
    ],
    'ul without li' => [
        '<ul>text</ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul>text</ul>\n"]
        TXT,
    ],
    'ul with stray text' => [
        '<ul>x<li>a</li> <li>b</li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,null,"</ul>\n"]
          core/list-item [] ["<li>a</li>"]
          core/list-item [] ["<li>b</li>"]
        TXT,
    ],
    'nested list' => [
        '<ul><li>a<ul><li>b</li></ul></li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li>a",null,"</li>"]
            core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
              core/list-item [] ["<li>b</li>"]
        TXT,
    ],
    'nested ordered with text after' => [
        '<ul><li>a<ol><li>b</li></ol> tail</li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li>a tail",null,"</li>"]
            core/list {"ordered":true} ["\n<ol class=\"wp-block-list\">",null,"</ol>\n"]
              core/list-item [] ["<li>b</li>"]
        TXT,
    ],
    'list item only nested' => [
        '<ul><li><ul><li>b</li></ul></li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li>",null,"</li>"]
            core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
              core/list-item [] ["<li>b</li>"]
        TXT,
    ],
    'list item markup' => [
        '<ul><li><strong>a</strong> b</li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li><strong>a</strong> b</li>"]
        TXT,
    ],
    'empty list item' => [
        '<ul><li></li></ul>',
        [],
        <<<'TXT'
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li></li>"]
        TXT,
    ],
    'list v2' => [
        '<ul><li>a</li></ul>',
        ['list_format' => 'v2'],
        <<<'TXT'
        core/list [] ["\n<ul><li>a</li></ul>\n"]
        TXT,
    ],
    'ol v2' => [
        '<ol><li>a</li></ol>',
        ['list_format' => 'v2'],
        <<<'TXT'
        core/list {"ordered":true} ["\n<ol><li>a</li></ol>\n"]
        TXT,
    ],
    'quote with paragraph' => [
        '<blockquote><p>q</p></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"</blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
        TXT,
    ],
    'quote with cite' => [
        '<blockquote><p>q</p><cite> Who </cite></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"<cite>Who</cite></blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
        TXT,
    ],
    'quote with wrapped cite' => [
        '<blockquote><p>q</p><p><cite>Who</cite></p></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"<cite>Who</cite></blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
        TXT,
    ],
    'quote cite with text beside' => [
        '<blockquote><p>q</p><p><cite>Who</cite> said</p></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,null,"</blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
          core/paragraph [] ["\n<p><cite>Who</cite> said</p>\n"]
        TXT,
    ],
    'quote cite paragraph with comment' => [
        '<blockquote><p>q</p><p><!-- x --><cite>Who</cite></p></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"<cite>Who</cite></blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
        TXT,
    ],
    'quote cite paragraph with two cites' => [
        '<blockquote><p>q</p><p><cite>A</cite><cite>B</cite></p></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,null,"</blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
          core/paragraph [] ["\n<p><cite>A</cite><cite>B</cite></p>\n"]
        TXT,
    ],
    'quote cite paragraph with em' => [
        '<blockquote><p>q</p><p><em>Who</em></p></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,null,"</blockquote>\n"]
          core/paragraph [] ["\n<p>q</p>\n"]
          core/paragraph [] ["\n<p><em>Who</em></p>\n"]
        TXT,
    ],
    'quote with text node' => [
        '<blockquote>bare</blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"</blockquote>\n"]
          core/paragraph [] ["\n<p>bare</p>\n"]
        TXT,
    ],
    'empty quote' => [
        '<blockquote></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"</blockquote>\n"]
          core/paragraph [] ["\n<p></p>\n"]
        TXT,
    ],
    'only cite quote' => [
        '<blockquote><cite>Who</cite></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"<cite>Who</cite></blockquote>\n"]
          core/paragraph [] ["\n<p></p>\n"]
        TXT,
    ],
    'quote with list' => [
        '<blockquote><ul><li>a</li></ul></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"</blockquote>\n"]
          core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
            core/list-item [] ["<li>a</li>"]
        TXT,
    ],
    'last cite wins' => [
        '<blockquote><cite>A</cite><cite>B</cite></blockquote>',
        [],
        <<<'TXT'
        core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"<cite>B</cite></blockquote>\n"]
          core/paragraph [] ["\n<p></p>\n"]
        TXT,
    ],
    'table' => [
        '<table><tr><td>a</td></tr></table>',
        [],
        <<<'TXT'
        core/table [] ["\n<figure class=\"wp-block-table\"><table><tr><td>a</td></tr></table></figure>\n"]
        TXT,
    ],
    'table stripes' => [
        '<table class="x is-style-stripes"><tr><td>a</td></tr></table>',
        [],
        <<<'TXT'
        core/table {"className":"is-style-stripes"} ["\n<figure class=\"wp-block-table\"><table class=\"x is-style-stripes\"><tr><td>a</td></tr></table></figure>\n"]
        TXT,
    ],
    'table other class' => [
        '<table class="x"><tr><td>a</td></tr></table>',
        [],
        <<<'TXT'
        core/table [] ["\n<figure class=\"wp-block-table\"><table class=\"x\"><tr><td>a</td></tr></table></figure>\n"]
        TXT,
    ],
    'figure image' => [
        '<figure><img src="https://e.test/a.jpg" alt="A"></figure>',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'figure image link caption' => [
        '<figure class="alignright size-medium"><a href="https://e.test/x"><img src="https://e.test/a.jpg" alt="A"></a><figcaption> Cap <b>b</b> </figcaption></figure>',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"medium","align":"right","linkDestination":"custom"} ["\n<figure class=\"wp-block-image alignright size-medium\"><a href=\"https://e.test/x\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></a><figcaption class=\"wp-element-caption\">Cap <b>b</b></figcaption></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'figure image parent not link' => [
        '<figure><span><img src="https://e.test/a.jpg" alt="A"></span></figure>',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'figure empty caption' => [
        '<figure><img src="https://e.test/a.jpg" alt="A"><figcaption> </figcaption></figure>',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'figure iframe youtube' => [
        '<figure><iframe src="https://www.youtube.com/embed/abc"></iframe></figure>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc\n</div></figure>\n"]
        TXT,
    ],
    'figure iframe unknown' => [
        '<figure><iframe src="https://e.test/x"></iframe></figure>',
        [],
        <<<'TXT'
        core/html [] ["\n<iframe src=\"https://e.test/x\"></iframe>\n"]
        note: An <iframe> from an unrecognised provider was preserved as a Custom HTML block.
        TXT,
    ],
    'figure neither' => [
        '<figure><p>x</p></figure>',
        [],
        <<<'TXT'
        core/html [] ["\n<figure><p>x</p></figure>\n"]
        note: A <figure> with no image or iframe was preserved as a Custom HTML block.
        TXT,
    ],
    'image wp-image class' => [
        '<img class="wp-image-42" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":42,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-42\"/></figure>\n"]
        TXT,
    ],
    'image wp-image not exact' => [
        '<img class="wp-image-42x size-thumb" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"thumb","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-thumb\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'image wp-image first match' => [
        '<img class="wp-image-5 wp-image-6" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":5,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-5\"/></figure>\n"]
        TXT,
    ],
    'image wp-image zero' => [
        '<img class="wp-image-0" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'image size class' => [
        '<img class="size-large wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"large","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-large\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image size class uppercase' => [
        '<img class="size-Large_2-x wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"Large_2-x","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-Large_2-x\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image size class not whole' => [
        '<img class="size-big! wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image size first match' => [
        '<img class="size-a size-b wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"a","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-a\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image align left' => [
        '<img class="alignleft wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","align":"left","linkDestination":"none"} ["\n<figure class=\"wp-block-image alignleft size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image align right' => [
        '<img class="alignright wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","align":"right","linkDestination":"none"} ["\n<figure class=\"wp-block-image alignright size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image align center' => [
        '<img class="aligncenter wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","align":"center","linkDestination":"none"} ["\n<figure class=\"wp-block-image aligncenter size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image align none ignored' => [
        '<img class="alignnone wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image align first match' => [
        '<img class="alignleft aligncenter wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","align":"left","linkDestination":"none"} ["\n<figure class=\"wp-block-image alignleft size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image align prefix not matched' => [
        '<img class="xalignleft wp-image-1" src="https://e.test/a.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image no alt' => [
        '<img class="wp-image-1" src="https://e.test/a.jpg">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"\" class=\"wp-image-1\"/></figure>\n"]
        note: An image has no alt text. Add one before publishing.
        TXT,
    ],
    'image width and height' => [
        '<img class="wp-image-1" src="https://e.test/a.jpg" alt="A" width="10" height="20">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\" width=\"10\" height=\"20\"/></figure>\n"]
        TXT,
    ],
    'image width only' => [
        '<img class="wp-image-1" src="https://e.test/a.jpg" alt="A" width="10">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image height only' => [
        '<img class="wp-image-1" src="https://e.test/a.jpg" alt="A" height="20">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image attachment lookup hit' => [
        '<img src="https://e.test/known.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":77,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/known.jpg\" alt=\"A\" class=\"wp-image-77\"/></figure>\n"]
        TXT,
    ],
    'image attachment lookup via full size' => [
        '<img src="https://e.test/known-300x200.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"id":77,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/known-300x200.jpg\" alt=\"A\" class=\"wp-image-77\"/></figure>\n"]
        TXT,
    ],
    'image attachment lookup via full size no match' => [
        '<img src="https://e.test/other-300x200.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/other-300x200.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'image attachment lookup miss' => [
        '<img src="https://e.test/unknown.jpg" alt="A">',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/unknown.jpg\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'image no src' => [
        '<img alt="A">',
        [],
        <<<'TXT'
        core/image {"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"\" alt=\"A\"/></figure>\n"]
        note: An image could not be matched to a Media Library item; it was linked by URL instead.
        TXT,
    ],
    'image attributes need escaping' => [
        '<img src="https://e.test/a.jpg?x=1&amp;y=2" alt="a&quot;b" class="wp-image-1">',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg?x=1&y=2\" alt=\"a&quot;b\" class=\"wp-image-1\"/></figure>\n"]
        TXT,
    ],
    'image link needs escaping' => [
        '<p><a href="https://e.test/x?a=1&amp;b=2"><img class="wp-image-1" src="https://e.test/a.jpg" alt="A"></a></p>',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"custom"} ["\n<figure class=\"wp-block-image size-full\"><a href=\"https://e.test/x?a=1&b=2\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/></a></figure>\n"]
        TXT,
    ],
    'figure classes give size and align' => [
        '<figure class="aligncenter size-thumbnail wp-image-9"><img src="https://e.test/a.jpg" alt="A"></figure>',
        [],
        <<<'TXT'
        core/image {"id":9,"sizeSlug":"thumbnail","align":"center","linkDestination":"none"} ["\n<figure class=\"wp-block-image aligncenter size-thumbnail\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-9\"/></figure>\n"]
        TXT,
    ],
    'figure caption' => [
        '<figure><img class="wp-image-1" src="https://e.test/a.jpg" alt="A"><figcaption>Hi</figcaption></figure>',
        [],
        <<<'TXT'
        core/image {"id":1,"sizeSlug":"full","linkDestination":"none"} ["\n<figure class=\"wp-block-image size-full\"><img src=\"https://e.test/a.jpg\" alt=\"A\" class=\"wp-image-1\"/><figcaption class=\"wp-element-caption\">Hi</figcaption></figure>\n"]
        TXT,
    ],
    'iframe youtube' => [
        '<iframe src="https://www.youtube.com/embed/abc_-1"></iframe>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.youtube.com/watch?v=abc_-1","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc_-1\n</div></figure>\n"]
        TXT,
    ],
    'iframe youtube nocookie' => [
        '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc\n</div></figure>\n"]
        TXT,
    ],
    'iframe vimeo' => [
        '<iframe src="https://player.vimeo.com/video/123456"></iframe>',
        [],
        <<<'TXT'
        core/embed {"url":"https://vimeo.com/123456","type":"video","providerNameSlug":"vimeo","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://vimeo.com/123456\n</div></figure>\n"]
        TXT,
    ],
    'iframe uppercase host' => [
        '<iframe src="https://WWW.YOUTUBE.COM/EMBED/abc"></iframe>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.youtube.com/watch?v=abc","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc\n</div></figure>\n"]
        TXT,
    ],
    'iframe unknown' => [
        '<iframe src="https://e.test/x" width="1"></iframe>',
        [],
        <<<'TXT'
        core/html [] ["\n<iframe src=\"https://e.test/x\" width=\"1\"></iframe>\n"]
        note: An <iframe> from an unrecognised provider was preserved as a Custom HTML block.
        TXT,
    ],
    'iframe no src' => [
        '<iframe></iframe>',
        [],
        <<<'TXT'
        core/html [] ["\n<iframe></iframe>\n"]
        note: An <iframe> from an unrecognised provider was preserved as a Custom HTML block.
        TXT,
    ],
    'iframe youtube without id' => [
        '<iframe src="https://www.youtube.com/embed/"></iframe>',
        [],
        <<<'TXT'
        core/html [] ["\n<iframe src=\"https://www.youtube.com/embed/\"></iframe>\n"]
        note: An <iframe> from an unrecognised provider was preserved as a Custom HTML block.
        TXT,
    ],
    'iframe vimeo non numeric' => [
        '<iframe src="https://player.vimeo.com/video/abc"></iframe>',
        [],
        <<<'TXT'
        core/html [] ["\n<iframe src=\"https://player.vimeo.com/video/abc\"></iframe>\n"]
        note: An <iframe> from an unrecognised provider was preserved as a Custom HTML block.
        TXT,
    ],
    'hr' => [
        '<hr>',
        [],
        <<<'TXT'
        core/separator [] ["\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n"]
        TXT,
    ],
    'pre with code' => [
        "<pre><code> a\n b </code></pre>",
        [],
        <<<'TXT'
        core/code [] ["\n<pre class=\"wp-block-code\"><code>a\n b</code></pre>\n"]
        TXT,
    ],
    'pre plain' => [
        "<pre> a\n b </pre>",
        [],
        <<<'TXT'
        core/preformatted [] ["\n<pre class=\"wp-block-preformatted\"> a\n b </pre>\n"]
        TXT,
    ],
    'embed youtube.com' => [
        '<p>https://youtube.com/watch?v=1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://youtube.com/watch?v=1","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtube.com/watch?v=1\n</div></figure>\n"]
        TXT,
    ],
    'embed youtu.be' => [
        '<p>https://youtu.be/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://youtu.be/1","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtu.be/1\n</div></figure>\n"]
        TXT,
    ],
    'embed youtube-nocookie.com' => [
        '<p>https://youtube-nocookie.com/embed/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://youtube-nocookie.com/embed/1","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtube-nocookie.com/embed/1\n</div></figure>\n"]
        TXT,
    ],
    'embed vimeo.com' => [
        '<p>https://vimeo.com/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://vimeo.com/1","type":"video","providerNameSlug":"vimeo","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://vimeo.com/1\n</div></figure>\n"]
        TXT,
    ],
    'embed tiktok.com' => [
        '<p>https://tiktok.com/@a/video/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://tiktok.com/@a/video/1","type":"video","providerNameSlug":"tiktok","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-tiktok wp-block-embed-tiktok wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://tiktok.com/@a/video/1\n</div></figure>\n"]
        TXT,
    ],
    'embed twitter.com' => [
        '<p>https://twitter.com/a/status/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://twitter.com/a/status/1","type":"rich","providerNameSlug":"twitter"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-twitter wp-block-embed-twitter\"><div class=\"wp-block-embed__wrapper\">\nhttps://twitter.com/a/status/1\n</div></figure>\n"]
        TXT,
    ],
    'embed x.com' => [
        '<p>https://x.com/a/status/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://x.com/a/status/1","type":"rich","providerNameSlug":"twitter"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-twitter wp-block-embed-twitter\"><div class=\"wp-block-embed__wrapper\">\nhttps://x.com/a/status/1\n</div></figure>\n"]
        TXT,
    ],
    'embed instagram.com' => [
        '<p>https://instagram.com/p/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://instagram.com/p/1","type":"rich","providerNameSlug":"instagram"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-instagram wp-block-embed-instagram\"><div class=\"wp-block-embed__wrapper\">\nhttps://instagram.com/p/1\n</div></figure>\n"]
        TXT,
    ],
    'embed facebook.com' => [
        '<p>https://facebook.com/a/posts/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://facebook.com/a/posts/1","type":"rich","providerNameSlug":"facebook"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-facebook wp-block-embed-facebook\"><div class=\"wp-block-embed__wrapper\">\nhttps://facebook.com/a/posts/1\n</div></figure>\n"]
        TXT,
    ],
    'embed flickr.com' => [
        '<p>https://flickr.com/photos/a/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://flickr.com/photos/a/1","type":"rich","providerNameSlug":"flickr"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-flickr wp-block-embed-flickr\"><div class=\"wp-block-embed__wrapper\">\nhttps://flickr.com/photos/a/1\n</div></figure>\n"]
        TXT,
    ],
    'embed flic.kr' => [
        '<p>https://flic.kr/p/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://flic.kr/p/1","type":"rich","providerNameSlug":"flickr"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-flickr wp-block-embed-flickr\"><div class=\"wp-block-embed__wrapper\">\nhttps://flic.kr/p/1\n</div></figure>\n"]
        TXT,
    ],
    'embed soundcloud.com' => [
        '<p>https://soundcloud.com/a/b</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://soundcloud.com/a/b","type":"rich","providerNameSlug":"soundcloud"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud\"><div class=\"wp-block-embed__wrapper\">\nhttps://soundcloud.com/a/b\n</div></figure>\n"]
        TXT,
    ],
    'embed spotify.com' => [
        '<p>https://spotify.com/track/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://spotify.com/track/1","type":"rich","providerNameSlug":"spotify"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify\"><div class=\"wp-block-embed__wrapper\">\nhttps://spotify.com/track/1\n</div></figure>\n"]
        TXT,
    ],
    'embed slideshare.net' => [
        '<p>https://slideshare.net/a/b</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://slideshare.net/a/b","type":"rich","providerNameSlug":"slideshare"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-slideshare wp-block-embed-slideshare\"><div class=\"wp-block-embed__wrapper\">\nhttps://slideshare.net/a/b\n</div></figure>\n"]
        TXT,
    ],
    'embed issuu.com' => [
        '<p>https://issuu.com/a/docs/b</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://issuu.com/a/docs/b","type":"rich","providerNameSlug":"issuu"} ["\n<figure class=\"wp-block-embed is-type-rich is-provider-issuu wp-block-embed-issuu\"><div class=\"wp-block-embed__wrapper\">\nhttps://issuu.com/a/docs/b\n</div></figure>\n"]
        TXT,
    ],
    'embed dailymotion.com' => [
        '<p>https://dailymotion.com/video/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://dailymotion.com/video/1","type":"video","providerNameSlug":"dailymotion","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-dailymotion wp-block-embed-dailymotion wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://dailymotion.com/video/1\n</div></figure>\n"]
        TXT,
    ],
    'embed subdomain' => [
        '<p>https://www.vimeo.com/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://www.vimeo.com/1","type":"video","providerNameSlug":"vimeo","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.vimeo.com/1\n</div></figure>\n"]
        TXT,
    ],
    'embed lookalike host' => [
        '<p>https://notyoutube.com/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://notyoutube.com/1","type":"rich"} ["\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://notyoutube.com/1\n</div></figure>\n"]
        note: An embed for "notyoutube.com" is not a block-editor provider; confirm it renders.
        TXT,
    ],
    'embed host with suffix' => [
        '<p>https://youtube.com.evil.test/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://youtube.com.evil.test/1","type":"rich"} ["\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtube.com.evil.test/1\n</div></figure>\n"]
        note: An embed for "youtube.com.evil.test" is not a block-editor provider; confirm it renders.
        TXT,
    ],
    'embed host case' => [
        '<p>https://WWW.Vimeo.COM/1</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://WWW.Vimeo.COM/1","type":"video","providerNameSlug":"vimeo","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://WWW.Vimeo.COM/1\n</div></figure>\n"]
        TXT,
    ],
    'embed url with angle brackets' => [
        '<p>https://e.test/a&lt;b&gt;c</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://e.test/a<b>c","type":"rich"} ["\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://e.test/a&lt;b&gt;c\n</div></figure>\n"]
        note: An embed for "e.test" is not a block-editor provider; confirm it renders.
        TXT,
    ],
    'embed url ampersand' => [
        '<p>https://youtube.com/watch?v=1&amp;t=2</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https://youtube.com/watch?v=1&t=2","type":"video","providerNameSlug":"youtube","responsive":true} ["\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">\nhttps://youtube.com/watch?v=1&t=2\n</div></figure>\n"]
        TXT,
    ],
    'embed no host' => [
        '<p>https:///path</p>',
        [],
        <<<'TXT'
        core/embed {"url":"https:///path","type":"rich"} ["\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps:///path\n</div></figure>\n"]
        note: An embed for "" is not a block-editor provider; confirm it renders.
        TXT,
    ],
    'duplicate notices collapse' => [
        '<h1>a</h1><h1>b</h1>',
        [],
        <<<'TXT'
        core/heading {"level":1} ["\n<h1 class=\"wp-block-heading\">a</h1>\n"]
        core/heading {"level":1} ["\n<h1 class=\"wp-block-heading\">b</h1>\n"]
        note: An <h1> sits inside the section, which already renders an <h2>. Consider the "demote headings" option.
        TXT,
    ],
    'mixed document' => [
        '<h2>T</h2><p>a</p><ul><li>x</li></ul><div>d</div><p>b</p>',
        [],
        <<<'TXT'
        core/heading [] ["\n<h2 class=\"wp-block-heading\">T</h2>\n"]
        core/paragraph [] ["\n<p>a</p>\n"]
        core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]
          core/list-item [] ["<li>x</li>"]
        core/html [] ["\n<div>d</div>\n"]
        core/paragraph [] ["\n<p>b</p>\n"]
        note: An <h2> sits inside the section, which already renders an <h2>. Consider the "demote headings" option.
        note: A <div> wrapper was preserved as a Custom HTML block.
        TXT,
    ],
]);

it('converts markup to the blocks the editor expects', function (string $html, array $args, string $expected): void {
    $result = uams_c2s_h2b($html, $args);

    $lines = $result['blocks'];

    foreach ($result['notices'] as $notice) {
        $lines[] = 'note: '.$notice;
    }

    expect(implode("\n", $lines))->toBe($expected);

    // Every block's innerHTML is its string content joined, and it has one null
    // placeholder per inner block, as serialize_blocks() requires.
    foreach (uams_c2s_h2b_flatten($result['raw']) as $block) {
        $strings = array_filter($block['innerContent'], 'is_string');
        $holes = array_filter($block['innerContent'], 'is_null');

        expect($block['innerHTML'])->toBe(implode('', $strings))
            ->and(count($holes))->toBe(count($block['innerBlocks']))
            ->and(array_keys($block))->toBe(['blockName', 'attrs', 'innerBlocks', 'innerHTML', 'innerContent']);
    }
})->with('uams_c2s_h2b_cases');

it('starts from the plugin defaults and lets the arguments override them', function (): void {
    uams_c2s_h2b_world();

    $default = new UAMS_C2S_HTML_To_Blocks;
    $override = new UAMS_C2S_HTML_To_Blocks(['list_format' => 'v2']);

    $property = new ReflectionProperty(UAMS_C2S_HTML_To_Blocks::class, 'args');

    expect($property->getValue($default))->toBe(uams_c2s_default_args())
        ->and($property->getValue($override))->toBe(array_merge(uams_c2s_default_args(), ['list_format' => 'v2']));
});

it('reports each note once, in order, and forgets them on the next conversion', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $converter->convert('<h1>a</h1><div>d</div><h1>b</h1>');

    expect($converter->get_notices())->toBe([
        'An <h1> sits inside the section, which already renders an <h2>. Consider the "demote headings" option.',
        'A <div> wrapper was preserved as a Custom HTML block.',
    ]);

    $converter->convert('<p>fine</p>');

    expect($converter->get_notices())->toBe([]);
});

it('treats a missing or numeric value as text', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;

    expect($converter->convert(null))->toBe([])
        ->and(uams_c2s_h2b_lines($converter->convert(123)))->toBe(['core/paragraph [] ["\n<p>123</p>\n"]']);
});

it('keeps the whole body as one Custom HTML block when PHP has no DOM extension', function (): void {
    uams_c2s_h2b_world();

    Patchwork\redefine('class_exists', static fn (string $class, bool $autoload = true): bool => $class === 'DOMDocument' ? false : Patchwork\relay());

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $blocks = $converter->convert('<p>AAA</p> <em>x</em> ');

    expect(uams_c2s_h2b_lines($blocks))->toBe(['core/html [] ["\n<p>CCC</p> <em>x</em>\n"]'])
        ->and($converter->get_notices())->toBe(['HTML could not be parsed; the body was preserved as a single Custom HTML block.']);
});

it('hands a node that is not text, a comment or an element back as nothing', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $walk = new ReflectionMethod($converter, 'node_to_blocks');

    $document = new DOMDocument;
    $instruction = $document->createProcessingInstruction('target', 'data');
    $reference = $document->createEntityReference('amp');
    $comment = $document->createComment('c');

    expect($walk->invoke($converter, $instruction))->toBe([])
        ->and($walk->invoke($converter, $reference))->toBe([])
        ->and($walk->invoke($converter, $comment))->toBe([]);
});

it('treats a CDATA section like text', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $walk = new ReflectionMethod($converter, 'node_to_blocks');
    $document = new DOMDocument;

    expect(uams_c2s_h2b_lines($walk->invoke($converter, $document->createCDATASection('[gallery]'))))->toBe(['core/shortcode [] ["\n[gallery]\n"]'])
        ->and($walk->invoke($converter, $document->createCDATASection('  ')))->toBe([]);
});

it('gives a node with no classes, or no element, an empty class list', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $classes = new ReflectionMethod($converter, 'class_list');
    $document = new DOMDocument;

    $bare = $document->createElement('p');
    $blank = $document->createElement('p');
    $blank->setAttribute('class', '   ');
    $some = $document->createElement('p');
    $some->setAttribute('class', " a \t b\n");

    expect($classes->invoke($converter, $document->createTextNode('x')))->toBe([])
        ->and($classes->invoke($converter, $bare))->toBe([])
        ->and($classes->invoke($converter, $blank))->toBe([])
        ->and($classes->invoke($converter, $some))->toBe(['a', 'b']);
});

it('recognises blank text, including the separators the DOM leaves behind', function (string $text, bool $blank): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;

    expect((new ReflectionMethod($converter, 'is_blank'))->invoke($converter, $text))->toBe($blank);
})->with([
    'empty' => ['', true],
    'spaces and newlines' => [" \n\t ", true],
    'non-breaking space' => ["\u{a0}", true],
    'zero width space' => ["\u{200b}", true],
    'byte order mark' => ["\u{feff}", true],
    'line separator' => ["\u{2028}", true],
    'a letter' => ['a', false],
    'a letter among spaces' => [" \u{a0}a ", false],
    'invalid UTF-8' => ["\xff", false],
    'invalid UTF-8 among spaces' => [" \xff ", false],
]);

it('recognises a single shortcode', function (string $text, bool $is): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;

    expect((new ReflectionMethod($converter, 'is_shortcode'))->invoke($converter, $text))->toBe($is);
})->with([
    'bare' => ['[a]', true],
    'with attributes' => ['[a b="c"]', true],
    'name characters' => ['[a-b_C1]', true],
    'trimmed' => ["  [a]\n", true],
    'across lines' => ["[a\nb]", true],
    'enclosing' => ['[a]x[/a]', true],
    'empty' => ['[]', false],
    'unclosed' => ['[a', false],
    'text before' => ['x[a]', false],
    'text after' => ['[a]x', false],
    'space first' => ['[ a]', false],
    'no brackets' => ['a', false],
]);

it('recognises a bare URL', function (string $text, bool $is): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;

    expect((new ReflectionMethod($converter, 'is_url'))->invoke($converter, $text))->toBe($is);
})->with([
    'https' => ['https://a.test/x', true],
    'http' => ['http://a.test', true],
    'httpv' => ['httpv://a.test', true],
    'upper case' => ['HTTPS://A.TEST', true],
    'trimmed' => ["  https://a.test  \n", true],
    'space inside' => ['https://a.test/a b', false],
    'newline inside' => ["https://a.test/a\nb", false],
    'tab inside' => ["https://a.test/a\tb", false],
    'other scheme' => ['ftp://a.test', false],
    'text before' => ['see https://a.test', false],
    'prefix before' => ['xhttps://a.test', false],
    'nothing after the scheme' => ['https://', false],
    'no scheme' => ['a.test', false],
]);

it('knows whether a string ends with another', function (string $haystack, string $needle, bool $ends): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;

    expect((new ReflectionMethod($converter, 'str_ends_with'))->invoke($converter, $haystack, $needle))->toBe($ends);
})->with([
    'whole string' => ['abc', 'abc', true],
    'tail' => ['abc', 'bc', true],
    'last character' => ['abc', 'c', true],
    'not the tail' => ['abc', 'ab', false],
    'middle' => ['abc', 'b', false],
    'longer than the string' => ['a', 'abc', false],
    'empty needle' => ['abc', '', true],
    'empty both' => ['', '', true],
]);

it('reads the media id from a URL, retrying without the size suffix', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $lookup = new ReflectionMethod($converter, 'attachment_id_from_url');

    expect($lookup->invoke($converter, 'https://e.test/known.jpg'))->toBe(77)
        ->and($lookup->invoke($converter, 'https://e.test/known-300x200.jpg'))->toBe(77)
        ->and($lookup->invoke($converter, 'https://e.test/known-300x200.JPEG'))->toBe(0)
        ->and($lookup->invoke($converter, 'https://e.test/other-300x200.jpg'))->toBe(0)
        ->and($lookup->invoke($converter, 'https://e.test/unknown.jpg'))->toBe(0)
        ->and($lookup->invoke($converter, 'https://e.test/known-300x200'))->toBe(0);
});

it('asks for the media id once when the URL already matches', function (): void {
    uams_c2s_h2b_world();

    $asked = [];

    when('attachment_url_to_postid')->alias(static function ($url) use (&$asked): int {
        $asked[] = $url;

        return $url === 'https://e.test/a-1x1.png' ? 5 : 0;
    });

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $lookup = new ReflectionMethod($converter, 'attachment_id_from_url');

    expect($lookup->invoke($converter, 'https://e.test/a-1x1.png'))->toBe(5)
        ->and($asked)->toBe(['https://e.test/a-1x1.png'])
        ->and($lookup->invoke($converter, 'https://e.test/a-2x2.png'))->toBe(0)
        ->and($asked)->toBe(['https://e.test/a-1x1.png', 'https://e.test/a-2x2.png', 'https://e.test/a.png']);
});

it('reads upper-case tags exactly as lower-case ones', function (): void {
    $upper = uams_c2s_h2b('<P CLASS="text-center">x</P><H3>T</H3><UL><LI>a</LI></UL><BLOCKQUOTE><P>q</P><CITE>w</CITE></BLOCKQUOTE><P><A HREF="https://e.test/x"><IMG SRC="https://e.test/a.jpg" ALT="A"></A></P><P>a<BR>b</P><DIV>d</DIV><PRE><CODE>c</CODE></PRE><TABLE><TR><TD>a</TD></TR></TABLE><FIGURE><IMG SRC="https://e.test/a.jpg" ALT="A"></FIGURE><OL><LI>a</LI></OL><HR>');
    $lower = uams_c2s_h2b('<p class="text-center">x</p><h3>T</h3><ul><li>a</li></ul><blockquote><p>q</p><cite>w</cite></blockquote><p><a href="https://e.test/x"><img src="https://e.test/a.jpg" alt="A"></a></p><p>a<br>b</p><div>d</div><pre><code>c</code></pre><table><tr><td>a</td></tr></table><figure><img src="https://e.test/a.jpg" alt="A"></figure><ol><li>a</li></ol><hr>');

    expect($upper['blocks'])->toBe($lower['blocks'])
        ->and($upper['notices'])->toBe($lower['notices'])
        ->and(count($upper['blocks']))->toBe(15);
});

it('keeps non-ASCII text as it came', function (): void {
    $result = uams_c2s_h2b("<p>Caf\u{e9} \u{2014} \u{2713} \u{65e5}</p>");

    expect($result['blocks'])->toBe(["core/paragraph [] [\"\\n<p>Caf\u{e9} \u{2014} \u{2713} \u{65e5}</p>\\n\"]"]);
});

it('leaves libxml the way it found it', function (bool $before): void {
    uams_c2s_h2b_world();

    libxml_use_internal_errors($before);

    try {
        (new UAMS_C2S_HTML_To_Blocks)->convert('<p>x</p></div><p>y</p>');

        expect(libxml_use_internal_errors())->toBe($before)
            ->and(libxml_get_errors())->toBe([]);
    } finally {
        libxml_use_internal_errors(false);
        libxml_clear_errors();
    }
})->with([true, false]);

it('reports each note once with its keys renumbered', function (): void {
    uams_c2s_h2b_world();

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $converter->convert('<h1>a</h1><h1>b</h1><div>d</div>');

    expect($converter->get_notices())->toBe([
        'An <h1> sits inside the section, which already renders an <h2>. Consider the "demote headings" option.',
        'A <div> wrapper was preserved as a Custom HTML block.',
    ]);
});

it('returns nothing for blank input even when PHP has no DOM extension', function (): void {
    uams_c2s_h2b_world();

    Patchwork\redefine('class_exists', static fn (string $class, bool $autoload = true): bool => $class === 'DOMDocument' ? false : Patchwork\relay());

    $converter = new UAMS_C2S_HTML_To_Blocks;

    expect($converter->convert('   '))->toBe([])
        ->and($converter->convert(''))->toBe([])
        ->and($converter->get_notices())->toBe([]);
});

it('embeds a URL paragraph padded with whitespace', function (): void {
    $result = uams_c2s_h2b('<p> https://www.youtube.com/watch?v=1 </p>');

    expect($result['blocks'][0])->toStartWith('core/embed {"url":"https://www.youtube.com/watch?v=1"');
});

it('only takes a citation from a cite or a paragraph around one', function (): void {
    $div = uams_c2s_h2b('<blockquote><p>q</p><div><cite>X</cite></div></blockquote>');
    $span = uams_c2s_h2b('<blockquote><p>q</p><span><cite>X</cite></span></blockquote>');
    $padded = uams_c2s_h2b('<blockquote><p>q</p><p><cite> Who </cite></p></blockquote>');

    expect($div['blocks'])->toBe([
        'core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,null,"</blockquote>\n"]',
        '  core/paragraph [] ["\n<p>q</p>\n"]',
        '  core/html [] ["\n<div><cite>X</cite></div>\n"]',
    ])
        ->and($span['blocks'][2])->toBe('  core/html [] ["\n<span><cite>X</cite></span>\n"]')
        ->and($padded['blocks'][0])->toBe('core/quote [] ["\n<blockquote class=\"wp-block-quote\">",null,"<cite>Who</cite></blockquote>\n"]');
});

it('ignores whitespace around the only child of a paragraph', function (): void {
    $bare = uams_c2s_h2b('<p> <img src="https://e.test/a.jpg" alt="A"> </p>');
    $linked = uams_c2s_h2b("<p>\n<a href=\"https://e.test/x\"><img src=\"https://e.test/a.jpg\" alt=\"A\"></a>\n</p>");

    expect($bare['blocks'][0])->toStartWith('core/image {"sizeSlug":"full","linkDestination":"none"}')
        ->and($linked['blocks'][0])->toStartWith('core/image {"sizeSlug":"full","linkDestination":"custom"}');
});

it('does not treat a section level that is not a number as a level', function (): void {
    $result = uams_c2s_h2b('<h2>T</h2>', ['max_heading' => 'abc']);

    expect($result['blocks'])->toBe(['core/heading [] ["\n<h2 class=\"wp-block-heading\">T</h2>\n"]'])
        ->and($result['notices'])->toBe([]);
});

it('keeps an author class that sits among the alignment classes', function (): void {
    $result = uams_c2s_h2b('<p class="a text-center b">x</p>');

    expect($result['blocks'])->toBe(['core/paragraph {"align":"center","className":"a b"} ["\n<p class=\"a b has-text-align-center\">x</p>\n"]']);
});

it('turns the media id the lookup returns into an int', function (): void {
    uams_c2s_h2b_world();

    when('attachment_url_to_postid')->alias(static fn ($url): string => '5');

    $converter = new UAMS_C2S_HTML_To_Blocks;
    $lookup = new ReflectionMethod($converter, 'attachment_id_from_url');

    expect($lookup->invoke($converter, 'https://e.test/a.png'))->toBe(5);

    when('attachment_url_to_postid')->alias(static fn ($url): string => $url === 'https://e.test/a.png' ? '6' : '0');

    expect($lookup->invoke($converter, 'https://e.test/a-1x1.png'))->toBe(6);
});

it('asks for a URL without a size suffix once', function (): void {
    uams_c2s_h2b_world();

    $asked = [];

    when('attachment_url_to_postid')->alias(static function ($url) use (&$asked): int {
        $asked[] = $url;

        return 0;
    });

    $converter = new UAMS_C2S_HTML_To_Blocks;
    (new ReflectionMethod($converter, 'attachment_id_from_url'))->invoke($converter, 'https://e.test/plain.png');

    expect($asked)->toBe(['https://e.test/plain.png']);
});

it('leaves a paragraph with more than one child as text', function (): void {
    $result = uams_c2s_h2b('<p><img src="https://e.test/a.jpg" alt="A"> look</p>');

    expect($result['blocks'])->toBe(['core/paragraph [] ["\n<p><img src=\"https://e.test/a.jpg\" alt=\"A\"> look</p>\n"]']);
});

it('takes only list items as items', function (): void {
    $result = uams_c2s_h2b('<ul><li>a</li><span>s</span></ul>');

    expect($result['blocks'])->toBe([
        'core/list [] ["\n<ul class=\"wp-block-list\">",null,"</ul>\n"]',
        '  core/list-item [] ["<li>a</li>"]',
    ]);
});

it('trims the content of a list item', function (): void {
    $result = uams_c2s_h2b("<ul><li> \n a \n </li></ul>");

    expect($result['blocks'][1])->toBe('  core/list-item [] ["<li>a</li>"]');
});

it('links a figure image only through a wrapping link', function (): void {
    $result = uams_c2s_h2b('<figure><span href="https://e.test/x"><img src="https://e.test/a.jpg" alt="A"></span></figure>');

    expect($result['blocks'][0])->toStartWith('core/image {"sizeSlug":"full","linkDestination":"none"}');
});
