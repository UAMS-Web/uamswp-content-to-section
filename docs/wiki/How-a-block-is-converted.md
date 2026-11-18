# How a block is converted

Written against v1.1.0.

The two blocks look alike but store their body differently, so a conversion is two jobs: remap three fields, and turn one value of WYSIWYG HTML into a real block tree.

## The fields

| | UAMS Content Block | UAMS Section Block |
|---|---|---|
| Block name | `acf/uams-content` | `acf/uams-section` |
| Heading | `content_heading` | `section_heading` |
| Hide heading | `content_hide_heading` | `section_hide_heading` |
| Background | `content_background_color` | `section_background_color` |
| Body | `content_content`, a single ACF WYSIWYG field | inner blocks |
| Region filter | `content_geo` and `content_geo_region` | none |
| Rendered id | `content-…` | `uams-section-…` |

The Content Block is read by field *name*. Only the Section Block being written needs field *keys*, and those are read from the live `group_uams_section` field group at runtime, so a change to the theme's field key suffix does not break the mapping. The keys written into the plugin are only a fallback. The [`uams_c2s_field_pairs`](Filters.md#uams_c2s_field_pairs) and [`uams_c2s_wysiwyg_field`](Filters.md#uams_c2s_wysiwyg_field) filters change the mapping.

## What the body becomes

| Source HTML | Block |
|---|---|
| `<p>` | `core/paragraph` (Bootstrap `text-center` and `text-right` become block alignment) |
| `<h1>` to `<h6>` | `core/heading`, with its `id` kept as the block anchor |
| `<ul>`, `<ol>` | `core/list` and `core/list-item`, nesting intact, `start` kept |
| `<blockquote>` | `core/quote`, with `<cite>` moved into the citation |
| `<table>` | `core/table` |
| `<img>`, `<figure>` | `core/image`, with the attachment id recovered from `wp-image-N` or the URL |
| A bare URL on its own line | `core/embed`, with the provider detected |
| A YouTube or Vimeo `<iframe>` | `core/embed` |
| `[shortcode]` | `core/shortcode` |
| `<hr>` | `core/separator` |
| `<pre>`, `<pre><code>` | `core/preformatted`, `core/code` |
| `<div>` and unknown markup | `core/html`, unchanged |

ACF stores the WYSIWYG value unfiltered, and the front end applied `wpautop()` at render time through `acf_the_content`. Inner blocks are not filtered that way, so the plugin runs `wpautop()` and `shortcode_unautop()` first; otherwise every double newline would collapse into one run-on paragraph. Splitting the HTML needs PHP's DOM extension (see [Troubleshooting](Troubleshooting.md#php-is-missing-the-dom-extension)).

## What it deliberately does not guess

- **Geo-targeted blocks are skipped by default.** See [Conversion options](Conversion-options.md#geo-targeted-blocks).
- **Anchors change.** A Content Block with no custom anchor renders as `id="content-block_…"`; the Section Block renders `id="uams-section-…"`. An explicit anchor carries across unchanged; anything relying on the generated id is flagged in the preview.
- **Layout markup is kept, not reinterpreted.** A `<div class="row">` of Bootstrap columns becomes a `core/html` block rather than a guessed-at column layout.
- **Headings stay where they are** unless you ask otherwise. See [Conversion options](Conversion-options.md#headings).
